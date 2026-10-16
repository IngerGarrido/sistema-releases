<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Desactivar buffering para evitar truncamiento
if (ob_get_level()) ob_end_clean();

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

    // --- GET: LISTAR ---
    // Soporta paginación: ?page=X&per_page=Y
    if ($method === 'GET') {
        $where = $esPapelera ? "WHERE deleted_at IS NOT NULL" : "WHERE deleted_at IS NULL";

        // Búsqueda server-side (corrige problema de filtrado solo en página actual)
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $params = [];
        if ($q !== '') {
            $where .= " AND (nombre LIKE ? OR descripcion LIKE ?)";
            $like = '%' . escapeLike($q) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // Parámetros de paginación
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
        $per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : null;

        // Contar total
        $countSql = "SELECT COUNT(*) FROM servicios {$where}";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT * FROM servicios {$where} ORDER BY id DESC";

        // Aplicar paginación
        if ($page && $per_page) {
            $offset = ($page - 1) * $per_page;
            $sql .= " LIMIT {$per_page} OFFSET {$offset}";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Responder con o sin paginación
        if ($page && $per_page) {
            $response = [
                'ok' => true, 
                'data' => $rows,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => $total,
                    'total_pages' => (int)ceil($total / $per_page)
                ]
            ];
            $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                echo json_encode(['ok' => false, 'error' => 'Error al codificar JSON: ' . json_last_error_msg()]);
            } else {
                echo $json;
                exit;
            }
        } else {
            ok($rows);
        }
    }

    // --- POST: CREAR ---
    if ($method === 'POST' && !$esRestore) {
        $b = body();
        if (empty(trim($b['nombre'] ?? ''))) err('El nombre es requerido.');

        $sql = "INSERT INTO servicios (nombre, descripcion, precio, activo) VALUES (?,?,?,?)";
        $db->prepare($sql)->execute([
            trim($b['nombre']),
            trim($b['descripcion'] ?? ''),
            (float)($b['precio'] ?? 0),
            (int)($b['activo'] ?? 1)
        ]);

        $servicio_id = (int)$db->lastInsertId();
        audit($db, $user['id'], 'CREATE', 'servicios', "Creó servicio #{$servicio_id}: {$b['nombre']}", $servicio_id);

        ok(['id' => $servicio_id], 201);
    }

    // --- PUT: EDITAR ---
    if ($method === 'PUT' && $id) {
        $b = body();
        if (empty(trim($b['nombre'] ?? ''))) err('El nombre es requerido.');

        $sql = "UPDATE servicios SET nombre=?, descripcion=?, precio=?, activo=? WHERE id=? AND deleted_at IS NULL";
        $db->prepare($sql)->execute([
            trim($b['nombre']),
            trim($b['descripcion'] ?? ''),
            (float)($b['precio'] ?? 0),
            (int)($b['activo'] ?? 1),
            $id
        ]);

        audit($db, $user['id'], 'UPDATE', 'servicios', "Editó servicio #{$id}: {$b['nombre']}", $id);

        ok(['updated' => true]);
    }

    // --- RESTAURAR ---
    if ($method === 'POST' && $esRestore && $id) {
        $stmt = $db->prepare("UPDATE servicios SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            audit($db, $user['id'], 'RESTORE', 'servicios', "Restauró servicio #{$id}", $id);
            ok(['restored' => true, 'message' => 'Servicio restaurado']);
        } else {
            err('Servicio no encontrado o no estaba eliminado', 404);
        }
    }

    // --- DELETE: ELIMINAR ---
    if ($method === 'DELETE' && $id) {
        // Obtener info antes de eliminar
        $info = $db->prepare("SELECT nombre FROM servicios WHERE id = ?");
        $info->execute([$id]);
        $svc = $info->fetch();

        if (!$svc) {
            err('Servicio no encontrado', 404);
        }

        if ($esHard) {
            $db->prepare("DELETE FROM servicios WHERE id=?")->execute([$id]);
            audit($db, $user['id'], 'HARD_DELETE', 'servicios', "Eliminó PERMANENTEMENTE servicio #{$id}: " . $svc['nombre'], $id);
            ok(['deleted' => true, 'message' => 'Servicio eliminado permanentemente']);
        } else {
            $db->prepare("UPDATE servicios SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
            audit($db, $user['id'], 'SOFT_DELETE', 'servicios', "Envió a papelera servicio #{$id}: " . $svc['nombre'], $id);
            ok(['deleted' => true, 'message' => 'Servicio enviado a papelera']);
        }
    }

    err('Método no permitido.', 405);

} catch (Throwable $e) {
    if (function_exists('logError')) {
        logError('Error en servicios.php', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Error en servicios: ' . $e->getMessage()
    ]);
}