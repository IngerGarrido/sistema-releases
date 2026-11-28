<?php
/**
 * Módulo Entregas: acta de entrega (HTML + PDF armados fuera del sistema).
 *
 *   - Feriados de Chile y días hábiles (para el plazo de observaciones).
 *   - Numeración: la del proyecto, tomada de su cotización (COT-2026-005 → ENT-2026-005).
 *   - Carga de una entrega con cliente / proyecto / sitio.
 *   - Correos: envío del acta al cliente y avisos a Inger.
 *   - entProcesarPlazos(): pasa a "recibida_por_plazo" las entregas cuyo plazo
 *     venció sin observaciones (cron diario + respaldo al abrir el Panel).
 */
declare(strict_types=1);

require_once __DIR__ . '/cotizacion_doc.php';   // cotH, cotAhora, cotFecha, cotBaseUrl, cotNuevoToken
require_once __DIR__ . '/cotizacion_correo.php'; // cotResponderYSeguir, cotSaludoNombre
require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/doc_subido.php';

const ENT_ESTADOS = ['borrador', 'enviada', 'recibida', 'recibida_por_plazo', 'con_observaciones'];
const ENT_ETIQUETAS = [
    'borrador' => 'Borrador', 'enviada' => 'Enviada', 'recibida' => 'Recibida',
    'recibida_por_plazo' => 'Recibida por plazo', 'con_observaciones' => 'Con observaciones',
];

/**
 * Lista de revisión antes de entregar (checklist-entrega.md). Solo para Inger.
 * Las claves se guardan en entregas.checklist; cambiar un texto no rompe nada,
 * cambiar una clave hace que esa casilla aparezca desmarcada.
 */
const ENT_CHECKLIST = [
    ['grupo' => 'Funcionamiento', 'items' => [
        ['formularios', 'Todos los formularios probados: llegan al correo del cliente (y no a spam).'],
        ['whatsapp', 'Botón de WhatsApp con el número correcto.'],
        ['enlaces', 'Enlaces del menú y del pie funcionan; no hay enlaces a la demo ni a localhost.'],
        ['404_gracias', 'Página 404 y página de gracias revisadas.'],
        ['responsive', 'Se ve bien en celular, tablet y computador.'],
    ]],
    ['grupo' => 'Seguridad y respaldo', 'items' => [
        ['ssl', 'SSL activo (candado) y todo redirige a https://.'],
        ['respaldo', 'Respaldo completo hecho y guardado fuera del hosting.'],
        ['actualizado', 'Plugins y tema actualizados; borrados los que no se usan.'],
        ['usuario_cliente', 'Usuario del cliente creado (Editor si no necesita más).'],
        ['usuarios_prueba', 'Usuarios de prueba borrados; el mío queda solo si hay mantención.'],
    ]],
    ['grupo' => 'Google y medición', 'items' => [
        ['seo', 'Título y descripción de Google en las páginas principales.'],
        ['indexable', 'El sitio no está bloqueado para buscadores (Ajustes → Lectura).'],
        ['analytics', 'Analytics y/o píxel conectados, si el cliente los tiene.'],
        ['favicon', 'Favicon e imagen para compartir en redes.'],
    ]],
    ['grupo' => 'Rendimiento', 'items' => [
        ['webp', 'Imágenes en WebP y con medidas correctas.'],
        ['cache', 'Caché activa; velocidad revisada en celular.'],
    ]],
    ['grupo' => 'Para el acta', 'items' => [
        ['videos', 'Videos tutoriales grabados y con link.'],
        ['vencimientos', 'Fechas de vencimiento de dominio, hosting y licencias anotadas.'],
        ['contrasena', 'Contraseña enviada aparte (nunca en el acta ni en el correo).'],
    ]],
];

function entChecklistClaves(): array {
    $k = [];
    foreach (ENT_CHECKLIST as $g) foreach ($g['items'] as [$clave]) $k[] = $clave;
    return $k;
}

/** Casillas que faltan por marcar. Si la lista "no aplica" (trabajo acotado), ninguna. */
function entChecklistFaltan(?string $json, bool $noAplica = false): array {
    if ($noAplica) return [];
    $m = json_decode((string)$json, true);
    $m = is_array($m) ? $m : [];
    return array_values(array_filter(entChecklistClaves(), fn($k) => empty($m[$k])));
}

