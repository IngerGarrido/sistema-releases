<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . FRONTEND_URL);

$status = ['ok' => true, 'timestamp' => date('c'), 'checks' => []];
$httpCode = 200;

// Check DB
try {
    $db = getDB();
    $db->query('SELECT 1');
    $status['checks']['db'] = 'up';
} catch (Throwable $e) {
    $status['ok'] = false;
    $status['checks']['db'] = 'down';
    $httpCode = 503;
}

// Check disk space (warn si <10% libre)
$free = @disk_free_space(__DIR__);
$total = @disk_total_space(__DIR__);
if ($total && $total > 0) {
    $pctFree = round(($free / $total) * 100, 1);
    $status['checks']['disk_free_pct'] = $pctFree;
    if ($pctFree < 10) {
        $status['ok'] = false;
        $httpCode = 503;
    }
}

// Version
$status['version'] = '5.0';

http_response_code($httpCode);
echo json_encode($status);
