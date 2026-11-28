<?php
/**
 * Documentos que Inger arma FUERA del sistema y sube como HTML + PDF:
 *   - propuestas (adjuntas a una cotización, /c/{token}/propuesta)
 *   - actas de entrega (módulo Entregas, /e/{token}/acta)
 * El sistema no los edita: los valida, limpia el HTML, los guarda y los sirve.
 *
 * Seguridad:
 *  - Se guardan en backend/uploads/{propuestas|actas}/ con nombre aleatorio
 *    (32 hex) y SIN extensión; la carpeta tiene "Require all denied". Solo se
 *    sirven por el endpoint del token (o con sesión desde el panel).
 *  - HTML: se quitan script/iframe/object/embed/form…, atributos on*, URLs
 *    javascript:. Los <a> solo conservan href https:// (con target=_blank).
 *  - Se muestran en <iframe sandbox="allow-popups allow-popups-to-escape-sandbox">
 *    (sin scripts) con una CSP estricta.
 */
declare(strict_types=1);

const DS_TIPOS = ['propuestas', 'actas'];
const DS_MAX_HTML = 8 * 1024 * 1024;    // 8 MB (las imágenes vienen en base64)
const DS_MAX_PDF  = 15 * 1024 * 1024;   // 15 MB
/** Alto de una hoja carta en px CSS (11in × 96). */
const DS_ALTO_HOJA = 1056;

/** CSP del HTML servido (INSTRUCCIONES-documentos.md §2). */
const DS_CSP = "default-src 'none'; img-src data: https:; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; frame-ancestors 'self'";

function dsDir(string $tipo): string {
    if (!in_array($tipo, DS_TIPOS, true)) throw new InvalidArgumentException('Tipo de documento inválido');
    $dir = __DIR__ . '/../uploads/' . $tipo;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear la carpeta de ' . $tipo);
    }
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nOptions -Indexes\n");
    if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
    return $dir;
}

function dsRutaAbs(string $tipo, ?string $ruta): ?string {
    if (!$ruta || !preg_match('/^[a-f0-9]{32}$/', $ruta)) return null;
    return dsDir($tipo) . '/' . $ruta;
}

function dsIniBytes(string $k): int {
    $v = trim((string)ini_get($k));
    if ($v === '' || $v === '0' || $v === '-1') return PHP_INT_MAX;
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n };
}

function dsMB(int $b): string {
    return rtrim(rtrim(number_format($b / 1048576, 1, ',', ''), '0'), ',') . ' MB';
}

/**
 * Lee y valida un archivo subido. Devuelve su contenido.
 * Lanza DomainException con un mensaje para mostrar a Inger.
 */
function dsLeerSubida(?array $f, string $clase): string {
    $max = $clase === 'html' ? DS_MAX_HTML : DS_MAX_PDF;
    $limServidor = min(dsIniBytes('upload_max_filesize'), dsIniBytes('post_max_size'));
    $etq = $clase === 'html' ? 'El HTML' : 'El PDF';
    $e = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($e === UPLOAD_ERR_NO_FILE) throw new DomainException("Falta el archivo " . strtoupper($clase) . '.');
    if ($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE) {
        throw new DomainException("$etq supera lo que permite el servidor (" . dsMB($limServidor) . ').');
    }
    if ($e !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) {
        throw new DomainException("No se pudo subir " . strtolower($etq) . ' (código ' . (int)$e . ').');
    }
    $bytes = (int)filesize($f['tmp_name']);
    if ($bytes <= 0) throw new DomainException("$etq está vacío.");
    if ($bytes > $max) throw new DomainException("$etq pesa " . dsMB($bytes) . '; el máximo es ' . dsMB($max) . '.');

    $nombre = strtolower((string)($f['name'] ?? ''));
    $ext = pathinfo($nombre, PATHINFO_EXTENSION);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    $datos = (string)file_get_contents($f['tmp_name']);

    if ($clase === 'pdf') {
        if ($ext !== 'pdf' || $mime !== 'application/pdf' || !str_starts_with($datos, '%PDF-')) {
            throw new DomainException('El archivo PDF no es un PDF válido.');
        }
        return $datos;
    }
    if (!in_array($ext, ['html', 'htm'], true) || !in_array($mime, ['text/html', 'text/plain', 'text/xml', 'application/xhtml+xml'], true)) {
        throw new DomainException('El archivo HTML no es un HTML válido.');
    }
    if (!mb_check_encoding($datos, 'UTF-8')) throw new DomainException('El HTML debe estar en UTF-8.');
    if (!preg_match('/<(html|body)\b/i', $datos)) throw new DomainException('El archivo no parece una página HTML completa.');
    return $datos;
}

