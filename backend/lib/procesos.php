<?php
/**
 * Procesos automáticos del sistema: TODO lo que el sistema hace solo, en un
 * único lugar. Antes estaba repartido entre dashboard.php, entregas.php, el
 * cron de entregas y el Panel (frontend).
 *
 * procesosDiarios() es idempotente: se puede llamar en cada carga del Panel y
 * desde el cron (backend/procesos_diarios.php) sin duplicar nada.
 *
 *  1. Mantención periódica y renovaciones de dominio/hosting con monto →
 *     proyecto + cobro (mantenciones.php)
 *  2. Entregas: actas con plazo vencido → recibidas por plazo; aviso de garantía
 *  3. Proyectos con acta recibida → Terminado
 *  4. Cotizaciones sin respuesta con la validez vencida → Expirada
 *  5. Avisos de la campana que dependen de datos (cuotas vencidas, respaldo):
 *     se crean una vez y se borran solos cuando ya no aplican.
 *  6. Papelera: lo que lleva más de PAPELERA_DIAS días se borra para siempre
 *     (solo si no tiene nada activo colgando).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../mantenciones.php';
require_once __DIR__ . '/entregas.php';
require_once __DIR__ . '/saldos.php';

/** Días sin respaldo antes de avisar. */
const PROC_BACKUP_DIAS = 7;

/** Días en la papelera antes de borrar para siempre (también lo usa Revisar datos). */
const PAPELERA_DIAS = 30;

/**
 * @return array resumen de lo hecho (para el log del cron y el Panel)
 */
function procesosDiarios(PDO $db, ?int $usuarioId = null, ?string $hoy = null): array {
    $hoy = $hoy ?: cotAhora()->format('Y-m-d');
    $r = ['mantenciones' => 0, 'renovaciones' => 0, 'entregas_por_plazo' => [], 'garantias' => [], 'proyectos_terminados' => 0,
          'cotizaciones_expiradas' => [], 'errores' => []];
    $paso = function (string $nombre, callable $fn) use (&$r) {
        try { $fn(); } catch (Throwable $e) { $r['errores'][] = "$nombre: " . $e->getMessage(); }
    };

    $paso('mantenciones', function () use ($db, $usuarioId, $hoy, &$r) {
        $uid = $usuarioId ?? procUsuarioSistema($db);
        $r['mantenciones'] = generarMantencionesMensuales($db, $uid);
        $r['renovaciones'] = generarRenovaciones($db, $uid, $hoy);
    });
    $paso('entregas', function () use ($db, $hoy, &$r) {
        entRecordarUrl($db);
        $r['entregas_por_plazo'] = entProcesarPlazos($db, $hoy);
        $r['garantias'] = entProcesarGarantias($db, $hoy);
    });
    $paso('proyectos', function () use ($db, &$r) { $r['proyectos_terminados'] = entTerminarProyecto($db); });
    $paso('cotizaciones', function () use ($db, $hoy, &$r) { $r['cotizaciones_expiradas'] = procExpirarCotizaciones($db, $hoy); });
    $paso('aviso_vencidas', function () use ($db, $hoy) { procAvisoCuotasVencidas($db, $hoy); });
    $paso('aviso_backup', function () use ($db) { procAvisoBackup($db); });
    $paso('papelera', function () use ($db, $hoy, &$r) {
        $r['papelera_vaciada'] = procVaciarPapeleraAntigua($db, $hoy);
        if ($r['papelera_vaciada'] > 0) {
            $n = $r['papelera_vaciada'];
            procNotificar($db, 'info', "Se borraron para siempre {$n} " . ($n === 1 ? 'elemento' : 'elementos')
                . ' con más de ' . PAPELERA_DIAS . ' días en la papelera.', 'papelera');
        }
    });
    return $r;
}

/**
 * Borra para siempre lo que lleva más de PAPELERA_DIAS días en la papelera.
 * Ojo con las cascadas de la base: borrar un cliente se lleva sus proyectos,
 * sitios y cotizaciones (aunque estén activos). Por eso solo se borra lo que
 * no tiene nada activo colgando; eso queda para "Revisar datos".
 * @return int filas borradas
 */
