<?php
require_once __DIR__ . '/config.php';

$db = getDB();
$results = [];

// Email queue: eliminar enviados >30 días
try {
    $stmt = $db->prepare("DELETE FROM email_queue WHERE status='sent' AND sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    $results['email_queue_sent'] = $stmt->rowCount();
} catch (Throwable $e) {
    $results['email_queue_sent_error'] = $e->getMessage();
}

// Email queue: eliminar fallidos definitivos >90 días
try {
    $stmt = $db->prepare("DELETE FROM email_queue WHERE status='failed' AND attempts >= max_attempts AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $stmt->execute();
    $results['email_queue_failed'] = $stmt->rowCount();
} catch (Throwable $e) {
    $results['email_queue_failed_error'] = $e->getMessage();
}

// Login attempts: eliminar >30 días (esquema usa intento_en)
try {
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE intento_en < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    $results['login_attempts'] = $stmt->rowCount();
} catch (Throwable $e) {
    $results['login_attempts_error'] = $e->getMessage();
}

// Sesiones expiradas
try {
    $stmt = $db->prepare("DELETE FROM sesiones WHERE expira_en < NOW()");
    $stmt->execute();
    $results['sesiones_expiradas'] = $stmt->rowCount();
} catch (Throwable $e) {
    $results['sesiones_expiradas_error'] = $e->getMessage();
}

// Notificaciones leídas >60 días (pueden crecer sin control)
try {
    $stmt = $db->prepare("DELETE FROM notificaciones WHERE leida = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");
    $stmt->execute();
    $results['notificaciones_leidas'] = $stmt->rowCount();
} catch (Throwable $e) {
    $results['notificaciones_leidas_error'] = $e->getMessage();
}

// Notificaciones (cualquier estado) >180 días — probablemente ya no relevantes
try {
    $stmt = $db->prepare("DELETE FROM notificaciones WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)");
    $stmt->execute();
    $results['notificaciones_antiguas'] = $stmt->rowCount();
} catch (Throwable $e) {
    $results['notificaciones_antiguas_error'] = $e->getMessage();
}

echo json_encode(['ok' => true, 'cleaned' => $results]) . "\n";
