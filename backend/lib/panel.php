<?php
/**
 * Panel: «Necesita tu atención» y «Proyectos en curso».
 *
 * Una sola lista ordenada por urgencia, donde cada fila dice qué pasa y lleva
 * al lugar donde se resuelve. La plata sale siempre de lib/saldos.php.
 *
 * Cada fila: tono ('rojo' | 'naranjo' | 'azul' | 'gris'), etiqueta (chip),
 * titulo, detalle, monto (o null) y accion { texto, panel, id?, principal? }
 * — `panel` + `id` es lo mismo que usa el evento `navegar` del frontend.
 */
declare(strict_types=1);

require_once __DIR__ . '/saldos.php';

/** Días antes del vencimiento en que una cotización sin respuesta entra a la lista. */
const PANEL_COT_AVISO_DIAS = 7;
/** Ventana de renovaciones de dominio/hosting que se muestran. */
const PANEL_RENOV_DIAS = 30;

function panelDias(int $n): string {
    return $n === 1 ? '1 día' : $n . ' días';
}

/**
 * @param ?int $clienteId solo lo de ese cliente (ficha del cliente)
 * @return array<int, array> filas ordenadas: lo más urgente primero
 */
function panelAtencion(PDO $db, ?array $cuotas = null, ?int $clienteId = null): array {
    $filas = [];
    $cliente = "COALESCE(NULLIF(c.nombre_agencia, ''), c.nombre)";

    // 1. Cobros vencidos (boleta emitida hace más de COBRO_PLAZO_DIAS).
    $cuotas = $cuotas ?? saldosCuotas($db);
    // Cuándo se le recordó cada una (si ya corrió la migración del recordatorio).
    $recordadas = [];
    $idsVenc = array_map(fn($q) => (int)$q['cuota_id'], array_filter($cuotas, fn($q) => !empty($q['vencida'])));
    if ($idsVenc) {
        try {
            $recordadas = $db->query("SELECT id, recordado_at FROM pago_cuotas WHERE recordado_at IS NOT NULL AND id IN (" . implode(',', $idsVenc) . ")")
                             ->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) { /* sin la migración */ }
    }
    foreach ($cuotas as $q) {
        if (empty($q['vencida'])) continue;
        $rec = $recordadas[$q['cuota_id']] ?? null;
        $diasRec = $rec ? (int)floor((time() - strtotime($rec)) / 86400) : null;
        $filas[] = [
            'cliente_id' => (int)$q['cliente_id'],
            'orden' => 100000 + (int)$q['dias'],
            'tono' => 'rojo', 'etiqueta' => 'Cobro vencido',
            'titulo' => etiquetaProyecto($q['proyecto_nombre'] ?: 'Pago directo', $q['sitio_nombre']),
            'detalle' => implode(' · ', array_filter([
                $q['cliente_nombre'],
                trim((string)$q['boleta_num']) !== '' ? 'boleta ' . $q['boleta_num'] : null,
                'hace ' . panelDias((int)$q['dias']),
                $rec ? ($diasRec === 0 ? 'recordado hoy' : 'recordado hace ' . panelDias($diasRec)) : null,
            ])),
            'monto' => (int)$q['saldo'],
            'accion' => ['texto' => 'Registrar pago', 'panel' => 'pagos', 'id' => (string)$q['pago_id']],
            'hacer' => ['tipo' => 'recordar', 'texto' => $rec ? 'Recordar otra vez' : 'Recordar al cliente', 'cuota_id' => (int)$q['cuota_id']],
        ];
    }

    // 2. Dominio / hosting: vencidos (rojo) y por vencer (gris).
    foreach (['dominio' => 'Dominio', 'hosting' => 'Hosting'] as $k => $nombre) {
        $st = $db->prepare("SELECT s.id, s.nombre, c.id AS cliente_id, $cliente AS cliente, DATEDIFF(s.{$k}_vence, CURDATE()) AS dias,
                                   s.{$k}_monto AS monto, (s.{$k}_cobrado IS NOT NULL AND s.{$k}_cobrado = s.{$k}_vence) AS cobro_creado
                            FROM sitios s JOIN clientes c ON c.id = s.cliente_id
                            WHERE s.deleted_at IS NULL AND c.deleted_at IS NULL AND s.{$k}_vence IS NOT NULL
                              AND DATEDIFF(s.{$k}_vence, CURDATE()) BETWEEN -60 AND ?");
        $st->execute([PANEL_RENOV_DIAS]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dias = (int)$r['dias'];
            $vencido = $dias < 0;
            $cuando = $vencido ? 'venció hace ' . panelDias(-$dias) : ($dias === 0 ? 'vence hoy' : 'vence en ' . panelDias($dias));
            $filas[] = [
                'cliente_id' => (int)$r['cliente_id'],
                'orden' => $vencido ? 90000 - $dias : 1000 - $dias,
                'tono' => $vencido ? 'rojo' : ($dias <= 7 ? 'naranjo' : 'gris'),
                'etiqueta' => $vencido ? "$nombre vencido" : ($dias === 0 ? 'Hoy' : 'En ' . panelDias($dias)),
                'titulo' => ($vencido ? '' : "Renovación de " . mb_strtolower($nombre) . ' · ') . $r['nombre'],
                'detalle' => implode(' · ', array_filter([
                    $r['cliente'], $cuando,
                    !empty($r['cobro_creado']) ? 'el cobro ya está creado' : null,
                ])),
                'monto' => $r['monto'] !== null && (int)$r['monto'] > 0 ? (int)$r['monto'] : null,
                'accion' => ['texto' => 'Ver sitio', 'panel' => 'sitios', 'id' => 'editar-' . (int)$r['id']],
                'hacer' => ['tipo' => 'renovar', 'texto' => 'Marcar renovado', 'sitio_id' => (int)$r['id'], 'que' => $k, 'sitio' => $r['nombre']],
            ];
        }
    }

    // 3. Proyectos en curso con la fecha de término pasada.
    $rows = $db->query("SELECT p.id, p.nombre, s.nombre AS sitio, c.id AS cliente_id, $cliente AS cliente, DATEDIFF(CURDATE(), p.fecha_termino) AS dias
                        FROM proyectos p JOIN clientes c ON c.id = p.cliente_id LEFT JOIN sitios s ON s.id = p.sitio_id
                        WHERE p.estado = 'En curso' AND p.deleted_at IS NULL AND c.deleted_at IS NULL
                          AND p.fecha_termino IS NOT NULL AND p.fecha_termino < CURDATE()")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $filas[] = [
            'cliente_id' => (int)$r['cliente_id'],
            'orden' => 50000 + (int)$r['dias'],
            'tono' => 'naranjo', 'etiqueta' => 'Proyecto atrasado',
            'titulo' => etiquetaProyecto($r['nombre'], $r['sitio']),
            'detalle' => $r['cliente'] . ' · debía terminar hace ' . panelDias((int)$r['dias']),
            'monto' => null,
            'accion' => ['texto' => 'Ver proyecto', 'panel' => 'proyectos', 'id' => 'ficha-' . (int)$r['id']],
        ];
    }

    // 4. Entregas con observaciones del cliente.
    try {
        $rows = $db->query("SELECT e.id, e.numero, c.id AS cliente_id, $cliente AS cliente FROM entregas e JOIN clientes c ON c.id = e.cliente_id
                            WHERE e.estado = 'con_observaciones'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $filas[] = [
                'cliente_id' => (int)$r['cliente_id'],
                'orden' => 60000,
                'tono' => 'naranjo', 'etiqueta' => 'Con observaciones',
                'titulo' => 'Acta ' . $r['numero'],
                'detalle' => $r['cliente'] . ' · el cliente pidió ajustes antes de recibir',
                'monto' => null,
                'accion' => ['texto' => 'Ver entrega', 'panel' => 'entregas', 'entrega' => (int)$r['id']],
            ];
        }
    } catch (Throwable $e) { /* sin la tabla de entregas: sigue */ }

    // 5. Cotizaciones: aprobadas sin proyecto, con comentario del cliente, o por vencer.
    // Mismo total que la lista y el documento: neto menos el descuento global (%).
    $total = "ROUND(COALESCE((SELECT SUM(ci.cantidad * ci.precio_unitario) FROM cotizacion_items ci WHERE ci.cotizacion_id = q.id), 0)
              * (1 - COALESCE(q.descuento_global, 0) / 100))";
    $rows = $db->query("SELECT q.id, q.numero, q.estado, q.proyecto_id, q.respuesta_tipo, q.respuesta_at, q.respuesta_texto,
                               c.id AS cliente_id, $cliente AS cliente, DATEDIFF(q.fecha_validez, CURDATE()) AS dias, $total AS total
                        FROM cotizaciones q JOIN clientes c ON c.id = q.cliente_id
                        WHERE q.deleted_at IS NULL AND c.deleted_at IS NULL
                          AND q.estado IN ('Pendiente', 'Enviada', 'Aceptada')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $base = ['cliente_id' => (int)$r['cliente_id'], 'titulo' => 'Cotización ' . $r['numero'] . ' · ' . $r['cliente'], 'monto' => max(0, (int)$r['total'])];
        $id = (int)$r['id'];
        if ($r['estado'] === 'Aceptada') {
            if (!empty($r['proyecto_id'])) continue;
            $filas[] = $base + [
                'orden' => 40000, 'tono' => 'azul', 'etiqueta' => 'Aprobada',
                'detalle' => 'El cliente aprobó: falta crear el proyecto y su cobro',
                'accion' => ['texto' => 'Crear proyecto', 'panel' => 'cotizaciones', 'id' => "generar-$id", 'principal' => true],
            ];
            continue;
        }
        if ($r['respuesta_tipo'] === 'comentario' && !empty($r['respuesta_at'])) {
            $filas[] = $base + [
                'orden' => 45000, 'tono' => 'azul', 'etiqueta' => 'Cliente comentó',
                'detalle' => '«' . mb_strimwidth(trim((string)$r['respuesta_texto']), 0, 110, '…') . '»',
                'accion' => ['texto' => 'Ver cotización', 'panel' => 'cotizaciones', 'id' => "editar-$id"],
            ];
            continue;
        }
        if ($r['dias'] === null || (int)$r['dias'] > PANEL_COT_AVISO_DIAS || (int)$r['dias'] < 0) continue;
        $dias = (int)$r['dias'];
        $sinEnviar = $r['estado'] === 'Pendiente';
        $filas[] = $base + [
            'orden' => 30000 - $dias,
            'tono' => 'naranjo', 'etiqueta' => $dias === 0 ? 'Vence hoy' : 'Vence en ' . panelDias($dias),
            'detalle' => $sinEnviar ? 'Todavía no se la envías' : 'Enviada, sin respuesta del cliente',
            'accion' => ['texto' => $sinEnviar ? 'Abrir y enviar' : 'Ver cotización', 'panel' => 'cotizaciones', 'id' => "editar-$id"],
        ];
    }

    if ($clienteId !== null) $filas = array_filter($filas, fn($f) => $f['cliente_id'] === $clienteId);
    usort($filas, fn($a, $b) => $b['orden'] <=> $a['orden']);
    return array_map(function ($f) { unset($f['orden'], $f['cliente_id']); return $f; }, array_values($filas));
}

