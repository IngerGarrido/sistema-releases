<?php
/**
 * Correos de cotizaciones:
 *   - cotCorreoEnvio():       "Enviar al cliente" (link /c/{token} para revisar y aprobar).
 *   - cotCorreosAprobacion(): al aprobar en línea → confirmación al cliente + aviso a Inger.
 *
 * El PDF de la cotización no se genera en el servidor (es HTML que se imprime
 * desde el navegador), así que el correo lleva el link al PDF en vez de adjuntarlo.
 */
declare(strict_types=1);

require_once __DIR__ . '/cotizacion_doc.php';
require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/doc_subido.php';

/** PDF de la propuesta como adjunto (o [] si no hay). */
function cotAdjuntoPropuesta(array $q): array {
    $info = cotPropuestaInfo($q);
    if (!$info || !$info['tiene_pdf']) return [];
    $abs = dsRutaAbs('propuestas', $q['propuesta_pdf_path'] ?? null);
    if (!$abs || !is_file($abs)) return [];
    return [['nombre' => 'Propuesta ' . ($info['numero'] ?: $q['numero']) . '.pdf', 'mime' => 'application/pdf', 'ruta' => $abs]];
}

/** Responde JSON al navegador y sigue ejecutando (para mandar correos sin hacer esperar al cliente). */
function cotResponderYSeguir(array $data, callable $despues): never {
    $json = json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ignore_user_abort(true);
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    while (ob_get_level()) @ob_end_clean();
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Encoding: none');
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;
    @flush();
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    try { $despues(); } catch (Throwable $e) {
        if (function_exists('logError')) logError('Tarea posterior a la respuesta falló', ['error' => $e->getMessage()]);
    }
    exit;
}

/** Aviso en la campana del sistema (no lanza). */
function cotNotificar(PDO $db, string $tipo, string $msg): void {
    try {
        $db->prepare("INSERT INTO notificaciones (usuario_id, tipo, mensaje, link) VALUES (NULL, ?, ?, 'cotizaciones')")
           ->execute([$tipo, mb_substr($msg, 0, 500)]);
    } catch (Throwable $e) { /* sin tabla o similar */ }
}

function cotEmpresa(array $cfg): string {
    return trim((string)($cfg['nombre_empresa'] ?? '')) ?: 'Inger Garrido';
}

/** Primer nombre para el saludo ("Hola Nicolás"). */
function cotSaludoNombre(string $nombre): string {
    $nombre = trim($nombre);
    return $nombre === '' ? '' : (string)preg_split('/\s+/u', $nombre)[0];
}

/**
 * Correo con el link de aprobación. $d = cotCargar(). Lanza RuntimeException si falla.
 * $mensaje: párrafo opcional escrito por Inger (texto plano).
 */
function cotCorreoEnvio(PDO $db, array $d, string $to, string $mensaje = ''): void {
    $q = $d['q'];
    $cfg = $d['cfg'];
    $e = 'cotH';
    $num = (string)$q['numero'];
    $link = cotLinkPublico((string)$q['token']);
    $fv = cotFecha($q['fecha_validez'] ?? null);
    $proy = trim((string)($q['proy_nombre'] ?? ''));
    $contacto = trim((string)($q['cli_nombre'] ?? '')) ?: $d['cliente'];

    $prop = cotPropuestaInfo($q);
    $pro = $prop ? ($prop['numero'] ?: '') : '';
    $p = [];
    $p[] = 'Hola ' . $e(cotSaludoNombre($contacto) ?: $d['cliente']) . ':';
    $p[] = 'Te envío ' . ($prop ? 'la propuesta' . ($pro !== '' ? ' N.º <strong>' . $e($pro) . '</strong>' : '') . ' y ' : '')
         . 'la cotización N.º <strong>' . $e($num) . '</strong>' . ($proy !== '' ? ' para <strong>' . $e($proy) . '</strong>' : '')
         . ' por <strong>' . $e(cotClp($d['total'])) . '</strong>.';
    if (trim($mensaje) !== '') $p[] = nl2br($e(trim($mensaje)));
    $p[] = $prop
        ? 'En el enlace puedes leer la propuesta, revisar la cotización con sus condiciones y, si estás de acuerdo, aprobarla en línea: no necesitas imprimir ni firmar nada. Te adjunto también la propuesta en PDF.'
        : 'En el enlace puedes revisar el detalle y las condiciones y, si estás de acuerdo, aprobarla en línea: no necesitas imprimir ni firmar nada.';

    $datos = [['Cotización', $e($num)]];
    if ($prop && $pro !== '') $datos[] = ['Propuesta', $e($pro)];
    if ($proy !== '') $datos[] = ['Proyecto', $e($proy)];
    $datos[] = ['Total', $e(cotClp($d['total']))];
    if ($fv) $datos[] = ['Válida hasta', $e($fv)];
    $html = correoPlantilla($prop ? 'Tu propuesta y cotización' : 'Tu cotización ' . $num, $p,
        ['texto' => $prop ? 'Ver propuesta y cotización' : 'Revisar y aprobar cotización', 'url' => $link], $datos,
        'Si el botón no funciona, copia este enlace: ' . $e($link),
        ['etiqueta' => ($prop ? 'Propuesta y cotización · ' : 'Cotización · ') . $num]);

    correoEnviar($db, [
        'to' => $to, 'to_nombre' => $contacto,
        'asunto' => ($prop ? 'Propuesta y cotización ' : 'Cotización ') . $num . ' · ' . cotEmpresa($cfg),
        'html' => $html,
        'reply_to' => trim((string)($cfg['email'] ?? '')),
        'adjuntos' => cotAdjuntoPropuesta($q),
    ]);
}

