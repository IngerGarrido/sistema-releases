<?php
/**
 * Migraciones desde la terminal — usa el MISMO migrador que
 * Configuración → Sistema → Migraciones (backend/migrator.php, tabla
 * `migraciones`). Antes este script tenía su propio registro
 * (`schema_migrations`) y, si se corría, intentaba aplicar todo de nuevo.
 *
 * Uso:
 *   php backend/scripts/migrate.php           # aplica las pendientes (en orden)
 *   php backend/scripts/migrate.php --status  # solo lista
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../migrator.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI'); }

$db = getDB();
if (in_array('--status', $argv ?? [], true)) {
    $st = migrationStatus($db);
    foreach ($st['items'] as $it) echo ($it['aplicada'] ? '  [✓] ' : '  [ ] ') . $it['archivo'] . "\n";
    echo "{$st['aplicadas']} de {$st['total']} aplicadas\n";
    exit(0);
}
$r = runPendingMigrations($db);
echo implode("\n", $r['logs']) . "\n";
echo ($r['ok'] ? 'OK' : 'ERROR: ' . ($r['error'] ?? '')) . " — {$r['aplicadas']} aplicadas\n";
exit($r['ok'] ? 0 : 1);
