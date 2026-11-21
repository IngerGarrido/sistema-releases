<?php
/**
 * Buscador Global - Busca en clientes, sitios, proyectos, cotizaciones, pagos, servicios y documentos
 * GET /buscar.php?q=termino
 */
require __DIR__ . '/../config.php';
cors();

// Obtener conexión a la base de datos
$db = getDB();

// Sesión válida (mismo chequeo que el resto de endpoints)
$user = requireAuth();

// Obtener término de búsqueda
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

// escapeLike: que "100%" o "mi_sitio" se busquen literal (no como comodines).
$searchTerm = '%' . escapeLike($query) . '%';
$limite = 20;
$resultados = [];

$nombreCliente = fn($r) => ($r['cliente_agencia'] ?? '') ?: (($r['cliente_nombre'] ?? '') ?: 'Sin cliente');

// 1. Buscar CLIENTES (por nombre, nombre_agencia, email o rut)
try {
    $stmt = $db->prepare("
        SELECT id, nombre, nombre_agencia, email, telefono, rut
        FROM clientes
        WHERE deleted_at IS NULL
        AND (nombre LIKE ? OR nombre_agencia LIKE ? OR email LIKE ? OR rut LIKE ?)
        ORDER BY nombre ASC
        LIMIT ?
    ");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limite]);
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $clientes = [];
}

foreach ($clientes as $c) {
    // Mostrar nombre de agencia si existe, sino el nombre del contacto
    $titulo = $c['nombre_agencia'] ?: $c['nombre'];
    $subtitulo = $c['nombre_agencia'] ? ($c['nombre'] . ' • ') : '';
    $subtitulo .= $c['email'] ? ($c['email'] . ($c['telefono'] ? ' • ' . $c['telefono'] : '')) : ($c['telefono'] ?: 'Sin contacto');

    $resultados[] = [
        'tipo' => 'clientes',
        'id' => $c['id'],
        'titulo' => $titulo,
        'subtitulo' => $subtitulo,
        'rut' => $c['rut']
    ];
}

// 2. Buscar SITIOS (por nombre o URL)
try {
    $stmt = $db->prepare("
        SELECT s.id, s.nombre, s.url_principal, c.nombre AS cliente_nombre, c.nombre_agencia AS cliente_agencia
        FROM sitios s
        LEFT JOIN clientes c ON s.cliente_id = c.id
        WHERE s.deleted_at IS NULL
        AND (s.nombre LIKE ? OR s.url_principal LIKE ?)
        ORDER BY s.nombre ASC
        LIMIT ?
    ");
    $stmt->execute([$searchTerm, $searchTerm, $limite]);
    $sitios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sitios = [];
}

foreach ($sitios as $s) {
    $resultados[] = [
        'tipo' => 'sitios',
        'id' => $s['id'],
        'titulo' => $s['nombre'],
        'subtitulo' => $nombreCliente($s) . ($s['url_principal'] ? ' • ' . preg_replace('#^https?://#', '', $s['url_principal']) : '')
    ];
}

// 3. Buscar PROYECTOS (por nombre, sitio o cliente)
try {
    $stmt = $db->prepare("
        SELECT p.id, p.nombre, p.estado, s.nombre AS sitio_nombre,
               c.nombre AS cliente_nombre, c.nombre_agencia AS cliente_agencia
        FROM proyectos p
        LEFT JOIN clientes c ON p.cliente_id = c.id
        LEFT JOIN sitios s ON p.sitio_id = s.id
        WHERE p.deleted_at IS NULL
        AND (p.nombre LIKE ? OR s.nombre LIKE ? OR c.nombre LIKE ? OR c.nombre_agencia LIKE ?)
        ORDER BY p.fecha_inicio DESC
        LIMIT ?
    ");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limite]);
    $proyectos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $proyectos = [];
}

foreach ($proyectos as $p) {
    $resultados[] = [
        'tipo' => 'proyectos',
        'id' => $p['id'],
        'titulo' => etiquetaProyecto($p['nombre'], $p['sitio_nombre']),
        'subtitulo' => $nombreCliente($p) . ' • ' . $p['estado']
    ];
}

// 4. Buscar COTIZACIONES (por número o cliente)
try {
    $stmt = $db->prepare("
        SELECT c.id, c.numero, c.estado, cl.nombre AS cliente_nombre, cl.nombre_agencia AS cliente_agencia
        FROM cotizaciones c
        LEFT JOIN clientes cl ON c.cliente_id = cl.id
        WHERE c.deleted_at IS NULL
        AND (c.numero LIKE ? OR cl.nombre LIKE ? OR cl.nombre_agencia LIKE ?)
        ORDER BY c.fecha DESC
        LIMIT ?
    ");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $limite]);
    $cotizaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cotizaciones = [];
}

foreach ($cotizaciones as $cot) {
    $resultados[] = [
        'tipo' => 'cotizaciones',
        'id' => $cot['id'],
        // El número ya viene completo (ej: "COT-2026-001"); no anteponer otro "COT-".
        'titulo' => $cot['numero'] ?: ('#' . $cot['id']),
        'subtitulo' => $nombreCliente($cot) . ' • ' . $cot['estado']
    ];
}

// 5. Buscar PAGOS (por cliente, descripción, proyecto o sitio)
try {
    $stmt = $db->prepare("
        SELECT p.id, p.monto_total, p.descripcion, pr.nombre AS proyecto_nombre, s.nombre AS sitio_nombre,
               c.nombre AS cliente_nombre, c.nombre_agencia AS cliente_agencia
        FROM pagos p
        INNER JOIN clientes c ON p.cliente_id = c.id
        LEFT JOIN proyectos pr ON pr.id = p.proyecto_id
        LEFT JOIN sitios s ON s.id = pr.sitio_id
        WHERE p.deleted_at IS NULL
        AND (c.nombre LIKE ? OR c.nombre_agencia LIKE ? OR c.email LIKE ? OR p.descripcion LIKE ? OR pr.nombre LIKE ? OR s.nombre LIKE ?)
        ORDER BY p.id DESC
        LIMIT ?
    ");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limite]);
    $pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pagos = [];
}

foreach ($pagos as $p) {
    $base = $p['proyecto_nombre'] ?: ($p['descripcion'] ?: 'Pago');
    $resultados[] = [
        'tipo' => 'pagos',
        'id' => $p['id'],
        'titulo' => etiquetaProyecto($base, $p['sitio_nombre']),
        'subtitulo' => $nombreCliente($p) . ' • $' . number_format((float)($p['monto_total'] ?? 0), 0, ',', '.')
    ];
}

// 6. Buscar SERVICIOS
try {
    $stmt = $db->prepare("
        SELECT id, nombre, descripcion, precio
        FROM servicios
        WHERE deleted_at IS NULL
        AND (nombre LIKE ? OR descripcion LIKE ?)
        ORDER BY nombre ASC
        LIMIT ?
    ");
    $stmt->execute([$searchTerm, $searchTerm, $limite]);
    $servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Error silenciado
    $servicios = [];
}


foreach ($servicios as $s) {
    $resultados[] = [
        'tipo' => 'servicios',
        'id' => $s['id'],
        'titulo' => $s['nombre'],
        'subtitulo' => '$' . number_format($s['precio'] ?? 0, 0, ',', '.') . ($s['descripcion'] ? ' • ' . mb_substr($s['descripcion'], 0, 50) : '')
    ];
}

// 7. Buscar DOCUMENTOS / enlaces (por título, notas o link). Al elegirlo,
//    el buscador abre el link directo en otra pestaña.
try {
    $stmt = $db->prepare("
        SELECT d.id, d.titulo, d.url, d.tipo, (d.url IS NULL) AS es_archivo, p.nombre AS proyecto_nombre, s.nombre AS sitio_nombre,
               c.nombre AS cliente_nombre, c.nombre_agencia AS cliente_agencia
        FROM documentos d
        JOIN clientes c ON c.id = d.cliente_id AND c.deleted_at IS NULL
        LEFT JOIN proyectos p ON p.id = d.proyecto_id
        LEFT JOIN sitios s ON s.id = d.sitio_id
        WHERE (d.proyecto_id IS NULL OR p.deleted_at IS NULL)
        AND (d.titulo LIKE ? OR d.notas LIKE ? OR d.url LIKE ?)
        ORDER BY d.id DESC
        LIMIT ?
    ");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $limite]);
    $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $documentos = [];   // sin la migración de Documentos
}

foreach ($documentos as $d) {
    $donde = $d['proyecto_nombre'] !== null ? etiquetaProyecto($d['proyecto_nombre'], $d['sitio_nombre']) : 'General';
    $resultados[] = [
        'tipo' => 'documentos',
        'id' => $d['id'],
        'titulo' => $d['titulo'],
        'subtitulo' => $donde . ' · ' . $nombreCliente($d),
        'url' => $d['url'],
        'es_archivo' => (bool)$d['es_archivo'],
    ];
}

// Ordenar por relevancia: los que empiezan con el término primero
usort($resultados, function($a, $b) use ($query) {
    $aStarts = stripos($a['titulo'], $query) === 0;
    $bStarts = stripos($b['titulo'], $query) === 0;
    
    if ($aStarts && !$bStarts) return -1;
    if (!$aStarts && $bStarts) return 1;
    return 0;
});

// Limitar a 15 resultados totales
$resultados = array_slice($resultados, 0, 15);

// Debug
logDebug('Buscador ejecutado', ['total_resultados' => count($resultados)]);

echo json_encode(['ok' => true, 'data' => $resultados]);
