<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

function nextNumero(PDO $db) {
    // Asume que la transacción está abierta por el caller.
    $pre = (string)$db->query("SELECT valor FROM configuracion WHERE clave = 'cotizacion_prefijo'")->fetchColumn();
    if ($pre === false || $pre === '') $pre = 'COT';
    $year = date('Y');

    // 1) Lee el contador (FOR UPDATE: bloquea para evitar duplicados en POSTs paralelos)
    $st = $db->prepare("SELECT CAST(valor AS UNSIGNED) AS num FROM configuracion WHERE clave = 'cotizacion_siguiente' FOR UPDATE");
    $st->execute();
    $confNum = (int)($st->fetchColumn() ?: 1);

    // 2) Mira el mayor número realmente existente este año en cotizaciones.
    //    Si el contador quedó atrasado (p.ej. se reseteó a 1 pero ya había
    //    cotizaciones), usamos max+1 para no chocar con la clave UNIQUE.
    $st2 = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(numero, '-', -1) AS UNSIGNED)) AS maxn
                         FROM cotizaciones
                         WHERE numero LIKE ?");
    $st2->execute([$pre . '-' . $year . '-%']);
    $maxn = (int)($st2->fetchColumn() ?: 0);

    $num = max($confNum, $maxn + 1);

    return $pre . '-' . $year . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];
    // Soporte para method override via query string (POST con _method=PUT)
    if ($method === 'POST' && isset($_GET['_method'])) {
        $method = strtoupper($_GET['_method']);
    }

    if ($user['rol'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if (!$id) {
        $path = explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $last = end($path);
        $id = is_numeric($last) ? (int)$last : null;
    }

    // Detectar acción especial
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $esRestore = strpos($uri, 'restore') !== false || isset($_GET['restore']);
    $esHard = isset($_GET['hard']) && $_GET['hard'] == '1';
    $esPapelera = isset($_GET['papelera']) && $_GET['papelera'] == '1';

    $limpiar = function($str) {
        return trim(str_replace("\0", "", (string)($str ?? '')));
    };

    // --- GET: LISTAR O VER DETALLE ---
   if ($method === 'GET') {
        if ($id) {
            // (Esta parte para ver una sola cotización está bien)
            $sql = "SELECT * FROM cotizaciones WHERE id = ?";
            $s = $db->prepare($sql);
            $s->execute([$id]);
            $cot = $s->fetch(PDO::FETCH_ASSOC);

            if (!$cot) {
                echo json_encode(['ok' => false, 'error' => 'No encontrada']);
                exit;
            }

            $cot['id'] = (int)$cot['id'];
            $cot['cliente_id'] = (int)$cot['cliente_id'];
            $cot['proyecto_id'] = !empty($cot['proyecto_id']) ? (int)$cot['proyecto_id'] : null;
            $cot['plantilla_id'] = !empty($cot['plantilla_id']) ? (int)$cot['plantilla_id'] : null;

            $si = $db->prepare("SELECT * FROM cotizacion_items WHERE cotizacion_id = ? ORDER BY orden");
            $si->execute([$id]);
            $items = $si->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as &$it) {
                $it['id'] = (int)$it['id'];
                $it['servicio_id'] = !empty($it['servicio_id']) ? (int)$it['servicio_id'] : null;
                $it['cantidad'] = (float)($it['cantidad'] ?? 1);
                $it['precio_unitario'] = (float)($it['precio_unitario'] ?? 0);
            }
            
            $cot['items'] = $items;
            echo json_encode(['ok' => true, 'data' => $cot]);
            exit;
        }

        // --- LISTADO GENERAL CON PAGINACIÓN ---
        $where = $esPapelera ? "WHERE c.deleted_at IS NOT NULL" : "WHERE c.deleted_at IS NULL";

        // Búsqueda server-side
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $params = [];
        if ($q !== '') {
            $where .= " AND (c.numero LIKE ? OR cl.nombre LIKE ? OR cl.nombre_agencia LIKE ? OR p.nombre LIKE ?)";
            $like = '%' . escapeLike($q) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        // Filtro por estado (server-side). "Por vencer" es un filtro virtual:
        // cotizaciones pendientes cuya validez está próxima o ya vencida.
        $estado = isset($_GET['estado']) ? trim((string)$_GET['estado']) : '';
        if ($estado === 'Por vencer') {
            $where .= " AND c.estado = 'Pendiente'
                        AND c.fecha_validez IS NOT NULL
                        AND c.fecha_validez <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($estado !== '' && $estado !== 'Todos') {
            $where .= " AND c.estado = ?";
            $params[] = $estado;
        }

        // 1. Lógica de Paginación con cap a 100 (consistencia con otros endpoints)
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 10;
        $offset = ($page - 1) * $per_page;

        // 2. Contar Total (con JOINs por filtro server-side)
        $countSql = "SELECT COUNT(*) FROM cotizaciones c
                     LEFT JOIN clientes cl ON cl.id = c.cliente_id
                     LEFT JOIN proyectos p ON p.id = c.proyecto_id
                     {$where}";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = $countStmt->fetchColumn();
        $totalPages = (int)ceil($totalItems / $per_page);

        // 3. Consulta con LIMIT y OFFSET
        $sql = "SELECT c.id, c.numero, c.fecha, c.fecha_validez, c.estado, c.proyecto_id, c.deleted_at,
                       cl.nombre as cliente_nom,
                       cl.nombre_agencia as cliente_age, p.nombre as proyecto_nom,
                       /* Total real = neto - descuento global. Antes mostraba
                          sólo el neto, que confundía al usuario. */
                       ROUND(
                         COALESCE((SELECT SUM(ci.cantidad * ci.precio_unitario)
                                   FROM cotizacion_items ci WHERE ci.cotizacion_id = c.id), 0)
                         * (1 - COALESCE(c.descuento_global, 0) / 100)
                       , 0) as total_calculado
                FROM cotizaciones c
                LEFT JOIN clientes cl ON cl.id = c.cliente_id
                LEFT JOIN proyectos p ON p.id = c.proyecto_id
                {$where}
                ORDER BY c.id DESC
                LIMIT $per_page OFFSET $offset";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = [];
        while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cliente_display = !empty($r['cliente_age']) ? $limpiar($r['cliente_age']) : $limpiar($r['cliente_nom']);
            
            $data[] = [
                'id'              => (int)$r['id'],
                'numero'          => $limpiar($r['numero']),
                'fecha'           => $r['fecha'],
                'fecha_validez'   => $r['fecha_validez'],
                'estado'          => $limpiar($r['estado']),
                'proyecto_id'     => !empty($r['proyecto_id']) ? (int)$r['proyecto_id'] : 0,
                'cliente_nombre'  => $cliente_display,
                'proyecto_nombre' => $limpiar($r['proyecto_nom'] ?? 'Sin proyecto'),
                'total'           => (float)($r['total_calculado'] ?? 0),
                'deleted_at'      => $r['deleted_at']
            ];
        }

        // 4. Respuesta con el objeto pagination (Exactamente como lo espera tu React)
        echo json_encode([
            'ok' => true, 
            'data' => $data,
            'pagination' => [
                'total' => (int)$totalItems,
                'page' => (int)$page,
                'per_page' => (int)$per_page,
                'total_pages' => (int)$totalPages
            ]
        ]);
        exit;
    }

    // --- RESTAURAR COTIZACIÓN ---
    // DEBE IR ANTES de POST/PUT para que no se confunda
    if ($method === 'POST' && $esRestore && $id) {
        $stmt = $db->prepare("UPDATE cotizaciones SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            audit($db, $user['id'], 'RESTORE', 'cotizaciones', "Restauró cotización #{$id}", $id);
            echo json_encode(['ok' => true, 'message' => 'Cotización restaurada']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Cotización no encontrada o no estaba eliminada']);
        }
        exit;
    }

   // --- POST (CREAR) O PUT (EDITAR) ---
    if ($method === 'POST' || ($method === 'PUT' && $id)) {
        $b = body();

        if (empty($b['cliente_id'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Debes seleccionar un Cliente.']);
            exit;
        }

        $db->beginTransaction();

        if ($method === 'POST') {
            if (empty($b['numero'])) {
                $b['numero'] = nextNumero($db);
                // Avanzar el contador al número ACTUAL + 1 (no a +1 del valor
                // anterior). Así si el contador estaba atrasado, queda
                // sincronizado con la realidad y no vuelve a generar duplicados.
                $usado = (int)substr($b['numero'], strrpos($b['numero'], '-') + 1);
                $db->prepare("INSERT INTO configuracion (clave, valor) VALUES ('cotizacion_siguiente', ?) ON DUPLICATE KEY UPDATE valor = ?")
                   ->execute([$usado + 1, $usado + 1]);
            }

            $dctoTipo = (($b['descuento_global_tipo'] ?? '') === 'fijo') ? 'fijo' : 'porcentaje';
            // Detectar si la columna existe (puede no estar si no aplicaron
            // la migración 2026_05_20_4_descuento_tipo.sql todavía).
            $tieneTipoCol = false;
            try {
                $check = $db->query("SHOW COLUMNS FROM cotizaciones LIKE 'descuento_global_tipo'");
                $tieneTipoCol = $check && $check->fetch() !== false;
            } catch (Throwable $_) { /* fallback a false */ }

            if ($tieneTipoCol) {
                $sql = "INSERT INTO cotizaciones (numero, proyecto_id, cliente_id, fecha, fecha_validez, estado, notas, descuento_global, descuento_global_tipo, plantilla_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $params = [
                    $limpiar($b['numero']),
                    !empty($b['proyecto_id']) ? (int)$b['proyecto_id'] : null,
                    (int)$b['cliente_id'],
                    $b['fecha'],
                    !empty($b['fecha_validez']) ? $b['fecha_validez'] : null,
                    $limpiar($b['estado'] ?? 'Pendiente'),
                    $limpiar($b['notas'] ?? null),
                    (float)($b['descuento_global'] ?? 0),
                    $dctoTipo,
                    !empty($b['plantilla_id']) ? (int)$b['plantilla_id'] : null
                ];
            } else {
                // Migración pendiente — guardamos sin el tipo, todo en %.
                $sql = "INSERT INTO cotizaciones (numero, proyecto_id, cliente_id, fecha, fecha_validez, estado, notas, descuento_global, plantilla_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $params = [
                    $limpiar($b['numero']),
                    !empty($b['proyecto_id']) ? (int)$b['proyecto_id'] : null,
                    (int)$b['cliente_id'],
                    $b['fecha'],
                    !empty($b['fecha_validez']) ? $b['fecha_validez'] : null,
                    $limpiar($b['estado'] ?? 'Pendiente'),
                    $limpiar($b['notas'] ?? null),
                    (float)($b['descuento_global'] ?? 0),
                    !empty($b['plantilla_id']) ? (int)$b['plantilla_id'] : null
                ];
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $cot_id = (int)$db->lastInsertId();
        } else {
            // --- ACTUALIZAR CABECERA ---
            $dctoTipo = (($b['descuento_global_tipo'] ?? '') === 'fijo') ? 'fijo' : 'porcentaje';
            // Mismo check que en INSERT: si la migración 2026_05_20_4 no
            // se aplicó, omitimos descuento_global_tipo del UPDATE.
            $tieneTipoCol = false;
            try {
                $check = $db->query("SHOW COLUMNS FROM cotizaciones LIKE 'descuento_global_tipo'");
                $tieneTipoCol = $check && $check->fetch() !== false;
            } catch (Throwable $_) { /* fallback false */ }

            if ($tieneTipoCol) {
                $sql = "UPDATE cotizaciones SET
                        numero=?, proyecto_id=?, cliente_id=?, fecha=?, fecha_validez=?,
                        estado=?, notas=?, descuento_global=?, descuento_global_tipo=?,
                        plantilla_id=?, updated_at=NOW()
                        WHERE id=? AND deleted_at IS NULL";
                $params = [
                    $b['numero'],
                    !empty($b['proyecto_id']) ? (int)$b['proyecto_id'] : null,
                    (int)$b['cliente_id'],
                    $b['fecha'],
                    !empty($b['fecha_validez']) ? $b['fecha_validez'] : null,
                    $b['estado'] ?: 'Pendiente',
                    $b['notas'] ?: null,
                    (float)($b['descuento_global'] ?? 0),
                    $dctoTipo,
                    !empty($b['plantilla_id']) ? (int)$b['plantilla_id'] : null,
                    $id
                ];
            } else {
                $sql = "UPDATE cotizaciones SET
                        numero=?, proyecto_id=?, cliente_id=?, fecha=?, fecha_validez=?,
                        estado=?, notas=?, descuento_global=?,
                        plantilla_id=?, updated_at=NOW()
                        WHERE id=? AND deleted_at IS NULL";
                $params = [
                    $b['numero'],
                    !empty($b['proyecto_id']) ? (int)$b['proyecto_id'] : null,
                    (int)$b['cliente_id'],
                    $b['fecha'],
                    !empty($b['fecha_validez']) ? $b['fecha_validez'] : null,
                    $b['estado'] ?: 'Pendiente',
                    $b['notas'] ?: null,
                    (float)($b['descuento_global'] ?? 0),
                    !empty($b['plantilla_id']) ? (int)$b['plantilla_id'] : null,
                    $id
                ];
            }
            $db->prepare($sql)->execute($params);

            // --- ¡ESTO ES LO QUE FALTABA! ---
            $cot_id = $id;

            // Borramos los ítems anteriores para reemplazarlos por los nuevos (edición limpia)
            $db->prepare("DELETE FROM cotizacion_items WHERE cotizacion_id = ?")->execute([$cot_id]);
        }

        // --- INSERTAR ÍTEMS (Ahora $cot_id siempre tiene valor) ---
        if (!empty($b['items']) && is_array($b['items'])) {
            $si = $db->prepare("INSERT INTO cotizacion_items (cotizacion_id, servicio_id, descripcion, cantidad, unidad, precio_unitario, orden) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($b['items'] as $k => $it) {
                $si->execute([
                    $cot_id, 
                    !empty($it['servicio_id']) ? (int)$it['servicio_id'] : null,
                    $limpiar($it['descripcion']), 
                    (float)($it['cantidad'] ?? 1), 
                    $limpiar($it['unidad'] ?? 'Servicio'), 
                    (float)($it['precio_unitario'] ?? 0), 
                    $k
                ]);
            }
        }

        $db->commit();
        
        // Auditoría
        $accion = ($method === 'POST') ? 'CREATE' : 'UPDATE';
        $detalle = ($method === 'POST') ? "Creó cotización #{$b['numero']}" : "Editó cotización ID {$id}";
        audit($db, $user['id'], $accion, 'cotizaciones', $detalle, $cot_id);
        
        echo json_encode(['ok' => true, 'id' => $cot_id]);
        exit;
    }

    // --- DELETE ---
    if ($method === 'DELETE' && $id) {
        $info = $db->prepare("SELECT numero FROM cotizaciones WHERE id = ?");
        $info->execute([$id]);
        $cot = $info->fetch();
        
        if (!$cot) {
            echo json_encode(['ok' => false, 'error' => 'Cotización no encontrada']);
            exit;
        }
        
        if ($esHard) {
            $db->prepare("DELETE FROM cotizaciones WHERE id = ?")->execute([$id]);
            audit($db, $user['id'], 'HARD_DELETE', 'cotizaciones', "Eliminó PERMANENTEMENTE cotización #{$cot['numero']}", $id);
            echo json_encode(['ok' => true, 'message' => 'Cotización eliminada permanentemente']);
        } else {
            $db->prepare("UPDATE cotizaciones SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
            audit($db, $user['id'], 'SOFT_DELETE', 'cotizaciones', "Envió a papelera cotización #{$cot['numero']}", $id);
            echo json_encode(['ok' => true, 'message' => 'Cotización enviada a papelera']);
        }
        exit;
    }

} catch (Throwable $e) {
    // Defensivo: si algo dentro de errSafe falla (poco probable) igual
    // devolvemos JSON con el detalle del error real, para no terminar con
    // body vacío y "Respuesta inválida" en el frontend.
    try { if (isset($db) && $db->inTransaction()) $db->rollBack(); } catch (Throwable $_) { /* ignorar */ }
    try {
        if (function_exists('logError')) {
            logError('Error en cotizaciones.php', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()]);
        } else {
            error_log('cotizaciones.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    } catch (Throwable $_) { /* ignorar */ }
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
        'where' => basename($e->getFile()) . ':' . $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}