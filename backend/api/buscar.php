<?php
/**
 * Buscador Global - Busca en clientes, proyectos, cotizaciones, pagos y servicios
 * GET /buscar.php?q=termino
 */
require __DIR__ . '/../config.php';
cors();

// Obtener conexión a la base de datos
$db = getDB();

// Validar autenticación usando getToken() (compatible con MAMP)
$token = getToken();

if (!$token) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

// Verificar token
$stmt = $db->prepare("SELECT u.id, u.nombre, u.rol FROM usuarios u JOIN sesiones s ON u.id = s.usuario_id WHERE s.token = ? AND s.expira_en > NOW() AND u.activo = 1 LIMIT 1");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Token inválido']);
    exit;
}

// Obtener término de búsqueda
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

$searchTerm = '%' . $query . '%';
$limite = 20;
$resultados = [];

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
    // Error silenciado
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

// 2. Buscar PROYECTOS
try {
    $stmt = $db->prepare("
        SELECT p.id, p.nombre, p.estado, c.nombre as cliente_nombre
        FROM proyectos p
        LEFT JOIN clientes c ON p.cliente_id = c.id
        WHERE p.deleted_at IS NULL
        AND (p.nombre LIKE ? OR c.nombre LIKE ?)
        ORDER BY p.fecha_inicio DESC
        LIMIT ?
    ");
    $stmt->execute([$searchTerm, $searchTerm, $limite]);
    $proyectos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Error silenciado
    $proyectos = [];
}

foreach ($proyectos as $p) {
    $estadoLabels = [
        'pendiente' => '⏳ Pendiente',
        'en_progreso' => '🔄 En progreso',
        'completado' => '✅ Completado',
        'cancelado' => '❌ Cancelado'
    ];
    $resultados[] = [
        'tipo' => 'proyectos',
        'id' => $p['id'],
        'titulo' => $p['nombre'],
        'subtitulo' => ($p['cliente_nombre'] ?: 'Sin cliente') . ' • ' . ($estadoLabels[$p['estado']] ?? $p['estado'])
    ];
}

// 3. Buscar COTIZACIONES
try {
    $stmt = $db->prepare("
        SELECT c.id, c.numero, c.estado, cl.nombre as cliente_nombre
        FROM cotizaciones c
        LEFT JOIN clientes cl ON c.cliente_id = cl.id
        WHERE c.deleted_at IS NULL
        AND (c.numero LIKE ? OR cl.nombre LIKE ?)
        ORDER BY c.fecha DESC
        LIMIT ?
    ");
    $stmt->execute([$searchTerm, $searchTerm, $limite]);
    $cotizaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Error silenciado
    $cotizaciones = [];
}

foreach ($cotizaciones as $cot) {
    $estadoLabels = [
        'pendiente' => '⏳ Pendiente',
        'aprobada' => '✅ Aprobada',
        'rechazada' => '❌ Rechazada'
    ];
    $resultados[] = [
        'tipo' => 'cotizaciones',
        'id' => $cot['id'],
        'titulo' => 'COT-' . $cot['numero'],
        'subtitulo' => ($cot['cliente_nombre'] ?: 'Sin cliente') . ' • ' . ($estadoLabels[$cot['estado']] ?? $cot['estado'])
    ];
}

// 4. Buscar PAGOS
try {
    $stmt = $db->prepare("
        SELECT p.id, p.monto_total, p.descripcion, c.nombre as cliente_nombre
        FROM pagos p
        INNER JOIN clientes c ON p.cliente_id = c.id
        WHERE p.deleted_at IS NULL
        AND (c.nombre LIKE ? OR c.email LIKE ? OR p.descripcion LIKE ?)
        ORDER BY p.id DESC
        LIMIT ?
    ");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $limite]);
    $pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Error silenciado
    $pagos = [];
}

foreach ($pagos as $p) {
    $resultados[] = [
        'tipo' => 'pagos',
        'id' => $p['id'],
        'titulo' => '$' . number_format($p['monto_total'] ?? 0, 0, ',', '.'),
        'subtitulo' => ($p['cliente_nombre'] ?: 'Sin cliente') . ' • ' . ($p['descripcion'] ?: 'Sin descripción')
    ];
}

// 5. Buscar SERVICIOS
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
        'subtitulo' => '$' . number_format($s['precio'] ?? 0, 0, ',', '.') . ($s['descripcion'] ? ' • ' . substr($s['descripcion'], 0, 50) : '')
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
