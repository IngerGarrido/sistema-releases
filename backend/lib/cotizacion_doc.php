<?php
/**
 * Documento de cotización (diseño "Opción A v2") compartido por:
 *   - el PDF interno (api/exports.php?type=pdf_cotizacion), y
 *   - la página pública de aprobación (publico/cotizacion.php → /c/{token}).
 *
 * Referencia visual exacta: cotizacion/final/*.html. El CSS vive en
 * backend/assets/css/cotizacion-doc.css (copiado de la referencia).
 */

const COT_TZ = 'America/Santiago';
const COT_FORMA_PAGO_DEFAULT = '50% de pie para comenzar · 50% al aprobar el demo';

function cotH($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function cotAhora(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone(COT_TZ));
}

/** "$ 1.950.000" (formato del diseño). */
function cotClp($n): string {
    $n = (float)$n;
    return ($n < 0 ? '-' : '') . '$ ' . number_format(abs(round($n)), 0, ',', '.');
}

function cotFecha(?string $ymd): string {
    if (!$ymd) return '';
    $t = strtotime($ymd);
    return $t ? date('d/m/Y', $t) : '';
}

function cotConfig(PDO $db): array {
    $c = [];
    foreach ($db->query("SELECT clave, valor FROM configuracion")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $c[$r['clave']] = $r['valor'];
    }
    return $c;
}

// ─── Textos enriquecidos (Quill) ──────────────────────────────────────

function cotTieneTexto(?string $html): bool {
    if ($html === null || $html === '') return false;
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/[\s\x{00A0}\x{200B}-\x{200D}]+/u', '', $t);
    return $t !== '';
}

/**
 * Deja solo ul, ol, li, p, strong, em, u, br, sin atributos. Además convierte
 * párrafos que empiezan con "•", "-" o "·" (viñetas escritas a mano) en una
 * lista real, para que salgan con las viñetas del diseño.
 */
function cotRtLimpio(?string $html): string {
    if (!cotTieneTexto($html)) return '';
    $h = (string)$html;
    $h = preg_replace('#<(/?)b\b[^>]*>#i', '<$1strong>', $h);
    $h = preg_replace('#<(/?)i\b[^>]*>#i', '<$1em>', $h);
    $h = strip_tags($h, '<ul><ol><li><p><strong><em><u><br>');
    $h = preg_replace('#<(/?)(ul|ol|li|p|strong|em|u)\b[^>]*>#i', '<$1$2>', $h);
    $h = preg_replace('#<br\b[^>]*>#i', '<br>', $h);
    // Párrafos vacíos de Quill (<p><br></p>)
    $h = preg_replace('#<p>(\s|&nbsp;|<br>)*</p>#i', '', $h);
    // Viñetas manuales → <li>
    $h = preg_replace('#<p>(?:\s|&nbsp;)*[•·▪\-–\*](?:\s|&nbsp;|\t)*(.*?)</p>#su', '<li data-x>$1</li>', $h);
    $h = preg_replace_callback('#(?:<li data-x>.*?</li>\s*)+#s', fn($m) => '<ul>' . str_replace('<li data-x>', '<li>', $m[0]) . '</ul>', $h);
    return trim($h);
}