// ─── Feriados de Chile ────────────────────────────────────────────────

/** Domingo de Pascua (algoritmo gregoriano anónimo; no depende de ext/calendar). */
function entPascua(int $y): DateTimeImmutable {
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31); $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $mes, $dia));
}

/**
 * Día del solsticio de invierno en Chile (Día Nacional de los Pueblos
 * Indígenas, Ley 21.357). Años conocidos en tabla; el resto se calcula
 * (Meeus, precisión de minutos) en hora de Santiago.
 */
function entSolsticio(int $y): string {
    $tabla = [2025 => '06-20', 2026 => '06-21', 2027 => '06-21', 2028 => '06-20', 2029 => '06-20', 2030 => '06-21'];
    if (isset($tabla[$y])) return "$y-{$tabla[$y]}";
    $Y = ($y - 2000) / 1000;
    $jde = 2451716.56767 + 365241.62603 * $Y + 0.00325 * $Y ** 2 + 0.00888 * $Y ** 3 - 0.00030 * $Y ** 4;
    $unix = ($jde - 2440587.5) * 86400;
    return (new DateTimeImmutable('@' . (int)round($unix)))->setTimezone(new DateTimeZone(COT_TZ))->format('Y-m-d');
}

/** Ley 19.668: martes/miércoles/jueves → lunes anterior; viernes → lunes siguiente. */
function entTrasladable(int $y, string $md): string {
    $d = new DateTimeImmutable("$y-$md");
    $dow = (int)$d->format('N'); // 1 = lunes
    if (in_array($dow, [2, 3, 4], true)) $d = $d->modify('-' . ($dow - 1) . ' days');
    elseif ($dow === 5) $d = $d->modify('+3 days');
    return $d->format('Y-m-d');
}

/** Feriados nacionales del año (Y-m-d => nombre) + los adicionales de Configuración. */
function entFeriados(int $y, ?PDO $db = null): array {
    $f = [
        "$y-01-01" => 'Año Nuevo',
        "$y-05-01" => 'Día del Trabajo',
        "$y-05-21" => 'Glorias Navales',
        entSolsticio($y) => 'Pueblos Indígenas',
        entTrasladable($y, '06-29') => 'San Pedro y San Pablo',
        "$y-07-16" => 'Virgen del Carmen',
        "$y-08-15" => 'Asunción de la Virgen',
        "$y-09-18" => 'Independencia Nacional',
        "$y-09-19" => 'Glorias del Ejército',
        entTrasladable($y, '10-12') => 'Encuentro de Dos Mundos',
        "$y-11-01" => 'Todos los Santos',
        "$y-12-08" => 'Inmaculada Concepción',
        "$y-12-25" => 'Navidad',
    ];
    $pascua = entPascua($y);
    $f[$pascua->modify('-2 days')->format('Y-m-d')] = 'Viernes Santo';
    $f[$pascua->modify('-1 day')->format('Y-m-d')] = 'Sábado Santo';
    // 2 de enero si es lunes; 17 de septiembre si es lunes; 20 si es viernes.
    if ((new DateTimeImmutable("$y-01-02"))->format('N') === '1') $f["$y-01-02"] = 'Feriado (2 de enero)';
    if ((new DateTimeImmutable("$y-09-17"))->format('N') === '1') $f["$y-09-17"] = 'Fiestas Patrias';
    if ((new DateTimeImmutable("$y-09-20"))->format('N') === '5') $f["$y-09-20"] = 'Fiestas Patrias';
    // Iglesias Evangélicas (Ley 20.299): martes → viernes anterior; miércoles → viernes siguiente.
    $ev = new DateTimeImmutable("$y-10-31");
    $dow = (int)$ev->format('N');
    if ($dow === 2) $ev = $ev->modify('-4 days'); elseif ($dow === 3) $ev = $ev->modify('+2 days');
    $f[$ev->format('Y-m-d')] = 'Iglesias Evangélicas';

    foreach (entFeriadosExtra($db) as $x) if (str_starts_with($x, "$y-")) $f[$x] = 'Feriado adicional';
    ksort($f);
    return $f;
}

