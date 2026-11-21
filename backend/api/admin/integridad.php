<?php
/**
 * Revisar datos: encuentra inconsistencias que hacían que cada pantalla
 * mostrara otro monto u otro cliente, y las corrige con un clic.
 * Nunca corrige en silencio: GET solo lista; POST aplica UNA acción elegida.
 *
 * GET  → { problemas: [...], clientes: [{id, nombre}] }
 * POST → { accion, ... }:
 *   cascada_papelera  {cliente_id}            lo activo de un cliente en papelera va a la papelera con él
 *   restaurar_cliente {cliente_id}            saca al cliente de la papelera
 *   mover_cliente     {cliente_id, destino_id} pasa lo activo de un cliente (en papelera) a otro
 *   pago_cliente      {pago_id}               el pago toma el cliente de su proyecto
 *   total_a_cuotas    {pago_id}               total del pago = suma de sus cuotas
 *   cuota_diferencia  {pago_id}               agrega una cuota pendiente por lo que falta
 *   estados_cuotas    {}                      recalcula el estado de las cuotas desde lo cobrado
 *   borrar_pagos_huerfanos {}                 borra pagos cuyo proyecto ya no existe
 *   vaciar_papelera_antigua {}                borra para siempre lo que lleva +30 días en la papelera
 *
 * (Reemplaza el botón "Limpiar BD" / api/mantenimiento.php, que borraba a ciegas.)
 */
/** Papelera: tablas que se revisan (pagos primero). PAPELERA_DIAS vive en lib/procesos.php. */
const PAPELERA_TABLAS = ['pagos', 'cotizaciones', 'proyectos', 'sitios', 'servicios', 'clientes'];

