<?php
/**
 * Saldos de cobro: ÚNICA fuente de "por cobrar", "cobrado", "vencida" y tramos.
 *
 * Antes cada pantalla (Panel, Proyectos, Pagos, ficha del cliente, Reportes)
 * tenía su propia consulta y la misma deuda salía con montos distintos. Todas
 * deben usar estas funciones.
 *
 * Reglas:
 *  - Una cuota vale max(boleta_monto, pago_monto): el monto acordado, o lo
 *    cobrado si es un pago directo sin monto acordado. El total de un pago es la
 *    suma de sus cuotas.
 *  - Saldo de una cuota = boleta_monto − cobrado (solo Pendiente/Parcial).
 *  - Cuota VENCIDA = tiene saldo y pasaron más de COBRO_PLAZO_DIAS desde la
 *    fecha de la boleta. Sin fecha de boleta no vence.
 *  - Cuenta solo lo "vivo": pago, proyecto (si tiene) y cliente sin borrar. El
 *    cliente es el del proyecto (si lo hay), no el guardado en el pago.
 */

const COBRO_PLAZO_DIAS = 30;

/** Saldo de una cuota en SQL (alias pc). Misma regla que saldoCuota(). */
const SALDO_CUOTA_SQL = "CASE WHEN pc.estado IN ('Pendiente', 'Parcial')
    THEN GREATEST(COALESCE(pc.boleta_monto, 0) - COALESCE(pc.pago_monto, 0), 0) ELSE 0 END";

/** Valor de una cuota: el monto acordado, o lo cobrado si es mayor (directo sin monto). */
function cuotaMonto(array $c): int {
    return max((int)($c['boleta_monto'] ?? 0), (int)($c['pago_monto'] ?? 0));
}

/** Saldo de una cuota (fila de pago_cuotas). Misma regla que SALDO_CUOTA_SQL. */
function saldoCuota(array $c): int {
    if (!in_array($c['estado'] ?? '', ['Pendiente', 'Parcial'], true)) return 0;
    return max(0, (int)($c['boleta_monto'] ?? 0) - (int)($c['pago_monto'] ?? 0));
}

/** ¿Cuota vencida? Con saldo y más de COBRO_PLAZO_DIAS desde la boleta. */
function cuotaVencida(array $c, ?string $hoy = null): bool {
    if (saldoCuota($c) <= 0 || empty($c['boleta_fecha'])) return false;
    $limite = (new DateTimeImmutable($hoy ?? 'today'))->modify('-' . COBRO_PLAZO_DIAS . ' days')->format('Y-m-d');
    return substr((string)$c['boleta_fecha'], 0, 10) < $limite;
}

/** Tramos de antigüedad (días desde la boleta) en el orden en que se muestran. */
const COBRO_TRAMOS = ['vigente', '1-30', '31-60', '61-90', '+90'];

/**
 * Cuotas vivas con su saldo. $where agrega condiciones (con alias pc, pg, pr, c).
 * @return array<int, array> filas con: cuota_id, pago_id, proyecto_id,
 *   cliente_id, numero_cuota, boleta_num, boleta_fecha, tipo_pago, estado,
 *   monto, cobrado, saldo, dias (null sin fecha), vencida, tramo, proyecto_nombre,
 *   sitio_nombre, cliente_nombre.
 */