/** Feriados adicionales (elecciones, decretos) escritos en Configuración → Documentos. */
function entFeriadosExtra(?PDO $db): array {
    static $cache = null;
    if ($db === null) return [];
    if ($cache === null) {
        $cache = [];
        try {
            $v = (string)$db->query("SELECT valor FROM configuracion WHERE clave = 'feriados_extra'")->fetchColumn();
            preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})\b/', $v, $m, PREG_SET_ORDER);
            foreach ($m as $x) if (checkdate((int)$x[2], (int)$x[3], (int)$x[1])) $cache[] = $x[0];
        } catch (Throwable $e) { /* sin tabla */ }
    }
    return $cache;
}

function entEsHabil(DateTimeImmutable $d, ?PDO $db = null): bool {
    if ((int)$d->format('N') >= 6) return false;
    return !isset(entFeriados((int)$d->format('Y'), $db)[$d->format('Y-m-d')]);
}

/** Fecha del N-ésimo día hábil DESPUÉS de $desde (el día del envío no cuenta). */
function entSumarHabiles(DateTimeImmutable $desde, int $n, ?PDO $db = null): DateTimeImmutable {
    $d = $desde->setTime(0, 0);
    $n = max(1, $n);
    while ($n > 0) {
        $d = $d->modify('+1 day');
        if (entEsHabil($d, $db)) $n--;
    }
    return $d;
}

// ─── Datos ────────────────────────────────────────────────────────────

/**
 * Número del acta = el de su cotización, igual que la propuesta:
 * COT-2026-005 → ENT-2026-005 (sin la versión: COT-2026-001-v3 → ENT-2026-001).
 * Si ese número ya lo usa otra entrega del mismo proyecto: ENT-2026-005-2, -3…
 * Sin cotización no hay número automático (uno correlativo podría chocar con una
 * cotización futura): lo escribe Inger.
 */
function entNumeroDesdeCotizacion(PDO $db, int $cotizacionId, int $excluirId = 0): ?string {
    $st = $db->prepare("SELECT numero FROM cotizaciones WHERE id = ?");
    $st->execute([$cotizacionId]);
    $cot = (string)($st->fetchColumn() ?: '');
    if (!preg_match('/^[A-Za-z]+-(\d{4}-\d+)/', $cot, $m)) return null;
    $base = 'ENT-' . $m[1];
    $existe = $db->prepare("SELECT id FROM entregas WHERE numero = ? AND id <> ?");
    for ($n = $base, $i = 1; ; $n = $base . '-' . (++$i)) {
        $existe->execute([$n, $excluirId]);
        if (!$existe->fetchColumn()) return $n;
    }
}

/** Dominio "limpio" desde una URL (https://www.x.cl/ → x.cl). */
function entDominio(?string $url): string {
    $url = trim((string)$url);
    if ($url === '') return '';
    $h = parse_url(preg_match('#^https?://#i', $url) ? $url : "https://$url", PHP_URL_HOST) ?: $url;
    return preg_replace('/^www\./i', '', strtolower($h));
}

