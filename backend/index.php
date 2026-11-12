<?php
require_once __DIR__ . '/config.php';
cors();

$method   = $_SERVER['REQUEST_METHOD'];
$path     = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$path     = preg_replace('#^.*?(backend|api)/?#i', '', $path);
$seg      = array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));
$resource = $seg[0] ?? '';
$seg1     = $seg[1] ?? null;
$seg2     = $seg[2] ?? null;
$id       = ($seg1 !== null && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
$action   = ($seg1 !== null && !ctype_digit((string)$seg1)) ? $seg1 : ($seg2 ?? null);

$rutasPublicas = ['auth'];
if (!in_array($resource, $rutasPublicas)) {
    requireAuth();
}

try {
    match ($resource) {
        'auth'          => require __DIR__ . '/api/auth.php',
        'usuarios'      => require __DIR__ . '/api/usuarios.php',
        'clientes'      => require __DIR__ . '/api/clientes.php',
        'proyectos'     => require __DIR__ . '/api/proyectos.php',
        'cotizaciones'  => require __DIR__ . '/api/cotizaciones.php',
        'pagos'         => require __DIR__ . '/api/pagos.php',
        'configuracion' => require __DIR__ . '/api/configuracion.php',
        'dashboard'     => require __DIR__ . '/api/dashboard.php',
        'exports'       => require __DIR__ . '/api/exports.php',
        'servicios'     => require __DIR__ . '/api/servicios.php',
        default         => err("Ruta no encontrada: /$resource", 404),
    };
} catch (PDOException $e) {
    err('Error base de datos: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    err('Error interno: ' . $e->getMessage(), 500);
}