/**
 * Proyectos en curso con su avance de cobro (lib/saldos.php) y su fecha.
 * @return array<int, array{id:int, titulo:string, cliente:string, total:int, cobrado:int, fecha_termino:?string, dias:?int}>
 */
function panelEnCurso(PDO $db, ?array $cuotas = null, int $limite = 5): array {
    $rows = $db->query("SELECT p.id, p.nombre, s.nombre AS sitio, COALESCE(NULLIF(c.nombre_agencia, ''), c.nombre) AS cliente,
                               p.fecha_termino, DATEDIFF(p.fecha_termino, CURDATE()) AS dias
                        FROM proyectos p JOIN clientes c ON c.id = p.cliente_id LEFT JOIN sitios s ON s.id = p.sitio_id
                        WHERE p.estado = 'En curso' AND p.deleted_at IS NULL AND c.deleted_at IS NULL
                        ORDER BY (p.fecha_termino IS NULL), p.fecha_termino, p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $porProyecto = saldoResumenPor($cuotas ?? saldosCuotas($db), 'proyecto_id');
    $out = [];
    foreach ($rows as $r) {
        $res = $porProyecto[(int)$r['id']] ?? null;
        $out[] = [
            'id' => (int)$r['id'],
            'titulo' => etiquetaProyecto($r['nombre'], $r['sitio']),
            'cliente' => $r['cliente'],
            'total' => (int)($res['total'] ?? 0),
            'cobrado' => (int)($res['cobrado'] ?? 0),
            'fecha_termino' => $r['fecha_termino'],
            'dias' => $r['dias'] === null ? null : (int)$r['dias'],
        ];
    }
    return ['total' => count($out), 'items' => array_slice($out, 0, $limite)];
}
