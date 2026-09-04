<?php
/**
 * Exportar datos a Excel (CSV) - Versión Limpia
 */
try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();

    // Autenticación por header Authorization (descargas usan fetch+blob).
    $user = requireAuth();
    rateLimit('exportar.csv', 30, 60);
    try {
        audit($db, (int)$user['id'], 'export.csv', 'exports', 'tipo=' . ($_GET['tipo'] ?? ''));
    } catch (Throwable $e) { /* no romper export por fallo de audit */ }

    $tipo = $_GET['tipo'] ?? '';
    $formato = $_GET['formato'] ?? 'csv';

    $datos = [];
    $headers = [];
    $filename = '';

    switch ($tipo) {
        case 'clientes':
            $stmt = $db->query("
                SELECT id,
                       COALESCE(NULLIF(nombre_agencia, ''), nombre) as nombre,
                       tipo, email, telefono, rut, direccion,
                       DATE_FORMAT(created_at, '%d/%m/%Y') as fecha_registro
                FROM clientes
                WHERE deleted_at IS NULL
                ORDER BY nombre ASC
            ");
            $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $headers = ['ID', 'Nombre', 'Tipo', 'Email', 'Teléfono', 'RUT', 'Dirección', 'Fecha Registro'];
            $filename = 'clientes_' . date('Y-m-d') . '.csv';
            break;

        case 'proyectos':
            $stmt = $db->query("
                SELECT p.id, p.nombre,
                       COALESCE(NULLIF(c.nombre_agencia, ''), c.nombre, 'Sin cliente') as cliente,
                       p.estado, p.presupuesto, p.fecha_inicio, p.fecha_termino,
                       DATE_FORMAT(p.created_at, '%d/%m/%Y') as fecha_registro
                FROM proyectos p
                LEFT JOIN clientes c ON p.cliente_id = c.id
                WHERE p.deleted_at IS NULL
                ORDER BY p.created_at DESC
            ");
            $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $headers = ['ID', 'Nombre', 'Cliente', 'Estado', 'Presupuesto', 'Inicio', 'Fin', 'Fecha Registro'];
            $filename = 'proyectos_' . date('Y-m-d') . '.csv';
            break;

        case 'pagos':
            $stmt = $db->query("
                SELECT p.id,
                       COALESCE(NULLIF(c.nombre_agencia, ''), c.nombre, 'Sin cliente') as cliente,
                       COALESCE(pr.nombre, 'Sin proyecto') as proyecto,
                       COALESCE(p.descripcion, '') as descripcion,
                       p.monto_total,
                       COALESCE((SELECT SUM(pc.pago_monto) FROM pago_cuotas pc
                                 WHERE pc.pago_id = p.id AND pc.estado IN ('Pagado','Parcial')), 0) as cobrado,
                       p.tipo_pago,
                       DATE_FORMAT(p.fecha_creacion, '%d/%m/%Y') as fecha_registro
                FROM pagos p
                LEFT JOIN proyectos pr ON p.proyecto_id = pr.id
                LEFT JOIN clientes c ON p.cliente_id = c.id
                WHERE p.deleted_at IS NULL
                ORDER BY p.id DESC
            ");
            $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $headers = ['ID', 'Cliente', 'Proyecto', 'Descripción', 'Monto Total', 'Cobrado', 'Tipo', 'Fecha Registro'];
            $filename = 'pagos_' . date('Y-m-d') . '.csv';
            break;

        case 'servicios':
            $stmt = $db->query("
                SELECT id, nombre, descripcion, precio,
                       DATE_FORMAT(created_at, '%d/%m/%Y') as fecha_registro
                FROM servicios
                WHERE deleted_at IS NULL
                ORDER BY nombre ASC
            ");
            $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $headers = ['ID', 'Nombre', 'Descripción', 'Precio', 'Fecha Registro'];
            $filename = 'servicios_' . date('Y-m-d') . '.csv';
            break;
            
        case 'cotizaciones':
            $stmt = $db->query("
                SELECT 
                    c.id,
                    CONCAT('COT-', c.numero) as numero,
                    COALESCE(cl.nombre, 'Sin cliente') as cliente,
                    COALESCE(p.nombre, 'Sin proyecto') as proyecto,
                    /* Total real = neto × (1 − %descuento_global) — coincide con lista y PDF. */
                    CAST(ROUND(
                      COALESCE((SELECT SUM(ci.cantidad * ci.precio_unitario) FROM cotizacion_items ci WHERE ci.cotizacion_id = c.id), 0)
                      * (1 - COALESCE(c.descuento_global, 0) / 100)
                    , 0) AS UNSIGNED) as total,
                    c.estado,
                    DATE_FORMAT(c.fecha, '%d/%m/%Y') as fecha
                FROM cotizaciones c
                LEFT JOIN clientes cl ON c.cliente_id = cl.id
                LEFT JOIN proyectos p ON c.proyecto_id = p.id
                WHERE c.deleted_at IS NULL 
                ORDER BY c.fecha DESC
            ");
            $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $headers = ['ID', 'Número', 'Cliente', 'Proyecto', 'Total', 'Estado', 'Fecha'];
            $filename = 'cotizaciones_' . date('Y-m-d') . '.csv';
            break;
            
        case 'sitios':
            $stmt = $db->query("
                SELECT s.id, s.nombre,
                       COALESCE(NULLIF(c.nombre_agencia, ''), c.nombre, 'Sin cliente') as cliente,
                       COALESCE(s.url_principal, '') as url,
                       COALESCE(s.descripcion, '') as descripcion,
                       (SELECT COUNT(*) FROM proyectos p WHERE p.sitio_id = s.id AND p.deleted_at IS NULL) as proyectos,
                       (SELECT COUNT(*) FROM accesos a WHERE a.sitio_id = s.id) as accesos,
                       DATE_FORMAT(s.created_at, '%d/%m/%Y') as fecha_registro
                FROM sitios s
                LEFT JOIN clientes c ON c.id = s.cliente_id
                WHERE s.deleted_at IS NULL
                ORDER BY s.nombre ASC
            ");
            $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $headers = ['ID', 'Sitio', 'Cliente', 'URL', 'Descripción', 'Proyectos', 'Accesos', 'Fecha Registro'];
            $filename = 'sitios_' . date('Y-m-d') . '.csv';
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Tipo no válido']);
            exit;
    }

    // Generar CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // BOM para Excel (UTF-8)
    echo "\xEF\xBB\xBF";

    // Headers
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers, ';');

    // Datos - usar array_values para mantener el orden de las columnas
    foreach ($datos as $fila) {
        fputcsv($output, array_values($fila), ';');
    }

    fclose($output);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    $errorMsg = $e->getMessage();
    logError('Error en exportar', ['message' => $errorMsg, 'trace' => $e->getTraceAsString()]);
    echo json_encode(['ok' => false, 'error' => 'Error: ' . $errorMsg, 'file' => $e->getFile(), 'line' => $e->getLine()]);
}
