<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'POST' && isset($_GET['_method'])) {
        $method = strtoupper($_GET['_method']);
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if (!$id) {
        $path = explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $last = end($path);
        $id = is_numeric($last) ? (int)$last : null;
    }

    $uri        = $_SERVER['REQUEST_URI'] ?? '';
    $esRestore  = strpos($uri, 'restore') !== false || isset($_GET['restore']);
    $esHard     = isset($_GET['hard']) && $_GET['hard'] == '1';
    $esPapelera = isset($_GET['papelera']) && $_GET['papelera'] == '1';

    // --- GET ---
    if ($method === 'GET') {
        if ($id) {
            $stmt = $db->prepare("SELECT s.*, c.nombre AS cliente_nombre, c.nombre_agencia
                                  FROM sitios s
                                  JOIN clientes c ON c.id = s.cliente_id
                                  WHERE s.id = ? AND s.deleted_at IS NULL");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) err('Sitio no encontrado', 404);
            $row['id'] = (int)$row['id'];
            $row['cliente_id'] = (int)$row['cliente_id'];
            ok($row);
        }

        $where = $esPapelera ? "s.deleted_at IS NOT NULL" : "s.deleted_at IS NULL";
        $params = [];

        $clienteId = isset($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : 0;
        if ($clienteId) { $where .= " AND s.cliente_id = ?"; $params[] = $clienteId; }

        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        if ($q !== '') {
            $where .= " AND (s.nombre LIKE ? OR s.url_principal LIKE ? OR c.nombre LIKE ? OR c.nombre_agencia LIKE ?)";
            $like = '%' . escapeLike($q) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $page     = isset($_GET['page'])     ? max(1, (int)$_GET['page']) : null;
        $per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : null;

        $countSql = "SELECT COUNT(*) FROM sitios s JOIN clientes c ON c.id = s.cliente_id WHERE $where";
        $cs = $db->prepare($countSql);
        $cs->execute($params);
        $total = (int)$cs->fetchColumn();

        $sql = "SELECT s.id, s.cliente_id, s.nombre, s.descripcion, s.url_principal,
                       s.created_at, s.updated_at, s.deleted_at,
                       c.nombre AS cliente_nombre, c.nombre_agencia,
                       (SELECT COUNT(*) FROM proyectos p WHERE p.sitio_id = s.id AND p.deleted_at IS NULL) AS proyectos_count,
                       (SELECT COUNT(*) FROM accesos a WHERE a.sitio_id = s.id) AS accesos_count
                FROM sitios s
                JOIN clientes c ON c.id = s.cliente_id
                WHERE $where
                ORDER BY s.id DESC";

        if ($page && $per_page) {
            $offset = ($page - 1) * $per_page;
            $sql .= " LIMIT {$per_page} OFFSET {$offset}";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        foreach ($data as &$r) {
            $r['id']              = (int)$r['id'];
            $r['cliente_id']      = (int)$r['cliente_id'];
            $r['proyectos_count'] = (int)$r['proyectos_count'];
            $r['accesos_count']   = (int)$r['accesos_count'];
            $r['cliente_display'] = !empty($r['nombre_agencia']) ? $r['nombre_agencia'] : $r['cliente_nombre'];
        }

        if ($page && $per_page) {
            echo json_encode([
                'ok' => true,
                'data' => $data,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => $total,
                    'total_pages' => (int)ceil($total / $per_page),
                ]
            ]);
        } else {
            echo json_encode(['ok' => true, 'data' => $data]);
        }
        exit;
    }

    // --- RESTAURAR (debe ir ANTES de POST/CREAR) ---
    if ($method === 'POST' && $esRestore && $id) {
        $stmt = $db->prepare("UPDATE sitios SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            audit($db, $user['id'], 'RESTORE', 'sitios', "Restauró sitio #{$id}", $id);
            echo json_encode(['ok' => true, 'message' => 'Sitio restaurado']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Sitio no encontrado o no estaba eliminado']);
        }
        exit;
    }

    // --- POST: crear ---
    if ($method === 'POST') {
        $b = body();
        $clienteId = (int)($b['cliente_id'] ?? 0);
        $nombre = trim((string)($b['nombre'] ?? ''));
        if (!$clienteId || $nombre === '') err('cliente_id y nombre son requeridos', 400);

        $chk = $db->prepare("SELECT id FROM clientes WHERE id = ? AND deleted_at IS NULL");
        $chk->execute([$clienteId]);
        if (!$chk->fetch()) err('Cliente no existe', 404);

        $sql = "INSERT INTO sitios (cliente_id, nombre, descripcion, url_principal, created_at)
                VALUES (?, ?, ?, ?, NOW())";
        $db->prepare($sql)->execute([
            $clienteId, $nombre,
            trim((string)($b['descripcion'] ?? '')) ?: null,
            trim((string)($b['url_principal'] ?? '')) ?: null,
        ]);
        $newId = (int)$db->lastInsertId();
        audit($db, $user['id'], 'CREATE', 'sitios', "Sitio '{$nombre}' (cliente #{$clienteId})", $newId);
        ok(['id' => $newId]);
    }

    // --- PUT: editar ---
    if ($method === 'PUT' && $id) {
        $b = body();
        $cur = $db->prepare("SELECT * FROM sitios WHERE id = ? AND deleted_at IS NULL");
        $cur->execute([$id]);
        $row = $cur->fetch();
        if (!$row) err('Sitio no encontrado', 404);

        $nombre = trim((string)($b['nombre'] ?? $row['nombre']));
        if ($nombre === '') err('Nombre requerido', 400);

        $sql = "UPDATE sitios SET nombre = ?, descripcion = ?, url_principal = ?, updated_at = NOW()
                WHERE id = ? AND deleted_at IS NULL";
        $db->prepare($sql)->execute([
            $nombre,
            trim((string)($b['descripcion'] ?? $row['descripcion'] ?? '')) ?: null,
            trim((string)($b['url_principal'] ?? $row['url_principal'] ?? '')) ?: null,
            $id,
        ]);
        audit($db, $user['id'], 'UPDATE', 'sitios', "Editó sitio #{$id} ({$nombre})", $id);
        ok(['ok' => true]);
    }

    // --- DELETE: soft o hard ---
    if ($method === 'DELETE' && $id) {
        $info = $db->prepare("SELECT nombre FROM sitios WHERE id = ?");
        $info->execute([$id]);
        $sit = $info->fetch();
        if (!$sit) {
            echo json_encode(['ok' => false, 'error' => 'Sitio no encontrado']);
            exit;
        }

        if ($esHard) {
            // Hard delete: arrastra accesos por FK CASCADE. Desvincula proyectos.
            $db->prepare("UPDATE proyectos SET sitio_id = NULL WHERE sitio_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM sitios WHERE id = ?")->execute([$id]);
            audit($db, $user['id'], 'HARD_DELETE', 'sitios', "Eliminó PERMANENTEMENTE sitio #{$id}: " . $sit['nombre'], $id);
            echo json_encode(['ok' => true, 'message' => 'Sitio eliminado permanentemente']);
        } else {
            $db->prepare("UPDATE sitios SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
            audit($db, $user['id'], 'SOFT_DELETE', 'sitios', "Envió a papelera sitio #{$id}: " . $sit['nombre'], $id);
            echo json_encode(['ok' => true, 'message' => 'Sitio enviado a papelera']);
        }
        exit;
    }

    err('Método no permitido', 405);

} catch (Throwable $e) {
    errSafe($e, 500, 'sitios');
}
