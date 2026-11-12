<?php
/**
 * Reset de datos para pruebas desde cero.
 *
 * Vacía las tablas "transaccionales" (clientes, proyectos, cotizaciones,
 * pagos, sitios, accesos, auditoría, notificaciones) y reinicia sus
 * AUTO_INCREMENT a 1. Mantiene intactos:
 *   - usuarios
 *   - configuracion (incluye colores, branding, etc.)
 *   - servicios (catálogo)
 *   - plantillas_terminos
 *
 * Uso (sólo CLI, no se puede ejecutar desde el navegador):
 *
 *   php backend/scripts/reset_data.php
 *
 * Si querés además resetear el catálogo de servicios:
 *   php backend/scripts/reset_data.php --con-servicios
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script sólo puede ejecutarse por línea de comandos.\n");
}

require_once __DIR__ . '/../config.php';
$db = getDB();

$conServicios = in_array('--con-servicios', $argv ?? [], true);

// Orden importa: hijas antes que padres por las FK. Igual desactivamos
// FOREIGN_KEY_CHECKS para evitar problemas si hay relaciones cruzadas.
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
if ($conServicios) {
    $tablas[] = 'servicios';
}

echo "==================================================\n";
echo "  Reset de datos de prueba\n";
echo "  Base: " . DB_NAME . "\n";
echo "  Servicios: " . ($conServicios ? 'SE VACÍAN también' : 'se conservan') . "\n";
echo "==================================================\n\n";

echo "Vas a borrar TODO de las tablas listadas. Esto NO se puede deshacer.\n";
echo "Tablas a vaciar: " . implode(', ', $tablas) . "\n\n";
echo "Conserva: usuarios, configuracion, plantillas_terminos" . ($conServicios ? '' : ', servicios') . "\n\n";
echo "Escribí 'RESET' para confirmar: ";
$conf = trim(fgets(STDIN));
if ($conf !== 'RESET') {
    echo "Cancelado.\n";
    exit(1);
}

try {
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");

    foreach ($tablas as $t) {
        try {
            $db->exec("TRUNCATE TABLE `{$t}`");
            echo "  ✓ {$t}\n";
        } catch (Throwable $e) {
            // Tabla puede no existir en algunas instalaciones (ej: client_errors)
            echo "  · {$t} (saltada: " . $e->getMessage() . ")\n";
        }
    }

    // Reiniciar el contador de números de cotización (vive en configuracion).
    $db->prepare("INSERT INTO configuracion (clave, valor) VALUES ('cotizacion_siguiente', '1')
                  ON DUPLICATE KEY UPDATE valor = '1'")->execute();
    echo "  ✓ cotizacion_siguiente → 1\n";

    $db->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "\n✓ Listo. Las tablas vacías arrancarán los id desde 1 en el próximo INSERT.\n";
    echo "  Usuarios, configuración" . ($conServicios ? '' : ', servicios') . " y plantillas siguen intactos.\n";
} catch (Throwable $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    @$db->exec("SET FOREIGN_KEY_CHECKS = 1");
    exit(1);
}
