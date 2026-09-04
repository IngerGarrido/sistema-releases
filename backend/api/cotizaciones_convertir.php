<?php
require_once '../config.php';
cors();
$db = getDB();
$user = requireAuth();

$input = body();
$cotizacion_id = $input['id'] ?? null;

if (!$cotizacion_id) {
    err('ID de cotización requerido');
}

try {
    // 1. Cabecera de la cotización
    $stmt = $db->prepare("SELECT * FROM cotizaciones WHERE id = ?");
    $stmt->execute([$cotizacion_id]);
    $cot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cot)                   err('Cotización no encontrada');
    if (!empty($cot['proyecto_id']))
        err('Esta cotización ya fue convertida a un proyecto anteriormente.');

    // 2. Ítems de la cotización: para el total real y la descripción del proyecto
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

    // 3. Descripción del proyecto = listado de ítems (para no perder el detalle
    //    cuando la cotización tiene varios servicios y proyectos sólo tiene 1).
    $clpFmt = function($n) { return '$ ' . number_format((float)$n, 0, ',', '.'); };
    $linesDesc = [];
    foreach ($items as $it) {
        $cant   = (float)$it['cantidad'];
        $precio = (float)$it['precio_unitario'];
        $sub    = $cant * $precio;
        $linesDesc[] = '• ' . (rtrim(rtrim((string)$cant, '0'), '.') ?: '0')
                     . ' × ' . trim((string)$it['descripcion'])
                     . ' — ' . $clpFmt($sub);
    }
    if ($pctDcto > 0) {
        // Si el descuento se ingresó como monto fijo, mostramos el $ en la
        // descripción del proyecto; si fue porcentaje, mostramos el %.
        $tipoDcto = ($cot['descuento_global_tipo'] ?? 'porcentaje') === 'fijo' ? 'fijo' : 'porcentaje';
        if ($tipoDcto === 'fijo') {
            $linesDesc[] = 'Descuento global aplicado: ' . $clpFmt(round($neto * $pctDcto / 100));
        } else {
            $linesDesc[] = 'Descuento global aplicado: ' . rtrim(rtrim(number_format($pctDcto, 4, '.', ''), '0'), '.') . '%';
        }
    }
    $descProyecto = "Generado desde cotización " . ($cot['numero'] ?? ('#' . $cot['id'])) . "\n\n" . implode("\n", $linesDesc);

    // 4. Nombre del proyecto
    $nombreProyecto = !empty($cot['titulo'])
        ? $cot['titulo']
        : (!empty($cot['numero']) ? ('Proyecto ' . $cot['numero']) : ('Proyecto desde Cotización #' . $cot['id']));

    $db->beginTransaction();

    // 5. Crear el proyecto. Servicio_id queda NULL: una cotización puede tener
    //    múltiples servicios y proyectos tiene sólo uno; el detalle real vive
    //    en la cotización vinculada y en la descripción.
    $stmt = $db->prepare("
        INSERT INTO proyectos (nombre, cliente_id, presupuesto, estado, fecha_inicio, descripcion)
        VALUES (?, ?, ?, 'En curso', CURDATE(), ?)
    ");
    $stmt->execute([
        $nombreProyecto,
        (int)$cot['cliente_id'],
        $total,
        $descProyecto,
    ]);
    $nuevo_proyecto_id = (int)$db->lastInsertId();

    // 5b. Llevar los servicios (con cantidad) a proyecto_servicios, para los
    //     ítems que estén vinculados a un servicio del catálogo. Se suman las
    //     cantidades si un servicio aparece en varios ítems. Así el proyecto
    //     convertido muestra sus servicios igual que uno creado a mano.
    $cantPorServicio = [];
    foreach ($items as $it) {
        $sid = !empty($it['servicio_id']) ? (int)$it['servicio_id'] : 0;
        if ($sid > 0) {
            $cantPorServicio[$sid] = ($cantPorServicio[$sid] ?? 0) + (int)round((float)$it['cantidad']);
        }
    }
    if (!empty($cantPorServicio)) {
        $insPS = $db->prepare("INSERT INTO proyecto_servicios (proyecto_id, servicio_id, cantidad, orden) VALUES (?, ?, ?, ?)");
        $orden = 0;
        foreach ($cantPorServicio as $sid => $cant) {
            $insPS->execute([$nuevo_proyecto_id, $sid, max(1, $cant), $orden++]);
        }
        // Campo legacy servicio_id = primer servicio.
        $db->prepare("UPDATE proyectos SET servicio_id = ? WHERE id = ?")
           ->execute([array_key_first($cantPorServicio), $nuevo_proyecto_id]);
    }

    // 6. Vincular la cotización al proyecto y dejarla en Aceptada
    $db->prepare("UPDATE cotizaciones SET proyecto_id = ?, estado = 'Aceptada' WHERE id = ?")
       ->execute([$nuevo_proyecto_id, $cotizacion_id]);

    $db->commit();
    ok(['proyecto_id' => $nuevo_proyecto_id, 'total' => $total]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    err("Error al convertir: " . $e->getMessage());
}