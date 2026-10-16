<?php
/**
 * Recibe errores capturados por ErrorBoundary del frontend.
 * No requiere auth (queremos capturar errores incluso de la pantalla de login),
 * pero está rate-limiteado y solo loggea — no devuelve nada útil al cliente.
 */
require_once __DIR__ . '/../config.php';
cors();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('Método no permitido', 405);

    rateLimit('client_errors', 30, 60); // 30 errores/min por IP máximo

    $b = body();
    $payload = [
        'message'        => substr((string)($b['message'] ?? ''), 0, 2000),
        'stack'          => substr((string)($b['stack'] ?? ''), 0, 8000),
        'componentStack' => substr((string)($b['componentStack'] ?? ''), 0, 4000),
        'url'            => substr((string)($b['url'] ?? ''), 0, 500),
        'userAgent'      => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
        'section'        => substr((string)($b['section'] ?? ''), 0, 80),
    ];

    // Intentar identificar al usuario si hay sesión
    try {
        $user = requireAuth();
        $payload['user_id'] = (int)$user['id'];
        $payload['user_email'] = $user['email'] ?? null;
    } catch (Throwable $e) {
        // sin sesión, OK
    }

    if (function_exists('logError')) {
        logError('Client error', $payload);
    } else {
        error_log('CLIENT_ERROR: ' . json_encode($payload));
    }

    ok(['received' => true]);
} catch (Throwable $e) {
    errSafe($e, 500, 'client_errors.php');
}
