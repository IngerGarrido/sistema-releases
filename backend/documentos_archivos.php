<?php
/**
 * Archivos subidos a Documentos (fase 2).
 *
 * Seguridad:
 *  - Se guardan en backend/uploads/documentos/ con nombre aleatorio (32 hex)
 *    y SIN extensión → aunque el servidor ignorara el .htaccess, nada se
 *    ejecuta y no hay rutas adivinables.
 *  - Solo extensiones de la lista blanca, y el contenido real (finfo) debe
 *    coincidir con lo esperado para esa extensión (un .php renombrado a .pdf
 *    se rechaza).
 *  - Se sirven solo vía documentos.php?archivo=1 (con sesión), con
 *    nosniff + CSP sandbox; los que no son PDF/imagen se descargan.
 *  - SVG/HTML no se aceptan (pueden llevar scripts).
 */

const DOC_MAX_BYTES = 10 * 1024 * 1024;   // 10 MB

/** extensión → MIME que se envía al descargar + MIMEs aceptados al detectar con finfo. */
const DOC_EXTENSIONES = [
    'pdf'  => ['application/pdf', ['application/pdf']],
    'jpg'  => ['image/jpeg', ['image/jpeg']],
    'jpeg' => ['image/jpeg', ['image/jpeg']],
    'png'  => ['image/png',  ['image/png']],
    'webp' => ['image/webp', ['image/webp']],
    'gif'  => ['image/gif',  ['image/gif']],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document',   ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream']],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',         ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream']],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream']],
    'doc'  => ['application/msword',            ['application/msword', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream']],
    'xls'  => ['application/vnd.ms-excel',      ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream']],
    'ppt'  => ['application/vnd.ms-powerpoint', ['application/vnd.ms-powerpoint', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream']],
    'odt'  => ['application/vnd.oasis.opendocument.text',        ['application/vnd.oasis.opendocument.text', 'application/zip']],
    'ods'  => ['application/vnd.oasis.opendocument.spreadsheet', ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip']],
    'txt'  => ['text/plain', ['text/plain']],
    'csv'  => ['text/csv',   ['text/plain', 'text/csv', 'application/csv']],
    'zip'  => ['application/zip', ['application/zip', 'application/x-zip-compressed']],
];

/** Se pueden ver en el navegador (el resto se descarga). */
const DOC_INLINE = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];

function docDir(): string {
    $dir = __DIR__ . '/uploads/documentos';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('No se pudo crear la carpeta de documentos');
    }
    // Defensa en profundidad: denegar acceso web directo (Apache).
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nOptions -Indexes\n");
    $idx = $dir . '/index.html';
    if (!is_file($idx)) @file_put_contents($idx, '');
    return $dir;
}

/** Ruta absoluta de un archivo guardado, o null si el nombre no es válido. */
function docRutaAbs(?string $ruta): ?string {
    if (!$ruta || !preg_match('/^[a-f0-9]{32}$/', $ruta)) return null;
    return docDir() . '/' . $ruta;
}

/** Límite efectivo: el menor entre 10 MB y lo que permite el PHP del servidor. */
function docLimiteBytes(): int {
    $ini = function (string $k): int {
        $v = trim((string)ini_get($k));
        if ($v === '' || $v === '0' || $v === '-1') return PHP_INT_MAX;
        $n = (int)$v; $u = strtolower(substr($v, -1));
        return match ($u) { 'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n };
    };
    return min(DOC_MAX_BYTES, $ini('upload_max_filesize'), $ini('post_max_size'));
}

function docMB(int $bytes): string {
    return rtrim(rtrim(number_format($bytes / 1048576, 1, ',', ''), '0'), ',') . ' MB';
}

/**
 * Valida y guarda un archivo de $_FILES. Devuelve los datos para la BD.
 * Lanza err() (respuesta 400) si no es válido.
 */
function docGuardarArchivo(array $f): array {
    $limite = docLimiteBytes();
    $e = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE) err('El archivo supera el máximo permitido (' . docMB($limite) . ')', 400);
    if ($e === UPLOAD_ERR_NO_FILE) err('No llegó ningún archivo', 400);
    if ($e !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'] ?? '')) err('No se pudo subir el archivo (código ' . (int)$e . ')', 400);

    $bytes = (int)filesize($f['tmp_name']);
    if ($bytes <= 0) err('El archivo está vacío', 400);
    if ($bytes > $limite) err('El archivo supera el máximo permitido (' . docMB($limite) . ')', 400);

    // Nombre original "limpio" (solo para mostrar / descargar con ese nombre).
    $nombre = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', (string)($f['name'] ?? '')));
    if ($nombre === '') $nombre = 'archivo';
    if (mb_strlen($nombre) > 200) {
        $ext0 = pathinfo($nombre, PATHINFO_EXTENSION);
        $nombre = mb_substr(pathinfo($nombre, PATHINFO_FILENAME), 0, 180) . ($ext0 !== '' ? '.' . $ext0 : '');
    }
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    if (!isset(DOC_EXTENSIONES[$ext])) {
        err('Tipo de archivo no permitido. Se aceptan: PDF, imágenes (JPG, PNG, WEBP, GIF), Word, Excel, PowerPoint, TXT, CSV y ZIP', 400);
    }
    [$mimeEnvio, $aceptados] = DOC_EXTENSIONES[$ext];
    $detectado = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    if (!in_array($detectado, $aceptados, true)) {
        err("El contenido del archivo no coincide con su extensión .{$ext}", 400);
    }

    $ruta = bin2hex(random_bytes(16));
    $dest = docDir() . '/' . $ruta;
    if (!move_uploaded_file($f['tmp_name'], $dest)) throw new RuntimeException('No se pudo guardar el archivo en el servidor');
    @chmod($dest, 0640);

    return ['ruta' => $ruta, 'nombre' => $nombre, 'mime' => $mimeEnvio, 'bytes' => $bytes];
}

/** Borra del disco los archivos indicados (ignora los que ya no están). */
function docBorrarArchivos(array $rutas): void {
    foreach ($rutas as $r) {
        $abs = docRutaAbs($r);
        if ($abs && is_file($abs)) @unlink($abs);
    }
}

/** Rutas de archivos asociados a un cliente (para limpiar en un borrado definitivo). */
function docRutasDeCliente(PDO $db, int $clienteId): array {
    try {
        $st = $db->prepare("SELECT archivo_ruta FROM documentos WHERE cliente_id = ? AND archivo_ruta IS NOT NULL");
        $st->execute([$clienteId]);
        return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];   // sin tabla/columna todavía
    }
}

/** Envía el archivo al navegador (inline si es PDF/imagen; si no, descarga). */
function docEnviarArchivo(array $doc): never {
    $abs = docRutaAbs($doc['archivo_ruta'] ?? null);
    if (!$abs || !is_file($abs)) err('El archivo ya no está en el servidor', 404);
    $mime = (string)$doc['archivo_mime'];
    $inline = in_array($mime, DOC_INLINE, true);
    $nombre = (string)$doc['archivo_nombre'];
    $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $nombre) ?: 'archivo';

    while (ob_get_level()) ob_end_clean();
    header_remove('Content-Type');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
        . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
    header('Cache-Control: private, no-store');
    header('Access-Control-Expose-Headers: Content-Disposition');
    readfile($abs);
    exit;
}
