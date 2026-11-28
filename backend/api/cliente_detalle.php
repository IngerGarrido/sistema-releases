<?php
/**
 * cliente_detalle.php — Ficha 360° de un cliente.
 * Devuelve el cliente + sus sitios, proyectos, cotizaciones y pagos,
 * más un resumen financiero. Una sola llamada para la vista de detalle.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $db->exec("SET NAMES utf8mb4");

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'ID inválido']);
        exit;
    }

    // ── Cliente ──
    $stmt = $db->prepare("SELECT * FROM clientes WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cliente) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Cliente no encontrado']);
        exit;
    }

    $out = ['cliente' => $cliente];

    // ── Sitios ──
    $st = $db->prepare("
        SELECT s.id, s.nombre, s.url_principal,
               (SELECT COUNT(*) FROM proyectos p WHERE p.sitio_id = s.id AND p.deleted_at IS NULL) AS proyectos_count
        FROM sitios s
        WHERE s.cliente_id = ? AND s.deleted_at IS NULL
        ORDER BY s.nombre ASC
    ");
    $st->execute([$id]);
    $out['sitios'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Proyectos ──
    $st = $db->prepare("
        SELECT p.id, p.nombre, p.estado, p.presupuesto, p.fecha_inicio, p.fecha_termino,
               s.nombre AS sitio_nombre
        FROM proyectos p
        LEFT JOIN sitios s ON s.id = p.sitio_id
        WHERE p.cliente_id = ? AND p.deleted_at IS NULL
        ORDER BY p.id DESC
    ");
    $st->execute([$id]);
    $out['proyectos'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Cotizaciones ──
    // Total real = neto × (1 − %descuento_global) para coincidir con lo que
    // muestra la lista de cotizaciones y el PDF.
    // Seguimiento en línea (envío, vista, aprobación, propuesta).
    $extraCot = ', c.enviada_at, c.vista_at, c.aprobada_at, c.aprobada_nombre, c.propuesta_vista_at, c.proyecto_id';
    $st = $db->prepare("
        SELECT c.id, c.numero, c.fecha, c.fecha_validez, c.estado{$extraCot},
               ROUND(
                 COALESCE((SELECT SUM(ci.cantidad * ci.precio_unitario)
                           FROM cotizacion_items ci WHERE ci.cotizacion_id = c.id), 0)
                 * (1 - COALESCE(c.descuento_global, 0) / 100)
               , 0) AS total
        FROM cotizaciones c
        WHERE c.cliente_id = ? AND c.deleted_at IS NULL
        ORDER BY c.id DESC
    ");
    $st->execute([$id]);
    $out['cotizaciones'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Entregas ──
    $out['entregas'] = [];
    try {
        require_once __DIR__ . '/../lib/entregas.php';
        $garSql = ENT_GARANTIA_HASTA_SQL;
        $st = $db->prepare("
            SELECT e.id, e.numero, e.estado, e.dominio, e.fecha_entrega, e.enviada_at, e.plazo_vence,
                   $garSql AS garantia_hasta,
                   e.vista_at, e.observacion_at, e.recibida_at, e.recibida_nombre,
                   e.proyecto_id, pr.nombre AS proyecto_nombre, s.nombre AS sitio_nombre
            FROM entregas e
            LEFT JOIN proyectos pr ON pr.id = e.proyecto_id
            LEFT JOIN sitios s ON s.id = pr.sitio_id
            WHERE e.cliente_id = ?
            ORDER BY e.id DESC
        ");
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $e['id'] = (int)$e['id'];
            $e['proyecto_id'] = $e['proyecto_id'] ? (int)$e['proyecto_id'] : null;
            $e['estado_label'] = ENT_ETIQUETAS[$e['estado']] ?? $e['estado'];
            $out['entregas'][] = $e;
        }
    } catch (Throwable $e) { /* sin entregas: la ficha sigue */ }

    // ── Pagos ── (montos desde lib/saldos.php: los mismos de Pagos y el Panel)
    // Son del cliente los pagos de SUS proyectos (o, sin proyecto, los suyos).
    require_once __DIR__ . '/../lib/saldos.php';
    $cuotas = saldosCuotas($db, 'COALESCE(pr.cliente_id, pg.cliente_id) = ?', [$id]);
    $porPago = saldoResumenPor($cuotas, 'pago_id');
    $pagos = [];
    if ($porPago) {
        $ph = implode(',', array_fill(0, count($porPago), '?'));
        $st = $db->prepare("
            SELECT p.id, p.descripcion, p.tipo_pago, pr.nombre AS proyecto_nombre, s.nombre AS sitio_nombre
            FROM pagos p
            LEFT JOIN proyectos pr ON pr.id = p.proyecto_id
            LEFT JOIN sitios s ON s.id = pr.sitio_id
            WHERE p.id IN ($ph)
            ORDER BY p.id DESC
        ");
        $st->execute(array_keys($porPago));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $res = $porPago[(int)$p['id']];
            $p['monto_total']  = $res['total'];
            $p['total_pagado'] = $res['cobrado'];
            $p['pendiente']    = $res['falta'];
            $p['vencidas']     = $res['vencidas'];
            $pagos[] = $p;
        }
    }
    $out['pagos'] = $pagos;

    // ── Movimientos de plata para la línea de tiempo: boletas emitidas, abonos y recordatorios ──
    $mov = [];
    $porCuota = [];
    foreach ($cuotas as $q) {
        $porCuota[$q['cuota_id']] = $q;
        $titulo = etiquetaProyecto($q['proyecto_nombre'] ?: 'Pago directo', $q['sitio_nombre']);
        if ($q['emitida'] && $q['tipo_pago'] !== 'directo' && $q['boleta_fecha']) {
            $mov[] = ['tipo' => 'boleta', 'fecha' => substr((string)$q['boleta_fecha'], 0, 10), 'titulo' => $titulo,
                      'detalle' => 'Boleta ' . $q['boleta_num'] . ' emitida', 'monto' => $q['monto']];
        }
    }
    if ($porCuota) {
        $phQ = implode(',', array_fill(0, count($porCuota), '?'));
        $st = $db->prepare("SELECT cuota_id, monto, fecha FROM pago_abonos WHERE cuota_id IN ($phQ) ORDER BY fecha DESC, id DESC");
        $st->execute(array_keys($porCuota));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $q = $porCuota[(int)$a['cuota_id']];
            $mov[] = ['tipo' => 'pago', 'fecha' => substr((string)$a['fecha'], 0, 10),
                      'titulo' => etiquetaProyecto($q['proyecto_nombre'] ?: 'Pago directo', $q['sitio_nombre']),
                      'detalle' => 'Pago recibido' . (trim((string)$q['boleta_num']) !== '' ? ' · boleta ' . $q['boleta_num'] : ''), 'monto' => (int)$a['monto']];
        }
        try {
            $st = $db->prepare("SELECT id, recordado_at FROM pago_cuotas WHERE recordado_at IS NOT NULL AND id IN ($phQ)");
            $st->execute(array_keys($porCuota));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $rr) {
                $q = $porCuota[(int)$rr['id']];
                $mov[] = ['tipo' => 'recordatorio', 'fecha' => substr((string)$rr['recordado_at'], 0, 10), 'orden' => $rr['recordado_at'],
                          'titulo' => etiquetaProyecto($q['proyecto_nombre'] ?: 'Pago directo', $q['sitio_nombre']),
                          'detalle' => 'Recordatorio de cobro enviado' . (trim((string)$q['boleta_num']) !== '' ? ' · boleta ' . $q['boleta_num'] : ''), 'monto' => null];
            }
        } catch (Throwable $e) { /* sin la migración del recordatorio */ }
    }
    $out['movimientos'] = $mov;

    // ── Resumen financiero ──
    $res = saldoResumen($cuotas);
    $out['resumen'] = [
        'proyectos'       => count($out['proyectos']),
        'sitios'          => count($out['sitios']),
        'cotizaciones'    => count($out['cotizaciones']),
        'entregas'        => count($out['entregas']),
        'total_facturado' => $res['total'],
        'total_cobrado'   => $res['cobrado'],
        'total_pendiente' => $res['por_cobrar'],
        'total_por_emitir' => $res['por_emitir'],
        'cuotas_vencidas' => $res['vencidas'],
        'total_vencido'   => $res['vencido'],
    ];
    // Lo que pide acción con este cliente (misma lista que el Panel, lib/panel.php).
    require_once __DIR__ . '/../lib/panel.php';
    $out['atencion'] = panelAtencion($db, null, $id);

    echo json_encode(['ok' => true, 'data' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    errSafe($e, 500);
}