// ─── Limpieza del HTML ────────────────────────────────────────────────

/** Valor de URL "normalizado" para revisar el esquema (quita espacios/controles). */
function dsEsquema(string $v): string {
    $v = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return strtolower((string)preg_replace('/[\x00-\x20]+/', '', $v));
}

function dsUrlPeligrosa(string $v): bool {
    $s = dsEsquema($v);
    return str_starts_with($s, 'javascript:') || str_starts_with($s, 'vbscript:')
        || (str_starts_with($s, 'data:') && !str_starts_with($s, 'data:image/'))
        || str_starts_with($s, 'data:image/svg');
}

/**
 * Limpia el HTML subido y le agrega el CSS para mostrarlo dentro del iframe
 * (hojas pegadas, sin fondo gris ni márgenes: así el alto = hojas × 11in).
 */
function dsLimpiarHtml(string $html): string {
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_PARSEHUGE | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    // Quitar el <?xml …> que se usó para fijar la codificación.
    foreach (iterator_to_array($doc->childNodes) as $n) {
        if ($n->nodeType === XML_PI_NODE) $doc->removeChild($n);
    }

    $xp = new DOMXPath($doc);
    $quitar = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button',
               'textarea', 'select', 'base', 'portal', 'template'];
    foreach ($quitar as $tag) {
        foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $el) $el->parentNode?->removeChild($el);
    }
    // <meta http-equiv> (refresh, CSP, set-cookie…)
    foreach (iterator_to_array($xp->query('//meta[@http-equiv]')) as $el) $el->parentNode?->removeChild($el);
    // <link>: solo hojas de Google Fonts (y preconnect a esos dominios).
    foreach (iterator_to_array($doc->getElementsByTagName('link')) as $el) {
        $href = dsEsquema($el->getAttribute('href'));
        if (!preg_match('#^https://fonts\.(googleapis|gstatic)\.com/#', $href . '/')) $el->parentNode?->removeChild($el);
    }

    // Atributos peligrosos en todos los elementos.
    foreach ($xp->query('//*') as $el) {
        /** @var DOMElement $el */
        foreach (iterator_to_array($el->attributes) as $at) {
            $nom = strtolower($at->nodeName);
            $val = (string)$at->nodeValue;
            if (str_starts_with($nom, 'on') || in_array($nom, ['formaction', 'srcdoc', 'ping'], true)) {
                $el->removeAttribute($at->nodeName);
            } elseif (in_array($nom, ['href', 'src', 'xlink:href', 'action', 'poster', 'background', 'srcset', 'data'], true) && dsUrlPeligrosa($val)) {
                $el->removeAttribute($at->nodeName);
            } elseif ($nom === 'style' && preg_match('/expression\s*\(|javascript:|behavior\s*:|-moz-binding/i', $val)) {
                $el->removeAttribute($at->nodeName);
            }
        }
    }

    // Links: solo https://, siempre en pestaña nueva (el iframe no tiene scripts).
    foreach ($doc->getElementsByTagName('a') as $a) {
        $href = trim($a->getAttribute('href'));
        if ($href !== '' && str_starts_with(dsEsquema($href), 'https://')) {
            $a->setAttribute('target', '_blank');
            $a->setAttribute('rel', 'noopener noreferrer');
        } else {
            $a->removeAttribute('href');
            $a->removeAttribute('target');
        }
    }

    // CSS para el iframe: hojas pegadas y sin sombra (el alto se calcula con la cantidad de hojas).
    $head = $doc->getElementsByTagName('head')->item(0);
    if (!$head) {
        $head = $doc->createElement('head');
        $htmlEl = $doc->getElementsByTagName('html')->item(0);
        $htmlEl?->insertBefore($head, $htmlEl->firstChild);
    }
    if ($head) {
        $robots = $doc->createElement('meta');
        $robots->setAttribute('name', 'robots');
        $robots->setAttribute('content', 'noindex, nofollow');
        $head->appendChild($robots);
        $st = $doc->createElement('style', '@media screen{html,body{background:#fff!important;margin:0!important;padding:0!important}'
            . '.sheet{margin:0!important;box-shadow:none!important}.sheet+.sheet{margin-top:0!important;box-shadow:inset 0 1px 0 rgba(12,22,51,.14)!important}}');
        $st->setAttribute('data-sistema', 'iframe');
        $head->appendChild($st);
    }

    $out = (string)$doc->saveHTML();
    // Siempre doctype HTML5 (si no venía, DOMDocument agrega uno HTML 4 → modo quirks)
    // y sin restos del <?xml encoding> que usamos para fijar la codificación.
    $out = preg_replace('/^\s*<!doctype[^>]*>\s*/i', '', $out);
    $out = str_replace(['<!--?xml encoding="UTF-8"-->', '<?xml encoding="UTF-8">'], '', $out);
    return "<!DOCTYPE html>\n" . ltrim($out);
}

