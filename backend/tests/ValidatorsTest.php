<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests para los validadores y helpers de config.php.
 * Cobertura crítica para evitar bypass de validación server-side.
 */
final class ValidatorsTest extends TestCase
{
    // ─── escapeLike (anti wildcard injection en búsquedas) ───────
    public function testEscapeLikeEscapesWildcards(): void
    {
        $this->assertSame('hola\%mundo', escapeLike('hola%mundo'));
        $this->assertSame('foo\_bar',    escapeLike('foo_bar'));
        $this->assertSame('a\\\\b',      escapeLike('a\\b'));
    }

    public function testEscapeLikeKeepsNormalText(): void
    {
        $this->assertSame('Juan Pérez', escapeLike('Juan Pérez'));
        $this->assertSame('',           escapeLike(''));
    }

    // ─── validateRut (con y sin puntos/guiones) ──────────────────
    public function testValidateRutToleratesFormatting(): void
    {
        // El frontend envía con puntos (12.345.678-5). El backend debe aceptarlo.
        $this->assertTrue(validateRut('12.345.678-5'));
        $this->assertTrue(validateRut('12345678-5'));
        $this->assertTrue(validateRut('123456785'));
    }

    public function testValidateRutAcceptsDvK(): void
    {
        // Busca un RUT cuyo DV calculado sea K
        $body = null;
        for ($n = 1000000; $n < 5000000; $n++) {
            if (self::calcDV((string)$n) === 'K') { $body = $n; break; }
        }
        $this->assertNotNull($body);
        $this->assertTrue(validateRut($body . '-K'));
        $this->assertTrue(validateRut($body . '-k')); // tolera minúscula
    }

    public function testValidateRutRejectsInvalid(): void
    {
        $this->assertFalse(validateRut(''));
        $this->assertFalse(validateRut('1'));         // muy corto
        $this->assertFalse(validateRut('abc-K'));     // letras en cuerpo
        $this->assertFalse(validateRut('12345678-0')); // DV incorrecto
    }

    // ─── validateInt / validateFloat ─────────────────────────────
    public function testValidateIntRespectsRange(): void
    {
        $this->assertSame(5,    validateInt(5, 1, 10));
        $this->assertNull(validateInt(15, 1, 10)); // fuera de rango
        $this->assertNull(validateInt('abc'));     // no numérico
        $this->assertSame(7,    validateInt('7'));    // string numérico OK
    }

    public function testValidateStringRespectsLength(): void
    {
        $this->assertTrue(validateString('ok', 1, 10));
        $this->assertFalse(validateString('', 1, 10));               // muy corto
        $this->assertFalse(validateString('aaaaaaaaaaa', 1, 10));    // muy largo
    }

    // ─── sanitizeHtml (permite solo tags básicos) ────────────────
    public function testSanitizeHtmlStripsDangerousTags(): void
    {
        $input = '<p>Hola <script>alert(1)</script><b>mundo</b></p>';
        $out = sanitizeHtml($input);
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('<p>', $out);
        $this->assertStringContainsString('<b>', $out);
    }

    // Helper local: calcula DV módulo 11 chileno
    private static function calcDV(string $body): string {
        $suma = 0; $mul = 2;
        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $suma += (int)$body[$i] * $mul;
            $mul = $mul === 7 ? 2 : $mul + 1;
        }
        $r = 11 - ($suma % 11);
        if ($r === 11) return '0';
        if ($r === 10) return 'K';
        return (string)$r;
    }
}
