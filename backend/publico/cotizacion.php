<?php
/**
 * Página pública de aprobación de cotizaciones: /c/{token}
 * (la regla de .htaccess de la raíz reescribe /c/{token} → este archivo).
 *
 *   GET  /c/{token}       → página para revisar y aprobar
 *   GET  /c/{token}/pdf   → documento para imprimir / guardar PDF
 *   POST /c/{token}       → aprobar { nombre, rut, acepto: true }
 *   GET  /c/{token}/propuesta      → HTML de la propuesta adjunta (para el iframe)
 *   GET  /c/{token}/propuesta.pdf  → PDF de la propuesta
 * Sin propuesta, la página se ve exactamente igual que antes (sin pestañas).
 *
 * Es lo único público del sistema: sin login, sin menú. El token es aleatorio
 * (32 bytes); el número o el id de la cotización no sirven para entrar.
 * Diseño: cotizacion/final/aprobar.html (sin la barra "Demo").
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/cotizacion_doc.php';
require_once __DIR__ . '/../lib/cotizacion_correo.php';
require_once __DIR__ . '/../lib/doc_subido.php';
require_once __DIR__ . '/../lib/visitas.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token = (string)($_GET['t'] ?? '');

function cotNoEncontrada(): never {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow"><title>No encontrada</title>'
       . '<style>body{font-family:system-ui,sans-serif;background:#F3F4F7;color:#4F5A74;display:grid;place-items:center;min-height:100vh;margin:0;padding:20px}'
       . 'div{max-width:420px;text-align:center}h1{color:#0C1633;font-size:24px;letter-spacing:-.03em}</style></head>'
       . '<body><div><h1>No encontramos esta cotización.</h1><p>Revisa que el enlace esté completo o pide uno nuevo a quien te lo envió.</p></div></body></html>';
    exit;
}

try {
    $db = getDB();

    // ─── POST: aprobar ────────────────────────────────────────────────
    if ($method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) err('No encontrada', 404);

        $b = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($b)) err('Solicitud inválida');

        // ─── Respuesta sin aprobar: «quiero ajustar algo» o «no voy a seguir» ───
        $accion = (string)($b['accion'] ?? '');
        if ($accion === 'comentario' || $accion === 'rechazo') {
            rateLimit('cot.responder', 10, 3600);
            if (!empty($_GET['p'])) err('Esta es la vista previa: el cliente responde desde su propio link.', 403);
            $texto = trim(str_replace("\r", '', (string)($b['texto'] ?? '')));
            if (mb_strlen($texto) > 2000) err('El mensaje es muy largo (máximo 2.000 caracteres).');
            $motivo = (string)($b['motivo'] ?? '');
            if ($accion === 'comentario' && mb_strlen($texto) < 5) err('Cuéntame qué te gustaría ajustar.');
            if ($accion === 'rechazo' && $motivo !== '' && !isset(COT_MOTIVOS[$motivo])) err('Motivo no válido.');
            if ($accion === 'comentario') $motivo = '';

            $d = cotCargar($db, null, $token);
            if (!$d) err('No encontrada', 404);
            if (cotEstadoPublico($d['q']) !== 'pendiente') err('Esta cotización ya no admite respuestas.', 409);
            $ahora = cotAhora();
            $st = $db->prepare("UPDATE cotizaciones SET respuesta_tipo = ?, respuesta_motivo = ?, respuesta_texto = ?, respuesta_at = ?"
                             . ($accion === 'rechazo' ? ", estado = 'Rechazada'" : '') . "
                                WHERE token = ? AND deleted_at IS NULL AND aprobada_at IS NULL
                                  AND estado IN ('Pendiente','Enviada')
                                  AND (fecha_validez IS NULL OR fecha_validez >= ?)");
            $st->execute([$accion, $motivo ?: null, $texto !== '' ? $texto : null, $ahora->format('Y-m-d H:i:s'), $token, $ahora->format('Y-m-d')]);
            if ($st->rowCount() !== 1) err('Esta cotización ya no admite respuestas.', 409);

            $num = (string)$d['q']['numero'];
            cotNotificar($db, $accion === 'rechazo' ? 'warning' : 'info', $accion === 'rechazo'
                ? "✕ {$d['cliente']} no seguirá con la {$num}" . ($motivo ? ' · ' . COT_MOTIVOS[$motivo] : '')
                : "💬 {$d['cliente']} quiere ajustar algo en la {$num}: " . mb_substr($texto, 0, 200));
            cotResponderYSeguir(['tipo' => $accion], function () use ($db, $d, $accion, $motivo, $texto) {
                cotCorreoRespuesta($db, $d, $accion, $motivo, $texto);
            });
        }

        rateLimit('cot.aprobar', 10, 3600); // 10 intentos por hora por IP
        if (($b['acepto'] ?? null) !== true) err('Debes aceptar la cotización y sus condiciones.');
        $nombre = trim(preg_replace('/\s+/u', ' ', (string)($b['nombre'] ?? '')));
        if ($nombre !== strip_tags($nombre) || preg_match('/[<>]/', $nombre)) err('Revisa el nombre.');
        $len = mb_strlen($nombre);
        if ($len < 3 || $len > 150) err('Escribe tu nombre completo.');
        $rut = (string)($b['rut'] ?? '');
        if (!validateRut($rut)) err('Revisa el RUT: el dígito verificador no coincide.');
        $rut = cotRutNormalizar($rut);
        if (strlen($rut) > 12) err('Revisa el RUT.');

        $d = cotCargar($db, null, $token);
        if (!$d) err('No encontrada', 404);
        $estado = cotEstadoPublico($d['q']);
        if ($estado === 'anulada') err('No encontrada', 404);
        if ($estado === 'aprobada') err('Esta cotización ya fue aprobada.', 409);
        if ($estado === 'vencida') err('Esta cotización venció y ya no se puede aprobar en línea.', 410);
        if ($estado === 'reemplazada') err('Hay una versión más nueva de esta cotización: pide el link actualizado.', 410);

        $hash = hash('sha256', cotContenidoFirmado($d));
        $codigo = cotCodigoVerificacion($hash);
        $ahora = cotAhora();
        $hoy = $ahora->format('Y-m-d');
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        // Condición de estado en el UPDATE: dos clics no generan dos aprobaciones.
        $st = $db->prepare("UPDATE cotizaciones SET estado = 'Aceptada', aprobada_at = ?, aprobada_nombre = ?, aprobada_rut = ?,
                                   aprobada_ip = ?, aprobada_user_agent = ?, aprobada_hash = ?, codigo_verificacion = ?
                            WHERE token = ? AND deleted_at IS NULL AND aprobada_at IS NULL
                              AND estado IN ('Pendiente','Enviada')
                              AND (fecha_validez IS NULL OR fecha_validez >= ?)");
        $st->execute([$ahora->format('Y-m-d H:i:s'), $nombre, $rut, $ip, $ua, $hash, $codigo, $token, $hoy]);
        if ($st->rowCount() !== 1) err('Esta cotización ya no se puede aprobar.', 409);

        // Aviso dentro del sistema (campana).
        try {
            $msg = mb_substr("✓ {$d['cliente']} aprobó la {$d['q']['numero']} (" . cotClp($d['total']) . ") · falta crear el proyecto", 0, 500);
            // El link abre "Generar proyecto" de esta cotización (no se crea solo:
            // hay que elegir el sitio y confirmar).
            $db->prepare("INSERT INTO notificaciones (usuario_id, tipo, mensaje, link) VALUES (NULL, 'success', ?, ?)")
               ->execute([$msg, 'cotizaciones:generar-' . (int)$d['q']['id']]);
        } catch (Throwable $e) { /* el aviso no debe impedir la aprobación */ }

        // Responder ya y mandar los correos después (el cliente no espera al SMTP).
        cotResponderYSeguir([
            'nombre' => $nombre,
            'rut'    => cotRutFormato($rut),
            'fecha'  => $ahora->format('d/m/Y · H:i') . ' h',
            'codigo' => $codigo,
        ], function () use ($db, $d, $nombre, $rut, $ip, $ahora, $codigo) {
            cotCorreosAprobacion($db, $d, ['nombre' => $nombre, 'rut' => $rut, 'ip' => $ip, 'fecha' => $ahora, 'codigo' => $codigo]);
        });
    }

    // ─── GET ──────────────────────────────────────────────────────────
    rateLimit('cot.ver', 120, 3600);
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) cotNoEncontrada();
    $d = cotCargar($db, null, $token);
    if (!$d) cotNoEncontrada();
    $q = $d['q'];
    $estado = cotEstadoPublico($q);
    if ($estado === 'anulada') cotNoEncontrada();
    $prop = cotPropuestaInfo($q);
    $preview = !empty($_GET['p']);   // ?p=1 = vista previa desde el sistema (no cuenta como vista)

    // ─── Propuesta adjunta: HTML (iframe) o PDF ──────────────────────
    $pedido = (string)($_GET['propuesta'] ?? '');
    if ($pedido !== '') {
        if (!$prop) cotNoEncontrada();
        if ($pedido === 'pdf') {
            dsEnviarPdf('propuestas', $q['propuesta_pdf_path'], 'Propuesta ' . ($prop['numero'] ?: $q['numero']) . '.pdf');
        }
        if (!$preview && visRegistrar($db, 'propuesta', (int)$q['id']) && empty($q['propuesta_vista_at'])) {
            $db->prepare("UPDATE cotizaciones SET propuesta_vista_at = ? WHERE id = ? AND propuesta_vista_at IS NULL")
               ->execute([cotAhora()->format('Y-m-d H:i:s'), (int)$q['id']]);
        }
        dsEnviarHtml('propuestas', $q['propuesta_html_path']);
    }

    // Vencida: dejarlo registrado en el sistema.
    if ($estado === 'vencida' && in_array($q['estado'], ['Pendiente', 'Enviada'], true)) {
        $db->prepare("UPDATE cotizaciones SET estado = 'Expirada' WHERE id = ? AND estado IN ('Pendiente','Enviada') AND aprobada_at IS NULL")
           ->execute([(int)$q['id']]);
    }
    // Visita del cliente (?p=1 = vista previa desde el sistema; robots y la conexión del panel no cuentan).
    // El PDF para imprimir no se registra: se abre desde la misma página.
    if (!$preview && empty($_GET['pdf']) && visRegistrar($db, 'cotizacion', (int)$q['id']) && empty($q['vista_at'])) {
        $db->prepare("UPDATE cotizaciones SET vista_at = ? WHERE id = ? AND vista_at IS NULL")
           ->execute([cotAhora()->format('Y-m-d H:i:s'), (int)$q['id']]);
    }

    $modoDoc = $estado === 'aprobada' ? 'aprobada' : 'online';
    $num = (string)$q['numero'];
    $cfg = $d['cfg'];
    $emp = trim((string)($cfg['nombre_empresa'] ?? '')) ?: 'Inger Garrido';
    $mail = trim((string)($cfg['email'] ?? ''));
    $tel = trim((string)($cfg['telefono'] ?? ''));
    $fv = cotFecha($q['fecha_validez'] ?? null);
    $link = cotLinkPublico($token);
    header('Content-Type: text/html; charset=utf-8');

    // ─── Documento para imprimir / guardar PDF ───────────────────────
    if (!empty($_GET['pdf'])) {
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">' . COT_VIEWPORT_DOC
           . '<meta name="robots" content="noindex, nofollow"><title>Cotización ' . cotH($num) . '</title>' . cotCssDoc() . '</head><body class="solo">'
           . '<div class="tools"><button type="button" onclick="window.print()">Imprimir / Guardar PDF</button></div>'
           . '<div class="hojas">' . cotHojasHtml($d, $modoDoc) . '</div>'
           . cotPaginadorJs() . '</body></html>';
        exit;
    }

    $forma = trim((string)($q['forma_pago'] ?? ''));
    $siguiente = preg_match('/(\d+)\s*%\s*de\s*pie/iu', $forma, $m)
        ? 'el pago del ' . $m[1] . ' % de pie para comenzar. Te escribo hoy con los datos de transferencia.'
        : 'te escribo hoy con los datos para comenzar.';
    $ap = !empty($q['aprobada_at']) ? new DateTimeImmutable($q['aprobada_at']) : null;
    $mailto = $mail ? 'mailto:' . $mail . '?subject=' . rawurlencode('Cotización ' . $num) : '';
    // "Te envié una copia": solo si hay correo configurado y el cliente tiene email.
    $conCopia = !empty($q['cli_email']) && filter_var($q['cli_email'], FILTER_VALIDATE_EMAIL) && correoConfigurado($db);
} catch (Throwable $e) {
    if ($method === 'POST') errSafe($e, 500, 'cotizacion publica');
    logError('cotizacion publica', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo 'No se pudo cargar la cotización. Intenta de nuevo en unos minutos.';
    exit;
}
?><!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Cotización <?= cotH($num) ?> · <?= cotH($emp) ?></title>
<?= cotCssDoc() ?>
<style><?= @file_get_contents(__DIR__ . '/../assets/css/cotizacion-publica.css') ?: '' ?></style><?php if ($prop): ?>
<style><?= @file_get_contents(__DIR__ . '/../assets/css/cotizacion-propuesta.css') ?: '' ?></style><?php endif; ?></head>
<body class="ap">
<header class="nav"><div class="w"><span class="logo"><?= cotH($emp) ?><span class="dot">.</span></span><small>Cotización N.º <?= cotH($num) ?></small></div></header>
<main class="w">
  <section class="hero">
    <?php if ($estado === 'pendiente'): ?>
    <?php if ($prop): ?><div id="heroPend"><h1>Hola, <?= cotH($d['cliente']) ?>. Esta es tu propuesta<span class="dot">.</span></h1>
    <p>Primero la propuesta y después la cotización, con el valor y las condiciones. Si estás de acuerdo, apruébala en la pestaña Cotización: no necesitas imprimir ni firmar nada.</p></div>
    <?php else: ?><div id="heroPend"><h1>Hola, <?= cotH($d['cliente']) ?>. Esta es tu cotización<span class="dot">.</span></h1>
    <p>Revisa el detalle y las condiciones. Si estás de acuerdo, apruébala aquí mismo: no necesitas imprimir ni firmar nada.</p></div><?php endif; ?>

    <?php elseif ($estado === 'aprobada'): ?>
    <div id="heroOk"><h1>Cotización aprobada<span class="dot">.</span></h1>
    <p>Gracias. Quedó registrada tu aprobación; puedes descargar el PDF con el registro cuando quieras.</p></div>
    <?php elseif ($estado === 'rechazada'): ?>
    <div id="heroNo"><h1>Gracias por avisarme<span class="dot">.</span></h1>
    <p>Quedó registrado que no vas a seguir por ahora. Si más adelante cambias de opinión, escríbeme y te envío una cotización actualizada.</p></div>
    <?php else: ?>
    <div id="heroExp"><h1>Esta cotización venció<span class="dot">.</span></h1>
    <p><?= $fv ? 'Era válida hasta el ' . cotH($fv) . '. ' : '' ?>Escríbeme y te envío una actualizada.</p></div>
    <?php endif; ?>
  </section><?php if ($prop):
    $hojasP = (int)$prop['hojas'];
    $altoP = $hojasP > 0 ? $hojasP * DS_ALTO_HOJA : 1400;
    $srcP = $link . '/propuesta' . ($preview ? '?p=1' : '');
  ?>
  <nav class="tabs" role="tablist" aria-label="Documentos">
    <button type="button" role="tab" id="tbProp" data-tab="prop" aria-controls="tabProp" aria-selected="true">1 · Propuesta</button>
    <button type="button" role="tab" id="tbCot" data-tab="cot" aria-controls="tabCot" aria-selected="false">2 · Cotización</button>
  </nav>
  <section class="layout tab-prop" id="tabProp" role="tabpanel" aria-labelledby="tbProp">
    <div class="docs">
      <div class="frame"><div class="bar"><i></i><i></i><i></i><span><?= cotH($prop['numero'] ?: 'Propuesta') ?>.pdf<?= $hojasP > 0 ? ' · ' . $hojasP . ($hojasP === 1 ? ' página' : ' páginas') : '' ?></span><?php if ($prop['tiene_pdf']): ?><a href="<?= cotH($link) ?>/propuesta.pdf">Propuesta PDF ↓</a><?php endif; ?></div>
        <div class="scaler"><iframe class="inner" src="<?= cotH($srcP) ?>" title="Propuesta <?= cotH($prop['numero']) ?>" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" loading="eager" style="height:<?= $altoP ?>px"<?= $hojasP > 0 ? ' scrolling="no"' : '' ?>></iframe></div></div>
      <div class="tab-next"><button type="button" class="btn" data-tab="cot">Ver cotización →</button></div>
    </div>
    <aside>
      <div class="card sum"><div class="sum-top"><span class="lbl">Total</span><strong class="sum-total"><?= cotClp($d['total']) ?></strong><?php if ($fv): ?><span class="sum-vence">Válida hasta <b><?= cotH($fv) ?></b></span><?php endif; ?></div>
        <dl class="sum-dl"><?php if ($prop['numero']): ?><dt>Propuesta</dt><dd><?= cotH($prop['numero']) ?></dd><?php endif; ?><dt>Cotización</dt><dd><?= cotH($num) ?></dd><?php if (!empty($q['proy_nombre'])): ?><dt>Proyecto</dt><dd><?= cotH($q['proy_nombre']) ?></dd><?php endif; ?></dl>
        <button type="button" class="btn prop-cta" data-tab="cot"><?= $estado === 'pendiente' ? 'Ver cotización y aprobar →' : 'Ver cotización →' ?></button>
        <?php if ($estado === 'aprobada'): ?><p class="prop-estado ok">✓ Aprobada<?= !empty($q['aprobada_at']) ? ' el ' . cotH((new DateTimeImmutable($q['aprobada_at']))->format('d/m/Y')) : '' ?></p><?php elseif ($estado === 'vencida'): ?><p class="prop-estado">Vencida<?= $fv ? ' el ' . cotH($fv) : '' ?></p><?php elseif ($estado === 'reemplazada'): ?><p class="prop-estado">Reemplazada por una versión más nueva</p><?php elseif ($estado === 'rechazada'): ?><p class="prop-estado">Indicaste que no vas a seguir por ahora</p><?php endif; ?>
      </div>
      <?php if ($mail || $tel): ?>
      <p class="help">¿Dudas o cambios? Escríbeme<?php if ($mail): ?> a <a href="mailto:<?= cotH($mail) ?>"><?= cotH($mail) ?></a><?php endif; ?><?php if ($tel): ?><?= $mail ? ' o' : '' ?> al <?= cotH($tel) ?><?php endif; ?>.</p>
      <?php endif; ?>
    </aside>
  </section>
  <noscript><style>.ap #tabCot{display:grid!important}.ap .tabs,.ap .tab-next{display:none}</style></noscript><?php endif; ?>

  <div class="layout"<?= $prop ? ' id="tabCot" role="tabpanel" aria-labelledby="tbCot" hidden' : '' ?>>
    <div class="docs">
      <div class="frame"><div class="bar"><i></i><i></i><i></i><span id="docLbl"><?= cotH($num) ?>.pdf · 2 páginas</span><a href="<?= cotH($link) ?>/pdf" target="_blank" rel="noopener"><?= $prop ? 'Cotización PDF ↓' : 'Descargar PDF ↓' ?></a></div>
        <div class="scaler"><div class="inner"><?= cotHojasHtml($d, $modoDoc) ?></div></div></div>
    </div>
    <aside>
      <div class="card sum"><div class="sum-top"><span class="lbl">Total</span><strong class="sum-total"><?= cotClp($d['total']) ?></strong><?php if ($fv): ?><span class="sum-vence">Válida hasta <b><?= cotH($fv) ?></b></span><?php endif; ?></div>
        <dl class="sum-dl"><?php if (!empty($q['proy_nombre'])): ?><dt>Proyecto</dt><dd><?= cotH($q['proy_nombre']) ?></dd><?php endif; ?><dt>Cotización</dt><dd><?= cotH($num) ?></dd><dt>Emisión</dt><dd><?= cotH(cotFecha($q['fecha'])) ?></dd></dl>
        <?php if ($forma): ?><div class="sum-pago"><span>Forma de pago</span><p><?= cotH($forma) ?></p></div><?php endif; ?></div>

      <?php if ($estado === 'pendiente'): ?>
      <form class="card" id="approve" novalidate>
        <div id="stPend" style="display:contents">
          <h2>Aprobar cotización</h2>
          <p>Tu nombre y RUT quedan registrados como aprobación electrónica, junto a la fecha y la hora.</p>
          <div><label class="f" for="nombre">Nombre completo</label><input type="text" id="nombre" autocomplete="name" maxlength="150" placeholder="Ej.: Nicolás Pérez"></div>
          <div><label class="f" for="rut">RUT</label><input type="text" id="rut" inputmode="text" maxlength="14" placeholder="12.345.678-5" aria-describedby="rutErr"><p class="err" id="rutErr">Revisa el RUT: el dígito verificador no coincide.</p></div>
          <label class="chk"><input type="checkbox" id="acepto"><span>Leí la cotización y acepto el detalle, el valor total y las condiciones de la página 2.</span></label>
          <button class="btn" id="btnAprobar" type="submit" disabled>Aprobar cotización</button>
          <p class="err" id="formErr" role="alert"></p>
          <p class="fine">Al aprobar, aceptas esta cotización con firma electrónica simple.</p>
        </div>
      </form>
      <?php if (!$preview): $yaComento = ($q['respuesta_tipo'] ?? null) === 'comentario' && !empty($q['respuesta_at']); ?>
      <details class="card resp" id="resp">
        <summary><span>¿Algo no te convence? <b>Cuéntame</b></span></summary>
        <?php if ($yaComento): ?><p class="resp-ya">Recibí tu comentario del <?= cotH((new DateTimeImmutable($q['respuesta_at']))->format('d/m/Y')) ?>. Te respondo pronto; si quieres, puedes agregar algo más.</p><?php endif; ?>
        <form id="respForm" novalidate>
          <fieldset class="resp-op"><legend class="f">¿Qué prefieres?</legend>
            <label class="chk"><input type="radio" name="accion" value="comentario" checked><span><b>Quiero ajustar algo</b><small>La cotización sigue abierta y te envío una versión con los cambios.</small></span></label>
            <label class="chk"><input type="radio" name="accion" value="rechazo"><span><b>No voy a seguir por ahora</b><small>Me sirve saberlo para no insistir.</small></span></label>
          </fieldset>
          <div id="respMotivo" hidden><label class="f" for="motivo">Motivo <small>(opcional)</small></label>
            <select id="motivo"><option value="">Prefiero no decirlo</option><?php foreach (COT_MOTIVOS as $k => $v): ?><option value="<?= cotH($k) ?>"><?= cotH($v) ?></option><?php endforeach; ?></select></div>
          <div><label class="f" for="respTexto" id="respLbl">¿Qué te gustaría ajustar?</label>
            <textarea id="respTexto" rows="4" maxlength="2000" placeholder="Ej.: ¿se puede separar en dos etapas?"></textarea></div>
          <button class="btn s" id="btnResp" type="submit">Enviar comentario</button>
          <p class="err" id="respErr" role="alert"></p>
        </form>
        <div class="resp-ok" id="respOk" hidden><b>¡Gracias! Lo recibí.</b><span>Te respondo pronto.</span></div>
      </details>
      <?php endif; ?>
      <?php elseif ($estado === 'rechazada'): ?>
      <div class="card exp" id="stNo">
        <span class="lbl">No seguirás por ahora</span>
        <h2 style="margin-top:8px">Gracias por contarme</h2>
        <p>Si cambias de opinión o quieres revisarla con otros valores o plazos, escríbeme y te envío una actualizada.</p>
        <?php if ($mailto): ?><a class="btn s" href="<?= cotH($mailto) ?>">Escribirme</a><?php endif; ?>
      </div>
      <?php elseif ($estado === 'aprobada'): ?>
      <div class="card ok" id="stOk">
        <span class="ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></span>
        <h2>¡Listo! Quedó aprobada.</h2>
        <p><?= $conCopia ? 'Te envié una copia a tu correo. También puedes descargar aquí el PDF con el registro.' : 'Puedes descargar el PDF con el registro de la aprobación.' ?></p>
        <?php if (!empty($q['aprobada_nombre'])): ?>
        <dl><dt>Aprobada por</dt><dd><?= cotH($q['aprobada_nombre']) ?></dd><dt>RUT</dt><dd><?= cotH(cotRutFormato($q['aprobada_rut'])) ?></dd><?php if ($ap): ?><dt>Fecha</dt><dd><?= $ap->format('d/m/Y · H:i') ?> h</dd><?php endif; ?><dt>Código</dt><dd><?= cotH($q['codigo_verificacion']) ?></dd></dl>
        <?php endif; ?>
        <p class="next"><b>Siguiente paso:</b> <?= cotH($siguiente) ?></p>
      </div>
      <?php else: ?>
      <div class="card exp" id="stExp">
        <span class="lbl"><?= $fv ? 'Vencida el ' . cotH($fv) : 'Vencida' ?></span>
        <h2 style="margin-top:8px">Ya no se puede aprobar en línea</h2>
        <p>Los valores y plazos pueden haber cambiado. Pídeme una cotización actualizada.</p>
        <?php if ($mailto): ?><a class="btn s" href="<?= cotH($mailto) ?>">Pedir cotización actualizada</a><?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($mail || $tel): ?>
      <p class="help">¿Dudas o cambios? Escríbeme<?php if ($mail): ?> a <a href="mailto:<?= cotH($mail) ?>"><?= cotH($mail) ?></a><?php endif; ?><?php if ($tel): ?><?= $mail ? ' o' : '' ?> al <?= cotH($tel) ?><?php endif; ?>.</p>
      <?php endif; ?>
    </aside>
  </div>
</main>
<?= cotPaginadorJs() ?>
<script>
(function(){
  const $=s=>document.querySelector(s);
  // --- Escala de las hojas al ancho disponible
  function fit(){document.querySelectorAll('.scaler').forEach(sc=>{
    const inner=sc.querySelector('.inner'); const k=Math.min(1,sc.clientWidth/816);
    inner.style.transform='scale('+k+')'; sc.style.height=(inner.scrollHeight*k)+'px';});}
  window.cotPaginado=function(n){ const l=$('#docLbl'); if(l) l.textContent=l.textContent.replace(/· \d+ páginas?/,'· '+n+(n===1?' página':' páginas')); fit(); };
  window.addEventListener('resize',fit); fit();
  if(document.fonts) document.fonts.ready.then(fit);

<?php if ($prop): ?>
  // --- Pestañas Propuesta / Cotización (solo si hay propuesta)
  const tabProp=$('#tabProp'), tabCot=$('#tabCot');
  if(tabProp&&tabCot){
    const btns=document.querySelectorAll('.tabs [data-tab]');
    const show=(t,scroll)=>{
      tabProp.hidden=t!=='prop'; tabCot.hidden=t!=='cot';
      btns.forEach(b=>{const on=b.dataset.tab===t; b.setAttribute('aria-selected',String(on)); b.tabIndex=on?0:-1;});
      try{history.replaceState(null,'',t==='cot'?'#cotizacion':'#propuesta');}catch(e){}
      fit(); if(scroll) $('.tabs').scrollIntoView({behavior:'smooth',block:'start'});
    };
    document.querySelectorAll('[data-tab]').forEach(b=>b.addEventListener('click',()=>show(b.dataset.tab,b.closest('.tabs')===null)));
    $('.tabs').addEventListener('keydown',e=>{ if(e.key==='ArrowRight'||e.key==='ArrowLeft'){ const t=tabProp.hidden?'prop':'cot'; show(t); $('#'+(t==='prop'?'tbProp':'tbCot')).focus(); }});
    show(location.hash==='#cotizacion'?'cot':'prop');
  }
<?php endif; ?>
  // --- Respuesta sin aprobar: ajustar algo / no seguir
  const rf=$('#respForm');
  if(rf){
    const lbl=$('#respLbl'), tx=$('#respTexto'), mot=$('#respMotivo'), rb=$('#btnResp'), rerr=$('#respErr');
    const tipo=()=>rf.querySelector('input[name=accion]:checked').value;
    const pinta=()=>{const no=tipo()==='rechazo'; mot.hidden=!no;
      lbl.innerHTML=no?'¿Algo que quieras contarme? <small>(opcional)</small>':'¿Qué te gustaría ajustar?';
      tx.placeholder=no?'Ej.: por ahora no tengo presupuesto.':'Ej.: ¿se puede separar en dos etapas?';
      rb.textContent=no?'Avisar que no seguiré':'Enviar comentario';};
    rf.querySelectorAll('input[name=accion]').forEach(r=>r.addEventListener('change',pinta)); pinta();
    rf.addEventListener('submit',async e=>{
      e.preventDefault(); rerr.classList.remove('on');
      const accion=tipo(), texto=tx.value.trim();
      if(accion==='comentario'&&texto.length<5){rerr.textContent='Cuéntame qué te gustaría ajustar.';rerr.classList.add('on');tx.focus();return;}
      rb.disabled=true; const txt=rb.textContent; rb.textContent='Enviando…';
      try{
        const r=await fetch(location.pathname+location.search,{method:'POST',headers:{'Content-Type':'application/json'},
          body:JSON.stringify({accion,texto,motivo:accion==='rechazo'?$('#motivo').value:''})});
        const j=await r.json().catch(()=>({}));
        if(r.ok&&j.ok){ if(accion==='rechazo'){location.reload();return;} rf.hidden=true; $('#respOk').hidden=false; return; }
        if(r.status===409||r.status===410){ location.reload(); return; }
        throw new Error(j.error||'No se pudo enviar. Intenta de nuevo.');
      }catch(err){ rerr.textContent=err.message||'No se pudo enviar. Revisa tu conexión e intenta de nuevo.'; rerr.classList.add('on'); rb.disabled=false; rb.textContent=txt; }
    });
  }
  const f=$('#approve'); if(!f) return;
  // --- Validación de RUT chileno (módulo 11)
  function cleanRut(v){return v.replace(/[^0-9kK]/g,'').toUpperCase();}
  function validRut(v){
    const c=cleanRut(v); if(c.length<2) return false;
    const body=c.slice(0,-1), dv=c.slice(-1); let sum=0, m=2;
    for(let i=body.length-1;i>=0;i--){sum+=Number(body[i])*m; m=m===7?2:m+1;}
    const r=11-(sum%11); const exp=r===11?'0':r===10?'K':String(r);
    return exp===dv;
  }
  function formatRut(v){
    const c=cleanRut(v); if(c.length<2) return c;
    const body=c.slice(0,-1).replace(/\B(?=(\d{3})+(?!\d))/g,'.');
    return body+'-'+c.slice(-1);
  }
  const name=$('#nombre'), rut=$('#rut'), chk=$('#acepto'), btn=$('#btnAprobar'), ferr=$('#formErr');
  let enviando=false;
  function ready(){btn.disabled=enviando||!(chk.checked&&name.value.trim().length>2&&cleanRut(rut.value).length>=8);}
  [name,rut,chk].forEach(el=>el.addEventListener('input',ready));
  chk.addEventListener('change',ready);
  rut.addEventListener('blur',()=>{rut.value=formatRut(rut.value);});
  f.addEventListener('submit',async e=>{
    e.preventDefault();
    const ok=validRut(rut.value);
    rut.setAttribute('aria-invalid',String(!ok)); $('#rutErr').classList.toggle('on',!ok);
    if(!ok){rut.focus();return;}
    ferr.classList.remove('on'); enviando=true; ready(); btn.textContent='Aprobando…';
    try{
      const r=await fetch(location.pathname+location.search,{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({nombre:name.value.trim(),rut:formatRut(rut.value),acepto:true})});
      const j=await r.json().catch(()=>({}));
      if(r.ok&&j.ok){ location.reload(); return; }
      if(r.status===409||r.status===410){ location.reload(); return; }
      throw new Error(j.error||'No se pudo registrar la aprobación. Intenta de nuevo.');
    }catch(err){
      ferr.textContent=err.message||'No se pudo registrar la aprobación. Revisa tu conexión e intenta de nuevo.'; ferr.classList.add('on');
      enviando=false; btn.textContent='Aprobar cotización'; ready();
    }
  });
})();
</script>
</body></html>
