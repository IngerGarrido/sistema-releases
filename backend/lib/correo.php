<?php
/**
 * Envío de correos por SMTP autenticado (sin dependencias: backend/vendor no
 * viaja en el release).
 *
 * Configuración en Configuración → Correo (tabla `configuracion`):
 *   smtp_host, smtp_puerto, smtp_seguridad (ssl | tls | ninguna), smtp_usuario,
 *   smtp_pass (cifrada con APP_KEY), smtp_from, smtp_from_nombre,
 *   smtp_verificar_ssl ('1' por defecto), correo_avisos (dónde llegan los avisos
 *   a Inger; si falta, el email de la empresa).
 * (Las SMTP_* del .env NO se usan: suelen ser el valor de ejemplo.)
 *
 *   correoEnviar($db, [
 *     'to' => 'cliente@x.cl' (o varios: 'a@x.cl, b@x.cl'), 'to_nombre' => 'Nicolás' (solo con uno),
 *     'asunto' => '…', 'html' => '…', 'texto' => '…' (opcional, se deriva del html),
 *     'reply_to' => '…' (opcional),
 *     'adjuntos' => [['nombre' => 'a.pdf', 'mime' => 'application/pdf', 'ruta' => '/abs/a.pdf']
 *                    | ['nombre' => …, 'mime' => …, 'datos' => '…']],
 *   ]);  // lanza RuntimeException si falla
 */
declare(strict_types=1);

require_once __DIR__ . '/../crypto.php';

// ─── Configuración ────────────────────────────────────────────────────

/** Datos SMTP efectivos (guardados en Configuración → Correo). */
function correoConfig(PDO $db): array {
    $c = [];
    try {
        $st = $db->query("SELECT clave, valor FROM configuracion WHERE clave LIKE 'smtp\\_%' OR clave IN ('correo_avisos','email','nombre_empresa')");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $c[$r['clave']] = (string)$r['valor'];
    } catch (Throwable $e) { /* sin tabla: sin correo */ }

    $pass = '';
    if (!empty($c['smtp_pass'])) {
        try { $pass = (string)decryptStr($c['smtp_pass']); } catch (Throwable $e) { $pass = ''; }
    }
    // Solo Configuración: las SMTP_* del .env suelen ser el ejemplo (smtp.gmail.com) y no sirven.
    $host = trim($c['smtp_host'] ?? '');
    return correoNormalizarConfig([
        'host' => $host, 'puerto' => $c['smtp_puerto'] ?? '', 'seguridad' => $c['smtp_seguridad'] ?? '',
        'usuario' => $c['smtp_usuario'] ?? '', 'pass' => $pass,
        'from' => $c['smtp_from'] ?? '', 'from_nombre' => $c['smtp_from_nombre'] ?? '',
        'verificar' => $c['smtp_verificar_ssl'] ?? '1',
        'avisos' => $c['correo_avisos'] ?? '', 'email' => $c['email'] ?? '', 'empresa' => $c['nombre_empresa'] ?? '',
    ]);
}

function correoNormalizarConfig(array $c): array {
    $puerto = (int)($c['puerto'] ?: 0);
    $seg = strtolower(trim((string)$c['seguridad']));
    if (!in_array($seg, ['ssl', 'tls', 'ninguna'], true)) $seg = $puerto === 465 ? 'ssl' : 'tls';
    if ($puerto <= 0) $puerto = $seg === 'ssl' ? 465 : ($seg === 'tls' ? 587 : 25);
    $from = trim((string)$c['from']) ?: trim((string)$c['usuario']);
    $empresa = trim((string)$c['empresa']);
    return [
        'host'        => trim((string)$c['host']),
        'puerto'      => $puerto,
        'seguridad'   => $seg,
        'usuario'     => trim((string)$c['usuario']),
        'pass'        => (string)$c['pass'],
        'from'        => $from,
        'from_nombre' => trim((string)$c['from_nombre']) ?: ($empresa ?: 'Sistema'),
        'verificar'   => (string)$c['verificar'] !== '0',
        // Dónde llegan los avisos internos ("✓ X aprobó…").
        'avisos'      => trim((string)$c['avisos']) ?: (trim((string)$c['email']) ?: $from),
        'empresa'     => $empresa,
        'email'       => trim((string)$c['email']),
    ];
}