error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../lib/procesos.php';   // PAPELERA_DIAS, procVaciarPapeleraAntigua()
    cors();
    $db = getDB();
    $user = requireAuth();
    if ($user['rol'] !== 'admin') err('Solo administradores', 403);

    $nombreSql = "COALESCE(NULLIF(c.nombre_agencia, ''), c.nombre)";
    $tablasCliente = ['proyectos' => 'proyecto', 'sitios' => 'sitio', 'cotizaciones' => 'cotización'];

    // Valor de una cuota (igual que lib/saldos.php → cuotaMonto)
    $sumaCuotasSql = "SELECT COALESCE(SUM(GREATEST(COALESCE(boleta_monto, 0), COALESCE(pago_monto, 0))), 0)
                      FROM pago_cuotas WHERE pago_id = pg.id";

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $problemas = [];

        // 1. Clientes en la papelera que aún tienen cosas activas (huérfanas).
        $huerf = [];
        foreach ($tablasCliente as $tabla => $etq) {
            $rows = $db->query("SELECT t.cliente_id, COUNT(*) AS n FROM {$tabla} t
                                JOIN clientes c ON c.id = t.cliente_id
                                WHERE c.deleted_at IS NOT NULL AND t.deleted_at IS NULL
                                GROUP BY t.cliente_id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) $huerf[(int)$r['cliente_id']][$etq] = (int)$r['n'];
        }
        $rows = $db->query("SELECT COALESCE(pr.cliente_id, pg.cliente_id) AS cliente_id, COUNT(*) AS n
                            FROM pagos pg LEFT JOIN proyectos pr ON pr.id = pg.proyecto_id
                            JOIN clientes c ON c.id = COALESCE(pr.cliente_id, pg.cliente_id)
                            WHERE c.deleted_at IS NOT NULL AND pg.deleted_at IS NULL
                            GROUP BY COALESCE(pr.cliente_id, pg.cliente_id)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $huerf[(int)$r['cliente_id']]['pago'] = (int)$r['n'];

        foreach ($huerf as $cid => $cuentas) {
            $st = $db->prepare("SELECT c.id, {$nombreSql} AS nombre FROM clientes c WHERE c.id = ?");
            $st->execute([$cid]);
            $cli = $st->fetch(PDO::FETCH_ASSOC);
            // Sugerir un cliente activo con el mismo nombre (típico de un "Deshacer" que lo duplicó)
            $st = $db->prepare("SELECT c.id FROM clientes c WHERE c.deleted_at IS NULL AND {$nombreSql} = ? ORDER BY c.id DESC LIMIT 1");
            $st->execute([$cli['nombre']]);
            $sugerido = $st->fetchColumn();
            $partes = [];
            foreach ($cuentas as $etq => $n) {
                $plural = $etq === 'cotización' ? 'cotizaciones' : $etq . 's';
                $partes[] = $n . ' ' . ($n === 1 ? $etq : $plural);
            }
            $problemas[] = [
                'id' => "huerfano-{$cid}",
                'tipo' => 'huerfano',
                'titulo' => "El cliente «{$cli['nombre']}» está en la papelera pero tiene cosas activas",
                'detalle' => implode(', ', $partes) . '. Hoy aparecen en algunas listas pero no en la ficha, el Panel ni Reportes.',
                'cliente_id' => $cid,
                'sugerido_id' => $sugerido ? (int)$sugerido : null,
                'acciones' => ['mover_cliente', 'restaurar_cliente', 'cascada_papelera'],
            ];
        }

        // 2. Pagos con un cliente distinto al de su proyecto (ambos activos).
        $rows = $db->query("SELECT pg.id, pr.nombre AS proyecto, {$nombreSql} AS cliente_proy,
                                   (SELECT COALESCE(NULLIF(c2.nombre_agencia, ''), c2.nombre) FROM clientes c2 WHERE c2.id = pg.cliente_id) AS cliente_pago
                            FROM pagos pg JOIN proyectos pr ON pr.id = pg.proyecto_id
                            JOIN clientes c ON c.id = pr.cliente_id
                            WHERE pg.deleted_at IS NULL AND pr.deleted_at IS NULL AND c.deleted_at IS NULL
                              AND pg.cliente_id <> pr.cliente_id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $problemas[] = [
                'id' => "pago-cliente-{$r['id']}",
                'tipo' => 'pago_cliente',
                'titulo' => "El pago de «{$r['proyecto']}» está a nombre de otro cliente",
                'detalle' => "El pago dice «" . ($r['cliente_pago'] ?? '—') . "» y el proyecto es de «{$r['cliente_proy']}».",
                'pago_id' => (int)$r['id'],
                'acciones' => ['pago_cliente'],
            ];
        }

        // 3. Pagos cuyo total guardado no coincide con la suma de sus cuotas.
        $rows = $db->query("SELECT pg.id, pg.monto_total, ({$sumaCuotasSql}) AS suma,
                                   COALESCE(pr.nombre, pg.descripcion) AS nombre
                            FROM pagos pg LEFT JOIN proyectos pr ON pr.id = pg.proyecto_id
                            WHERE pg.deleted_at IS NULL
                              AND EXISTS (SELECT 1 FROM pago_cuotas WHERE pago_id = pg.id)
                              AND pg.monto_total <> ({$sumaCuotasSql})")->fetchAll(PDO::FETCH_ASSOC);
        $fmt = fn(int $n) => '$' . number_format($n, 0, ',', '.');
        foreach ($rows as $r) {
            $total = (int)$r['monto_total']; $suma = (int)$r['suma'];
            $nombre = $r['nombre'] ?: "#{$r['id']}";
            if ($total > $suma) {
                $dif = $total - $suma;
                $problemas[] = [
                    'id' => "pago-total-{$r['id']}", 'tipo' => 'pago_total', 'pago_id' => (int)$r['id'],
                    'titulo' => "«{$nombre}»: {$fmt($dif)} sin cuota en Pagos",
                    'detalle' => "El total acordado es {$fmt($total)}, pero las cuotas creadas suman {$fmt($suma)} "
                               . "(ej: solo se registró el pie). Esa diferencia hoy no aparece como \"por cobrar\" en ningún lado. "
                               . "¿Todavía hay que cobrarla?",
                    'acciones' => ['cuota_diferencia', 'total_a_cuotas'],
                    'etiquetas' => ['cuota_diferencia' => "Sí, falta cobrar {$fmt($dif)}",
                                    'total_a_cuotas'   => "No, el total final fue {$fmt($suma)}"],
                ];
            } else {
                $problemas[] = [
                    'id' => "pago-total-{$r['id']}", 'tipo' => 'pago_total', 'pago_id' => (int)$r['id'],
                    'titulo' => "«{$nombre}»: las cuotas suman más que el total guardado",
                    'detalle' => "Total guardado {$fmt($total)} · cuotas {$fmt($suma)}. El total correcto es la suma de las cuotas.",
                    'acciones' => ['total_a_cuotas'],
                    'etiquetas' => ['total_a_cuotas' => "Dejar el total en {$fmt($suma)}"],
                ];
            }
        }

        // 4. Cuotas cuyo estado no corresponde a lo cobrado (oculta o inventa saldo).
        $n = (int)$db->query("SELECT COUNT(*) FROM pago_cuotas pc JOIN pagos pg ON pg.id = pc.pago_id
                              WHERE pg.deleted_at IS NULL AND pc.estado <> (" . cuotaEstadoSql() . ")")->fetchColumn();
        if ($n > 0) {
            $problemas[] = [
                'id' => 'estados-cuotas',
                'tipo' => 'estados_cuotas',
                'titulo' => "{$n} " . ($n === 1 ? 'cuota tiene' : 'cuotas tienen') . ' un estado que no corresponde a lo cobrado',
                'detalle' => 'Ej: marcada "Pagado" con saldo pendiente. Se recalcula desde los abonos registrados.',
                'acciones' => ['estados_cuotas'],
            ];
        }

        // 5. Pagos cuyo proyecto se borró para siempre (quedan sueltos).
        $n = (int)$db->query("SELECT COUNT(*) FROM pagos pg LEFT JOIN proyectos pr ON pr.id = pg.proyecto_id
                              WHERE pg.proyecto_id IS NOT NULL AND pr.id IS NULL")->fetchColumn();
        if ($n > 0) {
            $problemas[] = [
                'id' => 'pagos-huerfanos', 'tipo' => 'pagos_huerfanos',
                'titulo' => "{$n} " . ($n === 1 ? 'pago quedó' : 'pagos quedaron') . ' sin proyecto',
                'detalle' => 'Su proyecto se eliminó para siempre. No aparecen en ninguna pantalla.',
                'acciones' => ['borrar_pagos_huerfanos'],
            ];
        }

        // 6. Papelera: lo que lleva más de PAPELERA_DIAS días.
        $n = 0;
        foreach (PAPELERA_TABLAS as $t) {
            try { $n += (int)$db->query("SELECT COUNT(*) FROM {$t} WHERE deleted_at < DATE_SUB(NOW(), INTERVAL " . PAPELERA_DIAS . " DAY)")->fetchColumn(); }
            catch (Throwable $e) { /* tabla sin deleted_at */ }
        }
        if ($n > 0) {
            $problemas[] = [
                'id' => 'papelera-antigua', 'tipo' => 'papelera_antigua',
                'titulo' => "{$n} " . ($n === 1 ? 'elemento lleva' : 'elementos llevan') . ' más de ' . PAPELERA_DIAS . ' días en la papelera',
                'detalle' => 'Se borran solos cada día; aquí puedes hacerlo ya. Lo que aún tiene cosas activas '
                    . '(un cliente con proyectos, un proyecto con pagos) no se borra: restáuralo o envía eso también a la papelera.',
                'acciones' => ['vaciar_papelera_antigua'],
            ];
        }

        $clientes = $db->query("SELECT c.id, {$nombreSql} AS nombre FROM clientes c WHERE c.deleted_at IS NULL ORDER BY nombre")
                       ->fetchAll(PDO::FETCH_ASSOC);
        ok(['problemas' => $problemas, 'clientes' => array_map(fn($c) => ['id' => (int)$c['id'], 'nombre' => $c['nombre']], $clientes)]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = body();
        $accion = (string)($b['accion'] ?? '');
        $cid = (int)($b['cliente_id'] ?? 0);
        $pid = (int)($b['pago_id'] ?? 0);
        $db->beginTransaction();
        $msg = '';

        switch ($accion) {
            case 'cascada_papelera':
                $at = $db->prepare("SELECT deleted_at FROM clientes WHERE id = ? AND deleted_at IS NOT NULL");
                $at->execute([$cid]);
                $fecha = $at->fetchColumn();
                if (!$fecha) err('El cliente no está en la papelera');
                $n = 0;
                $st = $db->prepare("UPDATE pagos t LEFT JOIN proyectos pr ON pr.id = t.proyecto_id SET t.deleted_at = ?
                                    WHERE COALESCE(pr.cliente_id, t.cliente_id) = ? AND t.deleted_at IS NULL");
                $st->execute([$fecha, $cid]); $n += $st->rowCount();
                foreach (array_keys($tablasCliente) as $tabla) {
                    $st = $db->prepare("UPDATE {$tabla} SET deleted_at = ? WHERE cliente_id = ? AND deleted_at IS NULL");
                    $st->execute([$fecha, $cid]); $n += $st->rowCount();
                }
                $msg = "{$n} registros enviados a la papelera junto con el cliente";
                break;

            case 'restaurar_cliente':
                $db->prepare("UPDATE clientes SET deleted_at = NULL WHERE id = ?")->execute([$cid]);
                $msg = 'Cliente restaurado';
                break;

            case 'mover_cliente':
                $dest = (int)($b['destino_id'] ?? 0);
                $ok = $db->prepare("SELECT 1 FROM clientes WHERE id = ? AND deleted_at IS NULL");
                $ok->execute([$dest]);
                if (!$ok->fetchColumn() || $dest === $cid) err('Elige un cliente activo distinto');
                $n = 0;
                // Pagos de sus proyectos activos (o a su nombre) → al destino
                $st = $db->prepare("UPDATE pagos t LEFT JOIN proyectos pr ON pr.id = t.proyecto_id SET t.cliente_id = ?
                                    WHERE COALESCE(pr.cliente_id, t.cliente_id) = ? AND t.deleted_at IS NULL");
                $st->execute([$dest, $cid]); $n += $st->rowCount();
                foreach (array_keys($tablasCliente) as $tabla) {
                    $st = $db->prepare("UPDATE {$tabla} SET cliente_id = ? WHERE cliente_id = ? AND deleted_at IS NULL");
                    $st->execute([$dest, $cid]); $n += $st->rowCount();
                }
                foreach (['entregas', 'documentos'] as $tabla) {   // pueden no existir (migración)
                    try {
                        $db->prepare("UPDATE {$tabla} SET cliente_id = ? WHERE cliente_id = ?")->execute([$dest, $cid]);
                    } catch (Throwable $e) { /* sin la tabla */ }
                }
                $msg = "{$n} registros pasados al otro cliente";
                break;

            case 'pago_cliente':
                $db->prepare("UPDATE pagos pg JOIN proyectos pr ON pr.id = pg.proyecto_id SET pg.cliente_id = pr.cliente_id WHERE pg.id = ?")
                   ->execute([$pid]);
                $msg = 'El pago quedó con el cliente de su proyecto';
                break;

            case 'total_a_cuotas':
                $db->prepare("UPDATE pagos pg SET pg.monto_total = ({$sumaCuotasSql}) WHERE pg.id = ?")->execute([$pid]);
                $msg = 'Total ajustado a la suma de las cuotas';
                break;

            case 'cuota_diferencia':
                $st = $db->prepare("SELECT pg.monto_total, ({$sumaCuotasSql}) AS suma,
                                           (SELECT COALESCE(MAX(numero_cuota), 0) FROM pago_cuotas WHERE pago_id = pg.id) AS ult,
                                           (SELECT tipo_pago FROM pago_cuotas WHERE pago_id = pg.id ORDER BY numero_cuota DESC LIMIT 1) AS tipo
                                    FROM pagos pg WHERE pg.id = ?");
                $st->execute([$pid]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                $dif = $r ? (int)$r['monto_total'] - (int)$r['suma'] : 0;
                if ($dif <= 0) err('No hay diferencia por cobrar');
                $db->prepare("INSERT INTO pago_cuotas (pago_id, numero_cuota, boleta_monto, pago_monto, estado, tipo_pago)
                              VALUES (?, ?, ?, 0, 'Pendiente', ?)")
                   ->execute([$pid, (int)$r['ult'] + 1, $dif, $r['tipo'] ?: 'boleta']);
                $msg = 'Listo: cuota pendiente de $' . number_format($dif, 0, ',', '.') . ' agregada; ya aparece en Por cobrar';
                break;

            case 'estados_cuotas':
                $st = $db->query("UPDATE pago_cuotas pc SET pc.estado = (" . cuotaEstadoSql() . ") WHERE pc.estado <> (" . cuotaEstadoSql() . ")");
                $msg = $st->rowCount() . ' cuotas recalculadas';
                break;

            case 'borrar_pagos_huerfanos':
                $st = $db->query("DELETE pg FROM pagos pg LEFT JOIN proyectos pr ON pr.id = pg.proyecto_id
                                  WHERE pg.proyecto_id IS NOT NULL AND pr.id IS NULL");
                $msg = $st->rowCount() . ' pagos sin proyecto eliminados';
                break;

            case 'vaciar_papelera_antigua':
                // Misma regla que el proceso diario (no arrastra cosas activas por cascada).
                $n = procVaciarPapeleraAntigua($db);
                $msg = "{$n} elementos antiguos borrados de la papelera";
                break;

            default:
                err('Acción no válida');
        }

        $db->commit();
        audit($db, $user['id'], 'UPDATE', 'integridad', "Revisar datos: {$accion} — {$msg}", $cid ?: ($pid ?: null));
        ok(['mensaje' => $msg]);
    }

    err('Método no permitido', 405);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    errSafe($e, 500);
}

/** Estado que le corresponde a una cuota (alias pc), igual que pagos.php → procesarAbonos. */
function cuotaEstadoSql(): string {
    return "CASE WHEN COALESCE(pc.pago_monto, 0) <= 0 THEN 'Pendiente'
                 WHEN COALESCE(pc.boleta_monto, 0) > 0 AND pc.pago_monto < pc.boleta_monto THEN 'Parcial'
                 ELSE 'Pagado' END";
}
