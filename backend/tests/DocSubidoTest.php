<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/doc_subido.php';
require_once __DIR__ . '/../lib/cotizacion_doc.php';

/**
 * Documentos subidos (propuestas / actas): limpieza del HTML y hash firmado.
 */
final class DocSubidoTest extends TestCase
{
    private function limpio(string $body, string $head = ''): string
    {
        return dsLimpiarHtml("<!DOCTYPE html><html><head><meta charset=\"utf-8\">$head</head><body>$body</body></html>");
    }

    public function testQuitaScriptsYElementosActivos(): void
    {
        $o = $this->limpio('<script>alert(1)</script><iframe src="https://x"></iframe><object data="x"></object><embed src="x">'
            . '<form action="https://x"><input name="a"><button>b</button></form><svg><script>alert(2)</script></svg><p>ok</p>',
            '<base href="https://evil/"><meta http-equiv="refresh" content="0;url=https://evil">');
        foreach (['<script', '<iframe', '<object', '<embed', '<form', '<input', '<button', '<base', 'http-equiv', 'alert('] as $x) {
            $this->assertStringNotContainsStringIgnoringCase($x, $o, "Quedó $x");
        }
        $this->assertStringContainsString('<p>ok</p>', $o);
    }

    public function testQuitaAtributosOnYUrlsJavascript(): void
    {
        $o = $this->limpio('<div onclick="x()" onmouseover="y()">a</div><img src="x.png" onerror="z()">'
            . '<a href="javascript:alert(1)">j</a><a href=" JaVa&#x09;script:alert(1)">k</a><img src="data:image/svg+xml,&lt;svg onload=alert(1)&gt;">'
            . '<p style="background:url(javascript:alert(1))">p</p>');
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', $o);
        $this->assertStringNotContainsStringIgnoringCase('javascript', $o);
        $this->assertStringNotContainsString('data:image/svg', $o);
    }

    public function testConservaLinksHttpsEnPestanaNueva(): void
    {
        $o = $this->limpio('<a href="https://drive.google.com/x">Video</a><a href="http://inseguro.cl">http</a><a href="mailto:a@b.cl">mail</a>');
        $this->assertStringContainsString('<a href="https://drive.google.com/x" target="_blank" rel="noopener noreferrer">Video</a>', $o);
        // Solo https: los demás quedan como texto.
        $this->assertStringContainsString('<a>http</a>', $o);
        $this->assertStringContainsString('<a>mail</a>', $o);
    }

    public function testConservaImagenesBase64YGoogleFonts(): void
    {
        $img = 'data:image/png;base64,' . base64_encode(random_bytes(2000));
        $o = $this->limpio('<img src="' . $img . '" alt="logo">',
            '<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Geist" rel="stylesheet"><link rel="stylesheet" href="https://evil.com/a.css">');
        $this->assertStringContainsString($img, $o);
        $this->assertStringContainsString('fonts.googleapis.com/css2?family=Geist', $o);
        $this->assertStringNotContainsString('evil.com', $o);
    }

    public function testSiempreDoctypeHtml5YUtf8(): void
    {
        $o = dsLimpiarHtml('<html><body><p>Máquina · ñandú</p></body></html>');
        $this->assertStringStartsWith("<!DOCTYPE html>\n", $o);
        $this->assertStringNotContainsString('HTML 4.0', $o);
        $this->assertStringNotContainsString('xml encoding', $o);
        $this->assertStringContainsString('Máquina · ñandú', $o);
        $this->assertStringContainsString('data-sistema="iframe"', $o);
    }

    public function testCuentaHojas(): void
    {
        $this->assertSame(3, dsContarHojas('<div class="sheet cover"></div><div class="sheet"></div><section class="x sheet"></section><div class="sheets"></div>'));
        $this->assertSame(0, dsContarHojas('<div class="hoja"></div>'));
    }

    public function testPlantillasRealesSiExisten(): void
    {
        $dir = getenv('HOME') . '/Documents/Proyectos/web-inger-nueva/propuestas/documentos';
        if (!is_file("$dir/propuesta-plantilla.html")) $this->markTestSkipped('Sin plantillas locales');
        $p = dsLimpiarHtml((string)file_get_contents("$dir/propuesta-plantilla.html"));
        $a = dsLimpiarHtml((string)file_get_contents("$dir/acta-entrega-plantilla.html"));
        $this->assertSame(5, dsContarHojas($p));
        $this->assertSame(2, dsContarHojas($a));
        $this->assertGreaterThan(0, substr_count($a, 'target="_blank"'));
    }

    public function testHashSinPropuestaNoCambia(): void
    {
        $d = [
            'q' => ['numero' => 'COT-2026-001', 'proy_nombre' => 'Web', 'fecha' => '2026-11-20', 'fecha_validez' => '2026-12-20',
                    'forma_pago' => '50% de pie', 'plazo_estimado' => '4 semanas', 'notas' => ''],
            'cliente' => 'ATM', 'items' => [['descripcion' => 'Sitio', 'cantidad' => 1, 'precio' => 1950000]],
            'pct' => 0.0, 'total' => 1950000.0, 'terminos' => [['A', '<p>x</p>']],
        ];
        $sin = cotContenidoFirmado($d);
        $this->assertStringNotContainsString('propuesta', $sin);
        // Formato exacto de antes de la Fase A (las aprobaciones ya guardadas siguen calzando).
        $this->assertSame('{"numero":"COT-2026-001","cliente":"ATM","proyecto":"Web","fecha":"2026-11-20","validez":"2026-12-20",'
            . '"items":[["Sitio",1,1950000]],"descuento_pct":0,"total":1950000,"forma_pago":"50% de pie","plazo":"4 semanas",'
            . '"notas":"","terminos":["<p>x</p>"]}', $sin);

        $d['q']['propuesta_numero'] = 'PRO-2026-003';
        $d['q']['propuesta_hash'] = str_repeat('a', 64);
        $con = cotContenidoFirmado($d);
        $this->assertStringContainsString('"propuesta_numero":"PRO-2026-003","propuesta_hash":"' . str_repeat('a', 64) . '"', $con);
        $this->assertNotSame(hash('sha256', $sin), hash('sha256', $con));
    }
}
