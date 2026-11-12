<?php
/**
 * documentos.php — Documentos y enlaces (Fase 1: solo links).
 *
 * Un documento pertenece a un cliente y, opcionalmente, a un proyecto (del
 * que hereda el sitio). Sirve para guardar links a propuestas, informes,
 * diseños (Figma/Canva), artefactos de Claude, carpetas de Drive, etc.
 *
 *   GET    ?proyecto_id=X        → documentos del proyecto
 *   GET    ?sitio_id=X           → los del sitio (generales del sitio + de sus proyectos)
 *   GET    ?cliente_id=X         → todos los del cliente (incluye sitios y proyectos)
 *   POST   {proyecto_id | sitio_id | cliente_id, tipo, titulo, url, fecha?, notas?}
 *
 * Dónde cuelga: proyecto (hereda cliente y sitio) > sitio (hereda cliente) > cliente.
 *   PUT    ?id=X  (parcial)
 *   DELETE ?id=X
 *
 * Fase 2 — archivos (multipart/form-data, campo "archivo"):
 *   POST   documentos.php                 (crear con archivo en vez de url)
 *   POST   documentos.php?id=X&_method=PUT (editar; si viene archivo, lo reemplaza)
 *   GET    ?id=X&archivo=1                → descarga / vista del archivo (con sesión)
 *   GET    ?limite=1                      → { max_bytes } que acepta el servidor
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

const DOC_TIPOS = ['propuesta', 'informe', 'diseno', 'artefacto', 'contrato', 'documento', 'enlace'];

/** Normaliza y valida la URL: solo http(s) (bloquea javascript:, data:, etc). */
function docUrl(string $url): string {
    $url = trim($url);
    if ($url === '') err('El link es obligatorio', 400);
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) $url = 'https://' . $url;
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true) || !filter_var($url, FILTER_VALIDATE_URL)) {
        err('El link no es válido (debe empezar con http:// o https://)', 400);
    }
    if (mb_strlen($url) > 1000) err('El link es demasiado largo', 400);
    return $url;
}

function docFecha($f): ?string {
    $f = trim((string)$f);
    if ($f === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $f);
    if (!$d || $d->format('Y-m-d') !== $f) err('Fecha no válida', 400);
    return $f;
}

