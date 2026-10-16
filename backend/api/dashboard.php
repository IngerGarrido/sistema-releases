<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();  // Proteger dashboard
    $db->exec("SET NAMES utf8mb4");

    $out = [];

    // Conteos básicos: Solo contamos lo que existe
    $out['total_cotizaciones'] = (int)$db->query("SELECT COUNT(*) FROM cotizaciones where estado = 'Pendiente' AND deleted_at IS NULL")->fetchColumn();
    $out['proyectos_activos'] = (int)$db->query("SELECT COUNT(*) FROM proyectos WHERE estado = 'En curso' AND deleted_at IS NULL")->fetchColumn();
    // Cantidad de pagos con cuotas pendientes (para el badge del sidebar)
    $out['total_pagos_pendientes'] = (int)$db->query("
        SELECT COUNT(DISTINCT pg.id)
        FROM pagos pg
        INNER JOIN pago_cuotas pc ON pc.pago_id = pg.id
        WHERE pc.estado IN ('Pendiente','Parcial') AND pg.deleted_at IS NULL
    ")->fetchColumn();

    $periodo = $_GET['periodo'] ?? 'mes_actual';
    $where_fecha = "";
    $where_fecha_pend = "";

    // Fecha de cobro: usamos pago_fecha, pero si está vacía (caso de pagos
    // parciales marcados sin fecha de pago) caemos a boleta_fecha para que
    // el cobro NO desaparezca del período. COALESCE evita que MONTH(NULL)
    // excluya pagos parciales legítimos de "Ingreso en cuenta" / "Meta".
    $fechaCobro = "COALESCE(pc.pago_fecha, pc.boleta_fecha)";
    if ($periodo === 'mes_actual') {
        $where_fecha = " AND MONTH({$fechaCobro}) = MONTH(CURDATE()) AND YEAR({$fechaCobro}) = YEAR(CURDATE())";
        $where_fecha_pend = " AND MONTH(pc.boleta_fecha) = MONTH(CURDATE()) AND YEAR(pc.boleta_fecha) = YEAR(CURDATE())";
    } elseif ($periodo === 'mes_pasado') {
        $where_fecha = " AND MONTH({$fechaCobro}) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR({$fechaCobro}) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
        $where_fecha_pend = " AND MONTH(pc.boleta_fecha) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(pc.boleta_fecha) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
    } elseif ($periodo === 'anio') {
        $where_fecha = " AND YEAR({$fechaCobro}) = YEAR(CURDATE())";
        $where_fecha_pend = " AND YEAR(pc.boleta_fecha) = YEAR(CURDATE())";
    }

    // Filtro equivalente sobre los ABONOS (pago_abonos.fecha). Cada abono cuenta
    // en el mes en que realmente entró el dinero → ingresos por período exactos
    // aunque una misma boleta se haya cobrado en partes en meses distintos.
    $where_abono = "";
    if ($periodo === 'mes_actual') {
        $where_abono = " AND MONTH(pa.fecha) = MONTH(CURDATE()) AND YEAR(pa.fecha) = YEAR(CURDATE())";
    } elseif ($periodo === 'mes_pasado') {
        $where_abono = " AND MONTH(pa.fecha) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(pa.fecha) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
    } elseif ($periodo === 'anio') {
        $where_abono = " AND YEAR(pa.fecha) = YEAR(CURDATE())";
    }

    // TOTAL COBRADO del período = suma de abonos cuya fecha cae en el período.
    $out['total_cobrado'] = (int)($db->query("
        SELECT COALESCE(SUM(pa.monto), 0)
        FROM pago_abonos pa
        INNER JOIN pago_cuotas pc ON pc.id = pa.cuota_id
        INNER JOIN pagos pg ON pg.id = pc.pago_id
        WHERE pg.deleted_at IS NULL $where_abono
    ")->fetchColumn());

    // TOTAL PENDIENTE: boleta_monto para Pendiente/Vencido + saldo (boleta-pagado) para Parcial
    $out['total_pendiente'] = (int)($db->query("
        SELECT COALESCE(SUM(
            CASE
                WHEN pc.estado = 'Parcial' THEN GREATEST(pc.boleta_monto - pc.pago_monto, 0)
                ELSE pc.boleta_monto
            END
        ), 0)
        FROM pago_cuotas pc
        INNER JOIN pagos pg ON pg.id = pc.pago_id
        WHERE pc.estado IN ('Pendiente','Parcial') AND pg.deleted_at IS NULL
    ")->fetchColumn());

    // Cobros por cuota DENTRO del período (suma de abonos del período por cuota).
    // Se usa para el desglose boleta/directo y la retención SII del período.
    // pago_monto aquí = lo cobrado en el período (no el total histórico de la
    // cuota), para que el SII y el desglose reflejen exactamente el selector.
    $out['boletas_pagadas'] = $db->query("
        SELECT pc.boleta_monto, COALESCE(SUM(pa.monto), 0) as pago_monto, pc.tipo_pago
        FROM pago_cuotas pc
        INNER JOIN pagos pg ON pg.id = pc.pago_id
        INNER JOIN pago_abonos pa ON pa.cuota_id = pc.id
        WHERE pg.deleted_at IS NULL $where_abono
        GROUP BY pc.id, pc.boleta_monto, pc.tipo_pago
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Base de la retención SII: monto de las boletas EMITIDAS en el período
    // (por boleta_fecha), independiente de si se cobraron o no — la boleta ya
    // se emitió. Solo tipo 'boleta' (los pagos directos no generan retención).
    // La retención = SUM(boleta_monto) × % (se aplica el % en el frontend).
    $out['boletas_emitidas_monto'] = (int)($db->query("
        SELECT COALESCE(SUM(pc.boleta_monto), 0)
        FROM pago_cuotas pc
        INNER JOIN pagos pg ON pg.id = pc.pago_id
        WHERE pc.tipo_pago = 'boleta' AND pg.deleted_at IS NULL $where_fecha_pend
    ")->fetchColumn());

    // Proyectos por vencer (Próximos 30 días)
    $resV = $db->query("
        SELECT p.id, p.nombre, p.estado, c.nombre as cliente,
               s.nombre as sitio_nombre, DATEDIFF(p.fecha_termino, CURDATE()) as dias
        FROM proyectos p
        INNER JOIN clientes c ON p.cliente_id = c.id
        LEFT JOIN sitios s ON s.id = p.sitio_id
        WHERE p.estado != 'Terminado' AND p.fecha_termino IS NOT NULL AND p.deleted_at IS NULL
        AND DATEDIFF(p.fecha_termino, CURDATE()) BETWEEN 0 AND 30
        ORDER BY p.fecha_termino ASC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out['proyectos_por_vencer'] = [];
    foreach ($resV as $v) {
        $out['proyectos_por_vencer'][] = [
            'id' => (string)$v['id'],
            'nombre' => $v['nombre'],
            'estado' => $v['estado'],
            'cliente_nombre' => $v['cliente'],
            'sitio_nombre' => $v['sitio_nombre'],
            'dias_restantes' => (int)$v['dias']
        ];
    }

    // Actividad reciente (Solo si hay proyectos)
    $out['proyectos_recientes'] = $db->query("
        SELECT p.id, p.nombre, p.estado,
               COALESCE(NULLIF(c.nombre_agencia, ''), c.nombre) as cliente_nombre,
               s.nombre as sitio_nombre
        FROM proyectos p
        LEFT JOIN clientes c ON c.id = p.cliente_id
        LEFT JOIN sitios s ON s.id = p.sitio_id
        WHERE p.deleted_at IS NULL
        ORDER BY p.id DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Boletas pendientes: Cambiamos LEFT JOIN por INNER JOIN
    // Así, si borraste el pago, la boleta "huérfana" desaparece del dashboard
    $resB = $db->query("
        SELECT pc.id, pc.boleta_fecha, pc.boleta_monto, pc.pago_monto, pc.estado, p.nombre as proyecto_txt
        FROM pago_cuotas pc
        INNER JOIN pagos pg ON pg.id = pc.pago_id
        INNER JOIN proyectos p ON p.id = pg.proyecto_id
        WHERE pc.estado IN ('Pendiente','Parcial') AND pg.deleted_at IS NULL AND p.deleted_at IS NULL
        ORDER BY pc.boleta_fecha ASC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out['boletas_pendientes'] = [];
    foreach ($resB as $b) {
        // Para Parciales, el monto pendiente es el saldo (boleta − pagado)
        $saldo = $b['estado'] === 'Parcial'
            ? max(0, (int)$b['boleta_monto'] - (int)$b['pago_monto'])
            : (int)$b['boleta_monto'];
        $out['boletas_pendientes'][] = [
            'id' => $b['id'],
            'boleta_fecha' => $b['boleta_fecha'],
            'boleta_monto' => $saldo,
            'proyecto_nombre' => $b['proyecto_txt']
        ];
    }

    // DATOS PARA GRÁFICOS
    
    // 1. Ingresos mensuales (últimos 6 meses) - Simplificado para evitar ONLY_FULL_GROUP_BY
    try {
        // NOTA: solo agrupar por columnas reales (no CONCAT/MONTHNAME) para
        // compatibilidad con MySQL en modo only_full_group_by (strict).
        // El nombre del mes lo formateamos en PHP abajo.
        $resIng = $db->query("
            SELECT
                YEAR(pa.fecha) as anio,
                MONTH(pa.fecha) as mes_num,
                COALESCE(SUM(pa.monto), 0) as total
            FROM pago_abonos pa
            INNER JOIN pago_cuotas pc ON pc.id = pa.cuota_id
            INNER JOIN pagos pg ON pg.id = pc.pago_id
            WHERE pg.deleted_at IS NULL
              AND pa.fecha >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY YEAR(pa.fecha), MONTH(pa.fecha)
            ORDER BY anio ASC, mes_num ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Si falla, devolver array vacío
        $resIng = [];
    }

    // Meta mensual (default 500000)
    $metaMensual = 500000;
    try {
        $metaRow = $db->query("SELECT valor FROM configuracion WHERE clave = 'meta_mensual' LIMIT 1")->fetchColumn();
        if ($metaRow) $metaMensual = (int)$metaRow;
    } catch (Exception $e) {
        // Si falla, usar default
    }
    
    // Meses en español (no dependemos de locale del servidor)
    $MESES_ES = ['', 'Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    $out['ingresos_mensuales'] = [];
    foreach ($resIng as $ing) {
        $mn = (int)$ing['mes_num'];
        $out['ingresos_mensuales'][] = [
            'mes'   => ($MESES_ES[$mn] ?? '?') . ' ' . $ing['anio'],
            'total' => (int)$ing['total'],
            'meta'  => $metaMensual
        ];
    }

    // 2. Proyectos por estado
    $resEst = $db->query("
        SELECT 
            estado,
            COUNT(*) as cantidad
        FROM proyectos
        WHERE deleted_at IS NULL
        GROUP BY estado
        ORDER BY cantidad DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out['proyectos_por_estado'] = [];
    foreach ($resEst as $est) {
        $out['proyectos_por_estado'][] = [
            'estado' => $est['estado'],
            'cantidad' => (int)$est['cantidad']
        ];
    }

    // 2b. Cotizaciones por estado (para card donut del dashboard)
    // Incluye monto total por estado (suma de items × precio - descuento global)
    $resCotEst = $db->query("
        SELECT c.estado,
               COUNT(*) as cantidad,
               COALESCE(SUM(
                 (SELECT SUM(ci.cantidad * ci.precio_unitario)
                  FROM cotizacion_items ci WHERE ci.cotizacion_id = c.id)
                 * (1 - COALESCE(c.descuento_global, 0) / 100)
               ), 0) as monto_total
        FROM cotizaciones c
        WHERE c.deleted_at IS NULL
        GROUP BY c.estado
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out['cotizaciones_por_estado'] = [];
    foreach ($resCotEst as $est) {
        $out['cotizaciones_por_estado'][] = [
            'estado' => $est['estado'],
            'cantidad' => (int)$est['cantidad'],
            'monto' => (int)round($est['monto_total'])
        ];
    }

    // 3. Próximas boletas (para notificaciones)
    $out['proximas_boletas'] = $db->query("
        SELECT pc.boleta_fecha, p.nombre as proyecto_txt
        FROM pago_cuotas pc
        INNER JOIN pagos pg ON pg.id = pc.pago_id
        INNER JOIN proyectos p ON p.id = pg.proyecto_id
        WHERE pc.estado IN ('Pendiente','Parcial') AND pg.deleted_at IS NULL
        ORDER BY pc.boleta_fecha ASC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ============================================================
    // KPIs ADICIONALES (Sprint 4)
    // ============================================================

    // 4. Tasa de conversión: % cotizaciones (últimos 90 días) que se convirtieron
    //    a proyecto. Una cotización está "convertida" si tiene proyecto_id NOT NULL
    //    o estado = 'Aceptada' (ver cotizaciones_convertir.php).
    try {
        $totCot = (int)$db->query("
            SELECT COUNT(*) FROM cotizaciones
            WHERE deleted_at IS NULL
              AND fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        ")->fetchColumn();
        $convCot = (int)$db->query("
            SELECT COUNT(*) FROM cotizaciones
            WHERE deleted_at IS NULL
              AND fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
              AND (proyecto_id IS NOT NULL OR estado = 'Aceptada')
        ")->fetchColumn();
        $out['tasa_conversion'] = [
            'total'         => $totCot,
            'convertidas'   => $convCot,
            'porcentaje'    => $totCot > 0 ? round(($convCot / $totCot) * 100, 1) : 0
        ];
    } catch (Exception $e) {
        $out['tasa_conversion'] = null;
    }

    // 5. Ticket promedio: AVG(total) de cotizaciones aprobadas/convertidas
    //    últimos 90 días. Total se calcula sumando items.
    try {
        // Total real de cada cotización: neto × (1 − %descuento_global).
        $row = $db->query("
            SELECT AVG(t.total) as prom, COUNT(*) as n
            FROM (
                SELECT c.id,
                       COALESCE((SELECT SUM(ci.cantidad * ci.precio_unitario)
                                 FROM cotizacion_items ci WHERE ci.cotizacion_id = c.id), 0)
                       * (1 - COALESCE(c.descuento_global, 0) / 100) as total
                FROM cotizaciones c
                WHERE c.deleted_at IS NULL
                  AND c.fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                  AND (c.proyecto_id IS NOT NULL OR c.estado = 'Aceptada')
            ) t
        ")->fetch(PDO::FETCH_ASSOC);
        $out['ticket_promedio'] = [
            'monto' => (int)round($row['prom'] ?? 0),
            'n'     => (int)($row['n'] ?? 0)
        ];
    } catch (Exception $e) {
        $out['ticket_promedio'] = null;
    }

    // 6. Top 5 clientes por suma de pagos cobrados últimos 6 meses
    try {
        $top = $db->query("
            SELECT c.id, c.nombre, SUM(pa.monto) as total
            FROM pago_abonos pa
            INNER JOIN pago_cuotas pc ON pc.id = pa.cuota_id
            INNER JOIN pagos pg ON pg.id = pc.pago_id
            INNER JOIN proyectos p ON p.id = pg.proyecto_id
            INNER JOIN clientes c ON c.id = p.cliente_id
            WHERE pg.deleted_at IS NULL
              AND p.deleted_at IS NULL
              AND c.deleted_at IS NULL
              AND pa.fecha >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY c.id, c.nombre
            ORDER BY total DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
        $out['top_clientes'] = array_map(function($r){
            return [
                'id'     => (int)$r['id'],
                'nombre' => $r['nombre'],
                'total'  => (int)$r['total']
            ];
        }, $top);
    } catch (Exception $e) {
        $out['top_clientes'] = [];
    }

    // 7. Aging de cobros: buckets por días desde boleta_fecha (cuota pendiente)
    //    boleta_fecha cumple el rol de "fecha_emision" en este schema.
    try {
        $agingRows = $db->query("
            SELECT
                CASE
                    WHEN DATEDIFF(CURDATE(), pc.boleta_fecha) BETWEEN 0 AND 30 THEN '0-30'
                    WHEN DATEDIFF(CURDATE(), pc.boleta_fecha) BETWEEN 31 AND 60 THEN '31-60'
                    WHEN DATEDIFF(CURDATE(), pc.boleta_fecha) BETWEEN 61 AND 90 THEN '61-90'
                    WHEN DATEDIFF(CURDATE(), pc.boleta_fecha) > 90 THEN '+90'
                    ELSE 'futuro'
                END as bucket,
                COUNT(*) as cantidad,
                -- Saldo pendiente, NO el total de la boleta: en una cuota
                -- Parcial ya se cobro una parte. Debe cuadrar con Por cobrar.
                SUM(CASE
                        WHEN pc.estado = 'Parcial' THEN GREATEST(pc.boleta_monto - pc.pago_monto, 0)
                        ELSE pc.boleta_monto
                    END) as total
            FROM pago_cuotas pc
            INNER JOIN pagos pg ON pg.id = pc.pago_id
            WHERE pc.estado IN ('Pendiente','Parcial')
              AND pg.deleted_at IS NULL
              AND pc.boleta_fecha IS NOT NULL
            GROUP BY bucket
        ")->fetchAll(PDO::FETCH_ASSOC);

        $aging = [
            '0-30'  => ['cantidad' => 0, 'total' => 0],
            '31-60' => ['cantidad' => 0, 'total' => 0],
            '61-90' => ['cantidad' => 0, 'total' => 0],
            '+90'   => ['cantidad' => 0, 'total' => 0],
        ];
        foreach ($agingRows as $r) {
            if ($r['bucket'] === 'futuro') continue;
            $aging[$r['bucket']] = [
                'cantidad' => (int)$r['cantidad'],
                'total'    => (int)$r['total']
            ];
        }
        $out['aging_cobros'] = $aging;
    } catch (Exception $e) {
        $out['aging_cobros'] = null;
    }

    // 8. Cotizaciones por mes (últimos 6 meses): cantidad y suma
    try {
        $cpm = $db->query("
            SELECT
                YEAR(c.fecha) as anio,
                MONTH(c.fecha) as mes_num,
                CONCAT(MONTHNAME(c.fecha), ' ', YEAR(c.fecha)) as mes_nombre,
                COUNT(*) as cantidad,
                COALESCE(SUM(
                  COALESCE((SELECT SUM(ci.cantidad * ci.precio_unitario)
                            FROM cotizacion_items ci WHERE ci.cotizacion_id = c.id), 0)
                  * (1 - COALESCE(c.descuento_global, 0) / 100)
                ), 0) as total
            FROM cotizaciones c
            WHERE c.deleted_at IS NULL
              AND c.fecha >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY YEAR(c.fecha), MONTH(c.fecha)
            ORDER BY anio ASC, mes_num ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $out['cotizaciones_por_mes'] = array_map(function($r){
            return [
                'mes'      => $r['mes_nombre'],
                'cantidad' => (int)$r['cantidad'],
                'total'    => (int)$r['total']
            ];
        }, $cpm);
    } catch (Exception $e) {
        $out['cotizaciones_por_mes'] = [];
    }

    echo json_encode(['ok' => true, 'data' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    errSafe($e, 500);
}