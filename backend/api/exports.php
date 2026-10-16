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
    
    $hh = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES|ENT_HTML5, 'UTF-8'); };
    $limpiar = function($txt) { return trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string)$txt)); };

    try {
        // 1. CARGAR CONFIGURACIÓN
        $rows = $db->query("SELECT clave, valor FROM configuracion")->fetchAll();
        $c = []; foreach ($rows as $r) $c[$r['clave']] = $r['valor'];

        // Saneamos colores a hex válido para evitar CSS injection
        $hex = function($v, $def) {
            return preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$v) ? $v : $def;
        };
        $cp   = $hex($c['color_primario']   ?? null, '#2d6a4f');
        $cs   = $hex($c['color_secundario'] ?? null, '#1a1a2e');
        $term = trim($c['terminos_condiciones'] ?? '');
        $cn   = $c['nombre_empresa']      ?? 'INGER GARRIDO C.';
        $cp2  = $c['nombre_propietario'] ?? 'Freelance Web Developer';
        $crut = $c['rut']                ?? '';
        $cem  = $c['email']              ?? '';
        $ctel = $c['telefono']           ?? '';
        $logo = $c['logo_cotizaciones_url'] ?? '';

        // 2. DATOS DE LA COTIZACIÓN (incluyendo plantilla_id)
        $s = $db->prepare("SELECT c.*, cl.nombre AS cn, cl.nombre_agencia AS ca, cl.email AS clem, p.nombre AS pn 
                           FROM cotizaciones c 
                           LEFT JOIN clientes cl ON cl.id = c.cliente_id 
                           LEFT JOIN proyectos p ON p.id = c.proyecto_id 
                           WHERE c.id = ?");
        $s->execute([$eid]);
        $q = $s->fetch(PDO::FETCH_ASSOC);

        if (!$q) die("Cotización no encontrada.");

        // Cargar términos: de plantilla si existe, o de configuración base
        $plantilla_id = $q['plantilla_id'] ?? null;
        
        if ($plantilla_id) {
            // Usar términos de la plantilla seleccionada
            $pt = $db->prepare("SELECT terminos_obligaciones, terminos_desarrollo, terminos_condiciones 
                               FROM plantillas_terminos WHERE id = ? AND activa = 1");
            $pt->execute([$plantilla_id]);
            $plantilla = $pt->fetch(PDO::FETCH_ASSOC);
            
            if ($plantilla) {
                $term_obligaciones = trim($plantilla['terminos_obligaciones'] ?? '');
                $term_desarrollo   = trim($plantilla['terminos_desarrollo'] ?? '');
                $term_condiciones  = trim($plantilla['terminos_condiciones'] ?? '');
            } else {
                // Plantilla no encontrada o inactiva, usar base
                $term_obligaciones = trim($c['terminos_obligaciones'] ?? '');
                $term_desarrollo   = trim($c['terminos_desarrollo'] ?? '');
                $term_condiciones  = trim($c['terminos_condiciones'] ?? '');
            }
        } else {
            // Sin plantilla, usar términos base de configuración
            $term_obligaciones = trim($c['terminos_obligaciones'] ?? '');
            $term_desarrollo   = trim($c['terminos_desarrollo'] ?? '');
            $term_condiciones  = trim($c['terminos_condiciones'] ?? '');
        }

        // Función para verificar si el contenido tiene texto real (no solo HTML vacío de Quill)
        function tieneContenidoReal($html) {
            if (empty($html)) return false;
            // Eliminar tags HTML
            $texto = strip_tags($html);
            // Eliminar espacios, tabs, saltos de línea, nbsp, zero-width chars
            $texto = preg_replace('/\s|&nbsp;|\x{200B}|\x{200C}|\x{200D}/u', '', $texto);
            return strlen($texto) > 0;
        }

        $term_obligaciones = tieneContenidoReal($term_obligaciones) ? $term_obligaciones : '';
        $term_desarrollo   = tieneContenidoReal($term_desarrollo) ? $term_desarrollo : '';
        $term_condiciones  = tieneContenidoReal($term_condiciones) ? $term_condiciones : '';

        // Construir URL completa del logo para el PDF
        $logoUrl = '';
        if ($logo) {
            // Detectar si estamos en local por el host (sin puerto)
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $hostname = parse_url('http://' . $host, PHP_URL_HOST) ?: $host;
            $isLocal = ($hostname === 'localhost' || $hostname === '127.0.0.1' || strpos($hostname, '192.168.') === 0 || strpos($hostname, '10.') === 0);
            if ($isLocal) {
                // En local: usar URL completa del backend MAMP
                $logoUrl = 'http://localhost:8888/sistema-local' . $logo;
            } else {
                // En producción: usar protocolo y host actual
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $logoUrl = $protocol . '://' . $host . $logo;
            }
        }

        // 3. EXTRAER DATOS DE LA COTIZACIÓN
        $num   = $q['numero'];
        $fecha = date('d/m/Y', strtotime($q['fecha']));
        $fv    = $q['fecha_validez'] ? date('d/m/Y', strtotime($q['fecha_validez'])) : '—';
        $notas = $limpiar($q['notas'] ?? '');
        $desc_global = (float)($q['descuento_global'] ?? 0);
        // Tipo del descuento: si el usuario lo ingresó como monto fijo,
        // mostramos en el PDF "Descuento: -$X" en vez de "Descuento (N%): -$X".
        $desc_tipo = (($q['descuento_global_tipo'] ?? 'porcentaje') === 'fijo') ? 'fijo' : 'porcentaje';

    } catch (Exception $e) { die("Error: " . $e->getMessage()); }

    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo $num; ?></title>
    <!-- CSS embebido: evita el parpadeo de texto sin formato (FOUC) que
         se producía mientras cargaba la hoja de estilos externa. -->
    <style>
        :root {
            --color-primario: <?php echo $cp; ?>;
            --color-secundario: <?php echo $cs; ?>;
        }
<?php
        $cssFile = __DIR__ . '/../assets/css/pdf-cotizacion.css';
        echo is_file($cssFile) ? file_get_contents($cssFile) : '';
?>
    </style>
</head>
<body>
    <div class="no-print toolbar-actions">
        <button onclick="window.print()" class="btn-print">Imprimir / Guardar PDF</button>
        <button onclick="window.close()" class="btn-close">Cerrar</button>
    </div>

    <div class="page">
        <div class="bar"></div>
        <div class="hd">
            <div>
                <?php if ($logoUrl): ?>
                    <img src="<?php echo $hh($logoUrl); ?>" alt="Logo" class="company-logo" />
                <?php endif; ?>
                <div class="company-name"><?php echo $hh($cn); ?></div>
                <div class="company-info">
                    <?php echo $hh($cp2); ?><br>
                    RUT: <?php echo $hh($crut); ?><br>
                    <?php echo $hh($cem); ?> | <?php echo $hh($ctel); ?>
                </div>
            </div>
            <div class="rh">
                <div class="ml">Cotización</div>
                <div class="qn"><?php echo $hh($num); ?></div>
                <div class="company-info fechas-cotizacion">
                    Emisión: <strong><?php echo $hh($fecha); ?></strong><br>
                    Validez: <strong><?php echo $hh($fv); ?></strong>
                </div>
            </div>
        </div>

        <div class="meta">
            <div class="mb">
                <div class="ml">Cliente</div>
                <strong><?php echo $hh($q['ca'] ?: $q['cn']); ?></strong><br>
                <span class="text-muted"><?php echo $hh($q['clem']); ?></span>
            </div>
            <div class="mb">
                <div class="ml">Proyecto</div>
                <strong><?php echo $hh($q['pn'] ?: 'General'); ?></strong>
            </div>
        </div>

        <div class="body">
            <table>
                <thead>
                    <tr>
                        <th class="col-descripcion">Descripción</th>
                        <th class="col-cantidad">Cant.</th>
                        <th class="col-precio">Unitario</th>
                        <th class="col-total">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $si = $db->prepare("SELECT * FROM cotizacion_items WHERE cotizacion_id = ? ORDER BY orden");
                    $si->execute([$eid]);
                    $subtotal_acumulado = 0;
                    while($it = $si->fetch(PDO::FETCH_ASSOC)): 
                        $linea_total = (float)$it['cantidad'] * (float)$it['precio_unitario'];
                        $subtotal_acumulado += $linea_total;
                    ?>
                    <tr>
                        <td><?php echo nl2br($hh($it['descripcion'])); ?></td>
                        <td><?php echo (float)$it['cantidad']; ?></td>
                        <td class="text-right"><?php echo clpF($it['precio_unitario']); ?></td>
                        <td class="text-right font-bold"><?php echo clpF($linea_total); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>

            <div class="tw">
                <?php if($desc_global > 0): ?>
                    <?php
                        // Monto del descuento en pesos a partir del % guardado.
                        $monto_descuento = ($subtotal_acumulado * $desc_global) / 100;
                        // Si el usuario ingresó un monto fijo, mostramos solo "Descuento: -$X".
                        // Si era %, mostramos "Descuento (N,NN%): -$X" con hasta 4 decimales sin ceros sobrantes.
                        if ($desc_tipo === 'fijo') {
                            $etiquetaDcto = 'Descuento';
                        } else {
                            $pctFmt = rtrim(rtrim(number_format($desc_global, 4, ',', ''), '0'), ',');
                            $etiquetaDcto = 'Descuento (' . $pctFmt . '%)';
                        }
                    ?>
                    <div class="subtotal">Subtotal: <?php echo clpF($subtotal_acumulado); ?></div>
                    <div class="descuento-info">
                        <?php echo $hh($etiquetaDcto); ?>: -<?php echo clpF($monto_descuento); ?>
                    </div>
                <?php endif; ?>
                
                <div class="tb">
                    <div class="tb-label">Total Cotización</div>
                    <div class="tv">
                        <?php 
                            // 2. Restamos el monto calculado al subtotal
                            $total_final = $subtotal_acumulado - (($subtotal_acumulado * $desc_global) / 100);
                            echo clpF($total_final); 
                        ?>
                    </div>
                </div>
            </div>

            <?php if ($notas): ?>
                <div class="notes-box">
                    <strong>Notas del Proyecto:</strong>
                    <?php echo nl2br($hh($notas)); ?>
                </div>
            <?php endif; ?>

            <!-- Términos y Condiciones -->
            <?php if ($term_obligaciones || $term_desarrollo || $term_condiciones): ?>
                <div class="terms-section">
                    <?php if ($term_obligaciones): ?>
                        <div class="terms-title">Obligaciones del Cliente</div>
                        <div class="terms-content"><?php echo $term_obligaciones; ?></div>
                    <?php endif; ?>

                    <?php if ($term_desarrollo): ?>
                        <div class="terms-title terms-title-spaced">Desarrollo</div>
                        <div class="terms-content"><?php echo $term_desarrollo; ?></div>
                    <?php endif; ?>

                    <?php if ($term_condiciones): ?>
                        <div class="terms-title terms-title-spaced">Términos y Condiciones</div>
                        <div class="terms-content"><?php echo $term_condiciones; ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Sección de Firma -->
            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-item">
                        <div class="signature-line">Firma Cliente</div>
                    </div>
                    <div class="signature-item">
                        <div class="signature-line">Firma <?php echo $hh($cn); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pie de página -->
        <div class="footer">
            <span>Cotización <?php echo $hh($num); ?></span>
            <span>Fecha: <?php echo $hh($fecha); ?></span>
            <span class="page-number">Página <span class="page-current"></span> / <span class="page-total"></span></span>
        </div>
    </div>

    <script>
        // Configurar título del documento
        document.title = 'Cotización <?php echo $hh($num); ?>';
    </script>
</body>
</html>
<?php exit; }

// Si llegamos aquí, el tipo no era válido
die('Tipo de exportación no reconocido.');