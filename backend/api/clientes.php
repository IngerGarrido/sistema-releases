<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];
    // Soporte para method override via query string (POST con _method=PUT)
    if ($method === 'POST' && isset($_GET['_method'])) {
        $method = strtoupper($_GET['_method']);
    }

    if ($user['rol'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    
    if (!$id) {
        $path = explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $last = end($path);
        $id = is_numeric($last) ? (int)$last : null;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $esRestore = strpos($uri, 'restore') !== false || isset($_GET['restore']);
    $esHard = isset($_GET['hard']) && $_GET['hard'] == '1';
    $esPapelera = isset($_GET['papelera']) && $_GET['papelera'] == '1';

    $limpiar = function($str) {
        return trim(str_replace("\0", "", (string)($str ?? '')));
    };

    // --- LISTAR CLIENTES (GET) ---
    // Si ?papelera=1, muestra solo eliminados
    // Por defecto, excluye eliminados (deleted_at IS NULL)
    // Soporta paginación: ?page=X&per_page=Y
    if ($method === 'GET') {
        $where = $esPapelera ? "WHERE deleted_at IS NOT NULL" : "WHERE deleted_at IS NULL";

        // Búsqueda server-side (mismo patrón que servicios.php)
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $params = [];
        if ($q !== '') {
            $where .= " AND (nombre LIKE ? OR nombre_agencia LIKE ? OR rut LIKE ? OR email LIKE ?)";
            $like = '%' . escapeLike($q) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
        $per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : null;

        $countSql = "SELECT COUNT(*) FROM clientes {$where}";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        
        // Simplificamos el SQL quitando CAST innecesarios que pueden truncar o alterar el texto
        $sql = "SELECT
            id,
            tipo,
            nombre_agencia,
            nombre,
            url,
            rut,
            email,
            telefono,
            direccion,
            notas,
            created_at,
            updated_at,
            deleted_at
            FROM clientes
            {$where}
            ORDER BY id DESC"; // Cambiado a ID normal para mejor rendimiento
        
        if ($page && $per_page) {
            $offset = ($page - 1) * $per_page;
            $sql .= " LIMIT {$per_page} OFFSET {$offset}";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $raw_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $data = [];
        foreach ($raw_rows as $row) {
            // Aseguramos que el nombre nunca sea null para que React no salte al ID
            $nombre_final = $limpiar($row['nombre']);
            if (empty($nombre_final)) {
                $nombre_final = $limpiar($row['nombre_agencia']);
            }

            $data[] = [
                'id'             => (int)$row['id'],
                'tipo'           => ucfirst(strtolower($limpiar($row['tipo'] ?? 'Directo'))), 
                'nombre_agencia' => $limpiar($row['nombre_agencia']),
                'nombre'         => $nombre_final, // Si nombre está vacío, usa agencia
                'url'            => $limpiar($row['url']),
                'rut'            => $limpiar($row['rut']),
                'email'          => $limpiar($row['email']),
                'telefono'       => $limpiar($row['telefono']),
                'direccion'      => $limpiar($row['direccion']),
                'notas'          => $limpiar($row['notas']),
                'activo'         => 1,
                'deleted_at'     => $row['deleted_at']
            ];
        }
        
        // Responder con o sin paginación según corresponda
        if ($page && $per_page) {
            echo json_encode([
                'ok' => true, 
                'data' => $data,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => $total,
                    'total_pages' => (int)ceil($total / $per_page)
                ]
            ]);
        } else {
            echo json_encode(['ok' => true, 'data' => $data]);
        }
        exit;
    }

    // --- RESTAURAR CLIENTE (POST a clientes.php/restore/ID) ---
    // DEBE IR PRIMERO, antes que CREAR, para que no se confunda con crear cliente
    if ($method === 'POST' && $esRestore && $id) {
        $id_target = (int)preg_replace('/[^0-9]/', '', (string)$id);
        
        $stmt = $db->prepare("UPDATE clientes SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id_target]);
        
        if ($stmt->rowCount() > 0) {
            audit($db, $user['id'], 'RESTORE', 'clientes', "Restauró cliente #{$id_target}", $id_target);
            echo json_encode(['ok' => true, 'message' => 'Cliente restaurado']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Cliente no encontrado o no estaba eliminado']);
        }
        exit;
    }

    // --- CREAR CLIENTE (POST) ---
    if ($method === 'POST') {
        $b = body();
        // Validación server-side (el frontend valida con Zod, pero esto evita
        // que un cliente directo del API envíe datos fuera de rango).
        $tipo = $limpiar($b['tipo'] ?? 'Directo');
        if (!in_array($tipo, ['Agencia', 'Directo'], true)) err('Tipo inválido', 422);
        $email = $limpiar($b['email'] ?? '');
        if ($email !== '' && !validateEmail($email)) err('Email inválido', 422);
        $rut = $limpiar($b['rut'] ?? '');
        if ($rut !== '' && !validateRut($rut)) err('RUT inválido', 422);
        $nombre = $limpiar($b['nombre'] ?? '');
        $nombreAg = $limpiar($b['nombre_agencia'] ?? '');
        // Al menos uno de los dos debe estar
        if ($nombre === '' && $nombreAg === '') err('Nombre o nombre de agencia requerido', 422);
        if (mb_strlen($nombre) > 200 || mb_strlen($nombreAg) > 200) err('Nombre muy largo (máx 200 chars)', 422);

        $sql = "INSERT INTO clientes (tipo, nombre_agencia, nombre, url, rut, email, telefono, direccion, notas)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $limpiar($b['tipo'] ?? 'Directo'),
            $limpiar($b['nombre_agencia'] ?? ''),
            $limpiar($b['nombre'] ?? ''),
            $limpiar($b['url'] ?? ''),
            $limpiar($b['rut'] ?? ''),
            $limpiar($b['email'] ?? ''),
            $limpiar($b['telefono'] ?? ''),
            $limpiar($b['direccion'] ?? ''),
            $limpiar($b['notas'] ?? '')
        ]);
        $cliente_id = $db->lastInsertId();
        
        audit($db, $user['id'], 'CREATE', 'clientes', "Creó cliente #{$cliente_id}: {$b['nombre']}", $cliente_id);
        
        echo json_encode(['ok' => true, 'id' => $cliente_id]);
        exit;
    }

    // --- EDITAR CLIENTE (PUT) ---
    if ($method === 'PUT' && $id) {
        $b = body();
        $id_target = (int)preg_replace('/[^0-9]/', '', (string)$id);

        if ($id_target > 0) {
            // USAMOS UPDATE: Esto modifica los datos SIN borrar el registro,
            // por lo tanto, los proyectos asociados NO se eliminan.
            $sql = "UPDATE clientes SET 
                    tipo = ?, 
                    nombre_agencia = ?, 
                    nombre = ?, 
                    url = ?, 
                    rut = ?, 
                    email = ?, 
                    telefono = ?, 
                    direccion = ?, 
                    notas = ?
                    WHERE id = ? AND deleted_at IS NULL";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $limpiar($b['tipo'] ?? 'Directo'),
                $limpiar($b['nombre_agencia'] ?? ''),
                $limpiar($b['nombre'] ?? ''),
                $limpiar($b['url'] ?? ''),
                $limpiar($b['rut'] ?? ''),
                $limpiar($b['email'] ?? ''),
                $limpiar($b['telefono'] ?? ''),
                $limpiar($b['direccion'] ?? ''),
                $limpiar($b['notas'] ?? ''),
                $id_target
            ]);
            
            audit($db, $user['id'], 'UPDATE', 'clientes', "Editó cliente #{$id_target}: {$b['nombre']}", $id_target);
            
            echo json_encode(['ok' => true]);
            exit;
        }
    }

    // --- ELIMINAR CLIENTE (DELETE) ---
    // Por defecto: SOFT DELETE (marcar deleted_at)
    // Con ?hard=1: ELIMINACIÓN DEFINITIVA
    if ($method === 'DELETE' && $id) {
        $id_target = (int)preg_replace('/[^0-9]/', '', (string)$id);
        
        // Obtener info antes de borrar
        $info = $db->prepare("SELECT nombre FROM clientes WHERE id = ?");
        $info->execute([$id_target]);
        $cli = $info->fetch();
        
        if (!$cli) {
            echo json_encode(['ok' => false, 'error' => 'Cliente no encontrado']);
            exit;
        }
        
        if ($esHard) {
            // ELIMINACIÓN DEFINITIVA (hard delete)
            $db->prepare("DELETE FROM clientes WHERE id = ?")->execute([$id_target]);
            audit($db, $user['id'], 'HARD_DELETE', 'clientes', "Eliminó PERMANENTEMENTE cliente #{$id_target}: " . $cli['nombre'], $id_target);
            echo json_encode(['ok' => true, 'message' => 'Cliente eliminado permanentemente']);
        } else {
            // SOFT DELETE (marcar como eliminado)
            $db->prepare("UPDATE clientes SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id_target]);
            audit($db, $user['id'], 'SOFT_DELETE', 'clientes', "Envió a papelera cliente #{$id_target}: " . $cli['nombre'], $id_target);
            echo json_encode(['ok' => true, 'message' => 'Cliente enviado a papelera']);
        }
        exit;
    }

} catch (Throwable $e) {
    if (function_exists('logError')) {
        logError('Error en clientes.php', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    }
    errSafe($e, 500);
}