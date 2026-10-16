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

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $db->exec("SET NAMES utf8mb4");

    if ($user['rol'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $id_url = $_GET['id'] ?? null;
    if (!$id_url) {
        $path = explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $last = end($path);
        $id_url = is_numeric($last) ? (int)$last : null;
    }

    // Detectar acción especial
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $esRestore = strpos($uri, 'restore') !== false || isset($_GET['restore']);
    $esHard = isset($_GET['hard']) && $_GET['hard'] == '1';
    $esPapelera = isset($_GET['papelera']) && $_GET['papelera'] == '1';

    $limpiar = function($str) { return trim(str_replace("\0", "", (string)($str ?? ''))); };
    
    $cleanInt = function($valor) {
        if (is_null($valor) || $valor === '') return 0;
        $v = str_replace(['.', ',', '$', ' '], '', (string)$valor);
        return (int)round((float)$v);
    };

    // Normaliza los abonos[] de una cuota y deriva sus totales/estado.
    // Cada abono = { monto, fecha }. La cuota es la "boleta"; los abonos son los
    // cobros (con fecha propia) dentro de ella. pago_monto/pago_fecha/estado se
    // DERIVAN de los abonos (fuente única de verdad):
    //   - suma de abonos = pago_monto (caché)
    //   - fecha del último abono = pago_fecha
    //   - estado: 0 → Pendiente; 0<suma<boleta → Parcial; suma>=boleta → Pagado
    // Compatibilidad: si no llega abonos[] pero hay pago_monto (cliente viejo),
    // se construye un abono desde pago_monto/pago_fecha.
    $procesarAbonos = function($cuota) use ($cleanInt) {
        $boletaMonto = $cleanInt($cuota['boleta_monto'] ?? 0);
        $raw = $cuota['abonos'] ?? null;
        $abonos = [];
        if (is_array($raw)) {
            foreach ($raw as $a) {
                $monto = $cleanInt($a['monto'] ?? 0);
                $fecha = !empty($a['fecha']) ? substr((string)$a['fecha'], 0, 10) : null;
                if ($monto > 0 && $fecha) $abonos[] = ['monto' => $monto, 'fecha' => $fecha];
            }
        } else {
            $pm = $cleanInt($cuota['pago_monto'] ?? 0);
            if ($pm > 0) {
                $fecha = !empty($cuota['pago_fecha']) ? substr((string)$cuota['pago_fecha'], 0, 10) : date('Y-m-d');
                $abonos[] = ['monto' => $pm, 'fecha' => $fecha];
            }
        }
        $suma = 0; $maxFecha = null;
        foreach ($abonos as $a) {
            $suma += $a['monto'];
            if ($maxFecha === null || $a['fecha'] > $maxFecha) $maxFecha = $a['fecha'];
        }
        // Con un monto acordado (boleta_monto) aplica a boleta Y a directo:
        // si cobró menos del total → Parcial. (En directo, boleta_monto es el
        // "monto a cobrar" acordado; no genera retención pero sí saldo/pendiente.)
        if ($suma <= 0)                                   $estado = 'Pendiente';
        elseif ($boletaMonto > 0 && $suma < $boletaMonto) $estado = 'Parcial';
        else                                              $estado = 'Pagado';
        return ['abonos' => $abonos, 'suma' => $suma, 'maxFecha' => $maxFecha, 'estado' => $estado];
    };

    // --- LISTAR (GET) ---
    // Soporta paginación: ?page=X&per_page=Y
    if ($method === 'GET') {
        $where = $esPapelera ? "WHERE p.deleted_at IS NOT NULL" : "WHERE p.deleted_at IS NULL";

        // Búsqueda server-side
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $params = [];
        if ($q !== '') {
            $where .= " AND (p.descripcion LIKE ? OR c.nombre LIKE ? OR pr.nombre LIKE ?)";
            $like = '%' . escapeLike($q) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        // Filtro por estado de cobro (server-side). "estado" derivado:
        // comparamos monto_total contra lo efectivamente pagado en cuotas.
        $estado = isset($_GET['estado']) ? trim((string)$_GET['estado']) : '';
        // Cobrado real = suma de pago_monto en cuotas Pagado o Parcial (lo recibido)
        $cobradoExpr = "COALESCE((SELECT SUM(pc.pago_monto) FROM pago_cuotas pc WHERE pc.pago_id = p.id AND pc.estado IN ('Pagado','Parcial')), 0)";
        // Hay al menos una cuota Parcial pendiente de cobrar
        $hayParcialExpr = "EXISTS(SELECT 1 FROM pago_cuotas pc WHERE pc.pago_id = p.id AND pc.estado = 'Parcial')";
        if ($estado === 'Pagado') {
            $where .= " AND p.monto_total <= {$cobradoExpr}";
        } elseif ($estado === 'Pendiente') {
            // Pendiente "puro": falta cobrar y no hay nada parcial
            $where .= " AND p.monto_total > {$cobradoExpr} AND NOT {$hayParcialExpr}";
        } elseif ($estado === 'Parcial') {
            // Parcial: tiene al menos una cuota Parcial y aún falta cobrar
            $where .= " AND p.monto_total > {$cobradoExpr} AND {$hayParcialExpr}";
        }

        // Parámetros de paginación (con cap 100)
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
        $per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : null;

        // Contar total (con mismos JOINs por filtro server-side)
        $countSql = "SELECT COUNT(*) FROM pagos p INNER JOIN clientes c ON p.cliente_id = c.id LEFT JOIN proyectos pr ON p.proyecto_id = pr.id {$where}";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT p.*,
                       COALESCE(NULLIF(c.nombre_agencia, ''), c.nombre) as cliente_nombre,
                       pr.nombre as proyecto_nombre,
                       s.nombre as sitio_nombre
                FROM pagos p
                INNER JOIN clientes c ON p.cliente_id = c.id
                LEFT JOIN proyectos pr ON p.proyecto_id = pr.id
                LEFT JOIN sitios s ON pr.sitio_id = s.id
                {$where}
                ORDER BY p.id DESC";

        // Aplicar paginación
        if ($page && $per_page) {
            $offset = ($page - 1) * $per_page;
            $sql .= " LIMIT {$per_page} OFFSET {$offset}";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // OPTIMIZACIÓN N+1: cargar TODAS las cuotas en una sola query
        $cuotasPorPago = [];
        $cuotaIds = [];
        if (count($pagos) > 0) {
            $pagoIds = array_map(fn($p) => (int)$p['id'], $pagos);
            $placeholders = implode(',', array_fill(0, count($pagoIds), '?'));
            $sqlC = "SELECT * FROM pago_cuotas WHERE pago_id IN ($placeholders) ORDER BY pago_id ASC, numero_cuota ASC";
            $stmtC = $db->prepare($sqlC);
            $stmtC->execute($pagoIds);
            while ($c = $stmtC->fetch(PDO::FETCH_ASSOC)) {
                $cuotasPorPago[(int)$c['pago_id']][] = $c;
                $cuotaIds[] = (int)$c['id'];
            }
        }

        // Cargar TODOS los abonos de esas cuotas en una sola query (N+1 evitado)
        $abonosPorCuota = [];
        if (count($cuotaIds) > 0) {
            $ph = implode(',', array_fill(0, count($cuotaIds), '?'));
            $sqlA = "SELECT id, cuota_id, monto, fecha FROM pago_abonos WHERE cuota_id IN ($ph) ORDER BY fecha ASC, id ASC";
            $stmtAb = $db->prepare($sqlA);
            $stmtAb->execute($cuotaIds);
            while ($a = $stmtAb->fetch(PDO::FETCH_ASSOC)) {
                $abonosPorCuota[(int)$a['cuota_id']][] = [
                    'id'    => (int)$a['id'],
                    'monto' => (int)$a['monto'],
                    'fecha' => $a['fecha'],
                ];
            }
        }

        $res = [];
        foreach ($pagos as $p) {
            $cuotas = $cuotasPorPago[(int)$p['id']] ?? [];
            foreach ($cuotas as &$cRef) {
                $cRef['abonos'] = $abonosPorCuota[(int)$cRef['id']] ?? [];
            }
            unset($cRef);
            $suma_pagada = 0;
            foreach ($cuotas as $c) {
                // Suma incluye Pagado y Parcial (en Parcial cobramos menos del total)
                if ($c['estado'] === 'Pagado' || $c['estado'] === 'Parcial') {
                    $suma_pagada += (int)($c['pago_monto'] ?? 0);
                }
            }
            $p['total_pagado'] = $suma_pagada;
            $p['cuotas'] = $cuotas;
            $res[] = $p;
        }
        
        // Responder con o sin paginación
        if ($page && $per_page) {
            echo json_encode([
                'ok' => true, 
                'data' => $res,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => $total,
                    'total_pages' => (int)ceil($total / $per_page)
                ]
            ]);
        } else {
            echo json_encode(['ok' => true, 'data' => $res]);
        }
        exit;
    }

    // --- RESTAURAR PAGO ---
    // DEBE IR ANTES de POST/CREAR para que no se confunda
    if ($method === 'POST' && $esRestore && $id_url) {
        $id = (int)$id_url;
        $stmt = $db->prepare("UPDATE pagos SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            audit($db, $user['id'], 'RESTORE', 'pagos', "Restauró pago #{$id}", $id);
            echo json_encode(['ok' => true, 'message' => 'Pago restaurado']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Pago no encontrado o no estaba eliminado']);
        }
        exit;
    }

    // --- CREAR (POST) ---
    if ($method === 'POST') {
        $b = json_decode(file_get_contents("php://input"), true);
        if (!$b) throw new Exception("No se recibieron datos");

        // Validación mínima: cliente_id requerido y numérico
        if (empty($b['cliente_id']) || !is_numeric($b['cliente_id'])) {
            err('El campo cliente_id es requerido y debe ser numérico.');
        }

        $db->beginTransaction();

        // 1. Insertar en tabla maestra 'pagos'
        $sql = "INSERT INTO pagos (cliente_id, proyecto_id, descripcion, monto_total, tipo_pago) VALUES (?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            (int)$b['cliente_id'],
            $b['proyecto_id'] ?: null,
            $limpiar($b['descripcion'] ?? ''),
            $cleanInt($b['monto_total'] ?? 0),
            $b['tipo_pago'] ?? 'boleta'
        ]);
        $pago_id = $db->lastInsertId();

        // 2. Insertar cuotas en 'pago_cuotas' + sus abonos
        if (isset($b['cuotas']) && is_array($b['cuotas'])) {
            $sqlCuota = "INSERT INTO pago_cuotas (
                            pago_id, numero_cuota, boleta_num, boleta_monto,
                            boleta_fecha, pago_monto, pago_fecha, estado, tipo_pago
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtC = $db->prepare($sqlCuota);
            $stmtA = $db->prepare("INSERT INTO pago_abonos (cuota_id, monto, fecha) VALUES (?, ?, ?)");
            foreach ($b['cuotas'] as $index => $cuota) {
                $r = $procesarAbonos($cuota); // deriva suma/estado/fecha desde abonos
                $stmtC->execute([
                    $pago_id,
                    $index + 1,
                    $limpiar($cuota['boleta_num'] ?? ''),
                    $cleanInt($cuota['boleta_monto'] ?? 0),
                    !empty($cuota['boleta_fecha']) ? $cuota['boleta_fecha'] : null,
                    $r['suma'],
                    $r['maxFecha'],
                    $r['estado'],
                    $cuota['tipo_pago'] ?? 'boleta'
                ]);
                $cuotaId = (int)$db->lastInsertId();
                foreach ($r['abonos'] as $a) {
                    $stmtA->execute([$cuotaId, $a['monto'], $a['fecha']]);
                }
            }
        }
        $db->commit();
        
        // Auditoría
        $montoFmt = number_format($cleanInt($b['monto_total'] ?? 0), 0, ',', '.');
        audit($db, $user['id'], 'CREATE', 'pagos', "Creó pago #{$pago_id} - Cliente: {$b['cliente_id']} - Total: $ {$montoFmt}", $pago_id);
        
        echo json_encode(['ok' => true, 'id' => $pago_id]);
        exit;
    }

    // --- EDITAR (PUT) ---
    if ($method === 'PUT') {
        $b = json_decode(file_get_contents("php://input"), true);
        $target_id = $id_url ?? $b['id'] ?? null;

        if (!$target_id) throw new Exception("ID no proporcionado");
        if (empty($b['cliente_id']) || !is_numeric($b['cliente_id'])) {
            err('El campo cliente_id es requerido y debe ser numérico.');
        }

        $db->beginTransaction();

        // 1. Actualizar Pago Maestro
        $sql = "UPDATE pagos SET cliente_id = ?, proyecto_id = ?, descripcion = ?, monto_total = ?, tipo_pago = ? WHERE id = ? AND deleted_at IS NULL";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            (int)$b['cliente_id'],
            $b['proyecto_id'] ?: null,
            $limpiar($b['descripcion'] ?? ''),
            $cleanInt($b['monto_total'] ?? 0),
            $b['tipo_pago'] ?? 'boleta',
            (int)$target_id
        ]);

        // 2. Manejar Cuotas (+ abonos). Estrategia para abonos: por cada cuota
        // se borran y reinsertan sus abonos desde el payload (siempre llegan
        // completos), evitando rastrear ids de abono individuales.
        if (isset($b['cuotas']) && is_array($b['cuotas'])) {
            $ids_enviados = [];
            $stmtA = $db->prepare("INSERT INTO pago_abonos (cuota_id, monto, fecha) VALUES (?, ?, ?)");
            $stmtDelA = $db->prepare("DELETE FROM pago_abonos WHERE cuota_id = ?");

            foreach ($b['cuotas'] as $index => $c) {
                $r = $procesarAbonos($c); // deriva suma/estado/fecha desde abonos
                $boletaFecha = !empty($c['boleta_fecha']) ? $c['boleta_fecha'] : null;
                if (isset($c['id']) && is_numeric($c['id'])) {
                    // ACTUALIZAR EXISTENTE
                    $cuotaId = (int)$c['id'];
                    $ids_enviados[] = $cuotaId;
                    $sqlU = "UPDATE pago_cuotas SET
                                boleta_num = ?, boleta_fecha = ?, boleta_monto = ?,
                                pago_fecha = ?, pago_monto = ?, estado = ?, numero_cuota = ?, tipo_pago = ?
                             WHERE id = ?";
                    $stmtU = $db->prepare($sqlU);
                    $stmtU->execute([
                        $limpiar($c['boleta_num'] ?? ''),
                        $boletaFecha,
                        $cleanInt($c['boleta_monto'] ?? 0),
                        $r['maxFecha'],
                        $r['suma'],
                        $r['estado'],
                        $index + 1,
                        $c['tipo_pago'] ?? 'boleta',
                        $cuotaId
                    ]);
                } else {
                    // INSERTAR NUEVA
                    $sqlI = "INSERT INTO pago_cuotas (
                                pago_id, numero_cuota, boleta_num, boleta_monto,
                                boleta_fecha, pago_fecha, pago_monto, estado, tipo_pago
                             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmtI = $db->prepare($sqlI);
                    $stmtI->execute([
                        (int)$target_id,
                        $index + 1,
                        $limpiar($c['boleta_num'] ?? ''),
                        $cleanInt($c['boleta_monto'] ?? 0),
                        $boletaFecha,
                        $r['maxFecha'],
                        $r['suma'],
                        $r['estado'],
                        $c['tipo_pago'] ?? 'boleta'
                    ]);
                    $cuotaId = (int)$db->lastInsertId();
                    $ids_enviados[] = $cuotaId;
                }
                // Reescribir abonos de esta cuota
                $stmtDelA->execute([$cuotaId]);
                foreach ($r['abonos'] as $a) {
                    $stmtA->execute([$cuotaId, $a['monto'], $a['fecha']]);
                }
            }

            // 3. ELIMINAR LAS CUOTAS QUE NO VINIERON
            if (count($ids_enviados) > 0) {
                $placeholders = implode(',', array_fill(0, count($ids_enviados), '?'));
                $sqlD = "DELETE FROM pago_cuotas WHERE pago_id = ? AND id NOT IN ($placeholders)";
                $paramsD = array_merge([(int)$target_id], $ids_enviados);
                $db->prepare($sqlD)->execute($paramsD);
            } else {
                $db->prepare("DELETE FROM pago_cuotas WHERE pago_id = ?")->execute([(int)$target_id]);
            }
        }
        
        $db->commit();
        
        // Auditoría
        audit($db, $user['id'], 'UPDATE', 'pagos', "Editó pago #{$target_id}", $target_id);
        
        echo json_encode(['ok' => true]);
        exit;
    }

