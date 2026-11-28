<?php
// exports.php — PDF cotización + Excel
error_reporting(0);
ini_set('display_errors', 0);

// CARGA LAS DEPENDENCIAS
require_once __DIR__ . '/../config.php';
cors();

// Autenticación obligatoria por header Authorization (el frontend descarga
// vía fetch+blob, ya no usa window.open con ?token=…).
$user = requireAuth();
rateLimit('exports.download', 30, 60); // máx 30 descargas/min por IP
$db = getDB();

// Audit del intento de export (antes de ejecutar)
try {
    audit($db, (int)$user['id'], 'export.request', 'exports',
        'type=' . ($_GET['type'] ?? '') . (isset($_GET['id']) ? ' id=' . (int)$_GET['id'] : ''));
} catch (Throwable $e) { /* no romper export por fallo de audit */ }

// Solo admin puede exportar
if ($user['rol'] !== 'admin') {
    http_response_code(403);
    die(json_encode(['ok' => false, 'error' => 'Acceso denegado']));
}

$type  = $_GET['type'] ?? '';
$eid   = isset($_GET['id']) ? (int)$_GET['id'] : null;
$today = date('Y-m-d');

// --- FUNCIONES DE LIMPIEZA Y SEGURIDAD ---

/** * Limpia caracteres de control ASCII invisibles que el servidor inyecta por error 
 */
function cleanSystemNoise($val): string {
    $val = (string)$val;
    // Si detectamos basura de CloudLinux o JSON de error, devolvemos vacío o texto limpio
    if (strpos($val, 'hitting_limits') !== false || strpos($val, '{"ok":false') !== false) return '';
    // Eliminar caracteres no imprimibles (ASCII 0-31)
    return preg_replace('/[\x00-\x1F\x7F]/', '', $val);
}

function hh(string $s): string { 
    return htmlspecialchars(cleanSystemNoise($s), ENT_QUOTES|ENT_HTML5, 'UTF-8'); 
}

function clpF($n) {
    // Extraer solo números y puntos para evitar el error "string given"
    $limpio = preg_replace('/[^0-9.]/', '', (string)$n);
    if (empty($limpio) || !is_numeric($limpio)) return "$ 0";
    return "$ " . number_format((float)$limpio, 0, ',', '.');
}

function cfg(PDO $db): array {
    $rows = $db->query("SELECT clave,valor FROM configuracion")->fetchAll();
    $c=[]; foreach($rows as $r) $c[$r['clave']]=$r['valor']; return $c;
}

// ── XLSX builder (puro PHP, sin Composer) ────────────────────
function colL(int $i): string { $l='';$i++;while($i>0){$i--;$l=chr(65+$i%26).$l;$i=intdiv($i,26);}return $l; }
function makeXlsx(array $heads, array $rows, string $sn='Datos'): string {
    $strs=[];$idx=[];
    $gs=function(string $v)use(&$strs,&$idx):int{ if(!isset($idx[$v])){$idx[$v]=count($strs);$strs[]=$v;} return $idx[$v]; };
    $sr='';$rn=1;
    $c=''; foreach($heads as $i=>$h){$col=colL($i);$si=$gs((string)$h);$c.="<c r=\"{$col}{$rn}\" t=\"s\"><v>{$si}</v></c>";}
    $sr.="<row r=\"{$rn}\">{$c}</row>";$rn++;
    foreach($rows as $row){ $c='';$v=array_values($row);
        foreach($v as $i=>$x){ $col=colL($i);
            if(is_numeric($x)&&$x!==''){$c.="<c r=\"{$col}{$rn}\"><v>".(float)$x."</v></c>";}
            else{$si=$gs((string)($x??''));$c.="<c r=\"{$col}{$rn}\" t=\"s\"><v>{$si}</v></c>";}
        } $sr.="<row r=\"{$rn}\">{$c}</row>";$rn++; }
    $ssti=''; foreach($strs as $s){$s=htmlspecialchars($s,ENT_XML1,'UTF-8');$ssti.="<si><t xml:space=\"preserve\">{$s}</t></si>";}
    $sc=count($strs);$snE=htmlspecialchars($sn,ENT_XML1,'UTF-8');
    $tmp=tempnam(sys_get_temp_dir(),'xlsx_');
    $zip=new ZipArchive();$zip->open($tmp,ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
    $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml',"<?xml version=\"1.0\" encoding=\"UTF-8\"?><workbook xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><sheets><sheet name=\"{$snE}\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>");
    $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml',"<?xml version=\"1.0\" encoding=\"UTF-8\"?><worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\"><sheetData>{$sr}</sheetData></worksheet>");
    $zip->addFromString('xl/sharedStrings.xml',"<?xml version=\"1.0\" encoding=\"UTF-8\"?><sst xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" count=\"{$sc}\" uniqueCount=\"{$sc}\">{$ssti}</sst>");
    $zip->close(); return $tmp;
}
function sendXlsx(string $tmp, string $fn): never {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"{$fn}\"");
    header('Content-Length: '.filesize($tmp)); header('Cache-Control: max-age=0');
    readfile($tmp); unlink($tmp); exit;
}

