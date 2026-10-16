<?php
/**
 * Resetear Contraseña - Valida token y actualiza password
 * POST /reset_password.php
 * Body: { token: string, password: string }
 */
require __DIR__ . '/../config.php';
cors();

// Obtener conexión a la base de datos
$db = getDB();

// Obtener datos del body
$data = json_decode(file_get_contents('php://input'), true);
$token = isset($data['token']) ? trim($data['token']) : '';
$password = isset($data['password']) ? trim($data['password']) : '';

if (empty($token) || empty($password)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token y contraseña requeridos']);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'La contraseña debe tener al menos 6 caracteres']);
    exit;
}

try {
    // Limpieza preventiva: eliminar tokens expirados (housekeeping ligero)
    $db->prepare("DELETE FROM password_resets WHERE expira_en < NOW()")->execute();

    // Buscar token válido
    $stmt = $db->prepare("
        SELECT pr.usuario_id, pr.expira_en
        FROM password_resets pr
        WHERE pr.token = ? AND pr.expira_en > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reset) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Token inválido o expirado']);
        exit;
    }

    // Actualizar contraseña
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
    $stmt->execute([$hash, $reset['usuario_id']]);

    // Invalidar token usado Y cualquier otro token activo del mismo usuario
    // (defensa en profundidad: si pidió 2 resets, ambos quedan invalidados)
    $stmt = $db->prepare("DELETE FROM password_resets WHERE token = ? OR usuario_id = ?");
    $stmt->execute([$token, $reset['usuario_id']]);

    logInfo('Password reset exitoso', ['usuario_id' => $reset['usuario_id']]);
    
    echo json_encode(['ok' => true, 'message' => 'Contraseña actualizada correctamente']);
    
} catch (Exception $e) {
    logError('Error en reset password', ['message' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al actualizar contraseña']);
}
