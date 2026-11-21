<?php
/**
 * Convierte una cotización aceptada en un proyecto.
 * POST { id, sitio_id? }
 *
 * Debe dejar el proyecto igual que uno creado a mano desde Proyectos:
 *   - Cada ítem pasa a proyecto_servicios con el PRECIO COTIZADO (override del
 *     catálogo). Los ítems sin servicio de catálogo pasan como líneas libres.
 *   - Se crea el COBRO PENDIENTE automático en Pagos por el total de la
 *     cotización ("la plata vive solo en Pagos").
 *   - Opcionalmente queda asociado a un sitio del cliente.
 */
require_once __DIR__ . '/../config.php';
cors();
$db = getDB();
$user = requireAuth();

$input = body();
$cotizacion_id = (int)($input['id'] ?? 0);
$sitio_id      = !empty($input['sitio_id']) ? (int)$input['sitio_id'] : null;

if (!$cotizacion_id) {
    err('ID de cotización requerido');
}

try {
    $db->beginTransaction();

    // 1. Cabecera, bloqueada (FOR UPDATE) para que un doble clic no genere
    //    dos proyectos: la 2ª petición espera y luego ve proyecto_id ya puesto.
    $stmt = $db->prepare("SELECT * FROM cotizaciones WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
    $stmt->execute([$cotizacion_id]);
    $cot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cot) {
        $db->rollBack();
        err('Cotización no encontrada', 404);
    }
    if (!empty($cot['proyecto_id'])) {
        $db->rollBack();
        err('Esta cotización ya está vinculada a un proyecto.');
    }
    $cliente_id = (int)$cot['cliente_id'];

    // El sitio (si viene) debe pertenecer al mismo cliente.
    if ($sitio_id) {
        $chk = $db->prepare("SELECT 1 FROM sitios WHERE id = ? AND cliente_id = ? AND deleted_at IS NULL");
        $chk->execute([$sitio_id, $cliente_id]);
        if (!$chk->fetchColumn()) {
            $db->rollBack();
            err('El sitio elegido no pertenece al cliente de la cotización.');
        }
    }

    // 2. Ítems: total real y detalle.
    $si = $db->prepare("SELECT descripcion, cantidad, precio_unitario, servicio_id FROM cotizacion_items WHERE cotizacion_id = ? ORDER BY orden");
    $si->execute([$cotizacion_id]);
    $items = $si->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Neto = Σ cantidad × precio; Total = neto × (1 − %descuento_global)
    $neto = 0.0;
    foreach ($items as $it) {
        $neto += (float)$it['cantidad'] * (float)$it['precio_unitario'];
    }
    $pctDcto = (float)($cot['descuento_global'] ?? 0);
    $total   = (int)round($neto * (1 - $pctDcto / 100));

    // 3. Descripción del proyecto = listado de ítems.
    $clpFmt = function($n) { return '$ ' . number_format((float)$n, 0, ',', '.'); };
    $linesDesc = [];
    foreach ($items as $it) {
        $cant   = (float)$it['cantidad'];
        $sub    = $cant * (float)$it['precio_unitario'];
        $linesDesc[] = '• ' . (rtrim(rtrim((string)$cant, '0'), '.') ?: '0')
                     . ' × ' . trim((string)$it['descripcion'])
                     . ' — ' . $clpFmt($sub);
    }
    if ($pctDcto > 0) {
        $tipoDcto = ($cot['descuento_global_tipo'] ?? 'porcentaje') === 'fijo' ? 'fijo' : 'porcentaje';
        $linesDesc[] = $tipoDcto === 'fijo'
            ? 'Descuento global aplicado: ' . $clpFmt(round($neto * $pctDcto / 100))
            : 'Descuento global aplicado: ' . rtrim(rtrim(number_format($pctDcto, 4, '.', ''), '0'), '.') . '%';
    }
    $descProyecto = "Generado desde cotización " . ($cot['numero'] ?? ('#' . $cot['id'])) . "\n\n" . implode("\n", $linesDesc);

    // 4. Nombre del proyecto
    $nombreProyecto = !empty($cot['titulo'])
        ? $cot['titulo']
        : (!empty($cot['numero']) ? ('Proyecto ' . $cot['numero']) : ('Proyecto desde Cotización #' . $cot['id']));

    // 5. Líneas del proyecto con el precio cotizado.
    //    - Con servicio: una fila por servicio (UNIQUE proyecto+servicio); si se
    //      repite en la cotización se suman cantidades y se promedia el precio
    //      para conservar el subtotal.
    //    - Sin servicio: línea libre con la descripción del ítem.
    //    proyecto_servicios.cantidad es entera: si la cantidad cotizada tiene
    //    decimales (ej: 1,5 h) se guarda 1 × subtotal para no alterar el monto.
    $lineas  = [];   // en orden
    $porSvc  = [];   // servicio_id => índice en $lineas
    foreach ($items as $it) {
        $cant = (float)$it['cantidad'];
        $sub  = $cant * (float)$it['precio_unitario'];
        if ($cant <= 0) continue;
        $sid  = !empty($it['servicio_id']) ? (int)$it['servicio_id'] : 0;
        if ($sid > 0) {
            if (isset($porSvc[$sid])) {
                $lineas[$porSvc[$sid]]['cant'] += $cant;
                $lineas[$porSvc[$sid]]['sub']  += $sub;
            } else {
                $porSvc[$sid] = count($lineas);
                $lineas[] = ['sid' => $sid, 'nombre' => null, 'cant' => $cant, 'sub' => $sub];
            }
        } else {
            $nombre = trim((string)$it['descripcion']);
            if ($nombre === '') continue;
            $lineas[] = ['sid' => null, 'nombre' => mb_substr($nombre, 0, 200), 'cant' => $cant, 'sub' => $sub];
        }
    }

    $stmt = $db->prepare("
        INSERT INTO proyectos (nombre, cliente_id, sitio_id, servicio_id, presupuesto, estado, fecha_inicio, descripcion, created_at)
        VALUES (?, ?, ?, ?, ?, 'En curso', CURDATE(), ?, NOW())
    ");
    $stmt->execute([
        $nombreProyecto,
        $cliente_id,
        $sitio_id,
        array_key_first($porSvc),   // legacy: primer servicio de catálogo (o NULL)
        $total,
        $descProyecto,
    ]);
    $nuevo_proyecto_id = (int)$db->lastInsertId();

    if (!empty($lineas)) {
        $ins = $db->prepare("INSERT INTO proyecto_servicios (proyecto_id, servicio_id, nombre, cantidad, precio_unitario, orden) VALUES (?, ?, ?, ?, ?, ?)");
        $orden = 0;
        foreach ($lineas as $l) {
            $entera = abs($l['cant'] - round($l['cant'])) < 0.0001;
            $cant   = $entera ? max(1, (int)round($l['cant'])) : 1;
            $precio = (int)round($entera ? $l['sub'] / $cant : $l['sub']);
            $ins->execute([$nuevo_proyecto_id, $l['sid'], $l['nombre'], $cant, $precio, $orden++]);
        }
    }

    // 6. Cobro pendiente automático (igual que al crear el proyecto a mano).
    if ($total > 0) {
        $db->prepare("INSERT INTO pagos (cliente_id, proyecto_id, descripcion, monto_total, tipo_pago)
                      VALUES (?, ?, ?, ?, 'boleta')")
           ->execute([$cliente_id, $nuevo_proyecto_id, $nombreProyecto, $total]);
        $pago_id = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO pago_cuotas (pago_id, numero_cuota, boleta_monto, pago_monto, estado, tipo_pago)
                      VALUES (?, 1, ?, 0, 'Pendiente', 'boleta')")
           ->execute([$pago_id, $total]);
    }

    // 7. Vincular la cotización al proyecto y dejarla en Aceptada
    $db->prepare("UPDATE cotizaciones SET proyecto_id = ?, estado = 'Aceptada' WHERE id = ?")
       ->execute([$nuevo_proyecto_id, $cotizacion_id]);

    audit($db, $user['id'], 'CREATE', 'proyectos', "Proyecto #{$nuevo_proyecto_id} generado desde cotización #{$cotizacion_id}", $nuevo_proyecto_id);

    $db->commit();
    ok(['proyecto_id' => $nuevo_proyecto_id, 'total' => $total]);

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    errSafe($e, 500, 'cotizaciones_convertir');
}
