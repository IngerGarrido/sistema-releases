<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../migrator.php';
    cors();
    $db = getDB();
    $user = requireAuth();

    if ($user['rol'] !== 'admin') {
        err('Solo administradores pueden gestionar migraciones', 403);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $status = migrationStatus($db);
        ok($status);
    }

    if ($method === 'POST') {
        // Rate limit para evitar ejecución concurrente accidental
        rateLimit('migraciones.run', 5, 60);
        $result = runPendingMigrations($db);
        audit($db, $user['id'], 'MIGRATE', 'migraciones',
              ($result['ok'] ? 'Aplicadas ' : 'FALLÓ tras aplicar ') . $result['aplicadas'] . ' migraciones', null);
        if (!$result['ok']) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Error', 'logs' => $result['logs'], 'aplicadas' => $result['aplicadas']]);
            exit;
        }
        ok([
            'logs' => $result['logs'],
            'aplicadas' => $result['aplicadas'],
            'status' => migrationStatus($db),
        ]);
    }

    err('Método no permitido', 405);

} catch (Throwable $e) {
    errSafe($e, 500, 'admin/migraciones');
}
