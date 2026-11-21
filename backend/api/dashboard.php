<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();  // Proteger dashboard
    $db->exec("SET NAMES utf8mb4");

    // Procesos automáticos (mantenciones, entregas, proyectos entregados,
    // cotizaciones vencidas, avisos). Respaldo del cron diario; idempotente.
    require_once __DIR__ . '/../lib/procesos.php';
    $procesos = procesosDiarios($db, (int)$user['id']);

    $out = [];
    $out['mantenciones_generadas'] = $procesos['mantenciones'];

    // Conteos básicos: Solo contamos lo que existe
    // Aceptadas sin proyecto: el paso siguiente pendiente ("Falta proyecto")
    $out['cotizaciones_falta_proyecto'] = (int)$db->query("SELECT COUNT(*) FROM cotizaciones
        WHERE estado = 'Aceptada' AND (proyecto_id IS NULL OR proyecto_id = 0) AND deleted_at IS NULL")->fetchColumn();
    $out['total_cotizaciones'] = (int)$db->query("SELECT COUNT(*) FROM cotizaciones where estado = 'Pendiente' AND deleted_at IS NULL")->fetchColumn();
    $out['proyectos_activos'] = (int)$db->query("SELECT COUNT(*) FROM proyectos WHERE estado = 'En curso' AND deleted_at IS NULL")->fetchColumn();
    // En curso con la fecha de término ya pasada
    $out['proyectos_atrasados'] = (int)$db->query("SELECT COUNT(*) FROM proyectos WHERE estado = 'En curso' AND deleted_at IS NULL AND fecha_termino IS NOT NULL AND fecha_termino < CURDATE()")->fetchColumn();
    // Entregas en plazo (actas enviadas esperando al cliente) y con observaciones.
    // null si la tabla aún no existe (migración pendiente) → el Panel no muestra la tarjeta.
    $out['entregas'] = null;
    try {
        $cnt = $db->query("SELECT SUM(estado = 'enviada') AS en_plazo, SUM(estado = 'con_observaciones') AS con_obs FROM entregas")->fetch(PDO::FETCH_ASSOC);
        $prox = $db->query("SELECT numero, plazo_vence FROM entregas WHERE estado = 'enviada' AND plazo_vence IS NOT NULL ORDER BY plazo_vence ASC, id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $out['entregas'] = [
            'en_plazo' => (int)($cnt['en_plazo'] ?? 0),
            'con_observaciones' => (int)($cnt['con_obs'] ?? 0),
            'proxima' => $prox ? ['numero' => $prox['numero'], 'plazo_vence' => $prox['plazo_vence']] : null,
        ];
    } catch (Throwable $e) { $out['entregas'] = null; }

    // Saldos de cobro: una sola fuente (lib/saldos.php) para badge, Por cobrar,
    // cuotas vencidas y aging. Así cuadran con Pagos, Proyectos, la ficha del
    // cliente y Reportes.
    require_once __DIR__ . '/../lib/saldos.php';
    $cobros = saldoResumen(saldosCuotas($db));
    $out['cobros'] = [
        'por_cobrar' => $cobros['por_cobrar'],
        'vencidas'   => $cobros['vencidas'],
        'vencido'    => $cobros['vencido'],
        'cuotas_pendientes' => $cobros['cuotas_pendientes'],
        'plazo_dias' => COBRO_PLAZO_DIAS,
    ];
    // Cantidad de pagos con saldo (para el badge del sidebar)
    $out['total_pagos_pendientes'] = $cobros['pagos_pendientes'];

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

    // TOTAL PENDIENTE (saldo de todas las cuotas vivas)
    $out['total_pendiente'] = $cobros['por_cobrar'];

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

    // Renovaciones de dominio / hosting en los próximos 30 días (o vencidas
    // hace ≤ 7 días, por si se pasaron). Entran en "Mi semana" junto a los proyectos.
    $resR = $db->query("
        SELECT s.id, s.nombre AS sitio_nombre, COALESCE(NULLIF(c.nombre_agencia, ''), c.nombre) AS cliente,
               'Renovación de dominio' AS que, DATEDIFF(s.dominio_vence, CURDATE()) AS dias
        FROM sitios s JOIN clientes c ON c.id = s.cliente_id
        WHERE s.deleted_at IS NULL AND s.dominio_vence IS NOT NULL
          AND DATEDIFF(s.dominio_vence, CURDATE()) BETWEEN -7 AND 30
        UNION ALL
        SELECT s.id, s.nombre, COALESCE(NULLIF(c.nombre_agencia, ''), c.nombre),
               'Renovación de hosting', DATEDIFF(s.hosting_vence, CURDATE())
        FROM sitios s JOIN clientes c ON c.id = s.cliente_id
        WHERE s.deleted_at IS NULL AND s.hosting_vence IS NOT NULL
          AND DATEDIFF(s.hosting_vence, CURDATE()) BETWEEN -7 AND 30
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($resR as $r) {
        $out['proyectos_por_vencer'][] = [
            'id' => 'renov-' . $r['id'] . '-' . $r['que'],
            'nombre' => $r['que'],
            'estado' => (int)$r['dias'] < 0 ? 'Vencida' : '',
            'cliente_nombre' => $r['cliente'],
            'sitio_nombre' => $r['sitio_nombre'],
            'dias_restantes' => (int)$r['dias'],
            'tipo' => 'renovacion',
        ];
    }
    usort($out['proyectos_por_vencer'], fn($a, $b) => $a['dias_restantes'] <=> $b['dias_restantes']);
    $out['proyectos_por_vencer'] = array_slice($out['proyectos_por_vencer'], 0, 8);

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

    // Cotizaciones por estado (tarjeta Cotizaciones del Panel)
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


    echo json_encode(['ok' => true, 'data' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    errSafe($e, 500);
}