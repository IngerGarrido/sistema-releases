<?php
/**
 * proyecto_detalle.php — Ficha del proyecto: todo su ciclo en una llamada.
 *   cotización(es) → trabajo → cobro (lib/saldos.php) → acta → garantía
 * Antes había que mirarlo en Cotizaciones, Proyectos, Pagos y Entregas.
 *
 * GET ?id=N → { proyecto, servicios, cotizaciones, cobro, pagos, entrega, documentos, pasos }
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../lib/saldos.php';
    cors();
    $db = getDB();
    requireAuth();
    $db->exec("SET NAMES utf8mb4");

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) err('ID inválido', 400);

    $st = $db->prepare("SELECT p.*, COALESCE(NULLIF(c.nombre_agencia, ''), c.nombre) AS cliente_nombre,
                               s.nombre AS sitio_nombre, s.url_principal AS sitio_url
                        FROM proyectos p
                        JOIN clientes c ON c.id = p.cliente_id
                        LEFT JOIN sitios s ON s.id = p.sitio_id
                        WHERE p.id = ? AND p.deleted_at IS NULL");
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) err('Proyecto no encontrado', 404);
    $out = ['proyecto' => $p];

    // Servicios / líneas del alcance
    try {
        $st = $db->prepare("SELECT COALESCE(ps.nombre, sv.nombre) AS nombre, ps.cantidad, COALESCE(ps.precio_unitario, sv.precio, 0) AS precio
                            FROM proyecto_servicios ps LEFT JOIN servicios sv ON sv.id = ps.servicio_id
                            WHERE ps.proyecto_id = ? ORDER BY ps.orden, ps.id");
        $st->execute([$id]);
        $out['servicios'] = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $out['servicios'] = []; }

    // Cotizaciones vinculadas
    $extra = ', c.enviada_at, c.aprobada_at, c.aprobada_nombre';
    $st = $db->prepare("SELECT c.id, c.numero, c.estado, c.fecha $extra,
                               ROUND(COALESCE((SELECT SUM(ci.cantidad * ci.precio_unitario)
                                               FROM cotizacion_items ci WHERE ci.cotizacion_id = c.id), 0)
                                     * (1 - COALESCE(c.descuento_global, 0) / 100), 0) AS total
                        FROM cotizaciones c WHERE c.proyecto_id = ? AND c.deleted_at IS NULL ORDER BY c.id DESC");
    $st->execute([$id]);
    $out['cotizaciones'] = $st->fetchAll(PDO::FETCH_ASSOC);

    // Cobro: misma fuente que Panel, Pagos y Reportes
    $cuotas = saldosCuotas($db, 'pg.proyecto_id = ?', [$id]);
    $res = saldoResumen($cuotas);
    // Estimado del trabajo (alcance) vs lo que ya tiene cobro creado en Pagos:
    // ej. 50% de pie → el otro 50% aún no tiene cuota.
    $estimado = 0;
    foreach ($out['servicios'] as $sv) $estimado += (int)round((float)$sv['precio'] * max(1, (int)$sv['cantidad']));
    if ($estimado <= 0) $estimado = (int)round((float)($p['presupuesto'] ?? 0));
    $out['cobro'] = ['total' => $res['total'], 'cobrado' => $res['cobrado'], 'falta' => $res['falta'],
                     'por_cobrar' => $res['por_cobrar'], 'por_emitir' => $res['por_emitir'],
                     'vencidas' => $res['vencidas'], 'estimado' => $estimado,
                     'sin_cuota' => max(0, $estimado - $res['total'])];
    $out['cuotas'] = array_map(fn($c) => [
        'pago_id' => $c['pago_id'], 'numero' => $c['numero_cuota'], 'tipo' => $c['tipo_pago'], 'boleta_num' => $c['boleta_num'],
        'boleta_fecha' => $c['boleta_fecha'], 'monto' => $c['monto'], 'cobrado' => $c['cobrado'], 'saldo' => $c['saldo'],
        'vencida' => $c['vencida'],
    ], $cuotas);

    // Entrega (acta) más reciente
    $out['entrega'] = null;
    try {
        require_once __DIR__ . '/../lib/entregas.php';
        $gar = ENT_GARANTIA_HASTA_SQL;
        $st = $db->prepare("SELECT e.id, e.numero, e.estado, e.enviada_at, e.plazo_vence, e.recibida_at, $gar AS garantia_hasta
                            FROM entregas e WHERE e.proyecto_id = ? ORDER BY e.id DESC LIMIT 1");
        $st->execute([$id]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if ($e) { $e['estado_label'] = ENT_ETIQUETAS[$e['estado']] ?? $e['estado']; $out['entrega'] = $e; }
    } catch (Throwable $e) { /* sin entregas */ }

    // Documentos (subidos + automáticos)
    $out['documentos'] = 0;
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM documentos WHERE proyecto_id = ?");
        $st->execute([$id]);
        $out['documentos'] = (int)$st->fetchColumn();
        require_once __DIR__ . '/../lib/docs_auto.php';
        $out['documentos'] += count(docsAutomaticos($db, 'proyecto', $id));
    } catch (Throwable $e) { /* sin documentos */ }

    // Pasos del ciclo, para la línea de avance de la ficha
    $cot = $out['cotizaciones'][0] ?? null;
    $ent = $out['entrega'];
    $hoy = date('Y-m-d');
    // Mantenciones y renovaciones no se entregan con acta ni tienen garantía.
    $sinActa = !$ent && preg_match('/mantenci|renovaci/iu', ($p['tipo_proyecto'] ?? '') . ' ' . ($p['nombre'] ?? ''));
    $out['sin_acta'] = (bool)$sinActa;
    $out['pasos'] = [
        ['clave' => 'cotizacion', 'titulo' => 'Cotización',
         'estado' => $cot ? ($cot['estado'] === 'Aceptada' ? 'ok' : 'actual') : 'omitido',
         'detalle' => $cot ? "{$cot['numero']} · {$cot['estado']}" : 'Sin cotización vinculada'],
        ['clave' => 'trabajo', 'titulo' => 'Trabajo',
         'estado' => in_array($p['estado'], ['Terminado'], true) ? 'ok' : ($p['estado'] === 'Cancelado' ? 'omitido' : 'actual'),
         'detalle' => $p['estado'] . (($p['estado'] === 'En curso' && $p['fecha_termino'] && $p['fecha_termino'] < $hoy) ? ' · atrasado' : '')],
        // El cobro corre en paralelo al trabajo: no es "el paso actual" (azul),
        // muestra su avance (% cobrado). Rojo si hay vencidas, verde si está pagado.
        (function () use ($res, $out) {
            $total = $res['total'] + $out['cobro']['sin_cuota'];
            $falta = $res['falta'] + $out['cobro']['sin_cuota'];
            $pct = $total > 0 ? (int)floor($res['cobrado'] * 100 / $total) : 0;
            return ['clave' => 'cobro', 'titulo' => 'Cobro', 'pct' => $total > 0 ? $pct : null,
                'estado' => $total <= 0 ? 'pendiente' : ($falta <= 0 ? 'ok' : ($res['vencidas'] > 0 ? 'alerta' : 'progreso')),
                'detalle' => $total <= 0 ? 'Sin cobro registrado'
                    : ($falta <= 0 ? 'Pagado completo'
                       : "{$pct}% cobrado"
                         . ($res['vencidas'] > 0 ? ' · ' . $res['vencidas'] . ($res['vencidas'] === 1 ? ' vencida' : ' vencidas') : ''))];
        })(),
        ...($sinActa ? [] : [
        ['clave' => 'acta', 'titulo' => 'Acta de entrega',
         'estado' => !$ent ? ($p['estado'] === 'Terminado' ? 'actual' : 'pendiente')
            : (in_array($ent['estado'], ['recibida', 'recibida_por_plazo'], true) ? 'ok' : ($ent['estado'] === 'con_observaciones' ? 'alerta' : 'actual')),
         'detalle' => !$ent ? ($p['estado'] === 'Terminado' ? 'Por entregar' : 'Al terminar el trabajo') : "{$ent['numero']} · {$ent['estado_label']}"],
        ['clave' => 'garantia', 'titulo' => 'Garantía',
         'estado' => empty($ent['garantia_hasta']) ? 'pendiente' : ($ent['garantia_hasta'] >= $hoy ? 'actual' : 'ok'),
         'detalle' => empty($ent['garantia_hasta']) ? 'Desde que se recibe el acta'
            : ($ent['garantia_hasta'] >= $hoy ? 'Hasta el ' : 'Terminó el ') . date('d-m-Y', strtotime($ent['garantia_hasta']))],
        ]),
    ];

    ok($out);
} catch (Throwable $e) {
    errSafe($e, 500);
}