/** Entrega con datos del cliente / proyecto / sitio / cotización. */
function entCargar(PDO $db, ?int $id, ?string $token = null): ?array {
    $where = $token !== null ? 'e.token = ?' : 'e.id = ?';
    $st = $db->prepare("SELECT e.*, cl.nombre AS cli_nombre, cl.nombre_agencia AS cli_agencia, cl.email AS cli_email,
                               p.nombre AS proy_nombre, p.estado AS proy_estado, s.nombre AS sitio_nombre, s.url_principal AS sitio_url,
                               c.numero AS cot_numero
                        FROM entregas e
                        LEFT JOIN clientes cl ON cl.id = e.cliente_id
                        LEFT JOIN proyectos p ON p.id = e.proyecto_id
                        LEFT JOIN sitios s ON s.id = p.sitio_id
                        LEFT JOIN cotizaciones c ON c.id = e.cotizacion_id
                        WHERE $where");
    $st->execute([$token ?? $id]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    if (!$e) return null;
    $e['cliente'] = trim((string)($e['cli_agencia'] ?: $e['cli_nombre']));
    $e['dominio_efectivo'] = trim((string)$e['dominio']) ?: (entDominio($e['sitio_url']) ?: trim((string)$e['sitio_nombre']));
    return $e;
}

/** Lo que ve el panel (sin rutas internas). */
function entPublicoPanel(array $e): array {
    $chk = json_decode((string)($e['checklist'] ?? ''), true);
    return [
        'id' => (int)$e['id'], 'numero' => $e['numero'], 'estado' => $e['estado'],
        'estado_label' => ENT_ETIQUETAS[$e['estado']] ?? $e['estado'],
        'cliente_id' => (int)$e['cliente_id'], 'cliente' => $e['cliente'], 'cliente_email' => (string)($e['cli_email'] ?? ''),
        'proyecto_id' => $e['proyecto_id'] ? (int)$e['proyecto_id'] : null, 'proyecto' => $e['proy_nombre'],
        'sitio' => $e['sitio_nombre'],
        'cotizacion_id' => $e['cotizacion_id'] ? (int)$e['cotizacion_id'] : null, 'cotizacion' => $e['cot_numero'],
        'dominio' => (string)($e['dominio'] ?? ''), 'dominio_efectivo' => $e['dominio_efectivo'],
        'fecha_entrega' => $e['fecha_entrega'], 'plazo_dias_habiles' => (int)$e['plazo_dias_habiles'],
        'checklist' => is_array($chk) ? $chk : (object)[],
        'lista_no_aplica' => !empty($e['lista_no_aplica']),
        'acta' => $e['html_path'] ? ['hojas' => (int)$e['hojas'], 'tiene_pdf' => !empty($e['pdf_path']),
                                     'origen' => $e['acta_origen'] ?? null, 'subida_at' => $e['acta_subida_at'] ?? null] : null,
        'enviada_at' => $e['enviada_at'], 'enviada_a' => $e['enviada_a'], 'plazo_vence' => $e['plazo_vence'],
        'vista_at' => $e['vista_at'], 'recibida_at' => $e['recibida_at'], 'recibida_nombre' => $e['recibida_nombre'],
        'observaciones' => $e['observaciones'], 'observacion_at' => $e['observacion_at'],
        'notas' => $e['notas'], 'created_at' => $e['created_at'],
        // Garantía (null si falta la migración 2026_11_20_4).
        'garantia_meses' => array_key_exists('garantia_meses', $e) ? (int)$e['garantia_meses'] : null,
        'garantia_hasta' => array_key_exists('garantia_meses', $e) ? entGarantiaHasta($e['recibida_at'], (int)$e['garantia_meses']) : null,
        'link' => $e['estado'] !== 'borrador' ? entLink($e['token']) : null,
    ];
}

/** URL del sistema: la de la petición actual, o la guardada (para el cron). */
function entUrlSistema(?PDO $db = null): string {
    if (!empty($_SERVER['HTTP_HOST'])) return cotBaseUrl();
    if ($db) {
        try {
            $v = (string)$db->query("SELECT valor FROM configuracion WHERE clave = 'url_sistema'")->fetchColumn();
            if ($v !== '') return rtrim($v, '/');
        } catch (Throwable $e) {}
    }
    return '';
}

/** Guarda la URL del sistema para que el cron pueda armar links. */
function entRecordarUrl(PDO $db): void {
    if (empty($_SERVER['HTTP_HOST'])) return;
    $u = cotBaseUrl();
    $db->prepare("INSERT INTO configuracion (clave, valor) VALUES ('url_sistema', ?) ON DUPLICATE KEY UPDATE valor = ?")->execute([$u, $u]);
}

function entLink(string $token, ?PDO $db = null): string {
    return entUrlSistema($db) . '/e/' . $token;
}

// ─── Garantía ─────────────────────────────────────────────────────────

/** Días antes del fin de la garantía en que se avisa a Inger (ofrecer mantención). */
const ENT_GARANTIA_AVISO_DIAS = 15;

/** "Garantía hasta" en SQL (alias e = entregas), igual que entGarantiaHasta(). */
const ENT_GARANTIA_HASTA_SQL = "CASE WHEN e.recibida_at IS NOT NULL AND e.garantia_meses > 0
    THEN DATE_SUB(DATE_ADD(DATE(e.recibida_at), INTERVAL e.garantia_meses MONTH), INTERVAL 1 DAY) END";

/**
 * "Garantía hasta" (inclusive) = fecha de recepción + meses − 1 día. Si el día
 * no existe en el mes de destino se usa el último del mes (igual que MySQL
 * DATE_ADD … INTERVAL n MONTH). null si no hay recepción o la garantía es 0.
 */
function entGarantiaHasta(?string $recibidaAt, int $meses): ?string {
    if (!$recibidaAt || $meses <= 0) return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($recibidaAt, 0, 10));
    if (!$d) return null;
    $y = (int)$d->format('Y'); $m = (int)$d->format('n') + $meses; $dia = (int)$d->format('j');
    $y += intdiv($m - 1, 12); $m = (($m - 1) % 12) + 1;
    $ultimo = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');
    return (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, min($dia, $ultimo))))->modify('-1 day')->format('Y-m-d');
}

