<?php
require_once __DIR__ . '/../config.php';
cors();

$db = getDB();
$user = requireAuth();

if ($user['rol'] !== 'admin') {
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

try {
    $db->beginTransaction();
    
    // 1. Limpiar cuotas que pertenecen a pagos que ya no existen
    $q1 = $db->exec("DELETE FROM pago_cuotas WHERE pago_id NOT IN (SELECT id FROM pagos)");
    
    // 2. Limpiar pagos que pertenecen a proyectos que ya no existen (si tienen proyecto_id)
    $q2 = $db->exec("DELETE FROM pagos WHERE proyecto_id IS NOT NULL AND proyecto_id NOT IN (SELECT id FROM proyectos)");
    
    // 3. AUTO-LIMPIEZA: Eliminar elementos en papelera hace más de 30 días
    $q3a = $db->exec("DELETE FROM clientes WHERE deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $q3b = $db->exec("DELETE FROM proyectos WHERE deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $q3c = $db->exec("DELETE FROM cotizaciones WHERE deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $q3d = $db->exec("DELETE FROM pagos WHERE deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $q3e = $db->exec("DELETE FROM servicios WHERE deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $totalPapelera = $q3a + $q3b + $q3c + $q3d + $q3e;

    $db->commit();
    
    echo json_encode([
        'ok' => true, 
        'mensaje' => "Limpieza completada: $q1 cuotas y $q2 pagos huérfanos eliminados. Papelera: $totalPapelera elementos antiguos eliminados permanentemente."
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    errSafe($e, 500);
}