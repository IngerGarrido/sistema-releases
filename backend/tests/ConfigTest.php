<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testValidateEmailAcceptsValidAddress(): void
    {
        $this->assertTrue(validateEmail('foo@bar.com'));
    }

    public function testValidateEmailRejectsGarbage(): void
    {
        $this->assertFalse(validateEmail('nope'));
    }

    public function testValidateRutAcceptsKnownValidRut(): void
    {
        // 12345678-5 es el DV correcto según el algoritmo módulo 11
        // implementado en validateRut().
        $this->assertTrue(validateRut('12345678-5'));
    }

    public function testValidateRutRejectsInvalidRut(): void
    {
        $this->assertFalse(validateRut('12345678-0'));
        $this->assertFalse(validateRut('abc'));
    }

    public function testSanitizeStringRemovesNullsAndTrims(): void
    {
        $this->assertSame('holamundo', sanitizeString("hola\0mundo  "));
    }
}