/** Textos de la plantilla (o los base de Configuración si no hay plantilla). */
function cotTerminosDesdePlantilla(PDO $db, ?int $plantillaId, ?array $cfg = null): array {
    if ($plantillaId) {
        $st = $db->prepare("SELECT terminos_obligaciones, terminos_desarrollo, terminos_condiciones
                            FROM plantillas_terminos WHERE id = ? AND activa = 1");
        $st->execute([$plantillaId]);
        if ($p = $st->fetch(PDO::FETCH_ASSOC)) {
            return [$p['terminos_obligaciones'], $p['terminos_desarrollo'], $p['terminos_condiciones']];
        }
    }
    $cfg = $cfg ?? cotConfig($db);
    return [$cfg['terminos_obligaciones'] ?? null, $cfg['terminos_desarrollo'] ?? null, $cfg['terminos_condiciones'] ?? null];
}

/** Copia los textos de la plantilla a la cotización (se hace al crearla o al cambiar de plantilla). */
function cotCopiarTerminos(PDO $db, int $cotId, ?int $plantillaId): void {
    [$o, $d, $c] = cotTerminosDesdePlantilla($db, $plantillaId);
    $db->prepare("UPDATE cotizaciones SET terminos_obligaciones = ?, terminos_desarrollo = ?, terminos_condiciones = ? WHERE id = ?")
       ->execute([$o, $d, $c, $cotId]);
}

// ─── Monto en palabras ────────────────────────────────────────────────

function cotNumLetras(int $n): string {
    static $u = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez',
        'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
        'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis',
        'veintisiete', 'veintiocho', 'veintinueve'];
    static $d = [3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta', 7 => 'setenta', 8 => 'ochenta', 9 => 'noventa'];
    static $c = [1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos', 5 => 'quinientos',
        6 => 'seiscientos', 7 => 'setecientos', 8 => 'ochocientos', 9 => 'novecientos'];
    if ($n === 0) return 'cero';
    $tres = function (int $x) use ($u, $d, $c): string {
        if ($x === 0) return '';
        if ($x === 100) return 'cien';
        $out = [];
        $h = intdiv($x, 100); $r = $x % 100;
        if ($h) $out[] = $c[$h];
        if ($r) {
            if ($r < 30) $out[] = $u[$r];
            else { $o = $r % 10; $out[] = $d[intdiv($r, 10)] . ($o ? ' y ' . $u[$o] : ''); }
        }
        return implode(' ', $out);
    };
    $parts = [];
    $mill = intdiv($n, 1000000); $resto = $n % 1000000;
    if ($mill) $parts[] = $mill === 1 ? 'un millón' : cotApocope(cotNumLetras($mill)) . ' millones';
    $miles = intdiv($resto, 1000); $cien = $resto % 1000;
    if ($miles) $parts[] = $miles === 1 ? 'mil' : cotApocope($tres($miles)) . ' mil';
    if ($cien) $parts[] = $tres($cien);
    return implode(' ', $parts);
}

/** "uno" → "un", "veintiuno" → "veintiún" delante de mil/millones/pesos. */
function cotApocope(string $s): string {
    if (preg_match('/veintiuno$/u', $s)) return preg_replace('/veintiuno$/u', 'veintiún', $s);
    return preg_replace('/\buno$/u', 'un', $s);
}

/** 1950000 → "Un millón novecientos cincuenta mil pesos". */
function cotMontoPalabras($monto): string {
    $n = (int)round(abs((float)$monto));
    $w = cotApocope(cotNumLetras($n));
    $w .= ($n >= 1000000 && $n % 1000000 === 0) ? ' de pesos' : ($n === 1 ? ' peso' : ' pesos');
    return mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
}

// ─── RUT ──────────────────────────────────────────────────────────────

/** "12.345.678-5" → "12345678-5" */
function cotRutNormalizar(string $rut): string {
    $c = strtoupper(preg_replace('/[^0-9kK]/', '', $rut));
    return strlen($c) < 2 ? $c : substr($c, 0, -1) . '-' . substr($c, -1);
}

/** "12345678-5" → "12.345.678-5" */
function cotRutFormato(?string $rut): string {
    $c = strtoupper(preg_replace('/[^0-9kK]/', '', (string)$rut));
    if (strlen($c) < 2) return $c;
    return number_format((int)substr($c, 0, -1), 0, ',', '.') . '-' . substr($c, -1);
}

// ─── Carga de datos ───────────────────────────────────────────────────

/**
 * Carga todo lo que muestra el documento. Por id (interno) o por token (público).
 * Devuelve null si no existe.
 */
function cotCargar(PDO $db, ?int $id, ?string $token = null): ?array {
    $where = $token !== null ? "c.token = ? AND c.deleted_at IS NULL" : "c.id = ?";
    $st = $db->prepare("SELECT c.*, cl.nombre AS cli_nombre, cl.nombre_agencia AS cli_agencia, cl.email AS cli_email,
                               p.nombre AS proy_nombre
                        FROM cotizaciones c
                        LEFT JOIN clientes cl ON cl.id = c.cliente_id
                        LEFT JOIN proyectos p ON p.id = c.proyecto_id
                        WHERE $where");
    $st->execute([$token !== null ? $token : $id]);
    $q = $st->fetch(PDO::FETCH_ASSOC);
    if (!$q) return null;

    $si = $db->prepare("SELECT descripcion, cantidad, precio_unitario FROM cotizacion_items WHERE cotizacion_id = ? ORDER BY orden, id");
    $si->execute([(int)$q['id']]);
    $items = [];
    $sub = 0.0;
    foreach ($si->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $cant = (float)$it['cantidad'];
        $pu = (float)$it['precio_unitario'];
        $tot = $cant * $pu;
        $sub += $tot;
        $items[] = ['descripcion' => trim((string)$it['descripcion']), 'cantidad' => $cant, 'precio' => $pu, 'total' => $tot];
    }
    $pct = (float)($q['descuento_global'] ?? 0);
    $desc = $sub * $pct / 100;
    $total = round($sub - $desc);

    $cfg = cotConfig($db);
    $terms = [$q['terminos_obligaciones'] ?? null, $q['terminos_desarrollo'] ?? null, $q['terminos_condiciones'] ?? null];
    if (!array_filter($terms, fn($t) => $t !== null)) {
        // Cotización sin copia de textos (no debería pasar tras la migración).
        $terms = cotTerminosDesdePlantilla($db, $q['plantilla_id'] ? (int)$q['plantilla_id'] : null, $cfg);
    }

    return [
        'q'       => $q,
        'cfg'     => $cfg,
        'items'   => $items,
        'sub'     => round($sub),
        'pct'     => $pct,
        'tipo'    => (($q['descuento_global_tipo'] ?? 'porcentaje') === 'fijo') ? 'fijo' : 'porcentaje',
        'desc'    => round($desc),
        'total'   => $total,
        'cliente' => trim((string)($q['cli_agencia'] ?: $q['cli_nombre'])),
        'terminos' => [
            ['Obligaciones del cliente', cotRtLimpio($terms[0])],
            ['Desarrollo', cotRtLimpio($terms[1])],
            ['Términos y condiciones', cotRtLimpio($terms[2])],
        ],
    ];
}

/** pendiente | aprobada | vencida | anulada | reemplazada */
function cotEstadoPublico(array $q): string {
    if (!empty($q['aprobada_at']) || $q['estado'] === 'Aceptada') return 'aprobada';
    if ($q['estado'] === 'Rechazada') return 'anulada';
    if ($q['estado'] === 'Reemplazada') return 'reemplazada';
    if ($q['estado'] === 'Expirada') return 'vencida';
    if (!empty($q['fecha_validez']) && cotAhora()->format('Y-m-d') > $q['fecha_validez']) return 'vencida';
    return 'pendiente';
}

// ─── Hash de lo aprobado ──────────────────────────────────────────────

/** JSON canónico de lo que el cliente vio (orden de claves fijo). */
function cotContenidoFirmado(array $d): string {
    $q = $d['q'];
    $data = [
        'numero'        => (string)$q['numero'],
        'cliente'       => $d['cliente'],
        'proyecto'      => (string)($q['proy_nombre'] ?? ''),
        'fecha'         => (string)$q['fecha'],
        'validez'       => (string)($q['fecha_validez'] ?? ''),
        'items'         => array_map(fn($i) => [$i['descripcion'], $i['cantidad'], $i['precio']], $d['items']),
        'descuento_pct' => $d['pct'],
        'total'         => $d['total'],
        'forma_pago'    => (string)($q['forma_pago'] ?? ''),
        'plazo'         => (string)($q['plazo_estimado'] ?? ''),
        'notas'         => trim((string)($q['notas'] ?? '')),
        'terminos'      => array_map(fn($t) => $t[1], $d['terminos']),
    ];
    // Con propuesta adjunta: el registro prueba qué propuesta estaba en el link.
    // Sin propuesta, el JSON queda idéntico al de antes (mismo hash).
    if (!empty($q['propuesta_hash'])) {
        $data['propuesta_numero'] = (string)($q['propuesta_numero'] ?? '');
        $data['propuesta_hash'] = (string)$q['propuesta_hash'];
    }
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Datos de la propuesta adjunta para el panel / la página pública, o null si no tiene. */
function cotPropuestaInfo(?array $q): ?array {
    if (!$q || empty($q['propuesta_html_path'])) return null;
    return [
        'numero'    => (string)($q['propuesta_numero'] ?? ''),
        'tiene_pdf' => !empty($q['propuesta_pdf_path']),
        'hojas'     => (int)($q['propuesta_hojas'] ?? 0),
        'vista_at'  => $q['propuesta_vista_at'] ?? null,
        // "Subida automática" / "Subida manual" (null si falta la migración 2026_11_21_3).
        'origen'    => $q['propuesta_origen'] ?? null,
        'subida_at' => $q['propuesta_subida_at'] ?? null,
    ];
}

function cotCodigoVerificacion(string $hash): string {
    $h = strtoupper(substr($hash, 0, 8));
    return substr($h, 0, 4) . '-' . substr($h, 4, 4);
}

function cotNuevoToken(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

// ─── URLs ─────────────────────────────────────────────────────────────

/** Origen + carpeta del sistema (ej. https://sistema.ingergarrido.cl o http://localhost:8888/sistema-local). */
function cotBaseUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $pos = strpos($script, '/backend/');
    $prefix = $pos !== false ? substr($script, 0, $pos) : '';
    return ($https ? 'https' : 'http') . '://' . $host . $prefix;
}

function cotLinkPublico(string $token): string {
    return cotBaseUrl() . '/c/' . $token;
}

// ─── HTML ─────────────────────────────────────────────────────────────

/** <style> con la fuente Geist local + el CSS del documento. */
function cotCssDoc(): string {
    $font = cotBaseUrl() . '/backend/assets/fonts/Geist-Variable.woff2';
    $css = @file_get_contents(__DIR__ . '/../assets/css/cotizacion-doc.css') ?: '';
    return "<style>@font-face{font-family:\"Geist\";src:url(\"" . cotH($font) . "\") format(\"woff2\");font-weight:100 900;font-display:swap}\n"
        . $css . "</style>";
}

/**
 * Hojas del documento.
 * $modo: 'firma' (para imprimir y firmar a mano), 'online' (pendiente de
 * aprobación en línea) o 'aprobada' (con el sello).
 */
function cotHojasHtml(array $d, string $modo): string {
    $q = $d['q']; $cfg = $d['cfg'];
    $num = (string)$q['numero'];
    $emp = trim((string)($cfg['nombre_empresa'] ?? '')) ?: 'Inger Garrido';
    $rol = trim((string)($cfg['nombre_propietario'] ?? ''));
    $rut = trim((string)($cfg['rut'] ?? ''));
    $mail = trim((string)($cfg['email'] ?? ''));
    $tel = trim((string)($cfg['telefono'] ?? ''));
    $web = trim((string)($cfg['sitio_web'] ?? ''));
    $fv = cotFecha($q['fecha_validez'] ?? null);
    $forma = trim((string)($q['forma_pago'] ?? ''));
    $plazo = trim((string)($q['plazo_estimado'] ?? ''));
    $notas = trim((string)($q['notas'] ?? ''));
    $brand = cotH($emp) . '<span class="dot">.</span>';
    $foot = fn(int $p) => '<footer class="foot"><span>Cotización ' . cotH($num) . '</span><span>' . cotH($web) . '</span><span class="pg">Página ' . $p . ' de 2</span></footer>';

    $emiLineas = [];
    if ($rut) $emiLineas[] = 'RUT ' . cotH($rut);
    $contacto = implode(' · ', array_filter([$mail, $tel]));
    if ($contacto) $emiLineas[] = cotH($contacto);
    if ($web) $emiLineas[] = cotH($web);

    ob_start(); ?>
<div class="sheet doc a p1">
<header class="head">
  <div class="emi"><div class="brand"><?= $brand ?></div><?php if ($rol): ?><div class="role"><?= cotH($rol) ?></div><?php endif; ?>
  <p><?= implode('<br>', $emiLineas) ?></p></div>
  <div class="box"><div class="t">COTIZACIÓN</div><div class="n">N.º <?= cotH($num) ?></div><div class="f">Fecha de emisión: <?= cotH(cotFecha($q['fecha'])) ?></div></div>
</header>
<div class="flow">
<h3>Datos del cliente</h3>
<div class="cli">
  <div><span>Señor(es)</span><b><?= cotH($d['cliente']) ?></b></div><div><span>Proyecto</span><b><?= cotH($q['proy_nombre'] ?: '—') ?></b></div>
  <div><span>Correo</span><b><?= cotH($q['cli_email'] ?: '—') ?></b></div><div><span>Válida hasta</span><b><?= cotH($fv ?: '—') ?></b></div>
</div>
<h3>Detalle</h3>
<table><thead><tr><th class="c">N.º</th><th>Descripción</th><th class="c">Cant.</th><th class="r">Valor unitario</th><th class="r">Total</th></tr></thead><tbody><?php
    foreach ($d['items'] as $i => $it):
        $cant = rtrim(rtrim(number_format($it['cantidad'], 2, ',', '.'), '0'), ','); ?><tr><td class="c num"><?= $i + 1 ?></td><td><?= nl2br(cotH($it['descripcion'])) ?></td><td class="c num"><?= cotH($cant) ?></td><td class="r num"><?= cotClp($it['precio']) ?></td><td class="r num"><?= cotClp($it['total']) ?></td></tr><?php
    endforeach;
    for ($k = count($d['items']); $k < 5; $k++): ?><tr class='e'><td></td><td></td><td></td><td></td><td></td></tr><?php endfor; ?></tbody></table>
<div class="tot">
  <div class="son">Son: <b><?= cotH(cotMontoPalabras($d['total'])) ?></b>.</div>
  <div class="sums"><div><span>Subtotal</span><span class="num"><?= cotClp($d['sub']) ?></span></div><?php if ($d['desc'] > 0):
      $lblD = $d['tipo'] === 'fijo' ? 'Descuento' : 'Descuento (' . rtrim(rtrim(number_format($d['pct'], 4, ',', ''), '0'), ',') . ' %)'; ?><div><span><?= cotH($lblD) ?></span><span class="num">-<?= cotClp($d['desc']) ?></span></div><?php endif; ?><div class="g"><span>Total</span><span class="num"><?= cotClp($d['total']) ?></span></div></div>
</div>
<div class="cond u">
  <div><h3>Condiciones comerciales</h3><ul>
    <?php if ($forma): ?><li><b>Forma de pago:</b> <?= cotH($forma) ?></li><?php endif; ?>
    <?php if ($plazo): ?><li><b>Plazo estimado:</b> <?= cotH($plazo) ?></li><?php endif; ?>
    <?php if ($fv): ?><li><b>Validez:</b> hasta el <?= cotH($fv) ?></li><?php endif; ?></ul></div>
  <?php if ($notas): ?><div><h3>Notas</h3><p><?= nl2br(cotH($notas)) ?></p></div><?php endif; ?>
</div>
<p class="acc u" style="margin-top:auto;margin-bottom:22px">El detalle del alcance, las obligaciones del cliente y los términos y condiciones están en la página 2, <?= $modo === 'aprobada' ? 'junto al registro de aprobación' : 'junto a la aceptación' ?>.</p>
</div>
<?= $foot(1) ?>
</div><div class="sheet doc a p2">
<header class="mini"><div class="brand"><?= $brand ?></div><span>Cotización N.º <?= cotH($num) ?></span></header>
<div class="flow">
<h2>Alcance y condiciones<span class="dot">.</span></h2>
<p class="intro">Forman parte de esta cotización y se aceptan junto con ella.</p>
<?php $n = 0; foreach ($d['terminos'] as [$titulo, $html]): if ($html === '') continue; $n++; ?>
<section class="blk u" data-n="<?= $n ?>"><h3><i><?= $n ?></i><?= cotH($titulo) ?></h3><div class="rt"><?= $html ?></div></section>
<?php endforeach; ?>
<?php if ($modo === 'aprobada'):
    $ap = $q['aprobada_at'] ? new DateTimeImmutable($q['aprobada_at']) : null; ?>
<p class="acc u">Con su aprobación electrónica, el cliente aceptó la cotización N.º <?= cotH($num) ?> por un total de <b><?= cotClp($d['total']) ?></b> y las condiciones de este documento.</p>
<div class="seal u">
  <div class="ok"><span class="lbl"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg> Aprobada electrónicamente</span>
  <?php if (!empty($q['aprobada_nombre'])): ?><b><?= cotH($q['aprobada_nombre']) ?></b> · RUT <?= cotH(cotRutFormato($q['aprobada_rut'])) ?><br><?php endif; ?>
  <?php /* La IP queda guardada en la BD como respaldo, pero no se imprime. */ ?>
  <?php if ($ap): ?><?= $ap->format('d/m/Y') ?> a las <?= $ap->format('H:i') ?> h<br><?php endif; ?>
  <?php if (!empty($q['codigo_verificacion'])): ?>Código de verificación: <b><?= cotH($q['codigo_verificacion']) ?></b><?php else: ?>Aprobada sin registro en línea<?php endif; ?></div>
  <div class="me"><?= cotH($emp) ?><?php if ($rut): ?><small>RUT <?= cotH($rut) ?></small><?php endif; ?></div>
</div>
<?php else: ?>
<p class="acc u">Al firmar, el cliente acepta la cotización N.º <?= cotH($num) ?> por un total de <b><?= cotClp($d['total']) ?></b> y las condiciones de este documento.</p>
<div class="sign u" style="margin-top:auto"><?php if ($modo === 'online'): ?><div>Aprobación electrónica<small>Se registra al aprobar en línea</small></div><?php else: ?><div>Firma cliente<small>Nombre, RUT y fecha</small></div><?php endif; ?><div><?= cotH($emp) ?><?php if ($rut): ?><small>RUT <?= cotH($rut) ?></small><?php endif; ?></div></div>
<?php endif; ?>
</div>
<?= $foot(2) ?>
</div>
<template id="tplHoja"><div class="sheet doc a pc"><header class="mini"><div class="brand"><?= $brand ?></div><span>Cotización N.º <?= cotH($num) ?></span></header><div class="flow"></div><?= $foot(0) ?></div></template>
<?php
    return ob_get_clean();
}

/**
 * Script de paginación: si el contenido de una hoja no cabe en el alto carta,
 * pasa bloques (o viñetas de un bloque largo) a una hoja nueva, y numera
 * "Página X de Y". Llama a window.cotPaginado(n) al terminar.
 */
function cotPaginadorJs(): string {
    return <<<'JS'
<script>
(function(){
  function over(s){return s.scrollHeight>s.clientHeight+1}
  function units(f){return [...f.children].filter(e=>e.classList.contains('u'))}
  function paginar(){
    const tpl=document.getElementById('tplHoja'); if(!tpl) return;
    const root=document.querySelector('.p1').parentNode;
    let sheets=[...root.querySelectorAll(':scope > .sheet')];
    for(let i=0;i<sheets.length;i++){
      const s=sheets[i]; let guard=0, next=null;
      while(over(s)&&guard++<400){
        const flow=s.querySelector('.flow'); const us=units(flow); if(!us.length) break;
        if(!next){ next=tpl.content.firstElementChild.cloneNode(true); s.after(next); sheets.splice(i+1,0,next); }
        const nf=next.querySelector('.flow'); const last=us[us.length-1];
        if(us.length>1){
          // La firma/sello viaja con su texto de aceptación.
          const prev=us[us.length-2];
          if((last.classList.contains('sign')||last.classList.contains('seal'))&&prev.classList.contains('acc')&&us.length>2){ nf.prepend(last); nf.prepend(prev); }
          else nf.prepend(last);
          continue;
        }
        // Un solo bloque que no cabe: pasar viñetas de a una a la hoja siguiente.
        const lis=last.querySelectorAll('.rt > ul > li, .rt > ol > li, .rt > p');
        if(!last.classList.contains('blk')||lis.length<2) break;
        let cont=nf.firstElementChild;
        if(!cont||!cont.classList.contains('cont')||cont.dataset.n!==last.dataset.n){
          cont=document.createElement('section'); cont.className='blk u cont'; cont.dataset.n=last.dataset.n;
          const h=last.querySelector('h3').cloneNode(true); const sm=document.createElement('small'); sm.textContent=' (continuación)'; h.appendChild(sm);
          cont.appendChild(h); const rt=document.createElement('div'); rt.className='rt'; cont.appendChild(rt); nf.prepend(cont);
        }
        const rt=cont.querySelector('.rt'); const li=lis[lis.length-1]; const par=li.parentNode;
        if(li.tagName==='P'){ rt.prepend(li); }
        else { let list=rt.firstElementChild; if(!list||list.tagName!==par.tagName||list.dataset.from!=='1'){ list=document.createElement(par.tagName); list.dataset.from='1'; rt.prepend(list);} list.prepend(li); if(!par.children.length) par.remove(); }
      }
    }
    sheets=[...root.querySelectorAll(':scope > .sheet')];
    sheets.forEach((s,i)=>{const pg=s.querySelector('.foot .pg'); if(pg) pg.textContent='Página '+(i+1)+' de '+sheets.length;});
    if(window.cotPaginado) window.cotPaginado(sheets.length);
  }
  // Medir con la fuente real (Geist): con la de respaldo los cortes salen mal.
  let hecho=false; const go=()=>{ if(!hecho){ hecho=true; paginar(); } };
  if(document.fonts&&document.fonts.load){
    Promise.all([document.fonts.load('400 13px Geist'),document.fonts.load('700 13px Geist')])
      .then(()=>document.fonts.ready).then(go,go);
    setTimeout(go,3000);
  } else window.addEventListener('load',go);
})();
</script>
JS;
}
