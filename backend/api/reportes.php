<?php
// reportes.php — Reportes consolidados:
//   • ingresos_mes        : ingresos por mes últimos 12 meses
//   • cobros_pendientes   : detalle de cuotas pendientes con días vencidos
//   • cotizaciones_estado : cotizaciones por estado en rango
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $db->exec("SET NAMES utf8mb4");

    $tipo = $_GET['tipo'] ?? 'todos';
    // Rango de fechas común para todo Reportes (antes hardcodeado a 12 meses
    // por sección). Se acota a [1, 36500] para evitar valores absurdos; el
    // frontend pasa 99999 cuando el usuario elige "todo el historial".
    $dias = max(1, min(36500, (int)($_GET['dias'] ?? 365)));
    $out  = [];

    // Helper: recorta meses iniciales sin actividad para no mostrar
    // meses anteriores al primer uso del sistema. Conserva ceros interiores.
    // $isEmpty: callback que recibe una fila y dice si está "vacía".
    $trimLeadingEmpty = function(array $serie, callable $isEmpty) {
        $i = 0;
        while ($i < count($serie) && $isEmpty($serie[$i])) $i++;
        // Si TODO está vacío, dejamos los últimos 3 meses como contexto.
        if ($i >= count($serie)) return array_slice($serie, -3);
        return array_slice($serie, $i);
    };

    // ──────────────────────────────────────────────────────────
    // 1) INGRESOS POR MES (últimos 12 meses)
    //    Devuelve la serie SIN huecos (meses sin pagos = 0).
    // ──────────────────────────────────────────────────────────
    if ($tipo === 'todos' || $tipo === 'ingresos_mes') {
        try {
            $rows = $db->query("
                SELECT
                    YEAR(pa.fecha)  AS anio,
                    MONTH(pa.fecha) AS mes_num,
                    COALESCE(SUM(pa.monto), 0) AS total
                FROM pago_abonos pa
                INNER JOIN pago_cuotas pc ON pc.id = pa.cuota_id
                INNER JOIN pagos pg ON pg.id = pc.pago_id
                WHERE pg.deleted_at IS NULL
                  AND pa.fecha >= DATE_SUB(CURDATE(), INTERVAL {$dias} DAY)
                GROUP BY YEAR(pa.fecha), MONTH(pa.fecha)
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Indexar por 'YYYY-MM'
            $map = [];
            foreach ($rows as $r) {
                $key = sprintf('%04d-%02d', (int)$r['anio'], (int)$r['mes_num']);
                $map[$key] = (int)$r['total'];
            }

            // Construir serie continua de 12 meses (más antiguo → más reciente)
            $serie = [];
            $meses_es = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
            for ($i = 11; $i >= 0; $i--) {
                $ts = strtotime("-{$i} month", strtotime(date('Y-m-01')));
                $key   = date('Y-m', $ts);
                $label = $meses_es[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
                $serie[] = [
                    'mes'   => $label,
                    'key'   => $key,
                    'total' => $map[$key] ?? 0
                ];
            }
            $out['ingresos_mes'] = $trimLeadingEmpty($serie, fn($r) => ($r['total'] ?? 0) === 0);
        } catch (Throwable $e) {
            $out['ingresos_mes'] = [];
        }
    }

    // ──────────────────────────────────────────────────────────
    // 2) COBROS PENDIENTES (detalle: cliente, proyecto, monto, días)
    //    Ordena por días vencidos DESC para priorizar los más atrasados.
    // ──────────────────────────────────────────────────────────
    if ($tipo === 'todos' || $tipo === 'cobros_pendientes') {
        try {
            $rows = $db->query("
                SELECT
                    pc.id,
                    pc.boleta_fecha,
                    pc.estado,
                    pc.pago_monto,
                    CASE
                        WHEN pc.estado = 'Parcial' THEN GREATEST(pc.boleta_monto - pc.pago_monto, 0)
                        ELSE pc.boleta_monto
                    END AS boleta_monto,
                    p.id   AS proyecto_id,
                    p.nombre AS proyecto_nombre,
                    c.id   AS cliente_id,
                    COALESCE(NULLIF(c.nombre_agencia, ''), c.nombre) AS cliente_nombre,
                    DATEDIFF(CURDATE(), pc.boleta_fecha) AS dias
                FROM pago_cuotas pc
                INNER JOIN pagos pg     ON pg.id = pc.pago_id
                INNER JOIN proyectos p  ON p.id  = pg.proyecto_id
                INNER JOIN clientes c   ON c.id  = p.cliente_id
                WHERE pc.estado IN ('Pendiente','Parcial')
                  AND pg.deleted_at IS NULL
                  AND p.deleted_at  IS NULL
                  AND c.deleted_at  IS NULL
                ORDER BY (pc.boleta_fecha IS NULL) ASC, pc.boleta_fecha ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            $detalle = [];
            $tot = 0;
            $por_bucket = ['vigente'=>0, '1-30'=>0, '31-60'=>0, '61-90'=>0, '+90'=>0];
            foreach ($rows as $r) {
                // Variable distinta a $dias (rango global) para no pisarla.
                $diasRow = $r['boleta_fecha'] === null ? null : (int)$r['dias'];
                $bucket = 'vigente';
                if ($diasRow === null)        $bucket = 'vigente';
                elseif ($diasRow <= 0)        $bucket = 'vigente';
                elseif ($diasRow <= 30)       $bucket = '1-30';
                elseif ($diasRow <= 60)       $bucket = '31-60';
                elseif ($diasRow <= 90)       $bucket = '61-90';
                else                          $bucket = '+90';

                $monto = (int)$r['boleta_monto'];
                $tot += $monto;
                $por_bucket[$bucket] += $monto;

                $detalle[] = [
                    'id'              => (int)$r['id'],
                    'cliente_id'      => (int)$r['cliente_id'],
                    'cliente_nombre'  => $r['cliente_nombre'],
                    'proyecto_id'     => (int)$r['proyecto_id'],
                    'proyecto_nombre' => $r['proyecto_nombre'],
                    'boleta_fecha'    => $r['boleta_fecha'],
                    'monto'           => $monto,
                    'dias_vencido'    => $diasRow,
                    'bucket'          => $bucket,
                ];
            }
            $out['cobros_pendientes'] = [
                'total'   => $tot,
                'items'   => $detalle,
                'buckets' => $por_bucket,
            ];
        } catch (Throwable $e) {
            $out['cobros_pendientes'] = ['total'=>0, 'items'=>[], 'buckets'=>[]];
        }
    }

    // ──────────────────────────────────────────────────────────
    // 3) COTIZACIONES POR ESTADO (usa el rango global $dias)
    // ──────────────────────────────────────────────────────────
    if ($tipo === 'todos' || $tipo === 'cotizaciones_estado') {
        try {
            // Total real por cotización = neto × (1 − %descuento_global).
            // Antes mostraba el neto, sin aplicar descuento global.
            $rows = $db->query("
                SELECT
                    COALESCE(c.estado, 'Sin estado') AS estado,
                    COUNT(*) AS cantidad,
                    COALESCE(SUM(
                      COALESCE((SELECT SUM(ci.cantidad * ci.precio_unitario)
                                FROM cotizacion_items ci
                                WHERE ci.cotizacion_id = c.id), 0)
                      * (1 - COALESCE(c.descuento_global, 0) / 100)
                    ), 0) AS total
                FROM cotizaciones c
                WHERE c.deleted_at IS NULL
                  AND c.fecha >= DATE_SUB(CURDATE(), INTERVAL {$dias} DAY)
                GROUP BY c.estado
                ORDER BY cantidad DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            $items = [];
            $total_cantidad = 0;
            $total_monto = 0;
            foreach ($rows as $r) {
                $items[] = [
                    'estado'   => $r['estado'],
                    'cantidad' => (int)$r['cantidad'],
                    'total'    => (int)$r['total'],
                ];
                $total_cantidad += (int)$r['cantidad'];
                $total_monto    += (int)$r['total'];
            }
            $out['cotizaciones_estado'] = [
                'dias'           => $dias,
                'items'          => $items,
                'total_cantidad' => $total_cantidad,
                'total_monto'    => $total_monto,
            ];
        } catch (Throwable $e) {
            $out['cotizaciones_estado'] = ['dias'=>$dias, 'items'=>[], 'total_cantidad'=>0, 'total_monto'=>0];
        }
    }

    // ──────────────────────────────────────────────────────────
    // 4) SERVICIOS MÁS VENDIDOS (últimos 12 meses)
    //    Suma cotizacion_items de cotizaciones aceptadas/convertidas.
    //    Si servicio_id es NULL, usa la descripcion del item.
    // ──────────────────────────────────────────────────────────
    if ($tipo === 'todos' || $tipo === 'servicios_top') {
        try {
            $rows = $db->query("
                SELECT
                    COALESCE(s.nombre, ci.descripcion, 'Sin clasificar') AS servicio,
                    COUNT(*) AS cantidad,
                    COALESCE(SUM(ci.cantidad * ci.precio_unitario), 0) AS total
                FROM cotizacion_items ci
                INNER JOIN cotizaciones c ON c.id = ci.cotizacion_id
                LEFT  JOIN servicios s    ON s.id = ci.servicio_id
                WHERE c.deleted_at IS NULL
                  AND (c.proyecto_id IS NOT NULL OR c.estado = 'Aceptada')
                  AND c.fecha >= DATE_SUB(CURDATE(), INTERVAL {$dias} DAY)
                GROUP BY servicio
                ORDER BY total DESC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);

            $items = [];
            $tot_monto = 0;
            $tot_cant  = 0;
            foreach ($rows as $r) {
                $monto = (int)$r['total'];
                $cant  = (int)$r['cantidad'];
                $items[] = [
                    'servicio' => $r['servicio'],
                    'cantidad' => $cant,
                    'total'    => $monto,
                ];
                $tot_monto += $monto;
                $tot_cant  += $cant;
            }
            $out['servicios_top'] = [
                'items'        => $items,
                'total_monto'  => $tot_monto,
                'total_cantidad' => $tot_cant,
            ];
        } catch (Throwable $e) {
            $out['servicios_top'] = ['items'=>[], 'total_monto'=>0, 'total_cantidad'=>0];
        }
    }

    // ──────────────────────────────────────────────────────────
    // 5) PRODUCTIVIDAD MENSUAL (últimos 12 meses)
    //    Proyectos terminados por mes + tiempo promedio (inicio→término).
    // ──────────────────────────────────────────────────────────
    if ($tipo === 'todos' || $tipo === 'productividad_mensual') {
        try {
            $rows = $db->query("
                SELECT
                    YEAR(p.fecha_termino)  AS anio,
                    MONTH(p.fecha_termino) AS mes_num,
                    COUNT(*) AS terminados,
                    AVG(
                        CASE WHEN p.fecha_inicio IS NOT NULL
                             THEN DATEDIFF(p.fecha_termino, p.fecha_inicio)
                             ELSE NULL END
                    ) AS dias_promedio
                FROM proyectos p
                WHERE p.deleted_at IS NULL
                  AND p.estado = 'Terminado'
                  AND p.fecha_termino IS NOT NULL
                  AND p.fecha_termino >= DATE_SUB(CURDATE(), INTERVAL {$dias} DAY)
                GROUP BY YEAR(p.fecha_termino), MONTH(p.fecha_termino)
            ")->fetchAll(PDO::FETCH_ASSOC);

            $map = [];
            foreach ($rows as $r) {
                $key = sprintf('%04d-%02d', (int)$r['anio'], (int)$r['mes_num']);
                $map[$key] = [
                    'terminados' => (int)$r['terminados'],
                    'dias_prom'  => $r['dias_promedio'] !== null ? (int)round($r['dias_promedio']) : null,
                ];
            }

            $serie = [];
            $meses_es = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
            $tot_terminados = 0;
            $sum_dias = 0; $n_dias = 0;
            for ($i = 11; $i >= 0; $i--) {
                $ts = strtotime("-{$i} month", strtotime(date('Y-m-01')));
                $key = date('Y-m', $ts);
                $label = $meses_es[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
                $info = $map[$key] ?? ['terminados'=>0, 'dias_prom'=>null];
                $serie[] = [
                    'mes'        => $label,
                    'key'        => $key,
                    'terminados' => $info['terminados'],
                    'dias_prom'  => $info['dias_prom'],
                ];
                $tot_terminados += $info['terminados'];
                if ($info['dias_prom'] !== null) { $sum_dias += $info['dias_prom'] * $info['terminados']; $n_dias += $info['terminados']; }
            }

            // Proyectos activos hoy (informativo)
            $activos = (int)$db->query("
                SELECT COUNT(*) FROM proyectos
                WHERE deleted_at IS NULL AND estado IN ('En curso', 'Pausado')
            ")->fetchColumn();

            $out['productividad_mensual'] = [
                'serie'              => $trimLeadingEmpty($serie, fn($r) => ($r['terminados'] ?? 0) === 0),
                'total_terminados'   => $tot_terminados,
                'dias_promedio_glob' => $n_dias > 0 ? (int)round($sum_dias / $n_dias) : null,
                'activos_hoy'        => $activos,
            ];
        } catch (Throwable $e) {
            $out['productividad_mensual'] = ['serie'=>[], 'total_terminados'=>0, 'dias_promedio_glob'=>null, 'activos_hoy'=>0];
        }
    }

    // ──────────────────────────────────────────────────────────
    // 6) CONVERSIÓN MENSUAL DE COTIZACIONES (últimos 12 meses)
    //    Cotizaciones emitidas vs aceptadas/convertidas por mes.
    // ──────────────────────────────────────────────────────────
    if ($tipo === 'todos' || $tipo === 'conversion_mensual') {
        try {
            $rows = $db->query("
                SELECT
                    YEAR(c.fecha)  AS anio,
                    MONTH(c.fecha) AS mes_num,
                    COUNT(*) AS emitidas,
                    SUM(CASE WHEN c.proyecto_id IS NOT NULL OR c.estado = 'Aceptada' THEN 1 ELSE 0 END) AS aceptadas
                FROM cotizaciones c
                WHERE c.deleted_at IS NULL
                  AND c.fecha >= DATE_SUB(CURDATE(), INTERVAL {$dias} DAY)
                GROUP BY YEAR(c.fecha), MONTH(c.fecha)
            ")->fetchAll(PDO::FETCH_ASSOC);

            $map = [];
            foreach ($rows as $r) {
                $key = sprintf('%04d-%02d', (int)$r['anio'], (int)$r['mes_num']);
                $map[$key] = [
                    'emitidas'  => (int)$r['emitidas'],
                    'aceptadas' => (int)$r['aceptadas'],
                ];
            }

            $serie = [];
            $meses_es = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
            $tot_em = 0; $tot_ac = 0;
            for ($i = 11; $i >= 0; $i--) {
                $ts = strtotime("-{$i} month", strtotime(date('Y-m-01')));
                $key = date('Y-m', $ts);
                $label = $meses_es[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
                $info = $map[$key] ?? ['emitidas'=>0, 'aceptadas'=>0];
                $tasa = $info['emitidas'] > 0 ? round(($info['aceptadas'] / $info['emitidas']) * 100, 1) : 0;
                $serie[] = [
                    'mes'       => $label,
                    'key'       => $key,
                    'emitidas'  => $info['emitidas'],
                    'aceptadas' => $info['aceptadas'],
                    'tasa'      => $tasa,
                ];
                $tot_em += $info['emitidas'];
                $tot_ac += $info['aceptadas'];
            }
            $out['conversion_mensual'] = [
                'serie'         => $trimLeadingEmpty($serie, fn($r) => ($r['emitidas'] ?? 0) === 0),
                'tot_emitidas'  => $tot_em,
                'tot_aceptadas' => $tot_ac,
                'tasa_global'   => $tot_em > 0 ? round(($tot_ac / $tot_em) * 100, 1) : 0,
            ];
        } catch (Throwable $e) {
            $out['conversion_mensual'] = ['serie'=>[], 'tot_emitidas'=>0, 'tot_aceptadas'=>0, 'tasa_global'=>0];
        }
    }

    // ──────────────────────────────────────────────────────────
    // 7) PRONÓSTICO DE INGRESOS (próximos 30/60/90 días)
    //    Suma cuotas pendientes con boleta_fecha en cada ventana futura.
    // ──────────────────────────────────────────────────────────
    if ($tipo === 'todos' || $tipo === 'pronostico_ingresos') {
        try {
            $rows = $db->query("
                SELECT
                    pc.id,
                    pc.boleta_fecha,
                    CASE
                        WHEN pc.estado = 'Parcial' THEN GREATEST(pc.boleta_monto - pc.pago_monto, 0)
                        ELSE pc.boleta_monto
                    END AS boleta_monto,
                    p.nombre AS proyecto_nombre,
                    COALESCE(NULLIF(c.nombre_agencia, ''), c.nombre) AS cliente_nombre,
                    DATEDIFF(pc.boleta_fecha, CURDATE()) AS dias
                FROM pago_cuotas pc
                INNER JOIN pagos pg     ON pg.id = pc.pago_id
                INNER JOIN proyectos p  ON p.id  = pg.proyecto_id
                INNER JOIN clientes c   ON c.id  = p.cliente_id
                WHERE pc.estado IN ('Pendiente','Parcial')
                  AND pg.deleted_at IS NULL
                  AND p.deleted_at  IS NULL
                  AND c.deleted_at  IS NULL
                  AND pc.boleta_fecha IS NOT NULL
                  AND pc.boleta_fecha >= CURDATE()
                ORDER BY pc.boleta_fecha ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            $b30 = 0; $b60 = 0; $b90 = 0; $b90p = 0;
            $n30 = 0; $n60 = 0; $n90 = 0; $n90p = 0;
            $items = [];
            foreach ($rows as $r) {
                $dias  = (int)$r['dias'];
                $monto = (int)$r['boleta_monto'];
                if      ($dias <= 30) { $b30  += $monto; $n30++; }
                elseif  ($dias <= 60) { $b60  += $monto; $n60++; }
                elseif  ($dias <= 90) { $b90  += $monto; $n90++; }
                else                  { $b90p += $monto; $n90p++; }
                $items[] = [
                    'id'              => (int)$r['id'],
                    'boleta_fecha'    => $r['boleta_fecha'],
                    'monto'           => $monto,
                    'proyecto_nombre' => $r['proyecto_nombre'],
                    'cliente_nombre'  => $r['cliente_nombre'],
                    'dias'            => $dias,
                ];
            }
            $out['pronostico_ingresos'] = [
                'buckets' => [
                    '30'  => ['monto'=>$b30,  'cantidad'=>$n30],
                    '60'  => ['monto'=>$b60,  'cantidad'=>$n60],
                    '90'  => ['monto'=>$b90,  'cantidad'=>$n90],
                    '+90' => ['monto'=>$b90p, 'cantidad'=>$n90p],
                ],
                'items' => $items,
                'total' => $b30 + $b60 + $b90 + $b90p,
            ];
        } catch (Throwable $e) {
            $out['pronostico_ingresos'] = ['buckets'=>[], 'items'=>[], 'total'=>0];
        }
    }

    echo json_encode(['ok' => true, 'data' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    errSafe($e, 500);
}
