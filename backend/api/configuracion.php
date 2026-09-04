<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); 

try {
    require_once __DIR__ . '/../config.php'; // getDB, requireAuth, ok, err, body
    cors();
    $db = getDB();
    $user = requireAuth();
    
    $method = $_SERVER['REQUEST_METHOD'];
    // Soporte para method override via query string (POST con _method=PUT)
    if ($method === 'POST' && isset($_GET['_method'])) {
        $method = strtoupper($_GET['_method']);
    }
    
    $path = explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    $action = end($path); 

    // --- GET: LISTAR ---
    if ($method === 'GET') {
        $rows = $db->query("SELECT clave, valor FROM configuracion")->fetchAll();
        $cfg = []; 
        foreach ($rows as $r) {
            $cfg[$r['clave']] = $r['valor'];
        }
        ok($cfg); 
    }

    // --- PUT /configuracion/reset-numero ---
    if ($method === 'PUT' && $action === 'reset-numero') {
        $b = body();
        $n = max(1, (int)($b['siguiente'] ?? 1));
        $db->prepare("INSERT INTO configuracion (clave,valor) VALUES ('cotizacion_siguiente',?) ON DUPLICATE KEY UPDATE valor=?")
           ->execute([$n, $n]);
        ok(['siguiente' => $n]);
        exit; // Importante para que no siga al siguiente bloque PUT
    }

    // --- PUT: ACTUALIZACIÓN GENERAL ---
    if ($method === 'PUT') {
        $b = body();
        
        // Si por alguna razón body() llega vacío en PUT, intentamos capturarlo manualmente
        if (empty($b)) {
            $json = file_get_contents('php://input');
            $b = json_decode($json, true);
        }

        if (!empty($b)) {
            // Cargar valores actuales para detectar diff y auditar
            $prevRows = $db->query("SELECT clave, valor FROM configuracion")->fetchAll();
            $prev = [];
            foreach ($prevRows as $r) $prev[$r['clave']] = $r['valor'];

            $s = $db->prepare("INSERT INTO configuracion (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?");
            // Lista de claves que NO se deben actualizar de forma masiva
            $noTocar = ['cotizacion_siguiente'];
            // Claves de bookkeeping interno (timestamps de recordatorios que el
            // Dashboard guarda solo, a diario). Se guardan igual, pero NO se
            // auditan: no son acciones del usuario y ensuciaban el registro.
            $noAuditar = ['last_update_reminder', 'last_overdue_reminder', 'last_backup_reminder'];
            $cambiados = [];

            foreach ($b as $k => $v) {
                if (!in_array($k, $noTocar) && !is_array($v)) {
                    $newVal = (string)$v;
                    if (($prev[$k] ?? '') !== $newVal && !in_array($k, $noAuditar, true)) {
                        $cambiados[] = $k;
                    }
                    $s->execute([$k, $newVal, $newVal]);
                }
            }

            // Auditoría: solo si hubo cambios reales
            if (!empty($cambiados)) {
                try {
                    audit($db, (int)($user['id'] ?? 0), 'configuracion.update', 'configuracion',
                          'Campos modificados: ' . implode(', ', $cambiados));
                } catch (Throwable $e) { /* no romper save por fallo de audit */ }
            }
            ok(['updated' => true, 'changed' => $cambiados]);
        } else {
            err('No se recibieron datos para actualizar.');
        }
        exit;
    }

    // Helper validación robusta de upload de imagen.
    // NO confiar en $_FILES['type'] (lo controla el cliente): se valida el contenido real con finfo.
    // Se rechaza SVG porque puede contener <script> ejecutable al servir desde /uploads/.
    $validarImagen = function(array $file): array {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) err('Upload falló.');
        if (!is_uploaded_file($file['tmp_name'])) err('Upload inválido.');
        if ($file['size'] > 2 * 1024 * 1024) err('Archivo demasiado grande (max 2MB)');

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $extsPermitidas = ['jpg','jpeg','png','webp'];
        if (!in_array($ext, $extsPermitidas)) {
            err('Formato no permitido. Solo JPG, PNG o WebP.');
        }

        // MIME real (no el enviado por el cliente)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeReal = $finfo->file($file['tmp_name']);
        $mimePermitidos = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mimeReal, $mimePermitidos, true)) {
            err('El archivo no es una imagen válida.');
        }

        // exif_imagetype como segunda verificación
        $tipo = @exif_imagetype($file['tmp_name']);
        $tiposValidos = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if ($tipo === false || !in_array($tipo, $tiposValidos)) {
            err('La imagen no se pudo verificar.');
        }

        return ['ext' => $ext];
    };

    // --- POST /configuracion?accion=subir_logo ---
    if ($method === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'subir_logo') {
        if (empty($_FILES['logo'])) err('No se recibió archivo.');
        $file = $_FILES['logo'];
        $info = $validarImagen($file);
        $ext = $info['ext'];

        $dir = __DIR__ . '/../../uploads/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $nombre = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . $nombre;

        // Borrar logos anteriores
        $archivos = glob($dir . 'logo.*');
        foreach ($archivos as $archivo) {
            if (is_file($archivo)) unlink($archivo);
        }

        if (!move_uploaded_file($file['tmp_name'], $dest)) err('Error al guardar.');

        $url = '/uploads/' . $nombre;
        $db->prepare("INSERT INTO configuracion (clave,valor) VALUES ('logo_url',?) ON DUPLICATE KEY UPDATE valor=?")->execute([$url,$url]);
        ok(['logo_url' => $url]);
        exit;
    }

    // --- POST /configuracion?accion=subir_logo_cotizaciones ---
    if ($method === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'subir_logo_cotizaciones') {
        if (empty($_FILES['logo'])) err('No se recibió archivo.');
        $file = $_FILES['logo'];
        $info = $validarImagen($file);
        $ext = $info['ext'];

        $dir = __DIR__ . '/../../uploads/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $nombre = 'cot-' . bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . $nombre;

        // Borrar logos anteriores de cotizaciones
        $archivos = glob($dir . 'cot-*');
        foreach ($archivos as $archivo) {
            if (is_file($archivo)) unlink($archivo);
        }
        $legacy = glob($dir . 'logo-cotizaciones.*');
        foreach ($legacy as $archivo) {
            if (is_file($archivo)) unlink($archivo);
        }

        if (!move_uploaded_file($file['tmp_name'], $dest)) err('Error al guardar.');

        $url = '/uploads/' . $nombre;
        $db->prepare("INSERT INTO configuracion (clave,valor) VALUES ('logo_cotizaciones_url',?) ON DUPLICATE KEY UPDATE valor=?")->execute([$url,$url]);
        ok(['logo_cotizaciones_url' => $url]);
        exit;
    }

    // --- POST /configuracion?accion=subir_login_bg ---
    if ($method === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'subir_login_bg') {
        if (empty($_FILES['logo'])) err('No se recibió archivo.');
        $file = $_FILES['logo'];
        $info = $validarImagen($file);
        $ext = $info['ext'];

        $dir = __DIR__ . '/../../uploads/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $nombre = 'login-bg-' . bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . $nombre;

        // Borrar imágenes de fondo de login anteriores
        $archivos = glob($dir . 'login-bg-*');
        foreach ($archivos as $archivo) {
            if (is_file($archivo)) unlink($archivo);
        }

        if (!move_uploaded_file($file['tmp_name'], $dest)) err('Error al guardar.');

        $url = '/uploads/' . $nombre;
        $db->prepare("INSERT INTO configuracion (clave,valor) VALUES ('login_imagen_fondo',?) ON DUPLICATE KEY UPDATE valor=?")->execute([$url,$url]);
        ok(['login_imagen_fondo' => $url]);
        exit;
    }

    // --- DELETE: BORRAR IMAGEN FONDO LOGIN ---
    if ($method === 'DELETE' && isset($_GET['accion']) && $_GET['accion'] === 'borrar_login_bg') {
        $db->prepare("UPDATE configuracion SET valor = '' WHERE clave = 'login_imagen_fondo'")->execute();
        ok(['ok' => true]);
        exit;
    }

    // --- DELETE: BORRAR LOGO ---
    if ($method === 'DELETE' && isset($_GET['accion']) && $_GET['accion'] === 'borrar_logo') {
        $db->prepare("UPDATE configuracion SET valor = '' WHERE clave = 'logo_url'")->execute();
        ok(['ok' => true]);
        exit;
    }

    // --- DELETE: BORRAR LOGO COTIZACIONES ---
    if ($method === 'DELETE' && isset($_GET['accion']) && $_GET['accion'] === 'borrar_logo_cotizaciones') {
        $db->prepare("UPDATE configuracion SET valor = '' WHERE clave = 'logo_cotizaciones_url'")->execute();
        ok(['ok' => true]);
        exit;
    }

    err('Método no permitido.', 405);

} catch (Throwable $e) {
    errSafe($e, 500);
}