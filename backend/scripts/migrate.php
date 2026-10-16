<?php
/**
 * Runner de migraciones idempotente.
 *
 * - Lee archivos *.sql de `backend/migrations/`
 * - Registra los aplicados en tabla `schema_migrations`
 * - Solo aplica los que faltan
 *
 * Uso:
 *   php backend/scripts/migrate.php           # aplica pendientes
 *   php backend/scripts/migrate.php --status  # solo lista
 */
require_once __DIR__ . '/../config.php';

$db = getDB();

// Tabla de control
$db->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        version    VARCHAR(255) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        checksum   VARCHAR(64) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$migDir = realpath(__DIR__ . '/../migrations');
$files = glob($migDir . '/*.sql') ?: [];
sort($files); // orden alfabético = orden de aplicación

$applied = [];
foreach ($db->query("SELECT version FROM schema_migrations") as $r) {
    $applied[$r['version']] = true;
}

$statusOnly = in_array('--status', $argv ?? [], true);

if ($statusOnly) {
    echo "Migraciones:\n";
    foreach ($files as $f) {
        $v = basename($f);
        echo (isset($applied[$v]) ? '  [✓] ' : '  [ ] ') . $v . "\n";
    }
    exit(0);
}

$pending = array_filter($files, fn($f) => !isset($applied[basename($f)]));
if (empty($pending)) {
    echo "Sin migraciones pendientes.\n";
    exit(0);
}

echo "Aplicando " . count($pending) . " migración(es)...\n";
foreach ($pending as $f) {
    $v = basename($f);
    $sql = file_get_contents($f);
    $cs = hash('sha256', $sql);
    echo " - {$v} ... ";
    try {
        $db->beginTransaction();
        // mysqli no permite múltiples statements en prepared; PDO con exec() sí
        $db->exec($sql);
        $stmt = $db->prepare("INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)");
        $stmt->execute([$v, $cs]);
        $db->commit();
        echo "OK\n";
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo "FAIL: " . $e->getMessage() . "\n";
        exit(1);
    }
}
echo "Listo.\n";
