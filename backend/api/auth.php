<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
} catch (Exception $e) {
    header('Content-Type: application/json');
    die(json_encode(['ok' => false, 'error' => 'Error de DB']));
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'] ?? '';

// --- CASO 0: LOGOUT (POST a auth.php/logout) ---
if ($method === 'POST' && strpos($uri, 'logout') !== false) {
    $token = getToken();
    if ($token) {
        $db->prepare("DELETE FROM sesiones WHERE token = ?")->execute([$token]);
    }
    echo json_encode(['ok' => true, 'message' => 'Sesión cerrada']);
    exit;
}

// --- RATE LIMITING: Tabla temporal para intentos fallidos ---
function checkRateLimit(PDO $db, string $ip): bool {
    // Limpiar intentos antiguos (> 15 minutos)
    $db->prepare("DELETE FROM login_attempts WHERE intento_en < DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->execute();
    
    // Contar intentos fallidos recientes de esta IP
    $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND exitoso = 0 AND intento_en > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute([$ip]);
    $intentos = (int)$stmt->fetchColumn();
    
    return $intentos < 5; // Máximo 5 intentos fallidos cada 15 minutos
}

function logAttempt(PDO $db, string $ip, string $email, bool $exitoso): void {
    $db->prepare("INSERT INTO login_attempts (ip, email, exitoso, intento_en) VALUES (?, ?, ?, NOW())")
       ->execute([$ip, $email, $exitoso ? 1 : 0]);
}

// --- CASO 1: LOGIN (POST) ---
if ($method === 'POST' && strpos($uri, 'logout') === false) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    // Verificar rate limiting
    if (!checkRateLimit($db, $ip)) {
        http_response_code(429); // Too Many Requests
        echo json_encode(['ok' => false, 'error' => 'Demasiados intentos fallidos. Espera 15 minutos.']);
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM usuarios WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($password, $u['password'])) {
        logAttempt($db, $ip, $email, false); // Log intento fallido
        header('Content-Type: application/json');
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Credenciales inválidas']));
    }
    
    if (!$u['activo']) {
        logAttempt($db, $ip, $email, false); // Log intento fallido
        header('Content-Type: application/json');
        http_response_code(403);
        die(json_encode(['ok' => false, 'error' => 'Usuario desactivado']));
    }

    // Login exitoso
    logAttempt($db, $ip, $email, true);
    
    $token = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', strtotime('+8 hours'));
    
    // Guardar sesión en BD
    $db->prepare("INSERT INTO sesiones (usuario_id, token, expira_en, ultima_actividad) VALUES (?, ?, ?, NOW())")
       ->execute([$u['id'], $token, $expira]);
    
    // Log de auditoría — NO incluimos el email en `detalle` para evitar PII en logs
    // permanentes (GDPR / Ley 21.628). El usuario_id ya identifica unívocamente.
    $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla_afectada, detalle, ip_address, created_at) VALUES (?, 'LOGIN', 'usuarios', ?, ?, NOW())")
       ->execute([$u['id'], "Login exitoso", $ip]);
    
    header('Content-Type: application/json');
    echo json_encode([
        "ok" => true,
        "data" => [
            "token" => $token,
            "usuario" => [
                "id" => (int)$u['id'], 
                "nombre" => $u['nombre'], 
                "email" => $u['email'], 
                "rol" => $u['rol']
            ],
            "rol" => $u['rol']
        ]
    ]);
    exit;
}

// --- CASO 2: VALIDACIÓN /ME (CUALQUIER GET A ESTE ARCHIVO) ---
// En local, si React llega aquí con un GET, le decimos que "Todo OK" 
// para que no te desloguee.
// --- EN TU auth.php, REEMPLAZA LA SECCIÓN GET ---
// --- CASO 2: PETICIONES GET ---
if ($method === 'GET') {
    header('Content-Type: application/json');
    
    // Capturamos la acción (me o activos)
    $uri = $_SERVER['REQUEST_URI'];
    
    // SI PIDEN ACTIVOS: requiere autenticación (no debe filtrar quién está
    // conectado a usuarios no autenticados)
    if (strpos($uri, 'activos') !== false) {
        $tokenActivos = getToken();
        if (!$tokenActivos) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'No autenticado']);
            exit;
        }
        // Validar que el token sea de una sesión activa
        $chk = $db->prepare("SELECT 1 FROM sesiones WHERE token = ? AND expira_en > NOW() LIMIT 1");
        $chk->execute([$tokenActivos]);
        if (!$chk->fetchColumn()) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Sesión inválida']);
            exit;
        }

        // Consultamos quién tiene sesión activa. DISTINCT por usuario: varias
        // sesiones del mismo usuario = una sola entrada. Incluimos el id para
        // que el frontend identifique al usuario actual de forma fiable.
        $sql = "SELECT u.id, u.nombre
                FROM sesiones s
                JOIN usuarios u ON s.usuario_id = u.id
                WHERE s.expira_en > NOW()
                GROUP BY u.id, u.nombre";

        $stmt = $db->query($sql);
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "ok" => true,
            "data" => $usuarios
        ]);
        exit;
    }

    // VALIDACIÓN /me: Verificar token real
    $token = getToken();

    if (!$token) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'No autenticado - token no recibido']);
        exit;
    }
    
    $stmt = $db->prepare("SELECT u.id, u.nombre, u.email, u.rol, u.activo 
                         FROM usuarios u 
                         JOIN sesiones s ON u.id = s.usuario_id 
                         WHERE s.token = ? AND s.expira_en > NOW() 
                         LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if (!$user) {
        // Verificar si el token existe pero expiró
        $check = $db->prepare("SELECT expira_en FROM sesiones WHERE token = ?");
        $check->execute([$token]);
        $sesion = $check->fetch();
        if ($sesion) {
            logWarning('Token expirado', ['token_expira' => $sesion['expira_en']]);
        } else {
            logWarning('Token no encontrado en BD', ['token_prefix' => substr($token, 0, 10)]);
        }
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Sesión inválida o expirada']);
        exit;
    }
    
    if (!$user['activo']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Usuario desactivado']);
        exit;
    }
    
    // Actualizar última actividad Y extender expiración (sliding session)
    $nueva_expira = date('Y-m-d H:i:s', strtotime('+8 hours'));
    $db->prepare("UPDATE sesiones SET ultima_actividad = NOW(), expira_en = ? WHERE token = ?")
       ->execute([$nueva_expira, $token]);
    
    echo json_encode([
        "ok" => true,
        "data" => [
            "id" => (int)$user['id'],
            "usuario_id" => (int)$user['id'],
            "nombre" => $user['nombre'],
            "email" => $user['email'],
            "rol" => $user['rol']
        ]
    ]);
    exit;
}