/** Cantidad de hojas (.sheet) del documento; 0 si no usa ese formato. */
function dsContarHojas(string $html): int {
    return preg_match_all('/<[a-z][^>]*\bclass\s*=\s*["\'](?:[^"\']*\s)?sheet(?:\s[^"\']*)?["\']/i', $html);
}

// ─── Guardar / servir / borrar ────────────────────────────────────────

/**
 * Valida, limpia y guarda HTML + PDF. Devuelve ['html','pdf','hash','hojas'].
 * Lanza DomainException (mensaje para el usuario) si algo no es válido.
 */
function dsGuardar(string $tipo, ?array $fHtml, ?array $fPdf, bool $parcial = false): array {
    // $parcial (reemplazo): basta con uno de los dos; el otro se conserva.
    $vieneHtml = $fHtml && ($fHtml['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $vienePdf = $fPdf && ($fPdf['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($parcial && !$vieneHtml && !$vienePdf) throw new DomainException('Elige el archivo HTML, el PDF o ambos.');
    $html = (!$parcial || $vieneHtml) ? dsLeerSubida($fHtml, 'html') : null;
    $pdf = (!$parcial || $vienePdf) ? dsLeerSubida($fPdf, 'pdf') : null;
    $dir = dsDir($tipo);
    $out = [];
    $escritos = [];
    $escribir = function (string $datos) use ($dir, &$escritos): string {
        $r = bin2hex(random_bytes(16));
        if (@file_put_contents("$dir/$r", $datos) === false) {
            foreach ($escritos as $e) @unlink("$dir/$e");
            throw new RuntimeException('No se pudo guardar el archivo en el servidor');
        }
        @chmod("$dir/$r", 0640);
        $escritos[] = $r;
        return $r;
    };
    if ($html !== null) {
        $limpio = dsLimpiarHtml($html);
        $out['html'] = $escribir($limpio);
        // Hash del HTML guardado (lo que realmente ve el cliente en el link).
        $out['hash'] = hash('sha256', $limpio);
        $out['hojas'] = min(255, dsContarHojas($limpio));
    }
    if ($pdf !== null) $out['pdf'] = $escribir($pdf);
    return $out;
}

/** Copia un archivo guardado (para "Nueva versión"). Devuelve la ruta nueva o null. */
function dsCopiar(string $tipo, ?string $ruta): ?string {
    $abs = dsRutaAbs($tipo, $ruta);
    if (!$abs || !is_file($abs)) return null;
    $nueva = bin2hex(random_bytes(16));
    if (!@copy($abs, dsDir($tipo) . '/' . $nueva)) return null;
    @chmod(dsDir($tipo) . '/' . $nueva, 0640);
    return $nueva;
}

function dsBorrar(string $tipo, array $rutas): void {
    foreach ($rutas as $r) {
        $abs = dsRutaAbs($tipo, $r);
        if ($abs && is_file($abs)) @unlink($abs);
    }
}

/**
 * Sirve el HTML con la CSP estricta. $paraIframe = false (botón "Ver" del panel):
 * se quita el CSS que pega las hojas para el iframe, y se ve como la plantilla
 * original (hojas centradas sobre fondo gris).
 */
function dsEnviarHtml(string $tipo, ?string $ruta, bool $paraIframe = true): never {
    $abs = dsRutaAbs($tipo, $ruta);
    if (!$abs || !is_file($abs)) { http_response_code(404); exit; }
    $html = (string)file_get_contents($abs);
    if (!$paraIframe) $html = (string)preg_replace('#<style data-sistema="iframe">.*?</style>#s', '', $html);
    // Hojas tamaño carta (ancho fijo): al abrirlo solo en el celular se ve la
    // hoja entera, no ampliada y cortada. Dentro del iframe el viewport se ignora.
    if (dsContarHojas($html) > 0) {
        $vp = '<meta name="viewport" content="width=864">';
        $html = preg_match('#<meta\s[^>]*name\s*=\s*["\']?viewport[^>]*>#i', $html)
            ? (string)preg_replace('#<meta\s[^>]*name\s*=\s*["\']?viewport[^>]*>#i', $vp, $html, 1)
            : (string)preg_replace('#<head\b[^>]*>#i', '$0' . $vp, $html, 1);
    }
    while (ob_get_level()) ob_end_clean();
    header_remove('X-Frame-Options');
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline');
    header('Content-Security-Policy: ' . DS_CSP);
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . strlen($html));
    echo $html;
    exit;
}

/** Sirve el PDF (descarga con nombre legible). */
function dsEnviarPdf(string $tipo, ?string $ruta, string $nombre, bool $descargar = true): never {
    $abs = dsRutaAbs($tipo, $ruta);
    if (!$abs || !is_file($abs)) { http_response_code(404); exit; }
    $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $nombre) ?: 'documento.pdf';
    while (ob_get_level()) ob_end_clean();
    header_remove('Content-Security-Policy');
    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: ' . ($descargar ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
    readfile($abs);
    exit;
}

