#!/usr/bin/env php
<?php
/**
 * Email Worker - Procesa la cola de emails
 * 
 * Uso:
 *   php email_worker.php              # Procesa batch por defecto (10 emails)
 *   php email_worker.php 20           # Procesa 20 emails
 *   php email_worker.php --stats      # Muestra estadísticas
 * 
 * Cron job (cada minuto):
 *   * * * * * cd /ruta/al/backend && php email_worker.php >> logs/email_worker.log 2>&1
 */

require_once __DIR__ . '/config.php';

// Solo ejecutar desde CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Solo CLI');
}

$args = $argv;
$command = $args[1] ?? 'process';
$batchSize = is_numeric($command) ? (int)$command : 10;

// Modo stats
if ($command === '--stats' || $command === '-s') {
    $stats = getEmailQueueStats();
    echo "Estadísticas de Cola de Emails\n";
    echo str_repeat('=', 40) . "\n";
    echo "Pendientes:  {$stats['pending']}\n";
    echo "Procesando:  {$stats['processing']}\n";
    echo "Enviados:    {$stats['sent']}\n";
    echo "Fallidos:    {$stats['failed']}\n";
    echo str_repeat('-', 40) . "\n";
    echo "Total:       " . array_sum($stats) . "\n";
    exit(0);
}

// Procesar cola
$startTime = microtime(true);
$results = processEmailQueue($batchSize);
$duration = round((microtime(true) - $startTime) * 1000, 2);

// Log de resultados
logInfo('Email worker completed', [
    'processed' => $results['processed'],
    'sent' => $results['sent'],
    'failed' => $results['failed'],
    'duration_ms' => $duration
]);

// Output
$timestamp = date('Y-m-d H:i:s');
echo "[$timestamp] Worker completado en {$duration}ms\n";
echo "  Procesados: {$results['processed']}\n";
echo "  Enviados:   {$results['sent']}\n";
echo "  Fallidos:   {$results['failed']}\n";

exit($results['failed'] > 0 ? 1 : 0);
