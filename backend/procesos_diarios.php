#!/usr/bin/env php
<?php
/**
 * Procesos automáticos diarios (lib/procesos.php → procesosDiarios):
 * mantenciones periódicas, entregas recibidas por plazo, aviso de garantía,
 * proyectos con acta recibida → Terminado, cotizaciones vencidas → Expirada,
 * avisos de cuotas vencidas y de respaldo.
 *
 * Uso:
 *   php procesos_diarios.php                    # con la fecha de hoy
 *   php procesos_diarios.php --hoy=2026-12-01   # forzar la fecha (pruebas)
 *
 * Cron (una vez al día, 08:00):
 *   0 8 * * * /usr/bin/php /ruta/backend/procesos_diarios.php >> /ruta/backend/logs/procesos.log 2>&1
 *
 * Si el hosting no tiene cron, igual corre al abrir el Panel o Entregas.
 * Es idempotente.
 */
require_once __DIR__ . '/lib/procesos.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI'); }

$hoy = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--hoy=(\d{4}-\d{2}-\d{2})$/', $a, $m)) $hoy = $m[1];
}
$r = procesosDiarios(getDB(), null, $hoy);
$t = '[' . cotAhora()->format('Y-m-d H:i:s') . ']' . ($hoy ? " (hoy forzado: $hoy)" : '');
$lista = fn(array $a) => $a ? implode(', ', $a) : 'ninguna';
echo "$t Mantenciones generadas: {$r['mantenciones']}\n";
echo "$t Renovaciones cobradas: {$r['renovaciones']}\n";
echo "$t Entregas recibidas por plazo: " . $lista($r['entregas_por_plazo']) . "\n";
echo "$t Garantías por terminar (aviso): " . $lista($r['garantias']) . "\n";
echo "$t Proyectos pasados a Terminado: {$r['proyectos_terminados']}\n";
echo "$t Cotizaciones expiradas: " . $lista($r['cotizaciones_expiradas']) . "\n";
foreach ($r['errores'] as $e) echo "$t ERROR $e\n";
