<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $method = $_SERVER['REQUEST_METHOD'];
    // Soporte para method override via query string (POST con _method=PUT)
    if ($method === 'POST' && isset($_GET['_method'])) {
        $method = strtoupper($_GET['_method']);
    }
    $user = requireAuth();

    if ($user['rol'] !== 'admin') {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'No tienes permisos']));
    }

    // safeText() ahora es global (config.php) y preserva UTF-8 correctamente.

    // --- CAPTURA ÚNICA DE ID ---
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if (!$id) {
        $path = explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $last = end($path);
        $id = is_numeric($last) ? (int)$last : null;
    }

    // --- 1. MÉTODO DELETE: BORRAR ---
    if ($method === 'DELETE' && $id) {
        // Protección: No puede borrarse a sí mismo
        if ($id === $user['id']) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'No puedes eliminar tu propio usuario']);
            exit;
        }
        
        // Obtener info del usuario antes de borrar (para auditoría)
        $info = $db->prepare("SELECT email, nombre FROM usuarios WHERE id = ?");
        $info->execute([$id]);
        $usuarioBorrado = $info->fetch();
        
        // TX: dos DELETEs deben ser atómicos para no dejar sesiones huérfanas
        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM sesiones WHERE usuario_id = ?")->execute([$id]);

            $stmt = $db->prepare("DELETE FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);
            $deleted = $stmt->rowCount();
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        if ($deleted > 0) {
            audit($db, $user['id'], 'DELETE', 'usuarios', "Eliminó usuario ID {$id} ({$usuarioBorrado['email']})");
            echo json_encode(['ok' => true, 'message' => 'Eliminado']);
        } else {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'No se encontró el usuario']);
        }
        exit;
    }

    // --- 2. MÉTODO GET: LISTAR ---
    if ($method === 'GET' && !$id) {
        $sql = "SELECT u.id, u.nombre, u.email, u.rol, u.activo,
                (SELECT MAX(ultima_actividad) FROM sesiones WHERE usuario_id = u.id) as ultima_actividad
                FROM usuarios u ORDER BY u.id ASC";
        $stmt = $db->query($sql);
        $data = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id_v = (int)$row['id'];
            $data[] = [
                'id' => $id_v,
                'nombre' => trim($row['nombre']) ?: "Usuario " . $id_v,
                'email' => trim($row['email']),
                'rol' => (strpos(strtolower($row['rol']), 'admin') !== false) ? 'admin' : 'usuario',
                'activo' => (int)$row['activo'],
                'ultimo_acceso' => $row['ultima_actividad'] ?: null
            ];
        }
        echo json_encode(['ok' => true, 'data' => $data]);
        exit;
    }

    // --- 3. MÉTODO PUT: EDITAR ---
    if ($method === 'PUT' && $id) {
        $b = body();
        
        if (empty($b['nombre'])) {
            http_response_code(400);
            exit(json_encode(['ok' => false, 'error' => 'Nombre requerido']));
        }

        $check = $db->prepare("SELECT password, email, rol, activo FROM usuarios WHERE id = ?");
        $check->execute([$id]);
        $usuarioActual = $check->fetch();

        if (!$usuarioActual) {
            http_response_code(404);
            exit(json_encode(['ok' => false, 'error' => 'Usuario no encontrado']));
        }

        // Protección: No puede desactivarse a sí mismo
        if ($id === $user['id'] && ($b['activo'] ?? 1) == 0) {
            http_response_code(403);
            exit(json_encode(['ok' => false, 'error' => 'No puedes desactivar tu propio usuario']));
        }

        // Protección: No puede quitarse su propio rol admin (debe haber al menos un admin)
        if ($id === $user['id'] && $usuarioActual['rol'] === 'admin' && safeText($b['rol']) !== 'admin') {
            // Verificar si hay otro admin activo
            $stmtAd = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1 AND id != ?");
            $stmtAd->execute([$id]);
            $otrosAdmins = $stmtAd->fetchColumn();
            if ($otrosAdmins == 0) {
                http_response_code(409);
                exit(json_encode(['ok' => false, 'error' => 'Debe existir al menos otro administrador activo']));
            }
        }

        $cambioPass = !empty($b['password']);
        $pass = $cambioPass ? password_hash($b['password'], PASSWORD_DEFAULT) : $usuarioActual['password'];

        $sql = "UPDATE usuarios SET nombre = ?, email = ?, password = ?, rol = ?, activo = ? WHERE id = ?";
        $db->prepare($sql)->execute([
            safeText($b['nombre']),
            safeText($b['email']),
            $pass,
            safeText($b['rol']),
            (int)($b['activo'] ?? 1),
            $id
        ]);
        
        // Auditoría
        $detalle = "Editó usuario ID {$id} ({$usuarioActual['email']})";
        if ($cambioPass) $detalle .= " - Cambió contraseña";
        if ($usuarioActual['rol'] !== safeText($b['rol'])) $detalle .= " - Cambió rol de '{$usuarioActual['rol']}' a '{$b['rol']}'";
        if ($usuarioActual['activo'] != ($b['activo'] ?? 1)) $detalle .= " - Cambió estado activo";
        audit($db, $user['id'], 'UPDATE', 'usuarios', $detalle);

        echo json_encode(['ok' => true, 'message' => 'Actualizado']);
        exit;
    }

    // --- 4. MÉTODO POST: CREAR ---
    if ($method === 'POST' && !$id) {
        $b = body();
        
        // Verificar email único
        $existe = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
        $existe->execute([safeText($b['email'])]);
        if ($existe->fetch()) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'El email ya está registrado']);
            exit;
        }
        
        // Password temporal: 12 hex chars (~48 bits de entropía). Si no se envía,
        // se genera aleatorio y se devuelve al admin en la respuesta para que lo
        // comunique al usuario. El usuario debería cambiarlo en el primer login.
        $pass = !empty($b['password']) ? $b['password'] : bin2hex(random_bytes(6));
        
        $db->prepare("INSERT INTO usuarios (nombre, email, password, rol, activo) VALUES (?, ?, ?, ?, ?)")
           ->execute([
                safeText($b['nombre']),
                safeText($b['email']),
                password_hash($pass, PASSWORD_DEFAULT),
                safeText($b['rol'] ?? 'usuario'),
                1
           ]);
        $nuevoId = $db->lastInsertId();
        
        audit($db, $user['id'], 'CREATE', 'usuarios', "Creó usuario ID {$nuevoId} ({$b['email']}) con rol " . ($b['rol'] ?? 'usuario'));
        
        echo json_encode(['ok' => true, 'id' => $nuevoId, 'message' => 'Usuario creado' . (empty($b['password']) ? ' - Contraseña temporal: ' . $pass : '')]);
        exit;
    }

} catch (Throwable $e) {
    errSafe($e, 500);
}