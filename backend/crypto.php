<?php
// ============================================================
// Cifrado simétrico AES-256-GCM para datos sensibles en BD
// (accesos a sitios de clientes, tokens, etc.)
//
// La clave maestra se lee desde APP_KEY en .env (hex de 64 chars / 32 bytes).
// El installer la genera automáticamente. NO commitear .env.
// ============================================================

require_once __DIR__ . '/config.php';

function appKey(): string {
    static $key = null;
    if ($key !== null) return $key;
    $hex = getenv('APP_KEY') ?: '';
    if (!$hex || !ctype_xdigit($hex) || strlen($hex) !== 64) {
        throw new RuntimeException('APP_KEY inválida o ausente en .env (esperado: 64 chars hex)');
    }
    $key = hex2bin($hex);
    return $key;
}

/**
 * Cifra texto plano. Devuelve string base64 con formato:
 *   v1:<iv_b64>:<tag_b64>:<cipher_b64>
 * v1 = AES-256-GCM
 */
function encryptStr(?string $plaintext): ?string {
    if ($plaintext === null || $plaintext === '') return null;
    $key = appKey();
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false) {
        throw new RuntimeException('Cifrado falló');
    }
    return 'v1:' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($cipher);
}

/**
 * Descifra string producido por encryptStr(). Devuelve null si la entrada es vacía/null.
 * Lanza RuntimeException si el formato es inválido o la autenticación falla.
 */
function decryptStr(?string $payload): ?string {
    if ($payload === null || $payload === '') return null;
    $parts = explode(':', $payload, 4);
    if (count($parts) !== 4 || $parts[0] !== 'v1') {
        throw new RuntimeException('Payload cifrado con formato inválido');
    }
    $iv     = base64_decode($parts[1], true);
    $tag    = base64_decode($parts[2], true);
    $cipher = base64_decode($parts[3], true);
    if ($iv === false || $tag === false || $cipher === false) {
        throw new RuntimeException('Payload cifrado corrupto');
    }
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', appKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        throw new RuntimeException('Descifrado falló (clave incorrecta o datos manipulados)');
    }
    return $plain;
}