/** Proyecto vigente → [cliente_id, sitio_id]. */
function docProyecto(PDO $db, int $proyectoId): array {
    $st = $db->prepare("SELECT cliente_id, sitio_id FROM proyectos WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$proyectoId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) err('El proyecto no existe', 404);
    return [(int)$p['cliente_id'], $p['sitio_id'] !== null ? (int)$p['sitio_id'] : null];
}

/** Sitio vigente → cliente_id. */
function docSitio(PDO $db, int $sitioId): int {
    $st = $db->prepare("SELECT cliente_id FROM sitios WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$sitioId]);
    $c = $st->fetchColumn();
    if ($c === false) err('El sitio no existe', 404);
    return (int)$c;
}

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../documentos_archivos.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST' && isset($_GET['_method'])) $method = strtoupper($_GET['_method']);

    // Sin la migración 2026_11_12_1 todavía no existe la tabla.
    if (!$db->query("SHOW TABLES LIKE 'documentos'")->fetch()) {
        if ($method === 'GET' && !isset($_GET['id'])) ok([]);
        err('Falta aplicar la migración de Documentos (Sistema → Migraciones)', 409);
    }
    // Sin la migración 2026_11_12_2 no se pueden subir archivos (los links sí funcionan).
    $conArchivos = (bool)$db->query("SHOW COLUMNS FROM documentos LIKE 'archivo_ruta'")->fetch();

    // Multipart (con archivo) → los campos vienen en $_POST; si no, JSON.
    $esMultipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
        && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && $esMultipart) {
        // PHP descarta todo el cuerpo si supera post_max_size.
        err('El archivo supera el máximo permitido (' . docMB(docLimiteBytes()) . ')', 413);
    }
    $datos = fn() => $esMultipart ? $_POST : body();
    $archivoSubido = $esMultipart && isset($_FILES['archivo']) && ($_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        ? $_FILES['archivo'] : null;
    if ($archivoSubido && !$conArchivos) err('Falta aplicar la migración para subir archivos (Sistema → Migraciones)', 409);

    $colsArchivo = $conArchivos ? 'd.archivo_nombre, d.archivo_mime, d.archivo_bytes, (d.archivo_ruta IS NOT NULL) AS es_archivo,' : '0 AS es_archivo,';

    // ── GET ──
    if ($method === 'GET') {
        if (isset($_GET['limite'])) ok(['max_bytes' => docLimiteBytes()]);

        // Descargar / ver un archivo.
        if (isset($_GET['archivo'])) {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id || !$conArchivos) err('Documento no encontrado', 404);
            $st = $db->prepare("SELECT archivo_ruta, archivo_nombre, archivo_mime FROM documentos WHERE id = ?");
            $st->execute([$id]);
            $doc = $st->fetch(PDO::FETCH_ASSOC);
            if (!$doc || !$doc['archivo_ruta']) err('Documento no encontrado', 404);
            docEnviarArchivo($doc);
        }

        $proyectoId = (int)($_GET['proyecto_id'] ?? 0);
        $sitioId    = (int)($_GET['sitio_id'] ?? 0);
        $clienteId  = (int)($_GET['cliente_id'] ?? 0);
        if (!$proyectoId && !$sitioId && !$clienteId) err('proyecto_id, sitio_id o cliente_id requerido', 400);

        // Los documentos de proyectos en la papelera se ocultan (vuelven al restaurar).
        $where = $proyectoId ? 'd.proyecto_id = ?' : ($sitioId ? 'd.sitio_id = ?' : 'd.cliente_id = ?');
        $st = $db->prepare("
            SELECT d.id, d.cliente_id, d.proyecto_id, d.sitio_id, d.tipo, d.titulo, d.url, {$colsArchivo}
                   d.fecha, d.notas, d.created_at, d.updated_at,
                   p.nombre AS proyecto_nombre, s.nombre AS sitio_nombre
            FROM documentos d
            LEFT JOIN proyectos p ON p.id = d.proyecto_id
            LEFT JOIN sitios s    ON s.id = d.sitio_id AND s.deleted_at IS NULL
            WHERE {$where} AND (d.proyecto_id IS NULL OR p.deleted_at IS NULL)
            ORDER BY COALESCE(d.fecha, DATE(d.created_at)) DESC, d.id DESC
        ");
        $st->execute([$proyectoId ?: ($sitioId ?: $clienteId)]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']          = (int)$r['id'];
            $r['cliente_id']  = (int)$r['cliente_id'];
            $r['proyecto_id'] = $r['proyecto_id'] !== null ? (int)$r['proyecto_id'] : null;
            $r['sitio_id']    = $r['sitio_id'] !== null ? (int)$r['sitio_id'] : null;
            $r['es_archivo']  = (bool)$r['es_archivo'];
            if (isset($r['archivo_bytes'])) $r['archivo_bytes'] = (int)$r['archivo_bytes'];
            $r['etiqueta']    = $r['proyecto_nombre'] !== null
                ? etiquetaProyecto($r['proyecto_nombre'], $r['sitio_nombre'])
                : ($r['sitio_nombre'] ?: null);
        }
        unset($r);
        ok($rows);
    }

    // ── POST: crear (link o archivo) ──
    if ($method === 'POST') {
        $b = $datos();
        $titulo = trim((string)($b['titulo'] ?? ''));
        if ($titulo === '') err('El título es obligatorio', 400);
        if (mb_strlen($titulo) > 200) err('El título es demasiado largo', 400);
        $tipo  = in_array($b['tipo'] ?? '', DOC_TIPOS, true) ? $b['tipo'] : 'enlace';
        $fecha = docFecha($b['fecha'] ?? '');
        $url   = $archivoSubido ? null : docUrl((string)($b['url'] ?? ''));

        $proyectoId = (int)($b['proyecto_id'] ?? 0) ?: null;
        $sitioId = null;
        if ($proyectoId) {
            [$clienteId, $sitioId] = docProyecto($db, $proyectoId);
        } elseif ((int)($b['sitio_id'] ?? 0)) {
            $sitioId = (int)$b['sitio_id'];
            $clienteId = docSitio($db, $sitioId);
        } else {
            $clienteId = (int)($b['cliente_id'] ?? 0);
            $chk = $db->prepare("SELECT id FROM clientes WHERE id = ? AND deleted_at IS NULL");
            $chk->execute([$clienteId]);
            if (!$chk->fetch()) err('El cliente no existe', 404);
        }

        // El archivo se guarda al final, cuando todo lo demás ya se validó.
        $arch = $archivoSubido ? docGuardarArchivo($archivoSubido) : null;
        try {
            if ($arch) {
                $db->prepare("INSERT INTO documentos (cliente_id, proyecto_id, sitio_id, tipo, titulo, url, archivo_ruta, archivo_nombre, archivo_mime, archivo_bytes, fecha, notas, creado_por, created_at)
                              VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, NOW())")
                   ->execute([$clienteId, $proyectoId, $sitioId, $tipo, $titulo, $arch['ruta'], $arch['nombre'], $arch['mime'], $arch['bytes'],
                              $fecha, trim((string)($b['notas'] ?? '')) ?: null, $user['id']]);
            } else {
                $db->prepare("INSERT INTO documentos (cliente_id, proyecto_id, sitio_id, tipo, titulo, url, fecha, notas, creado_por, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                   ->execute([$clienteId, $proyectoId, $sitioId, $tipo, $titulo, $url,
                              $fecha, trim((string)($b['notas'] ?? '')) ?: null, $user['id']]);
            }
        } catch (Throwable $e) {
            if ($arch) docBorrarArchivos([$arch['ruta']]);   // no dejar archivos huérfanos
            throw $e;
        }
        $newId = (int)$db->lastInsertId();
        audit($db, $user['id'], 'CREATE', 'documentos', ($arch ? "Archivo '{$arch['nombre']}'" : 'Documento') . " '{$titulo}' (cliente #{$clienteId}" . ($proyectoId ? ", proyecto #{$proyectoId}" : '') . ')', $newId);
        ok(['id' => $newId]);
    }

    // ── PUT: editar (parcial; si viene archivo, reemplaza el anterior) ──
    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) err('id requerido', 400);
        $cur = $db->prepare("SELECT * FROM documentos WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) err('Documento no encontrado', 404);
        $b = $datos();
        $esArchivo = !empty($row['archivo_ruta']);
        if ($archivoSubido && !$esArchivo) err('Este documento es un link; para subir un archivo crea uno nuevo', 400);

        $titulo = array_key_exists('titulo', $b) ? trim((string)$b['titulo']) : $row['titulo'];
        if ($titulo === '') err('El título es obligatorio', 400);
        if (mb_strlen($titulo) > 200) err('El título es demasiado largo', 400);
        // Un documento-archivo no tiene url; uno-link no se convierte en archivo.
        $url   = !$esArchivo && array_key_exists('url', $b) ? docUrl((string)$b['url']) : $row['url'];
        $tipo  = array_key_exists('tipo', $b) && in_array($b['tipo'], DOC_TIPOS, true) ? $b['tipo'] : $row['tipo'];
        $fecha = array_key_exists('fecha', $b) ? docFecha($b['fecha']) : $row['fecha'];
        $notas = array_key_exists('notas', $b) ? (trim((string)$b['notas']) ?: null) : $row['notas'];

        // Mover: proyecto_id manda; sin proyecto, sitio_id (opcional) = general del sitio.
        // (En multipart los vacíos llegan como '' → mismo efecto que null.)
        $proyectoId = $row['proyecto_id'] !== null ? (int)$row['proyecto_id'] : null;
        $sitioId    = $row['sitio_id'] !== null ? (int)$row['sitio_id'] : null;
        if (array_key_exists('proyecto_id', $b) || array_key_exists('sitio_id', $b)) {
            $proyectoId = (int)($b['proyecto_id'] ?? 0) ?: null;
            $sitioId = null;
            if ($proyectoId) {
                [$cli, $sitioId] = docProyecto($db, $proyectoId);
                if ($cli !== (int)$row['cliente_id']) err('El proyecto es de otro cliente', 400);
            } elseif ((int)($b['sitio_id'] ?? 0)) {
                $sitioId = (int)$b['sitio_id'];
                if (docSitio($db, $sitioId) !== (int)$row['cliente_id']) err('El sitio es de otro cliente', 400);
            }
        }

        $arch = $archivoSubido ? docGuardarArchivo($archivoSubido) : null;
        try {
            $db->prepare("UPDATE documentos SET proyecto_id = ?, sitio_id = ?, tipo = ?, titulo = ?, url = ?, fecha = ?, notas = ?, updated_at = NOW()
                          WHERE id = ?")
               ->execute([$proyectoId, $sitioId, $tipo, $titulo, $url, $fecha, $notas, $id]);
            if ($arch) {
                $db->prepare("UPDATE documentos SET archivo_ruta = ?, archivo_nombre = ?, archivo_mime = ?, archivo_bytes = ? WHERE id = ?")
                   ->execute([$arch['ruta'], $arch['nombre'], $arch['mime'], $arch['bytes'], $id]);
            }
        } catch (Throwable $e) {
            if ($arch) docBorrarArchivos([$arch['ruta']]);
            throw $e;
        }
        if ($arch) docBorrarArchivos([$row['archivo_ruta']]);   // el anterior ya no se usa
        audit($db, $user['id'], 'UPDATE', 'documentos', "Editó documento #{$id} ({$titulo})" . ($arch ? " · archivo reemplazado por '{$arch['nombre']}'" : ''), $id);
        ok(['ok' => true]);
    }

    // ── DELETE (si es archivo, también se borra del disco) ──
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) err('id requerido', 400);
        $cur = $db->prepare("SELECT * FROM documentos WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) err('Documento no encontrado', 404);
        $db->prepare("DELETE FROM documentos WHERE id = ?")->execute([$id]);
        if (!empty($row['archivo_ruta'])) docBorrarArchivos([$row['archivo_ruta']]);
        audit($db, $user['id'], 'DELETE', 'documentos', "Eliminó documento #{$id} ({$row['titulo']})" . (!empty($row['archivo_nombre']) ? " · archivo '{$row['archivo_nombre']}'" : ''), $id);
        ok(['ok' => true]);
    }

    err('Método no permitido', 405);

} catch (Throwable $e) {
    errSafe($e, 500, 'documentos');
}
