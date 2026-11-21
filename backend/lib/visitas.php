<?php
/**
 * Visitas de los links públicos (/c/{token} cotización y propuesta, /e/{token} acta).
 *
 * Una visita cuenta como «el cliente la vio» solo si:
 *   1) no es un robot (lectores de enlaces de correo, vistas previas de WhatsApp,
 *      antivirus corporativos…): ver visEsRobot();
 *   2) no viene de una conexión desde la que se usó el panel en los últimos
 *      VIS_DIAS_PANEL días: así no cuentan las visitas de Inger desde cualquier
 *      navegador o dispositivo de su misma red (ver visMarcarPanel()).
 * Cada visita que cuenta queda en `doc_visitas` con la fecha y el dispositivo,
 * para que Inger pueda reconocerla («Celular · Android · Chrome»).
 *
 * Las IP nunca se guardan en claro: solo un hash con APP_KEY. En IPv6 se usa el
 * prefijo /64 (un mismo equipo cambia la parte final con frecuencia).
 * Si las tablas aún no existen (falta correr la migración), todo esto falla en
 * silencio y el sistema se comporta como antes.
 */
declare(strict_types=1);

const VIS_DIAS_PANEL = 30;

/** Clave de la conexión actual (hash, nunca la IP). '' si no hay IP. */
function visIpClave(?string $ip = null): string {
    $ip = trim((string)($ip ?? ($_SERVER['REMOTE_ADDR'] ?? '')));
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) return '';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = inet_pton($ip);
        // IPv4 mapeada en IPv6 (::ffff:1.2.3.4): tratarla como IPv4.
        if ($bin !== false && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $ip = (string)inet_ntop(substr($bin, 12));
        } elseif ($bin !== false) {
            $ip = (string)inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
        }
    }
    return hash('sha256', (getenv('APP_KEY') ?: '') . '|' . $ip);
}

