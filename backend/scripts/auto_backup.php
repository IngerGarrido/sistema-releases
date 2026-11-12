<?php
/**
 * Auto-backup programado.
 *
 * Lee de la tabla `configuracion`:
 *   - backup_auto_enabled    (0/1)
 *   - backup_auto_freq_days  (entero, frecuencia en días, default 7)
 *   - backup_auto_retention  (entero, cantidad de backups a mantener, default 5)
 *   - last_auto_backup_at    (datetime, se actualiza tras cada corrida)
 *
 * Uso (cron diario recomendado):
 *   0 3 * * * /usr/bin/php /ruta/al/sistema-local/backend/scripts/auto_backup.php >> /ruta/cron.log 2>&1
 */

require_once __DIR__ . '/../config.php';

function generateBackupSQLInline(PDO $db, string $dbName): string {
    $sql  = "-- Backup SQL (auto)\n-- Base: `{$dbName}`\n-- Fecha: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\nSET time_zone = \"+00:00\";\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tables = $db->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $tableRow) {
        $table = $tableRow[0];
        $qt = '`' . str_replace('`', '``', $table) . '`';
        $sql .= "DROP TABLE IF EXISTS {$qt};\n";
        $create = $db->query('SHOW CREATE TABLE ' . $qt)->fetch(PDO::FETCH_ASSOC);
        $sql .= ($create['Create Table'] ?? array_values($create)[1]) . ";\n\n";

        $stmt = $db->query('SELECT * FROM ' . $qt);
        $cols = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $cols[] = '`' . str_replace('`', '``', $stmt->getColumnMeta($i)['name']) . '`';
        }
        $batch = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string)$v), $row);
            $batch[] = '(' . implode(', ', $vals) . ')';
            if (count($batch) >= 100) {
                $sql .= 'INSERT INTO ' . $qt . ' (' . implode(', ', $cols) . ") VALUES\n" . implode(",\n", $batch) . ";\n";
                $batch = [];
            }
        }
        if (!empty($batch)) {
            $sql .= 'INSERT INTO ' . $qt . ' (' . implode(', ', $cols) . ") VALUES\n" . implode(",\n", $batch) . ";\n";
        }
        $sql .= "\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $sql;
}

$db = getDB();

$cfg = [];
foreach ($db->query("SELECT clave, valor FROM configuracion")->fetchAll() as $r) {
    $cfg[$r['clave']] = $r['valor'];
}

if (empty($cfg['backup_auto_enabled']) || $cfg['backup_auto_enabled'] === '0') {
    echo "[auto_backup] deshabilitado\n";
    exit(0);
}

$freqDays  = max(1, (int)($cfg['backup_auto_freq_days'] ?? 7));
$retention = max(1, (int)($cfg['backup_auto_retention'] ?? 5));

$lastAt = $cfg['last_auto_backup_at'] ?? null;
if ($lastAt) {
    if (time() - strtotime($lastAt) < $freqDays * 86400) {
        echo "[auto_backup] aún no toca (último " . $lastAt . ", freq " . $freqDays . "d)\n";
        exit(0);
    }
}

$dbName    = DB_NAME;
$backupDir = __DIR__ . '/../backups';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

$filename = 'auto_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $dbName) . '_' . date('Y-m-d_H-i-s') . '.sql';
$filepath = $backupDir . '/' . $filename;

$sql   = generateBackupSQLInline($db, $dbName);
$bytes = file_put_contents($filepath, $sql, LOCK_EX);
if ($bytes === false) {
    echo "[auto_backup] ERROR al escribir " . $filepath . "\n";
    exit(1);
}

$now  = date('Y-m-d H:i:s');
$stmt = $db->prepare("INSERT INTO configuracion (clave,valor) VALUES ('last_auto_backup_at',?) ON DUPLICATE KEY UPDATE valor=?");
$stmt->execute([$now, $now]);

// Retención
$autos = glob($backupDir . '/auto_*.sql');
usort($autos, fn($a, $b) => filemtime($b) - filemtime($a));
foreach (array_slice($autos, $retention) as $old) {
    if (is_file($old)) { @unlink($old); echo "[auto_backup] purgado: " . basename($old) . "\n"; }
}

echo "[auto_backup] OK: " . $filename . " (" . number_format($bytes / 1024, 1) . " KB)\n";
