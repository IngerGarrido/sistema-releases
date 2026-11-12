<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();

    if ($user['rol'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Solo administradores pueden ver auditoría']);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST' && isset($_GET['_method'])) {
        $method = strtoupper($_GET['_method']);
    }

    // ─────────── DELETE ───────────
    if ($method === 'DELETE') {
        // Vaciar todo: ?all=1
        if (!empty($_GET['all']) && $_GET['all'] == '1') {
            $stmt = $db->query("DELETE FROM auditoria");
            echo json_encode(['ok' => true, 'data' => ['deleted' => $stmt->rowCount()]]);
            exit;
        }

        // Bulk delete: ?ids=1,2,3
        if (!empty($_GET['ids'])) {
            $ids = array_filter(array_map('intval', explode(',', $_GET['ids'])));
            if (empty($ids)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'IDs inválidos']);
                exit;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("DELETE FROM auditoria WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            echo json_encode(['ok' => true, 'data' => ['deleted' => $stmt->rowCount()]]);
            exit;
        }

        // Single delete: ?id=X
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'ID requerido']);
            exit;
        }
        $stmt = $db->prepare("DELETE FROM auditoria WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true, 'data' => ['deleted' => $stmt->rowCount()]]);
        exit;
    }

    // ─────────── GET ───────────
    // Filtros opcionales
    $accion = $_GET['accion'] ?? null;
    $tabla = $_GET['tabla'] ?? null;
    $usuario_id = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : null;
    $desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
    $hasta = $_GET['hasta'] ?? date('Y-m-d');

    // Paginación
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 25;
    $offset = ($page - 1) * $per_page;

    $where = ["a.created_at BETWEEN ? AND ?"];
    $params = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

    if ($accion) {
        $where[] = "a.accion = ?";
        $params[] = $accion;
    }
    if ($tabla) {
        $where[] = "a.tabla_afectada = ?";
        $params[] = $tabla;
    }
    if ($usuario_id) {
        $where[] = "a.usuario_id = ?";
        $params[] = $usuario_id;
    }

    $sqlWhere = implode(' AND ', $where);

    // Total para paginación
    $countStmt = $db->prepare("SELECT COUNT(*) FROM auditoria a WHERE {$sqlWhere}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Consulta principal con paginación
    $sql = "SELECT a.id, a.usuario_id, u.nombre as usuario_nombre, u.email as usuario_email,
                   a.accion, a.tabla_afectada, a.registro_id, a.detalle, a.ip_address,
                   a.created_at
            FROM auditoria a
            LEFT JOIN usuarios u ON a.usuario_id = u.id
            WHERE {$sqlWhere}
            ORDER BY a.created_at DESC
            LIMIT {$per_page} OFFSET {$offset}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Resumen por acción (sin paginación, sobre todo el rango filtrado)
    $resumen = $db->prepare("SELECT accion, COUNT(*) as total
                            FROM auditoria
                            WHERE created_at BETWEEN ? AND ?
                            GROUP BY accion");
    $resumen->execute([$desde . ' 00:00:00', $hasta . ' 23:59:59']);
    $stats = $resumen->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'data' => [
            'registros' => $data,
            'stats' => $stats,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => (int)ceil($total / $per_page)
            ],
            'filtros' => [
                'desde' => $desde,
                'hasta' => $hasta,
                'accion' => $accion,
                'tabla' => $tabla
            ]
        ]
    ]);

} catch (Throwable $e) {
    errSafe($e, 500);
}
