<?php
/**
 * Recuperación de Contraseña - Genera token de recuperación
 * POST /recuperar.php
 * Body: { email: string }
 */
require __DIR__ . '/../config.php';
cors();

// Rate limit: 3 intentos por IP cada 15 min
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateKey = 'rate_recuperar_' . md5($ip);
$cached = cacheGet($rateKey);
$count = $cached['count'] ?? 0;
if ($count >= 3) {
    err('Demasiados intentos. Espera 15 minutos.', 429);
}
cacheSet($rateKey, ['count' => $count + 1], 900);

// Obtener conexión a la base de datos
$db = getDB();

// Obtener datos del body
$data = json_decode(file_get_contents('php://input'), true);
$email = isset($data['email']) ? trim($data['email']) : '';

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Email inválido']);
    exit;
}

try {
    // Verificar que el email existe
    $stmt = $db->prepare("SELECT id, nombre, email FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // Por seguridad, no revelar si el email existe o no
        echo json_encode(['ok' => true, 'message' => 'Si el email existe, recibirás instrucciones.']);
        exit;
    }
    
    // Generar token único
    $token = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    // Guardar token en la base de datos
    $stmt = $db->prepare("
        INSERT INTO password_resets (usuario_id, token, expira_en, created_at) 
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE token = ?, expira_en = ?, created_at = NOW()
    ");
    $stmt->execute([$user['id'], $token, $expira, $token, $expira]);
    
    // Construir link de recuperación
    $baseUrl = getenv('FRONTEND_URL') ?: 'http://localhost:5173';
    $resetLink = $baseUrl . '/#/reset-password?token=' . $token;
    
    // ═══════════════════════════════════════════════════════════════════
    // Configurar email
    // ═══════════════════════════════════════════════════════════════════
    $asunto = 'Restablecer tu contraseña';
    $mensaje = "Hemos recibido una solicitud para restablecer tu contraseña. " .
               "Haz clic en el siguiente enlace para crear una nueva contraseña:\n\n{$resetLink}\n\n" .
               "Este enlace expira en 1 hora. Si no solicitaste este cambio, ignora este email.";
    
    $mensajeHtml = "
        <h2>Restablecer tu contraseña</h2>
        <p>Hola {$user['nombre']},</p>
        <p>Hemos recibido una solicitud para restablecer tu contraseña.</p>
        <p>Haz clic en el siguiente enlace para crear una nueva contraseña:</p>
        <p><a href='{$resetLink}' style='background:#3b5bdb;color:#fff;padding:12px 24px;text-decoration:none;border-radius:8px;display:inline-block;'>Restablecer Contraseña</a></p>
        <p>O copia y pega este enlace en tu navegador:</p>
        <p>{$resetLink}</p>
        <p><small>Este enlace expira en 1 hora. Si no solicitaste este cambio, ignora este email.</small></p>
    ";
    
    // ═══════════════════════════════════════════════════════════════════
    
    // Agregar email a la cola para envío asíncrono
    $emailSent = queueEmail([
        'to_email' => $email,
        'to_name' => $user['nombre'],
        'subject' => $asunto,
        'body_html' => $mensajeHtml,
        'body_text' => "Hola {$user['nombre']},\n\n{$mensaje}\n\n{$resetLink}",
        'priority' => 1 // Alta prioridad
    ]);
    
    if ($emailSent) {
        logInfo('Password reset agregado a cola', ['email' => $email, 'queue_id' => getDB()->lastInsertId()]);
        
        echo json_encode([
            'ok' => true, 
            'message' => 'Se ha enviado un email con instrucciones. Revisa tu bandeja de entrada (y spam).',
            'debug_link' => APP_ENV === 'development' ? $resetLink : null // Solo para desarrollo
        ]);
    } else {
        throw new Exception('No se pudo agregar el email a la cola');
    }
    
} catch (Exception $e) {
    logError('Error en recuperar password', ['message' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al procesar la solicitud']);
}
