<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/cotizacion_doc.php';

/**
 * Helpers del documento de cotización / aprobación en línea.
 */
final class CotizacionDocTest extends TestCase
{
    public static function montos(): array
    {
        return [
            [1950000, 'Un millón novecientos cincuenta mil pesos'],
            [2071000, 'Dos millones setenta y un mil pesos'],
            [1000000, 'Un millón de pesos'],
            [3000000, 'Tres millones de pesos'],
            [21000, 'Veintiún mil pesos'],
            [100000, 'Cien mil pesos'],
            [121000, 'Ciento veintiún mil pesos'],
            [1001, 'Mil un pesos'],
            [21, 'Veintiún pesos'],
            [1, 'Un peso'],
            [0, 'Cero pesos'],
            [555555, 'Quinientos cincuenta y cinco mil quinientos cincuenta y cinco pesos'],
        ];
    }

    /** @dataProvider montos */
    public function testMontoEnPalabras(int $n, string $esperado): void
    {
        $this->assertSame($esperado, cotMontoPalabras($n));
    }

    public function testRut(): void
    {
        $this->assertSame('12345678-5', cotRutNormalizar('12.345.678-5'));
        $this->assertSame('12.345.678-5', cotRutFormato('12345678-5'));
        $this->assertSame('9.876.543-K', cotRutFormato('9876543k'));
    }

    public function testCodigoVerificacion(): void
    {
        $this->assertSame('7F3A-92C1', cotCodigoVerificacion('7f3a92c1' . str_repeat('0', 56)));
    }

    public function testTokenAleatorio(): void
    {
        $t = cotNuevoToken();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $t);
        $this->assertNotSame($t, cotNuevoToken());
    }

    public function testRtLimpioSoloEtiquetasPermitidas(): void
    {
        $html = '<p class="ql-align-justify" onclick="x()">Hola <b>mundo</b> <a href="javascript:1">link</a><script>alert(1)</script></p>';
        $this->assertSame('<p>Hola <strong>mundo</strong> linkalert(1)</p>', cotRtLimpio($html));
    }

    public function testRtLimpioConvierteVinetasManualesEnLista(): void
    {
        $html = "<p>•\tUno</p><p>•\tDos</p><p>Texto</p>";
        $this->assertSame('<ul><li>Uno</li><li>Dos</li></ul><p>Texto</p>', cotRtLimpio($html));
    }

    public function testRtLimpioVacio(): void
    {
        $this->assertSame('', cotRtLimpio('<p><br></p>'));
        $this->assertSame('', cotRtLimpio(null));
    }
}
