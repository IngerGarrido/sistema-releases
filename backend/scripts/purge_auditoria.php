<?php
/**
 * Purga de la tabla `auditoria`.
 *
 * La auditoría crece sin límite (cada acción registra una fila). Este script
 * elimina los registros más antiguos para que la tabla no se infle con los años.
 *
 * Lee de la tabla `configuracion`:
 *   - auditoria_retention_months  (entero, meses a conservar; default 12)
 *
 * Uso (cron mensual recomendado):
 *   0 4 1 * * /usr/bin/php /ruta/al/sistema-local/backend/scripts/purge_auditoria.php >> /ruta/cron.log 2>&1
 */
require_once __DIR__ . '/../config.php';

$db = getDB();

// Meses a conservar (configurable; mínimo 1 para no vaciar todo por error)
$months = 12;
try {
    $val = $db->query("SELECT valor FROM configuracion WHERE clave = 'auditoria_retention_months' LIMIT 1")->fetchColumn();
    if ($val !== false && $val !== null && $val !== '') {
        $months = max(1, (int)$val);
    }
} catch (Throwable $e) {
    // Si la clave no existe, usamos el default.
}

try {
    $stmt = $db->prepare("DELETE FROM auditoria WHERE created_at < DATE_SUB(NOW(), INTERVAL ? MONTH)");
    $stmt->execute([$months]);
    $borrados = $stmt->rowCount();
    echo "[purge_auditoria] OK: {$borrados} registro(s) eliminados (retención {$months} meses)\n";
} catch (Throwable $e) {
    echo "[purge_auditoria] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
