<?php
/**
 * Reset de datos para empezar de cero (sin tocar usuarios ni config).
 *
 * Endpoint web equivalente a backend/scripts/reset_data.php — pensado para
 * hostings sin SSH (DirectAdmin, cPanel compartido) donde no se puede
 * ejecutar por CLI.
 *
 * Protegido por:
 *  - requireAuth (sesión activa)
 *  - rol = 'admin'
 *  - confirmación textual ("RESET")
 *  - method = POST (no GET, para no dispararlo desde la URL)
 */
require_once __DIR__ . '/../config.php';
cors();
$db = getDB();
$user = requireAuth();

if (($user['rol'] ?? '') !== 'admin') {
    err('No autorizado', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    err('Solo se permite POST', 405);
}

$b = body();
if (($b['confirmar'] ?? '') !== 'RESET') {
    err('Confirmación inválida. Escribí "RESET" para ejecutar.', 400);
}

$conServicios = !empty($b['con_servicios']);

// Orden importa por FKs, igual desactivamos checks por seguridad.
$tablas = [
    'pago_cuotas',
    'pagos',
    'cotizacion_items',
    'cotizaciones',
    'accesos',
    'sitios',
    'proyectos',
    'clientes',
    'auditoria',
    'notificaciones',
    'client_errors',
];
if ($conServicios) $tablas[] = 'servicios';

try {
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");

    $vacias = [];
    foreach ($tablas as $t) {
        try {
            $db->exec("TRUNCATE TABLE `{$t}`");
            $vacias[] = $t;
        } catch (Throwable $_) {
            // Tabla puede no existir en algunas instalaciones; ignorar.
        }
    }

    // Reiniciar el contador de números de cotización.
    $db->prepare("INSERT INTO configuracion (clave, valor) VALUES ('cotizacion_siguiente', '1')
                  ON DUPLICATE KEY UPDATE valor = '1'")->execute();

    $db->exec("SET FOREIGN_KEY_CHECKS = 1");

    // Después de TRUNCATE de auditoria, registramos esta acción para dejar huella.
    try { audit($db, (int)$user['id'], 'RESET_DATA', 'all', 'Reset de datos: ' . implode(',', $vacias)); } catch (Throwable $_) {}

    ok(['vacias' => $vacias, 'con_servicios' => $conServicios]);
} catch (Throwable $e) {
    @$db->exec("SET FOREIGN_KEY_CHECKS = 1");
    errSafe($e, 500);
}
