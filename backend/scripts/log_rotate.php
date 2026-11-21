<?php
/**
 * Rotación de logs.
 *
 * - Si el log supera MAX_SIZE_MB, lo mueve a log.YYYY-MM-DD.gz y empieza uno nuevo.
 * - Mantiene los últimos KEEP archivos, borra el resto.
 *
 * Uso cron diario:
 *   0 4 * * * /usr/bin/php /ruta/backend/scripts/log_rotate.php
 */
require_once __DIR__ . '/../config.php';

const MAX_SIZE_MB = 10;
const KEEP        = 14;

$logFile = __DIR__ . '/../' . LOG_FILE;
if (!is_file($logFile)) {
    echo "Sin log para rotar: {$logFile}\n";
    exit(0);
}

$sizeMb = filesize($logFile) / 1024 / 1024;
if ($sizeMb < MAX_SIZE_MB) {
    echo "Log " . number_format($sizeMb, 2) . " MB < " . MAX_SIZE_MB . " MB, sin rotar.\n";
    // De todos modos limpiamos archivos viejos
    cleanup($logFile);
    exit(0);
}

$rotated = $logFile . '.' . date('Y-m-d_H-i-s');
if (!rename($logFile, $rotated)) {
    fwrite(STDERR, "ERROR: no se pudo rotar {$logFile}\n");
    exit(1);
}

// Comprimir
if (function_exists('gzopen')) {
    $gz = gzopen($rotated . '.gz', 'w9');
    $fh = fopen($rotated, 'r');
    while (!feof($fh)) gzwrite($gz, fread($fh, 65536));
    fclose($fh); gzclose($gz);
    unlink($rotated);
    $rotated .= '.gz';
}

// Crear el nuevo log vacío
touch($logFile);
chmod($logFile, 0640);

echo "Rotado a " . basename($rotated) . " (" . number_format($sizeMb, 1) . " MB)\n";
cleanup($logFile);

function cleanup(string $logFile): void {
    $files = glob($logFile . '.*') ?: [];
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    foreach (array_slice($files, KEEP) as $old) {
        if (is_file($old)) { unlink($old); echo "Purgado: " . basename($old) . "\n"; }
    }
}