/**
 * Aviso (campana + correo a Inger) cuando a una garantía le quedan
 * ENT_GARANTIA_AVISO_DIAS días o menos: buen momento para ofrecer mantención.
 * Idempotente: se marca garantia_aviso_at (UPDATE condicionado). Las que ya
 * vencieron sin aviso (p. ej. entregas antiguas) se marcan sin avisar.
 * Devuelve los números de las entregas avisadas.
 */
function entProcesarGarantias(PDO $db, ?string $hoy = null): array {
    $hoy = $hoy ?: cotAhora()->format('Y-m-d');
    try {
        $ids = $db->query("SELECT id FROM entregas WHERE estado IN ('recibida','recibida_por_plazo')
                           AND recibida_at IS NOT NULL AND garantia_meses > 0 AND garantia_aviso_at IS NULL")
                  ->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return []; }
    $avisadas = [];
    foreach ($ids as $id) {
        $e = entCargar($db, (int)$id);
        if (!$e) continue;
        $hasta = entGarantiaHasta($e['recibida_at'], (int)$e['garantia_meses']);
        if (!$hasta) continue;
        $desde = (new DateTimeImmutable($hasta))->modify('-' . ENT_GARANTIA_AVISO_DIAS . ' days')->format('Y-m-d');
        if ($hoy < $desde) continue;
        $up = $db->prepare("UPDATE entregas SET garantia_aviso_at = ? WHERE id = ? AND garantia_aviso_at IS NULL");
        $up->execute([cotAhora()->format('Y-m-d H:i:s'), (int)$id]);
        if ($up->rowCount() !== 1 || $hoy > $hasta) continue;   // ya vencida: solo se marca
        $avisadas[] = $e['numero'];
        $f = cotFecha($hasta);
        $sitio = $e['dominio_efectivo'] ?: ($e['sitio_nombre'] ?: '');
        entNotificar($db, 'info', "La garantía de {$e['numero']} ({$e['cliente']}" . ($sitio ? " · $sitio" : '') . ") termina el $f: buen momento para ofrecer mantención");
        entAvisoInger($db, $e, "La garantía de {$e['numero']} termina el $f",
            "La garantía de {$e['numero']} está por terminar",
            ['La garantía de la entrega <strong>' . cotH($e['numero']) . '</strong> de ' . cotH($e['cliente'])
             . ' termina el <strong>' . cotH($f) . '</strong>.',
             'Es un buen momento para ofrecerle un plan de mantención.'],
            [['Cliente', cotH($e['cliente'])], ['Sitio', cotH($sitio ?: '—')],
             ['Recibida', cotH(cotFecha(substr((string)$e['recibida_at'], 0, 10)))],
             ['Garantía', cotH((int)$e['garantia_meses'] . ' ' . ((int)$e['garantia_meses'] === 1 ? 'mes' : 'meses'))],
             ['Termina', cotH($f)]]);
    }
    return $avisadas;
}

/**
 * Acta recibida → su proyecto pasa a Terminado (si estaba En curso o Pausado).
 * Con $entregaId solo esa entrega; sin él, revisa todas (procesosDiarios).
 * @return int proyectos actualizados
 */
function entTerminarProyecto(PDO $db, ?int $entregaId = null): int {
    try {
        $sql = "UPDATE proyectos p JOIN entregas e ON e.proyecto_id = p.id
                SET p.estado = 'Terminado', p.updated_at = NOW()
                WHERE e.estado IN ('recibida', 'recibida_por_plazo')
                  AND p.estado IN ('En curso', 'Pausado') AND p.deleted_at IS NULL";
        if ($entregaId) { $st = $db->prepare($sql . ' AND e.id = ?'); $st->execute([$entregaId]); }
        else            { $st = $db->query($sql); }
        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;   // sin tabla entregas (migración pendiente)
    }
}

/** "Garantía desde": día siguiente al último día hábil del plazo (o la confirmación). */
function entGarantiaDesde(array $e): ?string {
    if (!empty($e['recibida_at'])) return substr((string)$e['recibida_at'], 0, 10);
    return null;
}

// ─── Correos ──────────────────────────────────────────────────────────

function entNotificar(PDO $db, string $tipo, string $msg): void {
    try {
        $db->prepare("INSERT INTO notificaciones (usuario_id, tipo, mensaje, link) VALUES (NULL, ?, ?, 'entregas')")
           ->execute([$tipo, mb_substr($msg, 0, 500)]);
    } catch (Throwable $e) {}
}

function entAdjuntoActa(array $e): array {
    $abs = dsRutaAbs('actas', $e['pdf_path'] ?? null);
    if (!$abs || !is_file($abs)) return [];
    return [['nombre' => 'Acta de entrega ' . $e['numero'] . '.pdf', 'mime' => 'application/pdf', 'ruta' => $abs]];
}

/** Correo al cliente con el link del acta y el PDF adjunto. Lanza si falla. */
function entCorreoEnvio(PDO $db, array $e, string $to, string $mensaje = ''): void {
    $c = entCorreoArmar($db, $e, $mensaje);
    $cfg = cotConfig($db);
    correoEnviar($db, [
        'to' => $to, 'to_nombre' => $c['contacto'],
        'asunto' => $c['asunto'],
        'html' => $c['html'],
        'reply_to' => trim((string)($cfg['email'] ?? '')),
        'adjuntos' => entAdjuntoActa($e),
    ]);
}

/** Arma el correo del acta (asunto + HTML) sin enviarlo: lo usan el envío y la vista previa. */
function entCorreoArmar(PDO $db, array $e, string $mensaje = ''): array {
    $h = 'cotH';
    $link = entLink($e['token'], $db);
    $dom = $e['dominio_efectivo'] ?: 'tu sitio';
    $contacto = trim((string)($e['cli_nombre'] ?? '')) ?: $e['cliente'];
    $saludo = 'Hola ' . $h(cotSaludoNombre($contacto) ?: $e['cliente']) . ':';
    $plazo = (int)$e['plazo_dias_habiles'];
    $hasta = cotFecha(entSumarHabiles(cotAhora(), $plazo, $db)->format('Y-m-d'));
    // "Trabajo acotado" (rediseño de páginas, un complemento, un arreglo): otro mensaje.
    $acotado = !empty($e['lista_no_aplica']);

    if ($acotado) {
        $titulo = 'Trabajo terminado en ' . $dom;
        $asunto = 'Entrega del trabajo en ' . $dom;
        $p = [$saludo . ' terminé el trabajo en tu sitio.',
              'En el enlace tienes el acta de entrega con el detalle de lo realizado y la garantía. Te adjunto también el acta en PDF.'];
        $pie = 'Si el botón no funciona, copia este enlace: ' . $h($link);
    } else {
        $titulo = 'Tu sitio ' . $dom . ' ya está publicado';
        $asunto = 'Entrega de tu sitio ' . $dom;
        $p = [$saludo . ' tu sitio ya está publicado.',
              'En el enlace tienes el acta de entrega con lo entregado, los accesos y la garantía. Te adjunto también el acta en PDF.'];
        $pie = 'Las contraseñas nunca van en este correo ni en el acta: te las envío por separado. Si el botón no funciona, copia este enlace: ' . $h($link);
    }
    if (trim($mensaje) !== '') $p[] = nl2br($h(trim($mensaje)));
    $p[] = 'No necesitas firmar nada. Si algo no corresponde a lo acordado, avísame dentro de <strong>' . $plazo . ' días hábiles</strong>; si no hay observaciones, la entrega se da por recibida y comienza la garantía.';
    $datos = [['Acta', $h($e['numero'])], ['Sitio', $h($dom)]];
    if (!empty($e['cot_numero'])) $datos[] = ['Cotización', $h($e['cot_numero'])];
    $datos[] = ['Observaciones hasta', $h($hasta)];

    $html = correoPlantilla($titulo, $p, ['texto' => 'Ver acta de entrega', 'url' => $link], $datos, $pie,
        ['etiqueta' => 'Acta de entrega · ' . $e['numero']]);
    return ['asunto' => $asunto, 'html' => $html, 'contacto' => $contacto];
}

/** Aviso a Inger (no lanza; si falla queda en la campana). */
function entAvisoInger(PDO $db, array $e, string $asunto, string $titulo, array $parrafos, array $datos = []): void {
    if (!correoConfigurado($db)) return;
    $to = correoConfig($db)['avisos'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    $base = entUrlSistema($db);
    $html = correoPlantilla($titulo, $parrafos, $base ? ['texto' => 'Abrir el sistema', 'url' => $base . '/'] : null, $datos,
        'Página del cliente: ' . cotH(entLink($e['token'], $db)));
    $er = correoIntentar($db, ['to' => $to, 'asunto' => $asunto, 'html' => $html], 'entrega.aviso');
    if ($er !== null) entNotificar($db, 'warning', "No se pudo enviar el aviso de la entrega {$e['numero']}: {$er}");
}

// ─── Plazo vencido → recibida por plazo ───────────────────────────────

/**
 * Marca como "recibida_por_plazo" las entregas enviadas cuyo plazo venció
 * (hoy > plazo_vence) sin observaciones. Idempotente: el UPDATE va condicionado
 * por estado. $hoy permite forzar la fecha (pruebas: --hoy=AAAA-MM-DD).
 * Devuelve los números de las entregas marcadas.
 */
function entProcesarPlazos(PDO $db, ?string $hoy = null): array {
    $hoy = $hoy ?: cotAhora()->format('Y-m-d');
    try {
        $st = $db->prepare("SELECT id FROM entregas WHERE estado = 'enviada' AND plazo_vence IS NOT NULL AND plazo_vence < ?");
        $st->execute([$hoy]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];   // sin tabla (migración pendiente)
    }
    $marcadas = [];
    foreach ($ids as $id) {
        $e = entCargar($db, (int)$id);
        if (!$e) continue;
        // La garantía corre desde el día siguiente al último día hábil del plazo.
        $desde = (new DateTimeImmutable($e['plazo_vence']))->modify('+1 day')->format('Y-m-d');
        $up = $db->prepare("UPDATE entregas SET estado = 'recibida_por_plazo', recibida_at = ? WHERE id = ? AND estado = 'enviada'");
        $up->execute(["$desde 00:00:00", (int)$id]);
        if ($up->rowCount() !== 1) continue;
        entTerminarProyecto($db, (int)$id);
        $marcadas[] = $e['numero'];
        $f = cotFecha($desde);
        entNotificar($db, 'success', "La entrega {$e['numero']} ({$e['cliente']}) quedó recibida por plazo; la garantía corre desde el $f");
        entAvisoInger($db, $e, "La entrega {$e['numero']} quedó recibida por plazo",
            "Entrega {$e['numero']} recibida por plazo",
            ['La entrega <strong>' . cotH($e['numero']) . '</strong> de ' . cotH($e['cliente'])
             . ' quedó recibida por plazo; la garantía corre desde el <strong>' . cotH($f) . '</strong>.'],
            [['Cliente', cotH($e['cliente'])], ['Sitio', cotH($e['dominio_efectivo'] ?: '—')],
             ['Enviada', cotH(cotFecha(substr((string)$e['enviada_at'], 0, 10)))], ['Plazo vencía', cotH(cotFecha($e['plazo_vence']))],
             ['Abrió el acta', $e['vista_at'] ? 'Sí, el ' . cotH(cotFecha(substr((string)$e['vista_at'], 0, 10))) : 'No']]);
    }
    return $marcadas;
}
