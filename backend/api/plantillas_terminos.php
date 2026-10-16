<?php
// plantillas_terminos.php - API para gestionar plantillas de términos y condiciones
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
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    
    // --- GET: Listar o Obtener una plantilla ---
    if ($method === 'GET') {
        if ($id) {
            // Obtener una plantilla específica
            $stmt = $db->prepare("SELECT * FROM plantillas_terminos WHERE id = ?");
            $stmt->execute([$id]);
            $plantilla = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$plantilla) {
                err('Plantilla no encontrada');
            }
            ok($plantilla);
        } else {
            // Listar todas las plantillas activas (o todas si se pide ?todas=1)
            $todas = isset($_GET['todas']) && $_GET['todas'] === '1';
            
            if ($todas) {
                $stmt = $db->query("SELECT id, nombre, descripcion, activa, orden, 
                                    terminos_obligaciones, terminos_desarrollo, terminos_condiciones,
                                    creado_en 
                                    FROM plantillas_terminos 
                                    ORDER BY orden ASC, nombre ASC");
            } else {
                $stmt = $db->query("SELECT id, nombre, descripcion, activa, orden,
                                    terminos_obligaciones, terminos_desarrollo, terminos_condiciones,
                                    creado_en 
                                    FROM plantillas_terminos 
                                    WHERE activa = 1 
                                    ORDER BY orden ASC, nombre ASC");
            }
            
            $plantillas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ok(['plantillas' => $plantillas]);
        }
    }
    
    // --- POST: Crear nueva plantilla ---
    if ($method === 'POST') {
        $b = body();
        
        // Validaciones
        if (empty($b['nombre'])) {
            err('El nombre de la plantilla es obligatorio');
        }
        
        $nombre = trim($b['nombre']);
        $descripcion = trim($b['descripcion'] ?? '');
        // HTML de Quill saneado (anti-XSS almacenado: se renderiza en el PDF)
        $terminos_obligaciones = sanitizeHtml($b['terminos_obligaciones'] ?? '');
        $terminos_desarrollo = sanitizeHtml($b['terminos_desarrollo'] ?? '');
        $terminos_condiciones = sanitizeHtml($b['terminos_condiciones'] ?? '');
        $activa = isset($b['activa']) ? (int)$b['activa'] : 1;
        $orden = isset($b['orden']) ? (int)$b['orden'] : 0;
        
        $stmt = $db->prepare("INSERT INTO plantillas_terminos 
            (nombre, descripcion, terminos_obligaciones, terminos_desarrollo, terminos_condiciones, activa, orden) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $nombre, $descripcion, $terminos_obligaciones, $terminos_desarrollo, 
            $terminos_condiciones, $activa, $orden
        ]);
        
        $id = $db->lastInsertId();
        ok(['id' => $id, 'mensaje' => 'Plantilla creada exitosamente']);
    }
    
    // --- PUT: Actualizar plantilla ---
    if ($method === 'PUT') {
        if (!$id) {
            err('ID de plantilla requerido');
        }
        
        $b = body();
        
        // Verificar que existe
        $check = $db->prepare("SELECT id FROM plantillas_terminos WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) {
            err('Plantilla no encontrada');
        }
        
        $campos = [];
        $valores = [];
        
        if (isset($b['nombre'])) {
            $campos[] = 'nombre = ?';
            $valores[] = trim($b['nombre']);
        }
        if (isset($b['descripcion'])) {
            $campos[] = 'descripcion = ?';
            $valores[] = trim($b['descripcion']);
        }
        if (isset($b['terminos_obligaciones'])) {
            $campos[] = 'terminos_obligaciones = ?';
            $valores[] = sanitizeHtml($b['terminos_obligaciones']);
        }
        if (isset($b['terminos_desarrollo'])) {
            $campos[] = 'terminos_desarrollo = ?';
            $valores[] = sanitizeHtml($b['terminos_desarrollo']);
        }
        if (isset($b['terminos_condiciones'])) {
            $campos[] = 'terminos_condiciones = ?';
            $valores[] = sanitizeHtml($b['terminos_condiciones']);
        }
        if (isset($b['activa'])) {
            $campos[] = 'activa = ?';
            $valores[] = (int)$b['activa'];
        }
        if (isset($b['orden'])) {
            $campos[] = 'orden = ?';
            $valores[] = (int)$b['orden'];
        }
        
        if (empty($campos)) {
            err('No hay campos para actualizar');
        }
        
        $sql = "UPDATE plantillas_terminos SET " . implode(', ', $campos) . " WHERE id = ?";
        $valores[] = $id;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($valores);
        
        ok(['mensaje' => 'Plantilla actualizada exitosamente']);
    }
    
    // --- DELETE: Eliminar plantilla (soft delete desactivando) ---
    if ($method === 'DELETE') {
        if (!$id) {
            err('ID de plantilla requerido');
        }
        
        // Soft delete: solo desactivar
        $stmt = $db->prepare("UPDATE plantillas_terminos SET activa = 0 WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() === 0) {
            err('Plantilla no encontrada');
        }
        
        ok(['mensaje' => 'Plantilla desactivada exitosamente']);
    }
    
    // Si llegamos aquí, método no permitido
    err('Método no permitido');
    
} catch (Exception $e) {
    err('Error del servidor: ' . $e->getMessage());
}
