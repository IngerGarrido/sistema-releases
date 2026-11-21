<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/entregas.php';

/**
 * Entregas: feriados de Chile, días hábiles del plazo y lista de revisión.
 */
final class EntregasTest extends TestCase
{
    public function testFeriados2026Oficiales(): void
    {
        $esperados = ['2026-01-01', '2026-04-03', '2026-04-04', '2026-05-01', '2026-05-21', '2026-06-21', '2026-06-29',
            '2026-07-16', '2026-08-15', '2026-09-18', '2026-09-19', '2026-10-12', '2026-10-31', '2026-11-01',
            '2026-12-08', '2026-12-25'];
        $this->assertSame($esperados, array_keys(entFeriados(2026)));
    }

    public function testFeriadosTrasladables(): void
    {
        // 2027: 29-jun y 12-oct caen martes → lunes anterior.
        $f = entFeriados(2027);
        $this->assertArrayHasKey('2027-06-28', $f);
        $this->assertArrayHasKey('2027-10-11', $f);
        $this->assertArrayNotHasKey('2027-06-29', $f);
        // 2025: 31-oct viernes, queda en su día.
        $this->assertArrayHasKey('2025-10-31', entFeriados(2025));
        // 2028: 31-oct martes → viernes 27.
        $this->assertArrayHasKey('2028-10-27', entFeriados(2028));
        // 17-sep lunes (2029) y 20-sep viernes (2024).
        $this->assertArrayHasKey('2029-09-17', entFeriados(2029));
        $this->assertArrayHasKey('2024-09-20', entFeriados(2024));
        // 2-ene lunes (2023).
        $this->assertArrayHasKey('2023-01-02', entFeriados(2023));
    }

    public function testPascua(): void
    {
        $this->assertSame('2024-03-31', entPascua(2024)->format('Y-m-d'));
        $this->assertSame('2026-04-05', entPascua(2026)->format('Y-m-d'));
        $this->assertSame('2027-03-28', entPascua(2027)->format('Y-m-d'));
    }

    public static function plazos(): array
    {
        return [
            'semana normal'           => ['2026-11-20', 5, '2026-11-27'],
            'cruza Fiestas Patrias'   => ['2026-09-14', 5, '2026-09-22'],
            'Navidad y Año Nuevo'     => ['2026-12-23', 5, '2026-12-31'],
            'enviada en fin de semana' => ['2026-11-21', 1, '2026-11-23'],
            'el día del envío no cuenta' => ['2026-11-23', 1, '2026-11-24'],
        ];
    }

    /** @dataProvider plazos */
    public function testSumarHabiles(string $envio, int $n, string $vence): void
    {
        $this->assertSame($vence, entSumarHabiles(new DateTimeImmutable("$envio 15:30"), $n)->format('Y-m-d'));
    }

    public function testChecklistFaltan(): void
    {
        $todas = entChecklistClaves();
        $this->assertCount(19, $todas);
        $this->assertSame($todas, entChecklistFaltan(null));
        $marcadas = array_fill_keys(array_slice($todas, 0, 17), true);
        $this->assertSame(array_slice($todas, 17), entChecklistFaltan(json_encode($marcadas)));
        $this->assertSame([], entChecklistFaltan(json_encode(array_fill_keys($todas, true))));
    }

    public function testDominio(): void
    {
        $this->assertSame('atodamaquina.cl', entDominio('https://www.atodamaquina.cl/'));
        $this->assertSame('x.cl', entDominio('x.cl/ruta'));
        $this->assertSame('', entDominio(null));
    }

    public function testGarantiaHasta(): void
    {
        // recepción + meses − 1 día
        $this->assertSame('2027-02-02', entGarantiaHasta('2026-11-03 09:12:00', 3));
        // cambio de año y fin de mes: 30-nov + 3 meses → 28-feb (último día) − 1 → 27-feb
        $this->assertSame('2027-02-27', entGarantiaHasta('2026-11-30', 3));
        $this->assertSame('2028-02-28', entGarantiaHasta('2027-11-30', 3));   // 2028 bisiesto: 29-feb − 1
        $this->assertSame('2027-11-19', entGarantiaHasta('2026-11-20', 12));
        $this->assertSame('2026-12-31', entGarantiaHasta('2026-01-01', 12));
        $this->assertNull(entGarantiaHasta('2026-11-03', 0));
        $this->assertNull(entGarantiaHasta(null, 3));
    }
}