/**
 * Correos al aprobar. $ap = ['nombre','rut','ip','fecha' (DateTimeImmutable),'codigo'].
 * No lanza: si algo falla, deja aviso en la campana.
 */
function cotCorreosAprobacion(PDO $db, array $d, array $ap): void {
    if (!correoConfigurado($db)) return;
    $q = $d['q'];
    $cfg = $d['cfg'];
    $cfgC = correoConfig($db);
    $e = 'cotH';
    $num = (string)$q['numero'];
    $link = cotLinkPublico((string)$q['token']);
    $total = cotClp($d['total']);
    /** @var DateTimeImmutable $f */
    $f = $ap['fecha'];
    $fecha = $f->format('d/m/Y');
    $hora = $f->format('H:i');
    $prop = cotPropuestaInfo($q);
    $pro = $prop ? ($prop['numero'] ?: '') : '';

    // Siguiente paso según la forma de pago (igual que la página pública).
    $forma = trim((string)($q['forma_pago'] ?? ''));
    $siguiente = preg_match('/(\d+)\s*%\s*de\s*pie/iu', $forma, $m)
        ? 'El siguiente paso es el pago del ' . $m[1] . ' % de pie; te escribiré con los datos de transferencia.'
        : 'Te escribiré con los datos para comenzar.';

    // 1) Al cliente
    $toCli = trim((string)($q['cli_email'] ?? ''));
    if ($toCli !== '' && filter_var($toCli, FILTER_VALIDATE_EMAIL)) {
        $html = correoPlantilla('Cotización ' . $num . ' aprobada', [
            'Hola ' . $e(cotSaludoNombre($ap['nombre'])) . ', quedó registrada tu aprobación de '
            . ($prop ? 'la propuesta' . ($pro !== '' ? ' N.º <strong>' . $e($pro) . '</strong>' : '') . ' y ' : '')
            . 'la cotización N.º <strong>' . $e($num)
            . '</strong> por <strong>' . $e($total) . '</strong>, el ' . $e($fecha) . ' a las ' . $e($hora) . ' h.',
            'En este enlace tienes el PDF de la cotización con el registro de la aprobación (puedes guardarlo o imprimirlo cuando quieras).'
            . ($prop && $prop['tiene_pdf'] ? ' Te adjunto también la propuesta en PDF.' : ''),
            $e($siguiente),
        ], ['texto' => 'Ver PDF con el registro', 'url' => $link . '/pdf'], [
            ['Aprobada por', $e($ap['nombre'])],
            ['RUT', $e(cotRutFormato($ap['rut']))],
            ['Fecha', $e($fecha . ' · ' . $hora . ' h')],
            ['Código de verificación', $e($ap['codigo'])],
        ]);
        $er = correoIntentar($db, [
            'to' => $toCli, 'to_nombre' => $ap['nombre'],
            'asunto' => 'Cotización ' . $num . ' aprobada', 'html' => $html,
            'reply_to' => trim((string)($cfg['email'] ?? '')),
            'adjuntos' => cotAdjuntoPropuesta($q),
        ], 'cot.aprobada.cliente');
        if ($er !== null) cotNotificar($db, 'warning', "No se pudo enviar al cliente la confirmación de la {$num}: {$er}");
    }

    // 2) A Inger
    $toYo = $cfgC['avisos'];
    if ($toYo !== '' && filter_var($toYo, FILTER_VALIDATE_EMAIL)) {
        $datos = [
            ['Cliente', $e($d['cliente'])],
            ['Cotización', $e($num) . ' · ' . $e($total)],
            ['Aprobada por', $e($ap['nombre'])],
            ['RUT', $e(cotRutFormato($ap['rut']))],
            ['Fecha', $e($fecha . ' · ' . $hora . ' h')],
            ['Código', $e($ap['codigo'])],
        ];
        if ($prop) {
            $va = $prop['vista_at'] ? new DateTimeImmutable($prop['vista_at']) : null;
            $datos[] = ['Propuesta', $e($pro ?: 'Adjunta')];
            $datos[] = ['Abrió la propuesta', $va ? 'Sí, el ' . $e($va->format('d/m/Y · H:i')) . ' h' : 'No'];
        }
        $html = correoPlantilla($d['cliente'] . ' aprobó la ' . $num, [
            'La cotización quedó aprobada en línea' . ($toCli ? ' y se le envió la confirmación a ' . $e($toCli) : '') . '.',
        ], ['texto' => 'Abrir el sistema', 'url' => cotBaseUrl() . '/'], $datos,
            'Página del cliente: ' . $e($link));
        $er = correoIntentar($db, [
            'to' => $toYo, 'asunto' => '✓ ' . $d['cliente'] . ' aprobó la ' . $num, 'html' => $html,
        ], 'cot.aprobada.inger');
        if ($er !== null) cotNotificar($db, 'warning', "No se pudo enviar el aviso de aprobación de la {$num}: {$er}");
    }
}
