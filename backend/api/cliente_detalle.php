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
    $st = $db->prepare("
        SELECT c.id, c.numero, c.fecha, c.fecha_validez, c.estado,
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

    // ── Pagos ──
    $st = $db->prepare("
        SELECT p.id, p.descripcion, p.monto_total, p.tipo_pago,
               pr.nombre AS proyecto_nombre,
               s.nombre AS sitio_nombre,
               COALESCE((SELECT SUM(pc.pago_monto) FROM pago_cuotas pc
                         WHERE pc.pago_id = p.id AND pc.estado IN ('Pagado','Parcial')), 0) AS total_pagado
        FROM pagos p
        LEFT JOIN proyectos pr ON pr.id = p.proyecto_id
        LEFT JOIN sitios s ON s.id = pr.sitio_id
        WHERE p.cliente_id = ? AND p.deleted_at IS NULL
        ORDER BY p.id DESC
    ");
    $st->execute([$id]);
    $pagos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out['pagos'] = $pagos;

    // ── Resumen financiero ──
    $totFacturado = 0;
    $totCobrado   = 0;
    foreach ($pagos as $p) {
        $totFacturado += (int)$p['monto_total'];
        $totCobrado   += (int)$p['total_pagado'];
    }
    $out['resumen'] = [
        'proyectos'       => count($out['proyectos']),
        'sitios'          => count($out['sitios']),
        'cotizaciones'    => count($out['cotizaciones']),
        'total_facturado' => $totFacturado,
        'total_cobrado'   => $totCobrado,
        'total_pendiente' => max(0, $totFacturado - $totCobrado),
    ];

    echo json_encode(['ok' => true, 'data' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    errSafe($e, 500);
}