function correoConfigurado(PDO $db): bool {
    $c = correoConfig($db);
    return $c['host'] !== '' && $c['from'] !== '' && filter_var($c['from'], FILTER_VALIDATE_EMAIL) !== false;
}

// ─── Envío ────────────────────────────────────────────────────────────

const CORREO_MAX_DESTINOS = 5;

/**
 * Destinatarios desde lo que escribe Inger: separados por coma, punto y coma o
 * espacio. Devuelve la lista sin repetidos, o [] si alguno no es válido o hay
 * más de CORREO_MAX_DESTINOS.
 */
function correoDestinos(string $s): array {
    $out = [];
    foreach (preg_split('/[\s,;]+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) as $d) {
        if (filter_var($d, FILTER_VALIDATE_EMAIL) === false) return [];
        $out[strtolower($d)] ??= $d;   // sin repetidos: queda el primero
    }
    return count($out) <= CORREO_MAX_DESTINOS ? array_values($out) : [];
}

function correoEnviar(PDO $db, array $m, ?array $cfg = null): void {
    $cfg = $cfg ?? correoConfig($db);
    if ($cfg['host'] === '' || $cfg['from'] === '') {
        throw new RuntimeException('El correo no está configurado (Configuración → Correo).');
    }
    $destinos = correoDestinos((string)($m['to'] ?? ''));
    if (!$destinos) throw new RuntimeException('Dirección de destino inválida: ' . ($m['to'] ?? ''));

    $mime = correoArmarMensaje($cfg, $m);
    $smtp = new CorreoSmtp($cfg);
    try {
        $smtp->enviar($cfg['from'], $destinos, $mime);
    } finally {
        $smtp->cerrar();
    }
}

/** Guarda el correo en la ficha del cliente solo si no tenía uno (nunca lo pisa). */
function clienteGuardarCorreoSiFalta(PDO $db, int $clienteId, string $email): void {
    if ($clienteId <= 0 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) return;
    $db->prepare("UPDATE clientes SET email = ? WHERE id = ? AND (email IS NULL OR email = '')")->execute([$email, $clienteId]);
}

/**
 * Envía sin lanzar: devuelve null si salió, o el mensaje de error.
 * Lo registra en el log para poder revisar después.
 */
function correoIntentar(PDO $db, array $m, string $contexto): ?string {
    try {
        correoEnviar($db, $m);
        if (function_exists('logInfo')) logInfo('Correo enviado', ['ctx' => $contexto, 'to' => $m['to'] ?? '']);
        return null;
    } catch (Throwable $e) {
        if (function_exists('logError')) logError('Correo falló', ['ctx' => $contexto, 'to' => $m['to'] ?? '', 'error' => $e->getMessage()]);
        return $e->getMessage();
    }
}

// ─── MIME ─────────────────────────────────────────────────────────────

/** Quita saltos de línea (evita inyección de cabeceras). */
function correoLinea(string $s): string {
    return trim(preg_replace('/[\r\n\t]+/', ' ', $s));
}

/** Codifica una cabecera con caracteres no ASCII (RFC 2047). */
function correoCodificar(string $s): string {
    $s = correoLinea($s);
    if ($s === '' || !preg_match('/[^\x20-\x7E]/', $s)) return $s;
    return mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n", 9);
}

function correoDireccion(string $email, string $nombre = ''): string {
    $email = correoLinea($email);
    $nombre = correoLinea($nombre);
    if ($nombre === '') return '<' . $email . '>';
    $enc = preg_match('/[^\x20-\x7E]/', $nombre)
        ? correoCodificar($nombre)
        : '"' . addcslashes($nombre, '"\\') . '"';
    return $enc . ' <' . $email . '>';
}

/** Texto plano aproximado desde el HTML (para clientes que no muestran HTML). */
function correoHtmlATexto(string $html): string {
    $t = preg_replace('#<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html);
    $t = preg_replace('#<br\s*/?>[ \t]*\r?\n?#i', "\n", $t);
    $t = preg_replace('#</td>\s*(?=<td)#i', ': ', $t);   // tabla de datos → "Etiqueta: valor"
    $t = preg_replace('#<(/p|/div|/h[1-6]|/tr|/li)\b[^>]*>#i', "\n", $t);
    $t = preg_replace('#<li\b[^>]*>#i', '· ', $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t]+/", ' ', $t);
    $t = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $t);
    return trim(implode("\n", array_map('trim', explode("\n", $t))));
}

function correoArmarMensaje(array $cfg, array $m): string {
    $html = (string)($m['html'] ?? '');
    $texto = (string)($m['texto'] ?? '') ?: correoHtmlATexto($html);
    $adjuntos = $m['adjuntos'] ?? [];
    $dominio = substr(strrchr($cfg['from'], '@') ?: '@sistema.local', 1);

    $h = [];
    $h[] = 'Date: ' . date('r');
    $h[] = 'From: ' . correoDireccion($cfg['from'], $cfg['from_nombre']);
    $destinos = correoDestinos((string)($m['to'] ?? '')) ?: [(string)($m['to'] ?? '')];
    // El nombre ("Nicolás") solo acompaña a un destinatario único.
    $h[] = 'To: ' . (count($destinos) === 1
        ? correoDireccion($destinos[0], (string)($m['to_nombre'] ?? ''))
        : implode(', ', array_map(fn($d) => correoDireccion($d), $destinos)));
    $reply = trim((string)($m['reply_to'] ?? ''));
    if ($reply !== '' && filter_var($reply, FILTER_VALIDATE_EMAIL)) $h[] = 'Reply-To: ' . correoDireccion($reply);
    $h[] = 'Subject: ' . correoCodificar((string)($m['asunto'] ?? ''));
    $h[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dominio . '>';
    $h[] = 'MIME-Version: 1.0';

    $b64 = fn(string $s) => rtrim(chunk_split(base64_encode($s), 76, "\r\n"));
    $alt = 'alt_' . bin2hex(random_bytes(8));
    $cuerpoAlt = "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($texto) . "\r\n"
               . ($html !== '' ? "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($html) . "\r\n" : '')
               . "--$alt--";

    if (!$adjuntos) {
        $h[] = 'Content-Type: multipart/alternative; boundary="' . $alt . '"';
        return implode("\r\n", $h) . "\r\n\r\n" . $cuerpoAlt . "\r\n";
    }

    $mix = 'mix_' . bin2hex(random_bytes(8));
    $h[] = 'Content-Type: multipart/mixed; boundary="' . $mix . '"';
    $cuerpo = "--$mix\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n" . $cuerpoAlt . "\r\n";
    foreach ($adjuntos as $a) {
        if (isset($a['ruta'])) {
            $datos = @file_get_contents((string)$a['ruta']);
            if ($datos === false) throw new RuntimeException('No se encontró el adjunto ' . ($a['nombre'] ?? ''));
        } else {
            $datos = (string)($a['datos'] ?? '');
        }
        $nombre = correoLinea((string)($a['nombre'] ?? 'archivo'));
        $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $nombre) ?: 'archivo';
        $mimeA = preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', (string)($a['mime'] ?? '')) ? $a['mime'] : 'application/octet-stream';
        $cuerpo .= "--$mix\r\nContent-Type: $mimeA; name=\"$ascii\"\r\nContent-Transfer-Encoding: base64\r\n"
                 . "Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($nombre) . "\r\n\r\n"
                 . $b64($datos) . "\r\n";
    }
    $cuerpo .= "--$mix--";
    return implode("\r\n", $h) . "\r\n\r\n" . $cuerpo . "\r\n";
}

// ─── Plantilla HTML de los correos ────────────────────────────────────

/** Marca y contacto para los correos (Configuración → Empresa). */
function correoMarca(): array {
    static $m = null;
    if ($m !== null) return $m;
    $m = ['nombre' => 'Inger Garrido', 'email' => '', 'web' => ''];
    try {
        $st = getDB()->query("SELECT clave, valor FROM configuracion WHERE clave IN ('nombre_empresa','email','sitio_web')");
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            $v = trim((string)$v);
            if ($v === '') continue;
            if ($k === 'nombre_empresa') $m['nombre'] = $v;
            if ($k === 'email') $m['email'] = $v;
            if ($k === 'sitio_web') $m['web'] = preg_replace('#^https?://(www\.)?|/$#i', '', $v);
        }
    } catch (Throwable $e) { /* sin BD: valores por defecto */ }
    return $m;
}

/**
 * Correo con la estética de los documentos (propuesta y acta): encabezado navy con
 * la marca y el punto azul, etiqueta del documento, título con punto, datos en un
 * recuadro, botón y pie con el contacto. Tablas e estilos en línea (clientes de correo).
 *   $opc: ['etiqueta' => 'Acta de entrega · ENT-2026-005']
 */
function correoPlantilla(string $titulo, array $parrafos, ?array $boton = null, array $datos = [], string $pie = '', array $opc = []): string {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $navy = '#0C1B4A'; $acento = '#2340E0'; $texto = '#4F5A74'; $suave = '#7A849C'; $linea = '#E6E8EE';
    $font = "Geist,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif";
    $marca = correoMarca();

    $p = '';
    foreach ($parrafos as $x) $p .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:' . $texto . '">' . $x . '</p>';
    $tabla = '';
    if ($datos) {
        $filas = '';
        $n = count($datos);
        foreach (array_values($datos) as $i => [$k, $v]) {
            $borde = $i < $n - 1 ? 'border-bottom:1px solid ' . $linea . ';' : '';
            $filas .= '<tr><td style="padding:10px 14px;' . $borde . 'background:#F6F7FA;font-size:13px;color:' . $suave . ';width:40%">' . $e($k) . '</td>'
                    . '<td style="padding:10px 14px;' . $borde . 'font-size:14px;color:' . $navy . ';font-weight:600">' . $v . '</td></tr>';
        }
        $tabla = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:4px 0 22px;border-collapse:separate;'
               . 'border:1px solid ' . $linea . ';border-radius:10px;overflow:hidden">' . $filas . '</table>';
    }
    $btn = '';
    if ($boton) {
        $btn = '<p style="margin:6px 0 8px"><a href="' . $e($boton['url']) . '" style="display:inline-block;background:' . $acento . ';color:#ffffff;'
             . 'text-decoration:none;font-weight:600;font-size:15px;padding:13px 22px;border-radius:10px">' . $e($boton['texto']) . '</a></p>';
    }
    // Título con punto final azul, como en los documentos.
    $t = rtrim($titulo);
    $titHtml = $e(preg_replace('/[.:!?]$/u', '', $t)) . '<span style="color:' . $acento . '">.</span>';
    $etiqueta = trim((string)($opc['etiqueta'] ?? ''));
    $contacto = implode(' · ', array_filter([$marca['email'], $marca['web']]));

    return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
         . '<body style="margin:0;padding:0;background:#F3F4F7">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F4F7"><tr><td align="center" style="padding:28px 14px">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;font-family:' . $font . '">'
         // Encabezado navy: marca + etiqueta del documento
         . '<tr><td style="background:' . $navy . ';padding:20px 30px">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
         . '<td style="font-size:18px;font-weight:700;letter-spacing:-.02em;color:#ffffff">' . $e($marca['nombre']) . '<span style="color:#8E9BFF">.</span></td>'
         . ($etiqueta !== '' ? '<td align="right" style="font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#AEB6D3">' . $e($etiqueta) . '</td>' : '')
         . '</tr></table></td></tr>'
         // Cuerpo
         . '<tr><td style="padding:30px 30px 18px">'
         . '<h1 style="margin:0 0 18px;font-size:23px;line-height:1.25;letter-spacing:-.02em;color:' . $navy . '">' . $titHtml . '</h1>'
         . $p . $tabla . $btn
         . '</td></tr>'
         // Pie con el contacto
         . '<tr><td style="padding:16px 30px 20px;border-top:1px solid ' . $linea . ';font-size:12px;color:' . $suave . '">'
         . '<strong style="color:' . $navy . '">' . $e($marca['nombre']) . '<span style="color:' . $acento . '">.</span></strong>'
         . ($contacto !== '' ? ' &nbsp;·&nbsp; ' . $e($contacto) : '')
         . '</td></tr>'
         . '</table>'
         . ($pie !== '' ? '<p style="max-width:560px;margin:14px auto 0;font-family:Arial,sans-serif;font-size:12px;line-height:1.5;color:' . $suave . '">' . $pie . '</p>' : '')
         . '</td></tr></table></body></html>';
}

// ─── Cliente SMTP mínimo ──────────────────────────────────────────────

final class CorreoSmtp
{
    /** @var resource|null */
    private $s = null;
    private array $cfg;
    private int $timeout;

    public function __construct(array $cfg, int $timeout = 20)
    {
        $this->cfg = $cfg;
        $this->timeout = $timeout;
    }

    public function enviar(string $from, array $rcpts, string $mensaje): void
    {
        $this->conectar();
        $this->cmd('MAIL FROM:<' . correoLinea($from) . '>', [250]);
        foreach ($rcpts as $r) $this->cmd('RCPT TO:<' . correoLinea($r) . '>', [250, 251]);
        $this->cmd('DATA', [354]);
        // Normalizar saltos a CRLF y "dot-stuffing" (RFC 5321 §4.5.2).
        $mensaje = preg_replace("/\r\n|\r|\n/", "\r\n", $mensaje);
        $mensaje = preg_replace('/^\./m', '..', $mensaje);
        $this->escribir(rtrim($mensaje, "\r\n") . "\r\n.\r\n");
        $this->leer([250]);
    }

    public function cerrar(): void
    {
        if ($this->s) {
            try { $this->escribir("QUIT\r\n"); } catch (Throwable $e) {}
            @fclose($this->s);
            $this->s = null;
        }
    }

    private function conectar(): void
    {
        $c = $this->cfg;
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $c['verificar'], 'verify_peer_name' => $c['verificar'],
            'allow_self_signed' => !$c['verificar'], 'SNI_enabled' => true, 'peer_name' => $c['host'],
        ]]);
        $dest = ($c['seguridad'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['puerto'];
        $errno = 0; $errstr = '';
        $s = @stream_socket_client($dest, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            throw new RuntimeException("No se pudo conectar a {$c['host']}:{$c['puerto']} ($errstr). Revisa servidor, puerto y seguridad.");
        }
        stream_set_timeout($s, $this->timeout);
        $this->s = $s;
        $this->leer([220]);
        $ehlo = $this->ehlo();

        if ($c['seguridad'] === 'tls') {
            if (stripos($ehlo, 'STARTTLS') === false) throw new RuntimeException('El servidor no ofrece STARTTLS. Prueba con seguridad SSL (puerto 465).');
            $this->cmd('STARTTLS', [220]);
            $metodo = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $metodo |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $metodo |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (!@stream_socket_enable_crypto($s, true, $metodo)) {
                throw new RuntimeException('Falló la conexión segura (TLS). Si el certificado del servidor no es válido, desmarca "Verificar certificado".');
            }
            $ehlo = $this->ehlo();
        }

        if ($c['usuario'] !== '') {
            if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $ehlo)) {
                $this->cmd('AUTH PLAIN ' . base64_encode("\0" . $c['usuario'] . "\0" . $c['pass']), [235], true);
            } else {
                $this->cmd('AUTH LOGIN', [334]);
                $this->cmd(base64_encode($c['usuario']), [334], true);
                $this->cmd(base64_encode($c['pass']), [235], true);
            }
        }
    }

    private function ehlo(): string
    {
        $host = preg_replace('/[^A-Za-z0-9.-]/', '', (string)($_SERVER['SERVER_NAME'] ?? '')) ?: 'localhost';
        return $this->cmd('EHLO ' . $host, [250]);
    }

    private function cmd(string $linea, array $esperados, bool $secreto = false): string
    {
        $this->escribir($linea . "\r\n");
        try {
            return $this->leer($esperados);
        } catch (RuntimeException $e) {
            if ($secreto && preg_match('/^(535|534|530)/', $e->getMessage())) {
                throw new RuntimeException('El servidor rechazó el usuario o la contraseña del correo.');
            }
            throw $e;
        }
    }

    private function escribir(string $datos): void
    {
        $total = strlen($datos);
        $hecho = 0;
        while ($hecho < $total) {
            $n = @fwrite($this->s, substr($datos, $hecho, 65536));
            if ($n === false || $n === 0) throw new RuntimeException('Se cortó la conexión con el servidor de correo.');
            $hecho += $n;
        }
    }

    private function leer(array $esperados): string
    {
        $resp = '';
        while (true) {
            $l = fgets($this->s, 4096);
            if ($l === false) {
                $meta = stream_get_meta_data($this->s);
                throw new RuntimeException(!empty($meta['timed_out']) ? 'El servidor de correo no respondió a tiempo.' : 'Se cortó la conexión con el servidor de correo.');
            }
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') break;
        }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $esperados, true)) {
            throw new RuntimeException(trim(preg_replace('/\s+/', ' ', $resp)));
        }
        return $resp;
    }
}
