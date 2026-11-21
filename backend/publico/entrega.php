<?php
/**
 * Página pública del acta de entrega: /e/{token}
 * (las reglas de .htaccess de la raíz reescriben /e/... → este archivo).
 *
 *   GET  /e/{token}              → página con el acta y las dos opciones
 *   GET  /e/{token}/acta         → HTML del acta (para el iframe sandbox)
 *   GET  /e/{token}/acta.pdf     → PDF del acta
 *   POST /e/{token}/confirmar    → { nombre }  → estado "recibida"
 *   POST /e/{token}/observacion  → { texto }   → estado "con_observaciones" + correo a Inger
 *
 * Los botones son opcionales: si el cliente no hace nada, la tarea diaria la
 * pasa a "recibida_por_plazo" cuando vencen los días hábiles.
 * Una entrega en borrador no existe para el público (solo vista previa ?p=1).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/entregas.php';
require_once __DIR__ . '/../lib/visitas.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token = (string)($_GET['t'] ?? '');
$pedido = (string)($_GET['r'] ?? '');       // '' | acta | pdf | confirmar | observacion
$preview = !empty($_GET['p']);

function entNoEncontrada(): never {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow"><title>No encontrada</title>'
       . '<style>body{font-family:system-ui,sans-serif;background:#F3F4F7;color:#4F5A74;display:grid;place-items:center;min-height:100vh;margin:0;padding:20px}'
       . 'div{max-width:420px;text-align:center}h1{color:#0C1633;font-size:24px;letter-spacing:-.03em}</style></head>'
       . '<body><div><h1>No encontramos esta entrega.</h1><p>Revisa que el enlace esté completo o pide uno nuevo a quien te lo envió.</p></div></body></html>';
    exit;
}

try {
    $db = getDB();
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
        if ($method === 'POST') { header('Content-Type: application/json; charset=utf-8'); err('No encontrada', 404); }
        entNoEncontrada();
    }

    // ─── POST: confirmar / observación ────────────────────────────────
    if ($method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        rateLimit('ent.publico', 10, 3600);   // 10 intentos por hora por IP
        $e = entCargar($db, null, $token);
        if (!$e || $e['estado'] === 'borrador') err('No encontrada', 404);
        $b = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($b)) err('Solicitud inválida');
        if (in_array($e['estado'], ['recibida', 'recibida_por_plazo'], true)) err('Esta entrega ya quedó recibida.', 409);
        $ahora = cotAhora();
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

        if ($pedido === 'confirmar') {
            $nombre = trim(preg_replace('/\s+/u', ' ', (string)($b['nombre'] ?? '')));
            if ($nombre !== strip_tags($nombre) || preg_match('/[<>]/', $nombre)) err('Revisa el nombre.');
            if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 150) err('Escribe tu nombre completo.');
            $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $st = $db->prepare("UPDATE entregas SET estado = 'recibida', recibida_at = ?, recibida_nombre = ?, recibida_ip = ?, recibida_user_agent = ?
                                WHERE token = ? AND estado IN ('enviada','con_observaciones')");
            $st->execute([$ahora->format('Y-m-d H:i:s'), $nombre, $ip, $ua, $token]);
            if ($st->rowCount() !== 1) err('Esta entrega ya no se puede confirmar.', 409);
            entTerminarProyecto($db, (int)$e['id']);   // acta recibida → proyecto Terminado
            entNotificar($db, 'success', "✓ {$e['cliente']} confirmó la recepción de la entrega {$e['numero']}");
            cotResponderYSeguir(['estado' => 'recibida', 'fecha' => $ahora->format('d/m/Y · H:i') . ' h'],
                function () use ($db, $e, $nombre, $ip, $ahora) {
                    entAvisoInger($db, $e, "✓ {$e['cliente']} confirmó la entrega {$e['numero']}",
                        "{$e['cliente']} confirmó la recepción",
                        ['El cliente confirmó la recepción de la entrega <strong>' . cotH($e['numero']) . '</strong>. La garantía corre desde hoy.'],
                        [['Cliente', cotH($e['cliente'])], ['Sitio', cotH($e['dominio_efectivo'] ?: '—')], ['Confirmó', cotH($nombre)],
                         ['Fecha', cotH($ahora->format('d/m/Y · H:i')) . ' h']]);
                });
        }

        if ($pedido === 'observacion') {
            $texto = trim(str_replace("\r\n", "\n", (string)($b['texto'] ?? '')));
            $texto = strip_tags($texto);
            if (mb_strlen($texto) < 5) err('Cuéntame qué no corresponde (al menos unas palabras).');
            if (mb_strlen($texto) > 3000) err('La observación es muy larga (máximo 3.000 caracteres).');
            $linea = '[' . $ahora->format('d/m/Y H:i') . '] ' . $texto;
            $st = $db->prepare("UPDATE entregas SET estado = 'con_observaciones', observacion_at = ?,
                                       observaciones = CONCAT_WS('\n\n', NULLIF(observaciones, ''), ?)
                                WHERE token = ? AND estado IN ('enviada','con_observaciones')");
            $st->execute([$ahora->format('Y-m-d H:i:s'), $linea, $token]);
            if ($st->rowCount() !== 1) err('No se pudo registrar la observación.', 409);
            entNotificar($db, 'warning', "{$e['cliente']} dejó una observación en la entrega {$e['numero']}");
            cotResponderYSeguir(['estado' => 'con_observaciones'], function () use ($db, $e, $texto, $ahora, $ip) {
                entAvisoInger($db, $e, "{$e['cliente']} dejó una observación en la entrega {$e['numero']}",
                    'Observación en la entrega ' . $e['numero'],
                    ['<strong>' . cotH($e['cliente']) . '</strong> escribió:',
                     '<span style="display:block;padding:12px 14px;background:#F3F4F7;border-left:3px solid #2340E0;border-radius:6px;color:#0C1633">'
                     . nl2br(cotH($texto)) . '</span>',
                     'El plazo queda detenido hasta que vuelvas a enviar el acta.'],
                    [['Sitio', cotH($e['dominio_efectivo'] ?: '—')], ['Fecha', cotH($ahora->format('d/m/Y · H:i')) . ' h']]);
            });
        }
        err('Acción no válida', 400);
    }

    // ─── GET ──────────────────────────────────────────────────────────
    rateLimit('ent.ver', 120, 3600);
    $e = entCargar($db, null, $token);
    if (!$e || !$e['html_path'] || ($e['estado'] === 'borrador' && !$preview)) entNoEncontrada();

    if ($pedido === 'pdf') dsEnviarPdf('actas', $e['pdf_path'], 'Acta de entrega ' . $e['numero'] . '.pdf');
    if ($pedido === 'acta') {
        if (!$preview && visRegistrar($db, 'acta', (int)$e['id']) && empty($e['vista_at'])) {
            $db->prepare("UPDATE entregas SET vista_at = ? WHERE id = ? AND vista_at IS NULL")
               ->execute([cotAhora()->format('Y-m-d H:i:s'), (int)$e['id']]);
        }
        dsEnviarHtml('actas', $e['html_path']);
    }

    $cfg = cotConfig($db);
    $emp = trim((string)($cfg['nombre_empresa'] ?? '')) ?: 'Inger Garrido';
    $mail = trim((string)($cfg['email'] ?? ''));
    $tel = trim((string)($cfg['telefono'] ?? ''));
    $link = entLink($token, $db);
    $sufijo = $preview ? '?p=1' : '';
    $estado = $e['estado'];
    $hojas = (int)$e['hojas'];
    $alto = $hojas > 0 ? $hojas * DS_ALTO_HOJA : 1400;
    $vence = $e['plazo_vence'] ? cotFecha($e['plazo_vence']) : '';
    $acotado = !empty($e['lista_no_aplica']);   // trabajo acotado: otro saludo
    $rec = !empty($e['recibida_at']) ? new DateTimeImmutable($e['recibida_at']) : null;
    $obsAt = !empty($e['observacion_at']) ? new DateTimeImmutable($e['observacion_at']) : null;
    header('Content-Type: text/html; charset=utf-8');
} catch (Throwable $ex) {
    if ($method === 'POST') errSafe($ex, 500, 'entrega publica');
    logError('entrega publica', ['message' => $ex->getMessage(), 'line' => $ex->getLine()]);
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo 'No se pudo cargar la entrega. Intenta de nuevo en unos minutos.';
    exit;
}
?><!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Acta de entrega <?= cotH($e['numero']) ?> · <?= cotH($emp) ?></title>
<?= cotCssDoc() ?>
<style><?= @file_get_contents(__DIR__ . '/../assets/css/cotizacion-publica.css') ?: '' ?></style>
<style><?= @file_get_contents(__DIR__ . '/../assets/css/entrega-publica.css') ?: '' ?></style></head>
<body class="ap">
<header class="nav"><div class="w"><span class="logo"><?= cotH($emp) ?><span class="dot">.</span></span><small>Acta de entrega N.º <?= cotH($e['numero']) ?></small></div></header>
<main class="w">
  <section class="hero">
    <?php if ($estado === 'recibida' || $estado === 'recibida_por_plazo'): ?>
    <h1>Entrega recibida<span class="dot">.</span></h1>
    <p>Gracias. Aquí sigues teniendo el acta con <?= $acotado ? 'el detalle de lo realizado' : 'lo entregado, los accesos' ?> y la garantía, para cuando la necesites.</p>
    <?php elseif ($acotado): ?>
    <h1>Hola, <?= cotH($e['cliente']) ?>. El trabajo en tu sitio está listo<span class="dot">.</span></h1>
    <p>Aquí tienes el acta de entrega con el detalle de lo realizado y la garantía. No necesitas firmar nada.</p>
    <?php else: ?>
    <h1>Hola, <?= cotH($e['cliente']) ?>. Tu sitio ya está publicado<span class="dot">.</span></h1>
    <p>Aquí tienes el acta de entrega con lo entregado, los accesos y la garantía. No necesitas firmar nada.</p>
    <?php endif; ?>
  </section>
  <div class="layout">
    <div class="docs">
      <div class="frame"><div class="bar"><i></i><i></i><i></i><span id="docLbl"><?= cotH($e['numero']) ?>.pdf<?= $hojas > 0 ? ' · ' . $hojas . ($hojas === 1 ? ' página' : ' páginas') : '' ?></span><?php if ($e['pdf_path']): ?><a href="<?= cotH($link) ?>/acta.pdf<?= $sufijo ?>">Acta PDF ↓</a><?php endif; ?></div>
        <div class="scaler"><iframe class="inner" src="<?= cotH($link . '/acta' . $sufijo) ?>" title="Acta de entrega <?= cotH($e['numero']) ?>" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" style="height:<?= $alto ?>px"<?= $hojas > 0 ? ' scrolling="no"' : '' ?>></iframe></div></div>
    </div>
    <aside>
      <div class="card sum"><span class="lbl">Resumen</span>
        <dl><?php if ($e['dominio_efectivo']): ?><dt>Sitio</dt><dd><?= cotH($e['dominio_efectivo']) ?></dd><?php endif; ?><?php if ($e['fecha_entrega']): ?><dt>Entrega</dt><dd><?= cotH(cotFecha($e['fecha_entrega'])) ?></dd><?php endif; ?><?php if ($e['cot_numero']): ?><dt>Cotización</dt><dd><?= cotH($e['cot_numero']) ?></dd><?php endif; ?><dt>Plazo</dt><dd><?= (int)$e['plazo_dias_habiles'] ?> días hábiles</dd></dl></div>

      <?php if ($estado === 'recibida' || $estado === 'recibida_por_plazo'): ?>
      <div class="card ok">
        <span class="ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></span>
        <h2><?= $estado === 'recibida' ? 'Recepción confirmada.' : 'Recibida por plazo.' ?></h2>
        <p><?= $estado === 'recibida' ? 'Quedó registrada tu confirmación.' : 'Pasó el plazo de observaciones sin comentarios, así que la entrega quedó recibida conforme.' ?></p>
        <dl><?php if ($estado === 'recibida' && $e['recibida_nombre']): ?><dt>Confirmó</dt><dd><?= cotH($e['recibida_nombre']) ?></dd><?php endif; ?><?php if ($rec): ?><dt><?= $estado === 'recibida' ? 'Fecha' : 'Recibida' ?></dt><dd><?= $estado === 'recibida' ? $rec->format('d/m/Y · H:i') . ' h' : $rec->format('d/m/Y') ?></dd><dt>Garantía desde</dt><dd><?= $rec->format('d/m/Y') ?></dd><?php endif; ?></dl>
      </div>
      <?php else: ?>
      <div class="card ent" id="opciones">
        <?php if ($estado === 'con_observaciones'): ?>
        <h2>Recibí tu observación</h2>
        <p><?= $obsAt ? 'La anotaste el ' . $obsAt->format('d/m/Y \a \l\a\s H:i') . ' h. ' : '' ?>Te escribo pronto con la solución. Si quieres, puedes agregar otra o confirmar la recepción.</p>
        <?php else: ?>
        <h2>¿Todo en orden?</h2>
        <p>Los botones son opcionales. <?= $vence ? 'Si no hay observaciones hasta el <b>' . cotH($vence) . '</b>, la entrega se da por recibida conforme y comienza la garantía.' : 'Si no hay observaciones dentro del plazo, la entrega se da por recibida conforme.' ?></p>
        <?php endif; ?>
        <div class="ent-acciones" id="entAcciones">
          <button type="button" class="btn" data-abrir="fConfirmar">Todo bien, confirmo la recepción</button>
          <button type="button" class="btn s" data-abrir="fObservacion"><?= $estado === 'con_observaciones' ? 'Agregar otra observación' : 'Tengo una observación' ?></button>
        </div>
        <form id="fConfirmar" class="ent-form" hidden novalidate>
          <div><label class="f" for="nombre">Tu nombre</label><input type="text" id="nombre" autocomplete="name" maxlength="150" placeholder="Ej.: Nicolás Pérez"></div>
          <button class="btn" type="submit" disabled>Confirmar recepción</button>
          <button type="button" class="ent-volver" data-volver>Volver</button>
          <p class="err" role="alert"></p>
        </form>
        <form id="fObservacion" class="ent-form" hidden novalidate>
          <div><label class="f" for="texto">¿Qué no corresponde a lo acordado?</label><textarea id="texto" rows="5" maxlength="3000" placeholder="Por ejemplo: el formulario de contacto no me llega al correo."></textarea></div>
          <button class="btn" type="submit" disabled>Enviar observación</button>
          <button type="button" class="ent-volver" data-volver>Volver</button>
          <p class="err" role="alert"></p>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($mail || $tel): ?>
      <p class="help">¿Dudas? Escríbeme<?php if ($mail): ?> a <a href="mailto:<?= cotH($mail) ?>"><?= cotH($mail) ?></a><?php endif; ?><?php if ($tel): ?><?= $mail ? ' o' : '' ?> al <?= cotH($tel) ?><?php endif; ?>.</p>
      <?php endif; ?>
    </aside>
  </div>
</main>
<script>
(function(){
  const $=s=>document.querySelector(s);
  function fit(){document.querySelectorAll('.scaler').forEach(sc=>{
    const inner=sc.querySelector('.inner'); const k=Math.min(1,sc.clientWidth/816);
    inner.style.transform='scale('+k+')'; sc.style.height=(inner.scrollHeight*k)+'px';});}
  window.addEventListener('resize',fit); fit();

  const acc=$('#entAcciones'); if(!acc) return;
  const forms=['fConfirmar','fObservacion'].map(id=>document.getElementById(id));
  const mostrar=id=>{ acc.hidden=!!id; forms.forEach(f=>f.hidden=f.id!==id); if(id){ const i=document.getElementById(id).querySelector('input,textarea'); i&&i.focus(); } };
  document.querySelectorAll('[data-abrir]').forEach(b=>b.addEventListener('click',()=>mostrar(b.dataset.abrir)));
  document.querySelectorAll('[data-volver]').forEach(b=>b.addEventListener('click',()=>mostrar(null)));
  const base=location.pathname.replace(/\/+$/,'');
  const preparar=(form,campo,min,ruta,cuerpo)=>{
    const btn=form.querySelector('button[type=submit]'), err=form.querySelector('.err'), inp=form.querySelector(campo);
    let enviando=false; const txt=btn.textContent;
    const listo=()=>{ btn.disabled=enviando||inp.value.trim().length<min; };
    inp.addEventListener('input',listo);
    form.addEventListener('submit',async ev=>{
      ev.preventDefault(); if(btn.disabled) return;
      enviando=true; listo(); btn.textContent='Enviando…'; err.classList.remove('on');
      try{
        const r=await fetch(base+'/'+ruta+location.search,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(cuerpo(inp.value.trim()))});
        const j=await r.json().catch(()=>({}));
        if((r.ok&&j.ok)||r.status===409){ location.reload(); return; }
        throw new Error(j.error||'No se pudo enviar. Intenta de nuevo.');
      }catch(e){ err.textContent=e.message||'Revisa tu conexión e intenta de nuevo.'; err.classList.add('on'); enviando=false; btn.textContent=txt; listo(); }
    });
  };
  preparar(forms[0],'#nombre',3,'confirmar',v=>({nombre:v}));
  preparar(forms[1],'#texto',5,'observacion',v=>({texto:v}));
})();
</script>
</body></html>
