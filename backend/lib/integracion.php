<?php
/**
 * Integración con Claude Code: llaves de API, autenticación, límites y registro.
 * Spec: INSTRUCCIONES-integracion-claude.md (web-inger-nueva/propuestas/documentos).
 *
 *  - Llave: "igs_" + 40 caracteres [A-Za-z0-9]. Se muestra una sola vez al
 *    crearla; en la BD solo queda su SHA-256 (api_llaves.llave_hash).
 *  - Cabecera: Authorization: Bearer igs_…  (comparación con hash_equals).
 *  - Cualquier rechazo responde 401 genérico, sin decir por qué.
 *  - Límites: 30 peticiones/min por llave · 10 intentos fallidos/15 min por IP.
 *  - Solo HTTPS (en local se acepta http://localhost).
 *  - La integración nunca envía correos ni cambia estados.
 */
declare(strict_types=1);

const IG_PREFIJO = 'igs_';
const IG_LARGO = 40;
const IG_PERMISOS = ['documentos:subir'];
const IG_LIMITE_LLAVE = 30;        // peticiones…
const IG_VENTANA_LLAVE = 60;       // …por minuto
const IG_LIMITE_FALLOS = 10;       // intentos fallidos…
const IG_VENTANA_FALLOS = 900;     // …cada 15 minutos

// Formatos de número (las cotizaciones pueden tener versión: COT-2026-001-v2).
const IG_RE_COT = '/^COT-\d{4}-\d{3,}(-v\d+)?$/D';
const IG_RE_PRO = '/^PRO-\d{4}-\d{3,}(-v\d+)?$/D';
const IG_RE_ENT = '/^ENT-\d{4}-\d{3,}(-\d+)?$/D';  // ENT-2026-005 o ENT-2026-005-2 (segunda entrega)

/** Llave nueva en claro (solo vive en la respuesta de "Crear llave"). */
function igNuevaLlave(): string {
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $s = '';
    for ($i = 0; $i < IG_LARGO; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
    return IG_PREFIJO . $s;
}

function igIp(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Respuesta JSON plana (el script lee { ok, error, … } en la raíz). */
function igResponder(int $code, array $data): never {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Cabeceras de la API: JSON, sin caché y SIN CORS (no se llama desde un navegador). */
function igCabeceras(): void {
    header_remove('Access-Control-Allow-Origin');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
}

function igEsHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Copia local del sistema (MAMP): se acepta http. */
function igEsLocal(): bool {
    $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    $ip = igIp();
    return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) && in_array($ip, ['127.0.0.1', '::1'], true);
}

// ─── Contadores para los límites (mismo lugar que rateLimit()) ─────────

function igContadorRuta(string $bucket): string {
    $dir = __DIR__ . '/../cache/rl';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $bucket);
}

function igContadorLeer(string $bucket): int {
    $f = igContadorRuta($bucket);
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return (is_array($d) && ($d['reset'] ?? 0) > time()) ? (int)$d['count'] : 0;
}

function igContadorSumar(string $bucket, int $ventana): int {
    $f = igContadorRuta($bucket);
    $now = time();
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($d) || ($d['reset'] ?? 0) <= $now) $d = ['reset' => $now + $ventana, 'count' => 0];
    $d['count']++;
    @file_put_contents($f, json_encode($d), LOCK_EX);
    return (int)$d['count'];
}

// ─── Registro (Configuración → Integraciones → Actividad reciente) ─────

function igRegistrar(PDO $db, ?int $llaveId, string $endpoint, ?string $numero, string $resultado): void {
    try {
        $db->prepare("INSERT INTO api_registro (llave_id, endpoint, numero, resultado, ip, fecha) VALUES (?, ?, ?, ?, ?, NOW())")
           ->execute([$llaveId, mb_substr($endpoint, 0, 60), $numero !== null ? mb_substr($numero, 0, 30) : null,
                      mb_substr($resultado, 0, 40), igIp()]);
    } catch (Throwable $e) {
        // El registro nunca debe tumbar la subida.
        if (function_exists('logError')) logError('No se pudo escribir api_registro', ['message' => $e->getMessage()]);
    }
}

/**
 * Valida la llave de la cabecera Authorization y devuelve su fila.
 * Si no sirve responde 401 genérico (y cuenta el intento fallido de la IP).
 */
function igAutenticar(PDO $db, string $permiso, string $endpoint, ?string $numero): array {
    if (!igEsHttps() && !igEsLocal()) {
        igRegistrar($db, null, $endpoint, $numero, 'sin_https');
        igResponder(403, ['ok' => false, 'error' => 'solo_https']);
    }
    $ip = igIp();
    if (igContadorLeer("ig.fallos:$ip") >= IG_LIMITE_FALLOS) {
        header('Retry-After: ' . IG_VENTANA_FALLOS);
        igRegistrar($db, null, $endpoint, $numero, 'ip_bloqueada');
        igResponder(429, ['ok' => false, 'error' => 'demasiados_intentos']);
    }

    $token = (string)(getToken() ?? '');
    $fila = null;
    if (preg_match('/^' . IG_PREFIJO . '[A-Za-z0-9]{' . IG_LARGO . '}$/D', $token)) {
        $hash = hash('sha256', $token);
        $st = $db->prepare("SELECT * FROM api_llaves WHERE llave_hash = ? LIMIT 1");
        $st->execute([$hash]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r && hash_equals((string)$r['llave_hash'], $hash) && $r['revocada_at'] === null
            && in_array($permiso, (array)json_decode((string)$r['permisos'], true), true)) {
            $fila = $r;
        }
    }
    if (!$fila) {
        igContadorSumar("ig.fallos:$ip", IG_VENTANA_FALLOS);
        igRegistrar($db, null, $endpoint, $numero, 'no_autorizado');
        igResponder(401, ['ok' => false, 'error' => 'no_autorizado']);
    }

    $id = (int)$fila['id'];
    if (igContadorSumar("ig.llave:$id", IG_VENTANA_LLAVE) > IG_LIMITE_LLAVE) {
        header('Retry-After: ' . IG_VENTANA_LLAVE);
        igRegistrar($db, $id, $endpoint, $numero, 'limite');
        igResponder(429, ['ok' => false, 'error' => 'demasiadas_peticiones']);
    }
    $db->prepare("UPDATE api_llaves SET ultimo_uso_at = NOW(), ultimo_uso_ip = ? WHERE id = ?")->execute([$ip, $id]);
    return $fila;
}

/** Usuario para la auditoría: quien creó la llave (o el primer admin activo). */
function igUsuarioAuditoria(PDO $db, array $llave): int {
    if (!empty($llave['creada_por'])) return (int)$llave['creada_por'];
    return (int)($db->query("SELECT id FROM usuarios WHERE rol = 'admin' AND activo = 1 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
}

/**
 * Bloqueada para la integración: aprobada en línea (igual que el panel), aceptada
 * o reemplazada por una versión nueva (el equivalente a "anulada": el sistema no
 * tiene ese estado). Las de la papelera no se encuentran (404).
 */
function igCotBloqueada(array $c): bool {
    return !empty($c['aprobada_at']) || in_array($c['estado'], ['Aceptada', 'Reemplazada'], true);
}
