<?php
// ============================================================
// Cargar variables de entorno desde .env
// ============================================================
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

loadEnv(__DIR__ . '/.env');

// ============================================================
// Definir constantes desde variables de entorno o valores por defecto
// ============================================================
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'sistema_local');
define('DB_USER', getenv('DB_USER') ?: 'root');
// DB_PASS sin default — si no hay .env, mejor fallar que usar password trivial.
// MAMP local usa 'root' pero se setea explícitamente en .env, no como default.
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_DEBUG', getenv('APP_DEBUG') === 'true');
// CORS por defecto restrictivo (mismo host). Solo APP_DEBUG=true permite '*'.
define('CORS_ORIGIN', getenv('CORS_ORIGIN') ?: (APP_DEBUG ? '*' : ''));
// ============================================================

define('DB_CHARSET', 'utf8mb4');
define('FRONTEND_URL', CORS_ORIGIN);

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]);
    }
    return $pdo;
}

function cors(): void {
    // Whitelist de orígenes desde .env (coma-separados). '*' solo se permite en debug.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = array_filter(array_map('trim', explode(',', FRONTEND_URL)));
    if (in_array('*', $allowed, true) && APP_DEBUG) {
        header("Access-Control-Allow-Origin: *");
    } elseif ($origin && in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: " . $origin);
        header("Vary: Origin");
    } elseif (!empty($allowed)) {
        // Fallback al primer origen permitido (evita CORS roto al hacer requests sin Origin)
        header("Access-Control-Allow-Origin: " . $allowed[0]);
        header("Vary: Origin");
    }
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Content-Type: application/json; charset=utf-8");
    
    // ═══════════════════════════════════════════════════════════
    // SECURITY HEADERS
    // ═══════════════════════════════════════════════════════════
    // Prevenir clickjacking
    header("X-Frame-Options: DENY");
    // Prevenir MIME type sniffing
    header("X-Content-Type-Options: nosniff");
    // Habilitar XSS filter (legacy browsers)
    header("X-XSS-Protection: 1; mode=block");
    // Referrer policy
    header("Referrer-Policy: strict-origin-when-cross-origin");
    // Permissions Policy: deshabilitar APIs sensibles no usadas
    header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()");
    
    // HSTS (solo en producción con HTTPS)
    if (!APP_DEBUG && isset($_SERVER['HTTPS'])) {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
    }
    
    // CSP (Content Security Policy) - Ajustar según necesidades
    $csp = "default-src 'self'; ";
    $csp .= "script-src 'self' 'unsafe-inline' 'unsafe-eval'; "; // Necesario para Quill/ React
    $csp .= "style-src 'self' 'unsafe-inline'; ";
    $csp .= "img-src 'self' data: blob:; ";
    $csp .= "font-src 'self'; ";
    $csp .= "connect-src 'self' http://localhost:* https:; "; // Para API calls
    $csp .= "frame-ancestors 'none'; ";
    $csp .= "base-uri 'self'; ";
    $csp .= "form-action 'self'; ";
    header("Content-Security-Policy: " . $csp);
    // ═══════════════════════════════════════════════════════════
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function body(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function ok(mixed $data = null, int $code = 200): never {
    http_response_code($code);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Maneja excepciones inesperadas sin filtrar detalles internos al cliente.
 * Loggea el detalle real y devuelve mensaje genérico (o el real si APP_DEBUG=true).
 */
function errSafe(Throwable $e, int $code = 500, string $context = ''): never {
    $id = bin2hex(random_bytes(6));
    if (function_exists('logError')) {
        logError('Excepción no controlada [' . $id . ']', [
            'context'   => $context,
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => APP_DEBUG ? $e->getTraceAsString() : null,
        ]);
    } else {
        error_log('[' . $id . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
    http_response_code($code);
    // Sanitizamos el mensaje para mostrarlo al cliente:
    // - quitamos paths absolutos (revelan estructura del servidor)
    // - cortamos a primera línea
    // No expone trace ni info crítica, pero da pista útil ("Table X doesn't exist").
    $rawMsg = $e->getMessage();
    $shortMsg = strtok($rawMsg, "\n"); // primera línea
    $shortMsg = preg_replace('#/[^\s]*?backend/#', '/backend/', $shortMsg); // anonimiza path
    $shortMsg = mb_substr($shortMsg, 0, 200);

    $payload = [
        'ok' => false,
        'error' => 'Error interno. Ref: ' . $id . ' — ' . $shortMsg,
    ];
    if (APP_DEBUG) {
        $payload['debug'] = [
            'message' => $rawMsg,
            'file'    => basename($e->getFile()),
            'line'    => $e->getLine(),
        ];
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Lee el token desde todas las fuentes posibles
function getToken(): ?string {
    $candidates = [];
    
    // 1. Intentar con apache_request_headers (Especial para MAMP/Mac)
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) $candidates[] = $headers['Authorization'];
        if (isset($headers['authorization'])) $candidates[] = $headers['authorization'];
    }

    // 2. Intentar con las variables de servidor estándar
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $candidates[] = $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $candidates[] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    // 3. Revisar cada candidato
    foreach ($candidates as $v) {
        if (empty($v)) continue;
        $v = trim((string)$v);
        if (preg_match('/^Bearer\s+(\S+)$/i', $v, $m)) {
            return $m[1];
        }
    }
    
    return null;
}

function requireAuth(): array {
    $token = getToken();
    
    if (!$token) {
        err('No autenticado. Inicia sesión.', 401);
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT u.id, u.nombre, u.email, u.rol, u.activo 
                         FROM usuarios u 
                         JOIN sesiones s ON u.id = s.usuario_id 
                         WHERE s.token = ? AND s.expira_en > NOW() 
                         LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if (!$user) {
        err('Sesión inválida o expirada. Inicia sesión nuevamente.', 401);
    }
    
    if (!$user['activo']) {
        err('Usuario desactivado. Contacta al administrador.', 403);
    }
    
    return [
        'id' => (int)$user['id'],
        'nombre' => $user['nombre'],
        'email' => $user['email'],
        'rol' => $user['rol']
    ];
}

// ═══════════════════════════════════════════════════════════
// VALIDACIÓN DE INPUTS
// ═══════════════════════════════════════════════════════════

function validateEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validateString(string $value, int $min = 1, int $max = 255): bool {
    $len = mb_strlen($value);
    return $len >= $min && $len <= $max;
}

function validateInt($value, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int {
    if (!is_numeric($value)) return null;
    $int = (int)$value;
    return ($int >= $min && $int <= $max) ? $int : null;
}

function validateFloat($value, float $min = PHP_FLOAT_MIN, float $max = PHP_FLOAT_MAX): ?float {
    if (!is_numeric($value)) return null;
    $float = (float)$value;
    return ($float >= $min && $float <= $max) ? $float : null;
}

function validateRut(string $rut): bool {
    // Limpia puntos/espacios/guión opcionales — el frontend envía con puntos
    // (12.345.678-9), pero acá normalizamos a 12345678-9 antes de validar.
    $clean = preg_replace('/[^0-9kK]/', '', $rut);
    if (strlen($clean) < 2) return false;
    $dv = strtoupper(substr($clean, -1));
    $numero = substr($clean, 0, -1);
    if (!preg_match('/^[0-9]+$/', $numero)) return false;
    if ($dv !== 'K' && !preg_match('/^[0-9]$/', $dv)) return false;
    $dv = $dv === 'K' ? 'K' : (int)$dv;
    
    // Algoritmo módulo 11
    $suma = 0;
    $multiplicador = 2;
    for ($i = strlen($numero) - 1; $i >= 0; $i--) {
        $suma += $numero[$i] * $multiplicador;
        $multiplicador = $multiplicador == 7 ? 2 : $multiplicador + 1;
    }
    
    $resto = $suma % 11;
    $dvCalculado = 11 - $resto;
    
    if ($dvCalculado == 11) $dvCalculado = '0';
    elseif ($dvCalculado == 10) $dvCalculado = 'K';
    else $dvCalculado = (string)$dvCalculado;
    
    return (string)$dv === $dvCalculado;
}

function sanitizeString(string $value): string {
    // Eliminar caracteres nulos y potencialmente peligrosos
    $value = str_replace(["\0", "\x00"], '', $value);
    // Trim
    return trim($value);
}

/**
 * safeText — limpia texto plano: quita caracteres de control pero PRESERVA
 * UTF-8 (acentos, ñ, etc.). Antes vivía local en usuarios.php con una regex
 * [\x7F-\xFF] que rompía multibyte (Muñoz → Muoz). Ahora global y correcta.
 */
function safeText($str): string {
    if ($str === null) return '';
    // Solo caracteres de control C0 (0x00-0x1F) y DEL (0x7F). Modificador /u
    // para que la regex respete los bytes UTF-8 de acentos/ñ.
    $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$str);
    return trim($clean ?? (string)$str);
}

function sanitizeHtml(string $html): string {
    // 1) Permitir solo tags básicos de formateo (Quill).
    $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><h1><h2><h3><h4><h5><h6><span><div>';
    $html = strip_tags($html, $allowed);
    // 2) strip_tags NO elimina atributos peligrosos dentro de las tags
    //    permitidas (onclick, onerror, style con expression, etc.). Los
    //    removemos para cerrar el vector de XSS almacenado:
    //    - atributos de evento on*="..." / on*='...'
    $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    //    - protocolo javascript: en cualquier atributo restante
    $html = preg_replace('/(javascript|vbscript|data)\s*:/i', '', $html);
    //    - atributo style (puede contener expression()/url(javascript:))
    $html = preg_replace('/\sstyle\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html);
    return $html;
}

/**
 * Escapa caracteres de wildcard en patrones LIKE de MySQL (% y _).
 * Sin esto, buscar "100%" trae TODO porque % es comodín SQL.
 */
function escapeLike(string $s): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/**
 * Valida y limpia un array de datos
 * @param array $data Datos a validar
 * @param array $rules Reglas de validación ['campo' => 'tipo:min:max', ...]
 * @return array [ok => bool, data => cleaned|error => mensaje]
 */
function validate(array $data, array $rules): array {
    $cleaned = [];
    
    foreach ($rules as $field => $rule) {
        $value = $data[$field] ?? null;
        if ($value === null) continue;
        
        $parts = explode(':', $rule);
        $type = $parts[0];
        $min = isset($parts[1]) ? (int)$parts[1] : null;
        $max = isset($parts[2]) ? (int)$parts[2] : null;
        
        switch ($type) {
            case 'email':
                if (!validateEmail($value)) {
                    return ['ok' => false, 'error' => "El campo $field debe ser un email válido"];
                }
                $cleaned[$field] = strtolower(trim($value));
                break;
                
            case 'string':
                $value = sanitizeString($value);
                $minLen = $min ?? 1;
                $maxLen = $max ?? 255;
                if (!validateString($value, $minLen, $maxLen)) {
                    return ['ok' => false, 'error' => "El campo $field debe tener entre $minLen y $maxLen caracteres"];
                }
                $cleaned[$field] = $value;
                break;
                
            case 'int':
                $int = validateInt($value, $min ?? PHP_INT_MIN, $max ?? PHP_INT_MAX);
                if ($int === null) {
                    return ['ok' => false, 'error' => "El campo $field debe ser un número entero válido"];
                }
                $cleaned[$field] = $int;
                break;
                
            case 'float':
                $float = validateFloat($value, $min ?? PHP_FLOAT_MIN, $max ?? PHP_FLOAT_MAX);
                if ($float === null) {
                    return ['ok' => false, 'error' => "El campo $field debe ser un número válido"];
                }
                $cleaned[$field] = $float;
                break;
                
            case 'rut':
                $value = strtoupper(trim($value));
                if (!validateRut($value)) {
                    return ['ok' => false, 'error' => "El campo $field debe ser un RUT válido"];
                }
                $cleaned[$field] = $value;
                break;
                
            case 'html':
                $cleaned[$field] = sanitizeHtml($value);
                break;
                
            case 'bool':
                $cleaned[$field] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                break;
                
            default:
                $cleaned[$field] = sanitizeString($value);
        }
    }
    
    return ['ok' => true, 'data' => $cleaned];
}
// ═══════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════
// LOGGER ESTRUCTURADO JSON
// ═══════════════════════════════════════════════════════════

define('LOG_LEVEL', getenv('LOG_LEVEL') ?: 'info');
// LOG_FILE absoluto: si es relativo, lo anclamos a /backend (cwd varía
// según desde dónde corra PHP). Sin esto, el log se intentaba escribir
// a /api/logs/app.log o similar y simplemente no se creaba.
$_logFileRaw = getenv('LOG_FILE') ?: 'logs/app.log';
define('LOG_FILE', $_logFileRaw[0] === '/' ? $_logFileRaw : __DIR__ . '/' . $_logFileRaw);

// Niveles de log (menor a mayor severidad)
$GLOBALS['LOG_LEVELS'] = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];

function logLevelValue(string $level): int {
    return $GLOBALS['LOG_LEVELS'][$level] ?? 1;
}

function shouldLog(string $level): bool {
    return logLevelValue($level) >= logLevelValue(LOG_LEVEL);
}

/**
 * Logger estructurado en JSON
 * @param string $level Nivel: debug, info, warning, error, critical
 * @param string $message Mensaje del log
 * @param array $context Datos adicionales (user_id, endpoint, ip, etc.)
 */
function logger(string $level, string $message, array $context = []): void {
    if (!shouldLog($level)) return;
    
    // Asegurar que existe el directorio de logs
    $logDir = dirname(LOG_FILE);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $entry = [
        'timestamp' => date('c'), // ISO 8601
        'level' => $level,
        'message' => $message,
        'context' => array_merge([
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ], $context),
        'request_id' => uniqid(),
    ];
    
    // Si hay usuario autenticado, agregarlo al contexto.
    // NOTA: la tabla se llama `sesiones` y la columna `usuario_id` (no `sessions`/`user_id`).
    // Todo este bloque está envuelto en try/catch porque ANTES, si el logger
    // fallaba (ej: tabla no existe), tiraba una PDOException no controlada que
    // dejaba la respuesta del endpoint en blanco → "Respuesta inválida" en frontend.
    try {
        if (function_exists('getToken')) {
            $token = getToken();
            if ($token) {
                $db = getDB();
                $stmt = $db->prepare("SELECT usuario_id FROM sesiones WHERE token = ? AND expira_en > NOW()");
                $stmt->execute([$token]);
                $session = $stmt->fetch();
                if ($session) {
                    $entry['context']['user_id'] = (int)$session['usuario_id'];
                }
            }
        }
    } catch (Throwable $logE) {
        // El logger no puede tirar excepciones — eso reventaba todo el flujo.
        // Silenciamos y dejamos el contexto sin user_id.
        $entry['context']['logger_warn'] = 'no se pudo agregar user_id al log';
    }

    try {
        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        error_log($json . "\n", 3, LOG_FILE);
    } catch (Throwable $writeE) {
        // Si ni siquiera podemos escribir el log, usamos error_log default
        error_log('[logger fallback] ' . ($entry['message'] ?? 'sin mensaje'));
    }
}

// Helpers para cada nivel
function logDebug(string $message, array $context = []): void { logger('debug', $message, $context); }
function logInfo(string $message, array $context = []): void { logger('info', $message, $context); }
function logWarning(string $message, array $context = []): void { logger('warning', $message, $context); }
function logError(string $message, array $context = []): void { logger('error', $message, $context); }
function logCritical(string $message, array $context = []): void { logger('critical', $message, $context); }

/**
 * Middleware para loggear cada request/response
 */
function logRequest(): array {
    $startTime = microtime(true);
    
    return [
        'start' => $startTime,
        'finish' => function($statusCode = 200, $extra = []) use ($startTime) {
            $duration = round((microtime(true) - $startTime) * 1000, 2); // ms
            logger('info', 'Request completed', array_merge([
                'status_code' => $statusCode,
                'duration_ms' => $duration,
            ], $extra));
        }
    ];
}
// ═══════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════
// SISTEMA DE CACHE
// ═══════════════════════════════════════════════════════════

define('CACHE_DRIVER', getenv('CACHE_DRIVER') ?: 'file');
define('CACHE_TTL', (int)(getenv('CACHE_TTL') ?: 300));
define('CACHE_DIR', __DIR__ . '/cache');
define('REDIS_HOST', getenv('REDIS_HOST') ?: 'localhost');
define('REDIS_PORT', (int)(getenv('REDIS_PORT') ?: 6379));

// Asegurar directorio de cache existe
if (CACHE_DRIVER === 'file' && !is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

/**
 * Genera una clave de cache única
 */
function cacheKey(string $prefix, ...$params): string {
    return $prefix . ':' . md5(serialize($params));
}

/**
 * Obtiene valor del cache
 */
function cacheGet(string $key): ?array {
    if (CACHE_DRIVER === 'file') {
        $file = CACHE_DIR . '/' . $key . '.cache';
        if (!file_exists($file)) return null;
        
        $data = unserialize(file_get_contents($file));
        if ($data['expires'] < time()) {
            unlink($file);
            return null;
        }
        return $data['value'];
    }
    
    // Redis support (when available)
    if (CACHE_DRIVER === 'redis') {
        try {
            $redis = new Redis();
            $redis->connect(REDIS_HOST, REDIS_PORT);
            $value = $redis->get($key);
            return $value ? unserialize($value) : null;
        } catch (Exception $e) {
            logWarning('Redis cache failed, falling back to no cache', ['error' => $e->getMessage()]);
            return null;
        }
    }
    
    return null;
}

/**
 * Guarda valor en cache
 */
function cacheSet(string $key, $value, ?int $ttl = null): bool {
    $ttl = $ttl ?? CACHE_TTL;
    
    if (CACHE_DRIVER === 'file') {
        $file = CACHE_DIR . '/' . $key . '.cache';
        $data = [
            'expires' => time() + $ttl,
            'value' => $value
        ];
        return file_put_contents($file, serialize($data), LOCK_EX) !== false;
    }
    
    if (CACHE_DRIVER === 'redis') {
        try {
            $redis = new Redis();
            $redis->connect(REDIS_HOST, REDIS_PORT);
            return $redis->setex($key, $ttl, serialize($value));
        } catch (Exception $e) {
            logWarning('Redis cache set failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    return false;
}

/**
 * Elimina valor del cache
 */
function cacheDelete(string $key): bool {
    if (CACHE_DRIVER === 'file') {
        $file = CACHE_DIR . '/' . $key . '.cache';
        return file_exists($file) ? unlink($file) : true;
    }
    
    if (CACHE_DRIVER === 'redis') {
        try {
            $redis = new Redis();
            $redis->connect(REDIS_HOST, REDIS_PORT);
            return $redis->del($key) > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    return false;
}

/**
 * Limpia todo el cache
 */
function cacheClear(): bool {
    if (CACHE_DRIVER === 'file') {
        $files = glob(CACHE_DIR . '/*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
        return true;
    }
    
    if (CACHE_DRIVER === 'redis') {
        try {
            $redis = new Redis();
            $redis->connect(REDIS_HOST, REDIS_PORT);
            return $redis->flushAll();
        } catch (Exception $e) {
            return false;
        }
    }
    
    return false;
}

/**
 * Cache helper: get or set
 */
function cacheRemember(string $key, callable $callback, ?int $ttl = null) {
    $cached = cacheGet($key);
    if ($cached !== null) {
        return $cached;
    }
    
    $value = $callback();
    cacheSet($key, $value, $ttl);
    return $value;
}
// ═══════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════
// EMAIL QUEUE SYSTEM
// ═══════════════════════════════════════════════════════════

define('SMTP_HOST', getenv('SMTP_HOST') ?: 'localhost');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_FROM', getenv('SMTP_FROM') ?: 'noreply@sistema.local');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Sistema Local');

/**
 * Agrega un email a la cola para envío asíncrono
 */
function queueEmail(array $data): bool {
    $db = getDB();
    
    $stmt = $db->prepare("INSERT INTO email_queue 
        (to_email, to_name, subject, body_html, body_text, from_email, from_name, priority, scheduled_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    return $stmt->execute([
        $data['to_email'],
        $data['to_name'] ?? null,
        $data['subject'],
        $data['body_html'] ?? null,
        $data['body_text'] ?? null,
        $data['from_email'] ?? SMTP_FROM,
        $data['from_name'] ?? SMTP_FROM_NAME,
        $data['priority'] ?? 5,
        $data['scheduled_at'] ?? date('Y-m-d H:i:s')
    ]);
}

/**
 * Obtiene emails pendientes de la cola
 */
function getPendingEmails(int $limit = 10): array {
    $db = getDB();
    
    $stmt = $db->prepare("SELECT * FROM email_queue 
        WHERE status = 'pending' 
        AND scheduled_at <= NOW()
        AND attempts < max_attempts
        ORDER BY priority ASC, created_at ASC
        LIMIT ?");
    
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

/**
 * Marca un email como en proceso
 */
 function markEmailProcessing(int $id): bool {
    $db = getDB();
    $stmt = $db->prepare("UPDATE email_queue 
        SET status = 'processing', attempts = attempts + 1, processed_at = NOW() 
        WHERE id = ?");
    return $stmt->execute([$id]);
}

/**
 * Marca un email como enviado
 */
function markEmailSent(int $id): bool {
    $db = getDB();
    $stmt = $db->prepare("UPDATE email_queue 
        SET status = 'sent', sent_at = NOW() 
        WHERE id = ?");
    return $stmt->execute([$id]);
}

/**
 * Marca un email como fallido
 */
function markEmailFailed(int $id, string $error): bool {
    $db = getDB();
    $stmt = $db->prepare("UPDATE email_queue 
        SET status = 'failed', error_message = ? 
        WHERE id = ?");
    return $stmt->execute([$error, $id]);
}

/**
 * Envía un email usando mail() nativo (fallback simple)
 * En producción, reemplazar por PHPMailer o SendGrid
 */
function sendEmail(array $email): bool {
    $to = $email['to_email'];
    $subject = $email['subject'];
    $headers = [
        'From: ' . $email['from_name'] . ' <' . $email['from_email'] . '>',
        'Reply-To: ' . $email['from_email'],
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8'
    ];
    
    // Para desarrollo, loggear en lugar de enviar
    if (APP_ENV === 'development') {
        logInfo('Email enviado (modo desarrollo)', [
            'to' => $to,
            'subject' => $subject,
            'queue_id' => $email['id']
        ]);
        return true;
    }
    
    // En producción, usar mail() o integrar PHPMailer
    $headersStr = implode("\r\n", $headers);
    return mail($to, $subject, $email['body_html'], $headersStr);
}

/**
 * Procesa la cola de emails (llamar desde cron job cada minuto)
 * Uso: php email_worker.php
 */
function processEmailQueue(int $batchSize = 10): array {
    $results = ['processed' => 0, 'sent' => 0, 'failed' => 0];
    
    $emails = getPendingEmails($batchSize);
    
    foreach ($emails as $email) {
        $results['processed']++;
        
        // Marcar como procesando
        markEmailProcessing($email['id']);
        
        // Intentar enviar
        try {
            if (sendEmail($email)) {
                markEmailSent($email['id']);
                $results['sent']++;
                logInfo('Email enviado desde cola', ['queue_id' => $email['id'], 'to' => $email['to_email']]);
            } else {
                throw new Exception('mail() returned false');
            }
        } catch (Exception $e) {
            markEmailFailed($email['id'], $e->getMessage());
            $results['failed']++;
            logError('Email falló', ['queue_id' => $email['id'], 'error' => $e->getMessage()]);
        }
    }
    
    return $results;
}

/**
 * Estadísticas de la cola de emails
 */
function getEmailQueueStats(): array {
    $db = getDB();
    
    $stmt = $db->query("SELECT 
        status, COUNT(*) as count 
        FROM email_queue 
        GROUP BY status");
    
    $stats = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $stats[$row['status']] = (int)$row['count'];
    }
    
    return $stats;
}
// ═══════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════
// AUDITORÍA
// ═══════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════
// RATE LIMITING (basado en file lock + ventana fija)
// ═══════════════════════════════════════════════════════════
/**
 * Rate limit por IP + clave. Si se supera, responde 429 y corta la ejecución.
 *
 * @param string $key   Identificador del bucket (ej: 'export', 'backup.create').
 * @param int    $limit Cantidad máxima de requests permitidos.
 * @param int    $window Ventana en segundos.
 */
function rateLimit(string $key, int $limit, int $window): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $bucket = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key . ':' . $ip);
    $dir = __DIR__ . '/cache/rl';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $file = $dir . '/' . $bucket;
    $now  = time();
    $data = ['reset' => $now + $window, 'count' => 0];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $parsed = $raw ? @json_decode($raw, true) : null;
        if (is_array($parsed) && ($parsed['reset'] ?? 0) > $now) $data = $parsed;
    }
    $data['count']++;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    if ($data['count'] > $limit) {
        header('Retry-After: ' . max(1, $data['reset'] - $now));
        err('Demasiadas solicitudes. Intenta en ' . ($data['reset'] - $now) . 's.', 429);
    }
}
// ═══════════════════════════════════════════════════════════

function audit(PDO $db, int $usuarioId, string $accion, string $tabla, string $detalle, ?int $regId = null): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla_afectada, registro_id, detalle, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
       ->execute([$usuarioId, $accion, $tabla, $regId, $detalle, $ip]);
}