/** Anota que desde esta conexión se usó el panel. Se llama en requireAuth(). */
function visMarcarPanel(PDO $db): void {
    $k = visIpClave();
    if ($k === '') return;
    try {
        // Solo escribe si pasaron 10 minutos desde la última marca (evita una escritura por petición).
        $db->prepare("INSERT INTO ips_panel (ip_hash, visto_at) VALUES (?, NOW())
                      ON DUPLICATE KEY UPDATE visto_at = IF(visto_at < NOW() - INTERVAL 10 MINUTE, NOW(), visto_at)")
           ->execute([$k]);
    } catch (Throwable $e) { /* sin migración: no hacer nada */ }
}

/** ¿Esta conexión usó el panel en los últimos VIS_DIAS_PANEL días? */
function visEsPanel(PDO $db): bool {
    $k = visIpClave();
    if ($k === '') return false;
    try {
        $st = $db->prepare("SELECT 1 FROM ips_panel WHERE ip_hash = ? AND visto_at > NOW() - INTERVAL " . VIS_DIAS_PANEL . " DAY");
        $st->execute([$k]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * ¿La petición es de un programa y no de una persona?
 * Los navegadores siempre mandan User-Agent y Accept-Language; los lectores de
 * enlaces de correo y las vistas previas de chats suelen no mandar el segundo
 * o se identifican en el User-Agent.
 */
function visEsRobot(?array $srv = null): bool {
    $srv ??= $_SERVER;
    if (strtoupper((string)($srv['REQUEST_METHOD'] ?? 'GET')) !== 'GET') return true;
    $ua = (string)($srv['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || trim((string)($srv['HTTP_ACCEPT_LANGUAGE'] ?? '')) === '') return true;
    if (!preg_match('/Mozilla\//', $ua)) return true;
    return (bool)preg_match(
        '/bot\b|bot\/|crawl|spider|slurp|preview|scanner|headless|phantom|lighthouse|python|curl|wget|java\/|go-http|okhttp|axios|node-fetch|'
        . 'facebookexternalhit|whatsapp|telegram|slack|discord|skype|teams|linkedin|google-safety|safelinks|proofpoint|mimecast|barracuda|'
        . 'forcepoint|symantec|trendmicro|sophos|fortinet|zscaler|cisco|ironport|microsoft office|ms-office|outlook-ios/i',
        $ua
    );
}

/** Descripción corta del dispositivo: «Celular · Android · Chrome». */
function visDispositivo(?string $ua = null): string {
    $ua = (string)($ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $so = match (true) {
        (bool)preg_match('/iPad/', $ua)                      => 'iPad',
        (bool)preg_match('/iPhone|iPod/', $ua)               => 'iPhone',
        (bool)preg_match('/Android/', $ua)                   => 'Android',
        (bool)preg_match('/Windows/', $ua)                   => 'Windows',
        (bool)preg_match('/Macintosh|Mac OS X/', $ua)        => 'Mac',
        (bool)preg_match('/CrOS/', $ua)                      => 'Chromebook',
        (bool)preg_match('/Linux/', $ua)                     => 'Linux',
        default                                              => '',
    };
    $tipo = match (true) {
        $so === 'iPad' || (bool)preg_match('/Tablet/i', $ua) || ($so === 'Android' && !preg_match('/Mobile/', $ua)) => 'Tablet',
        $so === 'iPhone' || (bool)preg_match('/Mobile/', $ua) => 'Celular',
        $so === ''                                            => '',
        default                                               => 'Computador',
    };
    $nav = match (true) {
        (bool)preg_match('/Edg(e|A|iOS)?\//', $ua)           => 'Edge',
        (bool)preg_match('/OPR\/|Opera/', $ua)               => 'Opera',
        (bool)preg_match('/SamsungBrowser/', $ua)            => 'Samsung',
        (bool)preg_match('/Firefox\/|FxiOS/', $ua)           => 'Firefox',
        (bool)preg_match('/Chrome\/|CriOS/', $ua)            => 'Chrome',
        (bool)preg_match('/Safari\//', $ua)                  => 'Safari',
        default                                              => '',
    };
    $partes = array_filter([$tipo, $so, $nav]);
    return $partes ? implode(' · ', $partes) : 'Dispositivo desconocido';
}

/**
 * Registra la visita si es del cliente. Devuelve true si cuenta (y entonces el
 * llamador marca vista_at como siempre); false si es un robot o es Inger.
 * $doc: 'cotizacion' | 'propuesta' | 'acta'.
 * Recargar la página no suma: mismo dispositivo y misma conexión dentro de
 * VIS_MIN_SESION minutos es la misma visita (solo se actualiza su hora).
 */
const VIS_MIN_SESION = 30;

function visRegistrar(PDO $db, string $doc, int $refId): bool {
    if (visEsRobot() || visEsPanel($db)) return false;
    $disp = mb_substr(visDispositivo(), 0, 60);
    $ip = visIpClave();
    try {
        $st = $db->prepare("SELECT id FROM doc_visitas WHERE doc = ? AND ref_id = ? AND ip_hash = ? AND dispositivo = ?
                              AND visto_at > NOW() - INTERVAL " . VIS_MIN_SESION . " MINUTE ORDER BY id DESC LIMIT 1");
        $st->execute([$doc, $refId, $ip, $disp]);
        $id = $st->fetchColumn();
        if ($id) {
            $db->prepare("UPDATE doc_visitas SET visto_at = NOW() WHERE id = ?")->execute([(int)$id]);
        } else {
            $db->prepare("INSERT INTO doc_visitas (doc, ref_id, visto_at, dispositivo, ip_hash) VALUES (?, ?, NOW(), ?, ?)")
               ->execute([$doc, $refId, $disp, $ip]);
        }
    } catch (Throwable $e) { /* sin migración: igual cuenta, como antes */ }
    return true;
}

/**
 * Resumen por id: [id => ['veces' => n, 'ultima' => 'Y-m-d H:i:s', 'dispositivo' => '…']].
 */
function visResumen(PDO $db, string $doc, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT v.ref_id, COUNT(*) AS veces, MAX(v.visto_at) AS ultima,
                                   (SELECT v2.dispositivo FROM doc_visitas v2 WHERE v2.doc = v.doc AND v2.ref_id = v.ref_id
                                    ORDER BY v2.visto_at DESC, v2.id DESC LIMIT 1) AS dispositivo
                            FROM doc_visitas v WHERE v.doc = ? AND v.ref_id IN ($in) GROUP BY v.doc, v.ref_id");
        $st->execute(array_merge([$doc], $ids));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['ref_id']] = ['veces' => (int)$r['veces'], 'ultima' => $r['ultima'], 'dispositivo' => (string)$r['dispositivo']];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}