// ── EXCEL EXPORTS ─────────────────────────────────────────────
if ($type === 'excel_clientes') {
    $r=$db->query("SELECT tipo,COALESCE(nombre_agencia,'—'),nombre,COALESCE(url,'—'),COALESCE(rut,'—'),COALESCE(email,'—'),COALESCE(telefono,'—'),COALESCE(notas,'') FROM clientes ORDER BY nombre")->fetchAll();
    sendXlsx(makeXlsx(['Tipo','Agencia','Nombre/Web','URL','RUT','Email','Teléfono','Notas'],$r,'Clientes'),"clientes_{$today}.xlsx");
}
if ($type === 'excel_proyectos') {
    $r=$db->query("SELECT p.nombre,c.nombre,COALESCE(c.nombre_agencia,'—'),COALESCE(p.tipo_proyecto,'—'),p.estado,p.presupuesto,COALESCE(p.fecha_inicio,'—'),COALESCE(p.fecha_termino,'—') FROM proyectos p LEFT JOIN clientes c ON c.id=p.cliente_id ORDER BY p.id DESC")->fetchAll();
    sendXlsx(makeXlsx(['Proyecto','Cliente','Agencia','Tipo','Estado','Estimado','Inicio','Término'],$r,'Proyectos'),"proyectos_{$today}.xlsx");
}
if ($type === 'excel_cotizaciones') {
    $r=$db->query("SELECT c.numero,cl.nombre,COALESCE(cl.nombre_agencia,'—'),COALESCE(p.nombre,'—'),c.fecha,COALESCE(c.fecha_validez,'—'),c.estado,COALESCE((SELECT SUM(ci.cantidad*ci.precio_unitario) FROM cotizacion_items ci WHERE ci.cotizacion_id=c.id),0) FROM cotizaciones c LEFT JOIN clientes cl ON cl.id=c.cliente_id LEFT JOIN proyectos p ON p.id=c.proyecto_id ORDER BY c.id DESC")->fetchAll();
    sendXlsx(makeXlsx(['Número','Cliente','Agencia','Proyecto','Fecha','Válida hasta','Estado','Total CLP'],$r,'Cotizaciones'),"cotizaciones_{$today}.xlsx");
}
if ($type === 'excel_pagos') {
    $r=$db->query("SELECT p.nombre,c.nombre,COALESCE(c.nombre_agencia,'—'),COALESCE(pg.descripcion,''),pc.numero_cuota,COALESCE(pc.boleta_num,'—'),COALESCE(pc.boleta_fecha,'—'),pc.boleta_monto,COALESCE(pc.pago_fecha,'—'),pc.pago_monto,pc.estado FROM pago_cuotas pc JOIN pagos pg ON pg.id=pc.pago_id LEFT JOIN proyectos p ON p.id=pg.proyecto_id LEFT JOIN clientes c ON c.id=pg.cliente_id ORDER BY pg.id,pc.numero_cuota")->fetchAll();
    sendXlsx(makeXlsx(['Proyecto','Cliente','Agencia','Descripción','Cuota','N° Boleta','Fecha Boleta','Monto Boleta','Fecha Pago','Monto Pagado','Estado'],$r,'Pagos'),"pagos_{$today}.xlsx");
}

// ── PDF COTIZACIÓN (RECONSTRUCCIÓN TOTAL DE STRINGS) ──────────
if ($type === 'pdf_cotizacion' && $eid) {
    while (ob_get_level()) { ob_end_clean(); }
    require_once __DIR__ . '/../lib/cotizacion_doc.php';
    $d = cotCargar($db, $eid);
    if (!$d) { http_response_code(404); die('Cotización no encontrada.'); }
    // Aprobada en línea → con sello. Si no, versión para imprimir y firmar.
    $modo = !empty($d['q']['aprobada_at']) ? 'aprobada' : 'firma';
    header('Content-Type: text/html; charset=utf-8');
    header_remove('Content-Security-Policy');
    $num = cotH($d['q']['numero']);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">' . COT_VIEWPORT_DOC
       . '<title>Cotización ' . $num . '</title>' . cotCssDoc() . '</head><body class="solo">'
       . '<div class="tools"><button type="button" onclick="window.print()">Imprimir / Guardar PDF</button>'
       . '<button type="button" class="s" onclick="window.close()">Cerrar</button></div>'
       . '<div class="hojas">' . cotHojasHtml($d, $modo) . '</div>'
       . cotPaginadorJs() . '</body></html>';
    exit;
}

// Si llegamos aquí, el tipo no era válido
die('Tipo de exportación no reconocido.');