// --- ELIMINAR (DELETE) ---
    if ($method === 'DELETE') {
        try {
            $target_id = $id_url;
            if (!$target_id && isset($_SERVER['PATH_INFO'])) {
                $target_id = trim($_SERVER['PATH_INFO'], '/');
            }
            if (!$target_id) {
                throw new Exception("ID de pago no proporcionado");
            }

            $id = (int)$target_id;
            
            // Verificar que existe
            $info = $db->prepare("SELECT descripcion FROM pagos WHERE id = ?");
            $info->execute([$id]);
            $pago = $info->fetch();
            
            if (!$pago) {
                throw new Exception("Pago no encontrado");
            }
            
            if ($esHard) {
                // HARD DELETE
                $db->beginTransaction();
                $db->prepare("DELETE FROM pago_cuotas WHERE pago_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM pagos WHERE id = ?")->execute([$id]);
                $db->commit();
                
                audit($db, $user['id'], 'HARD_DELETE', 'pagos', "Eliminó PERMANENTEMENTE pago #{$id}", $id);
                echo json_encode(['ok' => true, 'message' => 'Pago eliminado permanentemente']);
            } else {
                // SOFT DELETE
                $db->prepare("UPDATE pagos SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
                
                audit($db, $user['id'], 'SOFT_DELETE', 'pagos', "Envió a papelera pago #{$id}: " . ($pago['descripcion'] ?? ''), $id);
                echo json_encode(['ok' => true, 'message' => 'Pago enviado a papelera']);
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            errSafe($e, 500);
        }
        exit;
    }

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if (function_exists('logError')) {
        logError('Error en pagos.php', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    }
    errSafe($e, 500);
}