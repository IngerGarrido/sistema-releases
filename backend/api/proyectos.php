<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

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

    // Helper para limpiar strings
    $clean = function($str) { return trim((string)($str ?? '')); };

    // --- GET: LISTAR ---
    // Soporta paginación: ?page=X&per_page=Y
    if ($method === 'GET') {
        $where = $esPapelera ? "WHERE p.deleted_at IS NOT NULL" : "WHERE p.deleted_at IS NULL";

        // Búsqueda server-side
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $params = [];
        if ($q !== '') {
            $where .= " AND (p.nombre LIKE ? OR p.tipo_proyecto LIKE ? OR c.nombre LIKE ? OR c.nombre_agencia LIKE ?)";
            $like = '%' . escapeLike($q) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        // Filtro por estado (server-side, para que aplique a todo el dataset)
        $estado = isset($_GET['estado']) ? trim((string)$_GET['estado']) : '';
        if ($estado !== '' && $estado !== 'Todos') {
            $where .= " AND p.estado = ?";
            $params[] = $estado;
        }

        // Parámetros de paginación
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
        $per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : null;

        // Contar total (necesita JOIN si filtramos por cliente)
        $countSql = "SELECT COUNT(*) FROM proyectos p LEFT JOIN clientes c ON p.cliente_id = c.id {$where}";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT p.*, c.nombre as cliente_nombre_real, c.nombre_agencia,
                       s.nombre AS sitio_nombre, s.url_principal AS sitio_url
                FROM proyectos p
                LEFT JOIN clientes c ON p.cliente_id = c.id
                LEFT JOIN sitios s ON p.sitio_id = s.id
                {$where}
                ORDER BY p.id DESC";

        // Aplicar paginación
        if ($page && $per_page) {
            $offset = ($page - 1) * $per_page;
            $sql .= " LIMIT {$per_page} OFFSET {$offset}";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Formatear para el frontend
        foreach ($data as &$r) {
            $r['id'] = (int)$r['id'];
            $r['cliente_id'] = (int)$r['cliente_id'];
            $r['sitio_id'] = $r['sitio_id'] ? (int)$r['sitio_id'] : null;
            $r['servicio_id'] = $r['servicio_id'] ? (int)$r['servicio_id'] : null;
            $r['presupuesto'] = (int)$r['presupuesto'];
            $r['cliente_nombre'] = !empty($r['nombre_agencia']) ? $r['nombre_agencia'] : $r['cliente_nombre_real'];
        }
        unset($r);

        // Cargar TODOS los servicios asociados (pivote) en una sola query
        // para evitar N+1. Si un proyecto no tiene filas en el pivote pero sí
        // tiene servicio_id legacy, lo respaldamos.
        $ids = array_map(fn($x) => (int)$x['id'], $data);
        $svcByProy = [];
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT proyecto_id, servicio_id, cantidad FROM proyecto_servicios WHERE proyecto_id IN ($ph) ORDER BY orden ASC, id ASC");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $svcByProy[(int)$row['proyecto_id']][] = [
                    'servicio_id' => (int)$row['servicio_id'],
                    'cantidad'    => max(1, (int)$row['cantidad']),
                ];
            }
        }
        foreach ($data as &$r) {
            $lista = $svcByProy[$r['id']] ?? [];
            if (empty($lista) && !empty($r['servicio_id'])) $lista = [['servicio_id' => (int)$r['servicio_id'], 'cantidad' => 1]];
            $r['servicios'] = $lista;                                        // [{servicio_id, cantidad}]
            $r['servicios_ids'] = array_map(fn($x) => $x['servicio_id'], $lista); // compat
        }
        unset($r);

        // Resumen de dinero por proyecto, traído de Pagos (fuente única de la
        // plata). Dos consultas agrupadas por proyecto_id → sin N+1.
        //   cobrado    = suma de abonos de los pagos del proyecto
        //   por_cobrar = saldos pendientes (Pendiente + saldo de Parcial)
        $cobradoByProy = [];
        $porCobrarByProy = [];
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stC = $db->prepare("
                SELECT pg.proyecto_id, COALESCE(SUM(pa.monto), 0) AS cobrado
                FROM pagos pg
                INNER JOIN pago_cuotas pc ON pc.pago_id = pg.id
                INNER JOIN pago_abonos pa ON pa.cuota_id = pc.id
                WHERE pg.deleted_at IS NULL AND pg.proyecto_id IN ($ph)
                GROUP BY pg.proyecto_id
            ");
            $stC->execute($ids);
            foreach ($stC->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cobradoByProy[(int)$row['proyecto_id']] = (int)$row['cobrado'];
            }
            $stP = $db->prepare("
                SELECT pg.proyecto_id, COALESCE(SUM(
                    CASE WHEN pc.estado = 'Parcial'   THEN GREATEST(pc.boleta_monto - pc.pago_monto, 0)
                         WHEN pc.estado = 'Pendiente' THEN pc.boleta_monto
                         ELSE 0 END), 0) AS por_cobrar
                FROM pagos pg
                INNER JOIN pago_cuotas pc ON pc.pago_id = pg.id
                WHERE pg.deleted_at IS NULL AND pg.proyecto_id IN ($ph)
                GROUP BY pg.proyecto_id
            ");
            $stP->execute($ids);
            foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $porCobrarByProy[(int)$row['proyecto_id']] = (int)$row['por_cobrar'];
            }
        }
        foreach ($data as &$r) {
            $r['cobrado']    = $cobradoByProy[$r['id']]   ?? 0;
            $r['por_cobrar'] = $porCobrarByProy[$r['id']] ?? 0;
        }
        unset($r);

        // Responder con o sin paginación
        if ($page && $per_page) {
            echo json_encode([
                'ok' => true, 
                'data' => $data,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => $total,
                    'total_pages' => (int)ceil($total / $per_page)
                ]
            ]);
        } else {
            echo json_encode(['ok' => true, 'data' => $data]);
        }
        exit;
    }

    // --- RESTAURAR PROYECTO ---
    // DEBE IR ANTES de POST/CREAR para que no se confunda
    if ($method === 'POST' && $esRestore && $id) {
        $stmt = $db->prepare("UPDATE proyectos SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            audit($db, $user['id'], 'RESTORE', 'proyectos', "Restauró proyecto #{$id}", $id);
            echo json_encode(['ok' => true, 'message' => 'Proyecto restaurado']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Proyecto no encontrado o no estaba eliminado']);
        }
        exit;
    }

    // Helper: sincroniza la tabla pivote con la lista de servicios elegida.
    // Borra los existentes y vuelve a insertarlos en el orden recibido.
    // También actualiza proyectos.servicio_id legacy con el primero.
    // Acepta $servicios como [{servicio_id, cantidad}] (nuevo) o [id, ...] (compat).
    $syncServicios = function(int $proyecto_id, $servicios) use ($db) {
        $db->prepare("DELETE FROM proyecto_servicios WHERE proyecto_id = ?")->execute([$proyecto_id]);
        $norm = []; // servicio_id => cantidad (sin duplicados)
        foreach ((array)$servicios as $s) {
            if (is_array($s)) {
                $sid  = (int)($s['servicio_id'] ?? 0);
                $cant = max(1, (int)($s['cantidad'] ?? 1));
            } else {
                $sid = (int)$s; $cant = 1;
            }
            if ($sid > 0 && !isset($norm[$sid])) $norm[$sid] = $cant;
        }
        if (!empty($norm)) {
            $ins = $db->prepare("INSERT INTO proyecto_servicios (proyecto_id, servicio_id, cantidad, orden) VALUES (?, ?, ?, ?)");
            $orden = 0;
            foreach ($norm as $sid => $cant) $ins->execute([$proyecto_id, $sid, $cant, $orden++]);
        }
        $primero = !empty($norm) ? array_key_first($norm) : null;
        $db->prepare("UPDATE proyectos SET servicio_id = ? WHERE id = ?")->execute([$primero, $proyecto_id]);
    };

    // --- POST: CREAR ---
    if ($method === 'POST') {
        $b = body();
        // Servicios: preferimos la forma nueva con cantidad; caemos a la lista
        // de ids (compat) o al servicio_id legacy. El campo legacy queda con el 1º.
        $serviciosData = $b['servicios'] ?? $b['servicios_ids'] ?? (!empty($b['servicio_id']) ? [(int)$b['servicio_id']] : []);
        $primero = null;
        foreach ((array)$serviciosData as $s) {
            $sid = is_array($s) ? (int)($s['servicio_id'] ?? 0) : (int)$s;
            if ($sid > 0) { $primero = $sid; break; }
        }

        $sql = "INSERT INTO proyectos (cliente_id, sitio_id, servicio_id, nombre, descripcion, tipo_proyecto, estado, fecha_inicio, fecha_termino, presupuesto, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $db->prepare($sql)->execute([
            (int)$b['cliente_id'],
            !empty($b['sitio_id']) ? (int)$b['sitio_id'] : null,
            $primero,
            $clean($b['nombre']),
            $clean($b['descripcion']),
            $clean($b['tipo_proyecto']),
            $clean($b['estado'] ?? 'Cotizado'),
            !empty($b['fecha_inicio']) ? $b['fecha_inicio'] : null,
            !empty($b['fecha_termino']) ? $b['fecha_termino'] : null,
            (int)($b['presupuesto'] ?? 0)
        ]);
        $proyecto_id = (int)$db->lastInsertId();

        $syncServicios($proyecto_id, $serviciosData);

        // Cobro pendiente automático: si el proyecto tiene estimado y se pidió
        // generar el cobro, creamos un pago PENDIENTE vinculado (monto a cobrar
        // = estimado, 1 cuota, sin abonos). Así el "por cobrar" aparece solo,
        // sin tener que crear un pago vacío a mano; al cobrar se registra el
        // abono en ese pago. tipo por defecto 'boleta' (editable al cobrar).
        $estimado = (int)($b['presupuesto'] ?? 0);
        $generarCobro = !isset($b['generar_cobro']) || !empty($b['generar_cobro']);
        if ($generarCobro && $estimado > 0) {
            $tipoCobro = ($b['tipo_cobro'] ?? 'boleta') === 'directo' ? 'directo' : 'boleta';
            $db->prepare("INSERT INTO pagos (cliente_id, proyecto_id, descripcion, monto_total, tipo_pago)
                          VALUES (?, ?, ?, ?, ?)")
               ->execute([(int)$b['cliente_id'], $proyecto_id, $clean($b['nombre']), $estimado, $tipoCobro]);
            $pago_id = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO pago_cuotas (pago_id, numero_cuota, boleta_monto, pago_monto, estado, tipo_pago)
                          VALUES (?, 1, ?, 0, 'Pendiente', ?)")
               ->execute([$pago_id, $estimado, $tipoCobro]);
        }

        audit($db, $user['id'], 'CREATE', 'proyectos', "Creó proyecto #{$proyecto_id}: {$b['nombre']}", $proyecto_id);

        echo json_encode(['ok' => true, 'id' => $proyecto_id]);
        exit;
    }

    // --- EDITAR PROYECTO (PUT) ---
        if ($method === 'PUT' && $id) {
            $b = body();
            $serviciosData = $b['servicios'] ?? $b['servicios_ids'] ?? (!empty($b['servicio_id']) ? [(int)$b['servicio_id']] : []);
            $primero = null;
            foreach ((array)$serviciosData as $s) {
                $sid = is_array($s) ? (int)($s['servicio_id'] ?? 0) : (int)$s;
                if ($sid > 0) { $primero = $sid; break; }
            }

            $sql = "UPDATE proyectos SET
                    cliente_id = ?,
                    sitio_id = ?,
                    servicio_id = ?,
                    nombre = ?,
                    descripcion = ?,
                    tipo_proyecto = ?,
                    estado = ?,
                    fecha_inicio = ?,
                    fecha_termino = ?,
                    presupuesto = ?,
                    updated_at = NOW()
                    WHERE id = ? AND deleted_at IS NULL";

            $stmt = $db->prepare($sql);

            $stmt->execute([
                (int)$b['cliente_id'],
                !empty($b['sitio_id']) ? (int)$b['sitio_id'] : null,
                $primero,
                $clean($b['nombre']),
                $clean($b['descripcion']),
                $clean($b['tipo_proyecto']),
                $clean($b['estado']),
                !empty($b['fecha_inicio']) ? $b['fecha_inicio'] : null,
                !empty($b['fecha_termino']) ? $b['fecha_termino'] : null,
                (int)($b['presupuesto'] ?? 0),
                (int)$id // El ID del proyecto para el WHERE
            ]);

            $syncServicios((int)$id, $serviciosData);

            audit($db, $user['id'], 'UPDATE', 'proyectos', "Editó proyecto #{$id}: {$b['nombre']}", $id);

            echo json_encode(['ok' => true]);
            exit;
        }

    // --- DELETE ---
    if ($method === 'DELETE' && $id) {
        $info = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
        $info->execute([$id]);
        $proy = $info->fetch();
        
        if (!$proy) {
            echo json_encode(['ok' => false, 'error' => 'Proyecto no encontrado']);
            exit;
        }
        
        if ($esHard) {
            $db->prepare("DELETE FROM proyectos WHERE id = ?")->execute([$id]);
            audit($db, $user['id'], 'HARD_DELETE', 'proyectos', "Eliminó PERMANENTEMENTE proyecto #{$id}: " . $proy['nombre'], $id);
            echo json_encode(['ok' => true, 'message' => 'Proyecto eliminado permanentemente']);
        } else {
            $db->prepare("UPDATE proyectos SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
            audit($db, $user['id'], 'SOFT_DELETE', 'proyectos', "Envió a papelera proyecto #{$id}: " . $proy['nombre'], $id);
            echo json_encode(['ok' => true, 'message' => 'Proyecto enviado a papelera']);
        }
        exit;
    }

} catch (Throwable $e) {
    if (function_exists('logError')) {
        logError('Error en proyectos.php', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    }
    errSafe($e, 500);
}