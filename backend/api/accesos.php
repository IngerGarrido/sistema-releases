<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../crypto.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];

    // Sólo admin u operador pueden gestionar accesos (no clientes externos).
    if (!in_array($user['rol'], ['admin','operador'], true)) {
        err('No tienes permiso para ver accesos', 403);
    }

    if ($method === 'POST' && isset($_GET['_method'])) {
        $method = strtoupper($_GET['_method']);
    }

    $TIPOS = ['wp','ftp','hosting','bd','email','dominio','panel','otro'];

    // --- GET: lista por sitio (?sitio_id=X) o reveal (?sitio_id=X&id=Y&reveal=1) ---
    if ($method === 'GET') {
        $sitioId = isset($_GET['sitio_id']) ? (int)$_GET['sitio_id'] : 0;
        if (!$sitioId) err('sitio_id requerido', 400);

        $accId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $reveal = isset($_GET['reveal']) && $_GET['reveal'] === '1';

        if ($accId) {
            $stmt = $db->prepare("SELECT * FROM accesos WHERE id = ? AND sitio_id = ?");
            $stmt->execute([$accId, $sitioId]);
            $row = $stmt->fetch();
            if (!$row) err('Acceso no encontrado', 404);
            if ($reveal) {
                try { $row['password'] = decryptStr($row['password_cipher']); }
                catch (Throwable $e) { $row['password'] = null; $row['password_error'] = 'No se pudo descifrar'; }
                audit($db, $user['id'], 'REVEAL', 'accesos', "Reveló acceso #{$accId} (sitio #{$sitioId})", $accId);
            }
            $row['tiene_password'] = !empty($row['password_cipher']);
            unset($row['password_cipher']);
            $row['id'] = (int)$row['id'];
            $row['sitio_id'] = (int)$row['sitio_id'];
            ok($row);
        }

        $stmt = $db->prepare("SELECT id, sitio_id, tipo, etiqueta, url, usuario,
                                     (password_cipher IS NOT NULL AND password_cipher <> '') AS tiene_password,
                                     notas, creado_por, created_at, updated_at
                              FROM accesos
                              WHERE sitio_id = ?
                              ORDER BY tipo, etiqueta");
        $stmt->execute([$sitioId]);
        $data = $stmt->fetchAll();
        foreach ($data as &$r) {
            $r['id'] = (int)$r['id'];
            $r['sitio_id'] = (int)$r['sitio_id'];
            $r['tiene_password'] = (bool)$r['tiene_password'];
        }
        ok($data);
    }

    // --- POST: crear ---
    if ($method === 'POST') {
        $b = body();
        $sitioId  = (int)($b['sitio_id'] ?? 0);
        $tipo     = in_array($b['tipo'] ?? '', $TIPOS, true) ? $b['tipo'] : 'otro';
        $etiqueta = trim((string)($b['etiqueta'] ?? ''));
        if (!$sitioId) err('Sitio no válido', 400);
        if ($etiqueta === '') err('La etiqueta es obligatoria', 400);

        $chk = $db->prepare("SELECT id FROM sitios WHERE id = ? AND deleted_at IS NULL");
        $chk->execute([$sitioId]);
        if (!$chk->fetch()) err('Sitio no existe', 404);

        $passCipher = !empty($b['password']) ? encryptStr((string)$b['password']) : null;

        $sql = "INSERT INTO accesos
                (sitio_id, tipo, etiqueta, url, usuario, password_cipher, notas, creado_por, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $db->prepare($sql)->execute([
            $sitioId, $tipo, $etiqueta,
            trim((string)($b['url'] ?? '')) ?: null,
            trim((string)($b['usuario'] ?? '')) ?: null,
            $passCipher,
            trim((string)($b['notas'] ?? '')) ?: null,
            $user['id'],
        ]);
        $newId = (int)$db->lastInsertId();
        audit($db, $user['id'], 'CREATE', 'accesos', "Acceso '{$etiqueta}' en sitio #{$sitioId}", $newId);
        ok(['id' => $newId]);
    }

    // --- PUT: editar ---
    if ($method === 'PUT') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$id) err('id requerido', 400);
        $b = body();

        $cur = $db->prepare("SELECT * FROM accesos WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch();
        if (!$row) err('Acceso no encontrado', 404);

        $tipo     = in_array($b['tipo'] ?? $row['tipo'], $TIPOS, true) ? $b['tipo'] : $row['tipo'];
        $etiqueta = trim((string)($b['etiqueta'] ?? $row['etiqueta']));
        if ($etiqueta === '') err('Etiqueta requerida', 400);

        $passCipher = $row['password_cipher'];
        if (array_key_exists('password', $b)) {
            $passCipher = !empty($b['password']) ? encryptStr((string)$b['password']) : null;
        }

        $sql = "UPDATE accesos
                SET tipo = ?, etiqueta = ?, url = ?, usuario = ?, password_cipher = ?, notas = ?, updated_at = NOW()
                WHERE id = ?";
        $db->prepare($sql)->execute([
            $tipo, $etiqueta,
            trim((string)($b['url'] ?? $row['url'] ?? '')) ?: null,
            trim((string)($b['usuario'] ?? $row['usuario'] ?? '')) ?: null,
            $passCipher,
            trim((string)($b['notas'] ?? $row['notas'] ?? '')) ?: null,
            $id,
        ]);
        audit($db, $user['id'], 'UPDATE', 'accesos', "Editó acceso #{$id} ({$etiqueta})", $id);
        ok(['ok' => true]);
    }

    // --- DELETE ---
    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$id) err('id requerido', 400);
        $db->prepare("DELETE FROM accesos WHERE id = ?")->execute([$id]);
        audit($db, $user['id'], 'DELETE', 'accesos', "Eliminó acceso #{$id}", $id);
        ok(['ok' => true]);
    }

    err('Método no permitido', 405);

} catch (Throwable $e) {
    errSafe($e, 500, 'accesos');
}
