<?php
/**
 * Mantención periódica automática por sitio.
 *
 * Cada sitio con `mantencion_monto` > 0 tiene una frecuencia (cada 1, 3, 6 o
 * 12 meses) y una fecha de próximo cobro (`mantencion_proxima`). Cuando esa
 * fecha llega, se crea el proyecto "Mantención <mes año>" asociado al sitio,
 * con una línea libre por el monto y su COBRO PENDIENTE en Pagos (mismo
 * formato que un proyecto creado a mano: la plata vive en Pagos), y la fecha
 * avanza según la frecuencia.
 *
 * Si la fecha quedó atrás varios períodos (ej: nadie abrió el sistema), se
 * genera UNA mantención y la próxima fecha salta al primer período futuro:
 * una mantención que no se hizo no se cobra.
 *
 * Idempotente: la fecha se avanza con un compare-and-swap (WHERE proxima =
 * valor leído) dentro de la misma transacción, así dos visitas simultáneas al
 * Panel no duplican el cobro. Se invoca desde dashboard.php (sin cron).
 */

require_once __DIR__ . '/config.php';

const MESES_ES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                  'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** Frecuencias permitidas (meses) → etiqueta. */
const MANTENCION_FRECUENCIAS = [1 => 'mensual', 3 => 'trimestral', 6 => 'semestral', 12 => 'anual'];

/**
 * Suma $meses a una fecha conservando el día ancla (31 → 30/28 en meses cortos
 * y vuelve a 31 cuando el mes lo permite).
 */
function sumarMesesAncla(string $fecha, int $meses, int $diaAncla): string {
    $d = new DateTimeImmutable($fecha);
    $base = $d->modify('first day of this month')->modify("+{$meses} months");
    $dia = min(max(1, $diaAncla), (int)$base->format('t'));
    return $base->format('Y-m-') . str_pad((string)$dia, 2, '0', STR_PAD_LEFT);
}

/** @return int cantidad de mantenciones generadas */
function generarMantencionesMensuales(PDO $db, int $usuarioId): int {
    // Sin la migración 2026_11_07_2 no hay nada que hacer.
    if (!$db->query("SHOW COLUMNS FROM sitios LIKE 'mantencion_proxima'")->fetch()) return 0;

    $hoy = date('Y-m-d');
    $stmt = $db->prepare("
        SELECT s.id, s.cliente_id, s.nombre, s.mantencion_monto, s.mantencion_frecuencia,
               s.mantencion_proxima, s.mantencion_dia
        FROM sitios s
        JOIN clientes c ON c.id = s.cliente_id AND c.deleted_at IS NULL
        WHERE s.deleted_at IS NULL
          AND s.mantencion_monto > 0
          AND s.mantencion_proxima IS NOT NULL
          AND s.mantencion_proxima <= ?
    ");
    $stmt->execute([$hoy]);
    $sitios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $creados = 0;
    foreach ($sitios as $s) {
        try {
            $frec  = array_key_exists((int)$s['mantencion_frecuencia'], MANTENCION_FRECUENCIAS) ? (int)$s['mantencion_frecuencia'] : 1;
            $ancla = (int)$s['mantencion_dia'] ?: (int)date('j', strtotime($s['mantencion_proxima']));
            $vence = $s['mantencion_proxima'];

            // Siguiente fecha: primer período posterior a hoy.
            $siguiente = sumarMesesAncla($vence, $frec, $ancla);
            while ($siguiente <= $hoy) $siguiente = sumarMesesAncla($siguiente, $frec, $ancla);

            $db->beginTransaction();
            // Compare-and-swap: si otra petición ya avanzó la fecha, rowCount = 0.
            $claim = $db->prepare("UPDATE sitios SET mantencion_proxima = ?, mantencion_ultimo_periodo = ?
                                   WHERE id = ? AND mantencion_proxima = ?");
            $claim->execute([$siguiente, substr($vence, 0, 7), $s['id'], $vence]);
            if ($claim->rowCount() === 0) { $db->rollBack(); continue; }

            $monto  = (int)$s['mantencion_monto'];
            $ts     = strtotime($vence);
            $nombre = 'Mantención ' . MESES_ES[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);

            $db->prepare("INSERT INTO proyectos (cliente_id, sitio_id, nombre, descripcion, tipo_proyecto, estado, fecha_inicio, fecha_termino, presupuesto, created_at)
                          VALUES (?, ?, ?, ?, 'Mantención', 'En curso', ?, ?, ?, NOW())")
               ->execute([(int)$s['cliente_id'], (int)$s['id'], $nombre,
                          'Generado automáticamente: mantención ' . MANTENCION_FRECUENCIAS[$frec] . ' del sitio ' . $s['nombre'] . '.',
                          $vence, date('Y-m-t', $ts), $monto]);
            $proyectoId = (int)$db->lastInsertId();

            $db->prepare("INSERT INTO proyecto_servicios (proyecto_id, servicio_id, nombre, cantidad, precio_unitario, orden)
                          VALUES (?, NULL, ?, 1, ?, 0)")
               ->execute([$proyectoId, 'Mantención ' . MANTENCION_FRECUENCIAS[$frec], $monto]);

            $db->prepare("INSERT INTO pagos (cliente_id, proyecto_id, descripcion, monto_total, tipo_pago)
                          VALUES (?, ?, ?, ?, 'boleta')")
               ->execute([(int)$s['cliente_id'], $proyectoId, $nombre, $monto]);
            $pagoId = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO pago_cuotas (pago_id, numero_cuota, boleta_monto, pago_monto, estado, tipo_pago)
                          VALUES (?, 1, ?, 0, 'Pendiente', 'boleta')")
               ->execute([$pagoId, $monto]);

            audit($db, $usuarioId, 'CREATE', 'proyectos',
                  "Mantención automática {$vence} del sitio #{$s['id']} ({$s['nombre']}); próxima {$siguiente}", $proyectoId);
            $db->commit();
            $creados++;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            logError('No se pudo generar mantención periódica', ['sitio_id' => $s['id'], 'error' => $e->getMessage()]);
        }
    }
    return $creados;
}
