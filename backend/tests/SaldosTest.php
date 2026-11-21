<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/saldos.php';

/**
 * Saldos de cobro (lib/saldos.php): la única regla de "por cobrar" y "vencida"
 * que usan Panel, Pagos, Proyectos, ficha del cliente y Reportes.
 */
final class SaldosTest extends TestCase
{
    public function testSaldoCuota(): void
    {
        $this->assertSame(60000, saldoCuota(['estado' => 'Pendiente', 'boleta_monto' => 60000, 'pago_monto' => 0]));
        $this->assertSame(10000, saldoCuota(['estado' => 'Parcial', 'boleta_monto' => 60000, 'pago_monto' => 50000]));
        $this->assertSame(0, saldoCuota(['estado' => 'Pagado', 'boleta_monto' => 60000, 'pago_monto' => 60000]));
        // Directo sin monto acordado: no deja saldo
        $this->assertSame(0, saldoCuota(['estado' => 'Pendiente', 'boleta_monto' => 0, 'pago_monto' => 0]));
    }

    public function testCuotaMontoUsaLoCobradoSiEsMayor(): void
    {
        $this->assertSame(60000, cuotaMonto(['boleta_monto' => 60000, 'pago_monto' => 50000]));
        $this->assertSame(80000, cuotaMonto(['boleta_monto' => 0, 'pago_monto' => 80000]));   // directo
    }

    public function testVencidaDespuesDelPlazo(): void
    {
        $c = ['estado' => 'Pendiente', 'boleta_monto' => 1000, 'pago_monto' => 0];
        $hoy = '2026-11-20';
        $this->assertFalse(cuotaVencida($c + ['boleta_fecha' => '2026-10-21'], $hoy)); // 30 días: aún no
        $this->assertTrue(cuotaVencida($c + ['boleta_fecha' => '2026-10-20'], $hoy));  // 31 días
        $this->assertFalse(cuotaVencida($c + ['boleta_fecha' => null], $hoy));         // sin boleta no vence
        $pagada = ['estado' => 'Pagado', 'boleta_monto' => 1000, 'pago_monto' => 1000, 'boleta_fecha' => '2026-01-01'];
        $this->assertFalse(cuotaVencida($pagada, $hoy));
    }

    public function testTramos(): void
    {
        $this->assertSame('vigente', saldoTramo(null));
        $this->assertSame('vigente', saldoTramo(-5));
        $this->assertSame('vigente', saldoTramo(0));
        $this->assertSame('1-30', saldoTramo(30));
        $this->assertSame('31-60', saldoTramo(31));
        $this->assertSame('61-90', saldoTramo(90));
        $this->assertSame('+90', saldoTramo(91));
    }

    public function testResumenCuadraConTramos(): void
    {
        $fila = fn(int $pago, int $monto, int $cobrado, int $saldo, ?int $dias) => [
            'pago_id' => $pago, 'proyecto_id' => 1, 'monto' => $monto, 'cobrado' => $cobrado, 'saldo' => $saldo,
            'dias' => $dias, 'tramo' => saldoTramo($dias), 'vencida' => $saldo > 0 && $dias !== null && $dias > COBRO_PLAZO_DIAS,
        ];
        $r = saldoResumen([
            $fila(1, 60000, 50000, 10000, 175),   // parcial, vencida
            $fila(1, 30000, 0, 30000, 10),        // pendiente reciente
            $fila(2, 20000, 20000, 0, 200),       // pagada: no suma saldo ni vencida
            $fila(3, 5000, 0, 5000, null),        // sin boleta aún
        ]);
        $this->assertSame(115000, $r['total']);
        $this->assertSame(70000, $r['cobrado']);
        $this->assertSame(45000, $r['por_cobrar']);
        $this->assertSame(1, $r['vencidas']);
        $this->assertSame(10000, $r['vencido']);
        $this->assertSame(2, $r['pagos_pendientes']);
        $this->assertSame($r['por_cobrar'], array_sum(array_column($r['tramos'], 'total')));
    }
}
