<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/integracion.php';

/**
 * Integración con Claude Code (lib/integracion.php): formato de las llaves,
 * números aceptados y cuándo una cotización queda bloqueada para la subida.
 */
final class IntegracionTest extends TestCase
{
    public function testLlaveTienePrefijoY40Caracteres(): void
    {
        $vistas = [];
        for ($i = 0; $i < 50; $i++) {
            $k = igNuevaLlave();
            $this->assertMatchesRegularExpression('/^igs_[A-Za-z0-9]{40}$/', $k);
            $vistas[$k] = true;
        }
        $this->assertCount(50, $vistas, 'Las llaves no se repiten');
    }

    public function testFormatosDeNumero(): void
    {
        $this->assertMatchesRegularExpression(IG_RE_COT, 'COT-2026-006');
        $this->assertMatchesRegularExpression(IG_RE_COT, 'COT-2026-001-v2');
        $this->assertMatchesRegularExpression(IG_RE_PRO, 'PRO-2026-001-v2');
        $this->assertMatchesRegularExpression(IG_RE_ENT, 'ENT-2026-007');
        $this->assertDoesNotMatchRegularExpression(IG_RE_COT, 'COT-2026-6');
        $this->assertMatchesRegularExpression(IG_RE_ENT, 'ENT-2026-007-2');
        $this->assertDoesNotMatchRegularExpression(IG_RE_ENT, 'ENT-2026-007-v2');
        $this->assertDoesNotMatchRegularExpression(IG_RE_PRO, "PRO-2026-006\n");
    }

    public function testCotizacionBloqueada(): void
    {
        $base = ['aprobada_at' => null, 'estado' => 'Pendiente'];
        $this->assertFalse(igCotBloqueada($base));
        $this->assertFalse(igCotBloqueada(['estado' => 'Enviada'] + $base));
        $this->assertFalse(igCotBloqueada(['estado' => 'Rechazada'] + $base));
        $this->assertTrue(igCotBloqueada(['aprobada_at' => '2026-11-20 10:00:00'] + $base));
        $this->assertTrue(igCotBloqueada(['estado' => 'Aceptada'] + $base));
        $this->assertTrue(igCotBloqueada(['estado' => 'Reemplazada'] + $base));
    }
}