// ─── Guardado compartido: panel e integración (Claude) usan ESTAS funciones ──
// Así es imposible que una vía valide o limpie distinto que la otra: las dos
// pasan por dsGuardar() (tipo real, tamaño, limpieza del HTML, nombre
// aleatorio, hash) y por el mismo UPDATE + borrado del archivo anterior.

const DS_ORIGENES = ['manual', 'claude'];

/** El documento cambió de estado entremedio (p. ej. la cotización se aprobó). */
class DsConflicto extends RuntimeException {}

/**
 * Guarda la propuesta de una cotización y deja la marca de origen.
 * $c: fila con id, propuesta_html_path, propuesta_pdf_path.
 * $parcial: reemplazo con un solo archivo (el otro se conserva).
 * Lanza DomainException (archivo no válido; mensaje para el usuario) o
 * DsConflicto (la cotización se aprobó mientras se subía).
 */
function dsGuardarPropuesta(PDO $db, array $c, ?array $fHtml, ?array $fPdf, bool $parcial, ?string $numero, string $origen): void {
    if (!in_array($origen, DS_ORIGENES, true)) throw new InvalidArgumentException('Origen inválido');
    $g = dsGuardar('propuestas', $fHtml, $fPdf, $parcial);
    $sets = ['propuesta_numero = ?', 'propuesta_origen = ?', 'propuesta_subida_at = NOW()'];
    $vals = [$numero ?: null, $origen];
    if (isset($g['html'])) {
        array_push($sets, 'propuesta_html_path = ?', 'propuesta_hash = ?', 'propuesta_hojas = ?', 'propuesta_vista_at = NULL');
        array_push($vals, $g['html'], $g['hash'], $g['hojas'] ?: null);
    }
    if (isset($g['pdf'])) { $sets[] = 'propuesta_pdf_path = ?'; $vals[] = $g['pdf']; }
    $up = $db->prepare("UPDATE cotizaciones SET " . implode(', ', $sets) . " WHERE id = ? AND aprobada_at IS NULL");
    $up->execute([...$vals, (int)$c['id']]);
    if ($up->rowCount() !== 1) {
        dsBorrar('propuestas', array_values(array_filter([$g['html'] ?? null, $g['pdf'] ?? null])));
        throw new DsConflicto('No se pudo guardar la propuesta: la cotización cambió mientras se subía.');
    }
    // Borrar solo los archivos que se reemplazaron.
    dsBorrar('propuestas', array_filter([isset($g['html']) ? $c['propuesta_html_path'] : null, isset($g['pdf']) ? $c['propuesta_pdf_path'] : null]));
}

/**
 * Guarda el acta de una entrega y deja la marca de origen.
 * $e: fila con id, html_path, pdf_path. Solo en borrador o con observaciones
 * (igual que el panel); quien llama puede ser más estricto.
 */
function dsGuardarActa(PDO $db, array $e, ?array $fHtml, ?array $fPdf, bool $parcial, string $origen): void {
    if (!in_array($origen, DS_ORIGENES, true)) throw new InvalidArgumentException('Origen inválido');
    $g = dsGuardar('actas', $fHtml, $fPdf, $parcial);
    $sets = ['acta_origen = ?', 'acta_subida_at = NOW()'];
    $vals = [$origen];
    if (isset($g['html'])) { array_push($sets, 'html_path = ?', 'hash = ?', 'hojas = ?'); array_push($vals, $g['html'], $g['hash'], $g['hojas'] ?: null); }
    if (isset($g['pdf'])) { $sets[] = 'pdf_path = ?'; $vals[] = $g['pdf']; }
    $up = $db->prepare("UPDATE entregas SET " . implode(', ', $sets) . " WHERE id = ? AND estado IN ('borrador', 'con_observaciones')");
    $up->execute([...$vals, (int)$e['id']]);
    if ($up->rowCount() !== 1) {
        dsBorrar('actas', array_values(array_filter([$g['html'] ?? null, $g['pdf'] ?? null])));
        throw new DsConflicto('No se pudo guardar el acta: la entrega cambió mientras se subía.');
    }
    dsBorrar('actas', array_filter([isset($g['html']) ? $e['html_path'] : null, isset($g['pdf']) ? $e['pdf_path'] : null]));
}