function saldosCuotas(PDO $db, string $where = '', array $params = []): array {
    $sql = "
        SELECT pc.id AS cuota_id, pc.pago_id, pg.proyecto_id,
               COALESCE(pr.cliente_id, pg.cliente_id) AS cliente_id,
               pc.numero_cuota, pc.boleta_num, pc.boleta_fecha, pc.tipo_pago, pc.estado,
               GREATEST(COALESCE(pc.boleta_monto, 0), COALESCE(pc.pago_monto, 0)) AS monto,
               COALESCE(pc.pago_monto, 0) AS cobrado,
               " . SALDO_CUOTA_SQL . " AS saldo,
               DATEDIFF(CURDATE(), pc.boleta_fecha) AS dias,
               pr.nombre AS proyecto_nombre, s.nombre AS sitio_nombre,
               COALESCE(NULLIF(c.nombre_agencia, ''), c.nombre) AS cliente_nombre
        FROM pago_cuotas pc
        INNER JOIN pagos pg    ON pg.id = pc.pago_id AND pg.deleted_at IS NULL
        LEFT  JOIN proyectos pr ON pr.id = pg.proyecto_id
        LEFT  JOIN sitios s     ON s.id = pr.sitio_id
        INNER JOIN clientes c   ON c.id = COALESCE(pr.cliente_id, pg.cliente_id)
        WHERE (pr.id IS NULL OR pr.deleted_at IS NULL)
          AND c.deleted_at IS NULL
          " . ($where !== '' ? "AND ($where)" : '') . "
        ORDER BY (pc.boleta_fecha IS NULL), pc.boleta_fecha, pc.pago_id, pc.numero_cuota";
    $st = $db->prepare($sql);
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach (['cuota_id', 'pago_id', 'cliente_id', 'numero_cuota', 'monto', 'cobrado', 'saldo'] as $k) $r[$k] = (int)$r[$k];
        $r['proyecto_id'] = $r['proyecto_id'] !== null ? (int)$r['proyecto_id'] : null;
        $r['dias'] = $r['boleta_fecha'] === null ? null : (int)$r['dias'];
        $r['vencida'] = $r['saldo'] > 0 && $r['dias'] !== null && $r['dias'] > COBRO_PLAZO_DIAS;
        $r['tramo'] = saldoTramo($r['dias']);
        $out[] = $r;
    }
    return $out;
}

/** Tramo de antigüedad según los días desde la boleta (null = sin fecha). */
function saldoTramo(?int $dias): string {
    if ($dias === null || $dias <= 0) return 'vigente';
    if ($dias <= 30) return '1-30';
    if ($dias <= 60) return '31-60';
    if ($dias <= 90) return '61-90';
    return '+90';
}

/**
 * Resumen de un conjunto de cuotas (de saldosCuotas).
 * @return array{total:int, cobrado:int, por_cobrar:int, cuotas_pendientes:int,
 *   vencidas:int, vencido:int, pagos_pendientes:int, tramos:array}
 */
function saldoResumen(array $cuotas): array {
    $r = ['total' => 0, 'cobrado' => 0, 'por_cobrar' => 0, 'cuotas_pendientes' => 0,
          'vencidas' => 0, 'vencido' => 0, 'pagos_pendientes' => 0,
          'tramos' => array_fill_keys(COBRO_TRAMOS, ['cantidad' => 0, 'total' => 0])];
    $pagosConSaldo = [];
    foreach ($cuotas as $c) {
        $r['total']   += $c['monto'];
        $r['cobrado'] += $c['cobrado'];
        if ($c['saldo'] <= 0) continue;
        $r['por_cobrar'] += $c['saldo'];
        $r['cuotas_pendientes']++;
        $pagosConSaldo[$c['pago_id']] = true;
        $r['tramos'][$c['tramo']]['cantidad']++;
        $r['tramos'][$c['tramo']]['total'] += $c['saldo'];
        if ($c['vencida']) { $r['vencidas']++; $r['vencido'] += $c['saldo']; }
    }
    $r['pagos_pendientes'] = count($pagosConSaldo);
    return $r;
}

/** Agrupa saldoResumen() por una clave de la cuota (ej: 'proyecto_id', 'pago_id'). */
function saldoResumenPor(array $cuotas, string $clave): array {
    $grupos = [];
    foreach ($cuotas as $c) {
        if ($c[$clave] === null) continue;
        $grupos[$c[$clave]][] = $c;
    }
    return array_map('saldoResumen', $grupos);
}