function procVaciarPapeleraAntigua(PDO $db, ?string $hoy = null): int {
    $hoy = $hoy ?: cotAhora()->format('Y-m-d');
    $limite = date('Y-m-d H:i:s', strtotime("$hoy -" . PAPELERA_DIAS . ' days'));
    $viejo = "deleted_at IS NOT NULL AND deleted_at < " . $db->quote($limite);
    $n = 0;
    // Pagos (sus cuotas y abonos caen por cascada).
    $n += $db->exec("DELETE FROM pagos WHERE $viejo");
    // Cotizaciones (ítems por cascada).
    $n += $db->exec("DELETE FROM cotizaciones WHERE $viejo");
    // Proyectos sin pagos activos; sus líneas de servicio no tienen cascada.
    $ids = $db->query("SELECT p.id FROM proyectos p WHERE p.$viejo
                       AND NOT EXISTS (SELECT 1 FROM pagos pg WHERE pg.proyecto_id = p.id AND pg.deleted_at IS NULL)")
              ->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $db->exec("DELETE FROM proyecto_servicios WHERE proyecto_id IN ($in)");
        $n += $db->exec("DELETE FROM proyectos WHERE id IN ($in)");
    }
    // Sitios (accesos por cascada; proyectos quedan sin sitio).
    $n += $db->exec("DELETE FROM sitios WHERE $viejo");
    // Servicios que ya no usa ningún proyecto ni cotización.
    $n += $db->exec("DELETE FROM servicios WHERE $viejo
                     AND NOT EXISTS (SELECT 1 FROM proyecto_servicios ps WHERE ps.servicio_id = servicios.id)
                     AND NOT EXISTS (SELECT 1 FROM cotizacion_items ci WHERE ci.servicio_id = servicios.id)");
    // Clientes sin nada (activo o no) que la cascada se llevaría.
    $n += $db->exec("DELETE FROM clientes WHERE $viejo
                     AND NOT EXISTS (SELECT 1 FROM proyectos p WHERE p.cliente_id = clientes.id)
                     AND NOT EXISTS (SELECT 1 FROM sitios s WHERE s.cliente_id = clientes.id)
                     AND NOT EXISTS (SELECT 1 FROM cotizaciones c WHERE c.cliente_id = clientes.id)
                     AND NOT EXISTS (SELECT 1 FROM pagos pg WHERE pg.cliente_id = clientes.id)");
    return $n;
}

/** Usuario para la auditoría cuando corre el cron: el primer admin activo. */
function procUsuarioSistema(PDO $db): int {
    return (int)($db->query("SELECT id FROM usuarios WHERE rol = 'admin' AND activo = 1 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
}

/**
 * Cotizaciones Pendiente/Enviada sin aprobar cuya validez ya pasó → Expirada.
 * No toca las que se cambiaron a mano DESPUÉS de vencer (updated_at posterior
 * a la validez): así Inger puede reactivar o aceptar una vencida sin que el
 * sistema la vuelva a expirar. Avisa una vez en la campana.
 * @return string[] números de las cotizaciones expiradas
 */
function procExpirarCotizaciones(PDO $db, string $hoy): array {
    $st = $db->prepare("SELECT id, numero FROM cotizaciones
                        WHERE deleted_at IS NULL AND estado IN ('Pendiente', 'Enviada')
                          AND fecha_validez IS NOT NULL AND fecha_validez < ?
                          AND (updated_at IS NULL OR updated_at < DATE_ADD(fecha_validez, INTERVAL 1 DAY))
                          AND aprobada_at IS NULL");
    $st->execute([$hoy]);
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$filas) return [];
    $up = $db->prepare("UPDATE cotizaciones SET estado = 'Expirada' WHERE id = ? AND estado IN ('Pendiente', 'Enviada')");
    $nums = [];
    foreach ($filas as $f) {
        $up->execute([(int)$f['id']]);
        if ($up->rowCount() === 1) $nums[] = $f['numero'];
    }
    if ($nums) {
        $n = count($nums);
        procNotificar($db, 'info',
            ($n === 1 ? "La cotización {$nums[0]} expiró" : "$n cotizaciones expiraron") . ' sin respuesta del cliente: ¿hacer seguimiento?',
            'cotizaciones');
    }
    return $nums;
}

/**
 * "N cuotas vencidas por cobrar" (misma regla que el Panel, lib/saldos.php).
 * Una vez por día; si ya no hay vencidas se borra el aviso sin leer.
 */
function procAvisoCuotasVencidas(PDO $db, string $hoy): void {
    $res = saldoResumen(saldosCuotas($db));
    $patron = '%vencida% por cobrar%';
    if ($res['vencidas'] <= 0) {
        $db->prepare("DELETE FROM notificaciones WHERE leida = 0 AND link = 'pagos' AND mensaje LIKE ?")->execute([$patron]);
        return;
    }
    if (procConfig($db, 'aviso_vencidas_fecha') === $hoy) return;
    $n = $res['vencidas'];
    $msg = "$n cuota" . ($n === 1 ? ' vencida' : 's vencidas') . ' por cobrar ($' . number_format($res['vencido'], 0, ',', '.') . ')';
    // Reemplaza el aviso anterior sin leer (no se acumulan)
    $db->prepare("DELETE FROM notificaciones WHERE leida = 0 AND link = 'pagos' AND mensaje LIKE ?")->execute([$patron]);
    procNotificar($db, 'warning', $msg, 'pagos');
    procConfigSet($db, 'aviso_vencidas_fecha', $hoy);
}

/**
 * Avisa si no hay un respaldo de la base en los últimos PROC_BACKUP_DIAS días
 * (reemplaza el "Recuerda: haz backup" semanal, que avisaba aunque hubiera).
 */
function procAvisoBackup(PDO $db): void {
    $dir = __DIR__ . '/../backups';
    $ultimo = 0;
    foreach (glob($dir . '/*.sql') ?: [] as $f) $ultimo = max($ultimo, (int)filemtime($f));
    $msg = 'No hay un respaldo de la base de datos en los últimos ' . PROC_BACKUP_DIAS . ' días';
    if ($ultimo >= time() - PROC_BACKUP_DIAS * 86400) {
        $db->prepare("DELETE FROM notificaciones WHERE leida = 0 AND mensaje = ?")->execute([$msg]);
        return;
    }
    procNotificar($db, 'warning', $msg, 'configuracion:sistema');
}

/** Crea un aviso en la campana si no hay uno igual sin leer. */
function procNotificar(PDO $db, string $tipo, string $msg, ?string $link = null): void {
    $msg = mb_substr($msg, 0, 500);
    $st = $db->prepare("SELECT 1 FROM notificaciones WHERE leida = 0 AND mensaje = ? LIMIT 1");
    $st->execute([$msg]);
    if ($st->fetchColumn()) return;
    $db->prepare("INSERT INTO notificaciones (usuario_id, tipo, mensaje, link) VALUES (NULL, ?, ?, ?)")->execute([$tipo, $msg, $link]);
}

function procConfig(PDO $db, string $clave): ?string {
    $st = $db->prepare("SELECT valor FROM configuracion WHERE clave = ?");
    $st->execute([$clave]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function procConfigSet(PDO $db, string $clave, string $valor): void {
    $db->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?")->execute([$clave, $valor, $valor]);
}
