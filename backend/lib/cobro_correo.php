<?php
/**
 * Recordatorio de cobro por correo: una boleta emitida con saldo que el cliente
 * aún no paga. Lo envía Inger con un clic desde el Panel o la ficha del cliente
 * (nunca sale solo). El saldo y la regla de «emitida» vienen de lib/saldos.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/saldos.php';
require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/cotizacion_doc.php';     // cotH, cotClp, cotFecha, cotConfig, cotAhora
require_once __DIR__ . '/cotizacion_correo.php';  // cotEmpresa, cotSaludoNombre

/** Días mínimos entre dos recordatorios de la misma boleta. */
const COBRO_RECORDAR_CADA_DIAS = 3;

/**
 * Datos del recordatorio de una cuota: a quién, qué boleta, cuánto y el texto.
 * @return ?array null si la cuota no existe, no está emitida o no tiene saldo
 */
function cobroRecordatorio(PDO $db, int $cuotaId): ?array {
    $cuotas = saldosCuotas($db, 'pc.id = ?', [$cuotaId]);
    $q = $cuotas[0] ?? null;
    if (!$q || !$q['emitida'] || $q['saldo'] <= 0) return null;

    $st = $db->prepare("SELECT email, nombre, nombre_agencia FROM clientes WHERE id = ?");
    $st->execute([$q['cliente_id']]);
    $cli = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $rec = ['recordado_at' => null, 'recordatorios' => 0];
    try {
        $st = $db->prepare("SELECT recordado_at, recordatorios FROM pago_cuotas WHERE id = ?");
        $st->execute([$cuotaId]);
        $rec = $st->fetch(PDO::FETCH_ASSOC) ?: $rec;
    } catch (Throwable $e) { /* sin la migración: se envía igual, sin registro */ }

    $trabajo = etiquetaProyecto($q['proyecto_nombre'] ?: '', $q['sitio_nombre']);
    $boleta = trim((string)$q['boleta_num']);
    $contacto = trim((string)($cli['nombre'] ?? '')) ?: (string)$q['cliente_nombre'];
    $saludo = cotSaludoNombre($contacto) ?: $contacto;
    $texto = ($boleta !== '' ? "Te escribo por la boleta N.º $boleta" : 'Te escribo por el pago')
           . ($q['boleta_fecha'] ? ' del ' . cotFecha($q['boleta_fecha']) : '')
           . ($trabajo !== '' ? ", de $trabajo" : '')
           . ', que tiene un saldo pendiente de ' . cotClp($q['saldo']) . '.'
           . "\n\nSi ya la pagaste, avísame para registrarlo. Y si necesitas los datos de transferencia o prefieres coordinar otra fecha, respóndeme a este correo.";

    return [
        'cuota_id' => $cuotaId, 'pago_id' => $q['pago_id'], 'cliente_id' => $q['cliente_id'],
        'to' => trim((string)($cli['email'] ?? '')), 'contacto' => $contacto, 'saludo' => $saludo,
        'cliente' => $q['cliente_nombre'], 'trabajo' => $trabajo, 'boleta' => $boleta,
        'boleta_fecha' => $q['boleta_fecha'], 'saldo' => $q['saldo'], 'dias' => $q['dias'],
        'texto' => $texto,
        'recordado_at' => $rec['recordado_at'] ?? null, 'recordatorios' => (int)($rec['recordatorios'] ?? 0),
    ];
}

/** Envía el recordatorio. Lanza RuntimeException con un mensaje para mostrar si no se puede. */
function cobroRecordar(PDO $db, int $cuotaId, ?string $to = null, ?string $texto = null): array {
    $r = cobroRecordatorio($db, $cuotaId);
    if (!$r) throw new RuntimeException('Esa cuota ya no tiene saldo por cobrar.');
    $to = trim((string)($to ?? $r['to']));
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) throw new RuntimeException('Escribe el correo del cliente.');
    $texto = trim((string)($texto ?? '')) ?: $r['texto'];
    if (mb_strlen($texto) > 2000) throw new RuntimeException('El mensaje es muy largo (máximo 2.000 caracteres).');

    $cfg = cotConfig($db);
    $e = 'cotH';
    $parrafos = ['Hola ' . $e($r['saludo']) . ':'];
    foreach (preg_split('/\n\s*\n/', str_replace("\r", '', $texto)) as $p) {
        if (trim($p) !== '') $parrafos[] = nl2br($e(trim($p)));
    }
    $datos = [];
    if ($r['boleta'] !== '') $datos[] = ['Boleta', $e($r['boleta'])];
    if ($r['boleta_fecha']) $datos[] = ['Emitida el', $e(cotFecha($r['boleta_fecha']))];
    if ($r['trabajo'] !== '') $datos[] = ['Trabajo', $e($r['trabajo'])];
    $datos[] = ['Saldo pendiente', $e(cotClp($r['saldo']))];
    $asunto = ($r['boleta'] !== '' ? 'Boleta ' . $r['boleta'] : 'Pago') . ' pendiente · ' . cotEmpresa($cfg);
    $html = correoPlantilla('Pago pendiente', $parrafos, null, $datos, '', ['etiqueta' => $r['boleta'] !== '' ? 'Boleta · ' . $r['boleta'] : 'Pago pendiente']);

    correoEnviar($db, [
        'to' => $to, 'to_nombre' => $r['contacto'], 'asunto' => $asunto, 'html' => $html,
        'reply_to' => trim((string)($cfg['email'] ?? '')),
    ]);
    clienteGuardarCorreoSiFalta($db, (int)$r['cliente_id'], $to);
    try {
        $db->prepare("UPDATE pago_cuotas SET recordado_at = ?, recordatorios = recordatorios + 1 WHERE id = ?")
           ->execute([cotAhora()->format('Y-m-d H:i:s'), $cuotaId]);
    } catch (Throwable $ex) { /* sin la migración: el correo ya salió */ }
    return $r + ['enviado_a' => $to];
}

/**
 * Renovación hecha: corre la fecha de vencimiento del dominio u hosting un año
 * (las veces que haga falta para que quede en el futuro).
 * @return ?string la nueva fecha (AAAA-MM-DD), o null si el sitio no tiene esa fecha
 */
function sitioMarcarRenovado(PDO $db, int $sitioId, string $que, ?string $hoy = null): ?string {
    if (!in_array($que, ['dominio', 'hosting'], true)) return null;
    $st = $db->prepare("SELECT {$que}_vence FROM sitios WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$sitioId]);
    $vence = $st->fetchColumn();
    if (!$vence) return null;
    $hoy = $hoy ?: cotAhora()->format('Y-m-d');
    $f = new DateTimeImmutable(substr((string)$vence, 0, 10));
    do { $f = $f->modify('+1 year'); } while ($f->format('Y-m-d') <= $hoy);
    $nueva = $f->format('Y-m-d');
    $db->prepare("UPDATE sitios SET {$que}_vence = ? WHERE id = ?")->execute([$nueva, $sitioId]);
    return $nueva;
}
