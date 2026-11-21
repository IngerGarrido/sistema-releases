<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

require_once __DIR__ . '/../lib/procesos.php';

/**
 * Procesos automáticos (lib/procesos.php → procesosDiarios): lo único que
 * cambia datos sin que nadie lo pida. Corre contra una base de pruebas aparte
 * (sistema_local_test) con el mismo esquema que la local; se crea y se borra
 * en cada corrida. Si no hay MySQL disponible, los tests se saltan.
 *
 * Correr con el socket de MAMP:
 *   php -d pdo_mysql.default_socket=/Applications/MAMP/tmp/mysql/mysql.sock vendor/bin/phpunit
 */
final class ProcesosTest extends TestCase
{
    private const DB_TEST = 'sistema_local_test';
    private static ?PDO $db = null;

    public static function setUpBeforeClass(): void
    {
        $origen = getenv('TEST_SCHEMA_DB') ?: 'sistema_local';
        try {
            $srv = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS,
                           [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $tablas = $srv->query("SELECT TABLE_NAME FROM information_schema.TABLES
                                   WHERE TABLE_SCHEMA = " . $srv->quote($origen) . " AND TABLE_TYPE = 'BASE TABLE'")
                          ->fetchAll(PDO::FETCH_COLUMN);
            if (!$tablas) return;
            $srv->exec('DROP DATABASE IF EXISTS ' . self::DB_TEST);
            $srv->exec('CREATE DATABASE ' . self::DB_TEST . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            foreach ($tablas as $t) {
                $srv->exec('CREATE TABLE ' . self::DB_TEST . ".`$t` LIKE `$origen`.`$t`");
            }
            $srv->exec('USE ' . self::DB_TEST);
            self::$db = $srv;
        } catch (Throwable $e) {
            self::$db = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db) self::$db->exec('DROP DATABASE IF EXISTS ' . self::DB_TEST);
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (!self::$db) $this->markTestSkipped('Sin MySQL local (o sin la base sistema_local para copiar el esquema).');
        $db = self::$db;
        foreach (['pago_cuotas', 'pagos', 'proyecto_servicios', 'proyectos', 'sitios', 'cotizaciones', 'entregas',
                  'servicios', 'clientes', 'notificaciones', 'auditoria', 'usuarios'] as $t) {
            $db->exec("DELETE FROM `$t`");
        }
        $db->exec("INSERT INTO usuarios (id, nombre, email, password, rol, activo) VALUES (1, 'Admin', 'a@test.cl', 'x', 'admin', 1)");
        $db->exec("INSERT INTO clientes (id, nombre, tipo) VALUES (1, 'ZZ Cliente', 'Directo')");
    }

    private function contar(string $sql): int
    {
        return (int)self::$db->query($sql)->fetchColumn();
    }

    public function testRenovacionConMontoCreaUnSoloCobroAunqueCorraDosVeces(): void
    {
        $db = self::$db;
        $db->exec("INSERT INTO sitios (id, cliente_id, nombre, dominio_vence, dominio_monto)
                   VALUES (1, 1, 'zz.cl', '2026-12-10', 15000)");

        $r1 = procesosDiarios($db, 1, '2026-11-20');   // vence en 20 días: dentro del aviso (30)
        $r2 = procesosDiarios($db, 1, '2026-11-20');

        $this->assertSame([], $r1['errores']);
        $this->assertSame(1, $r1['renovaciones']);
        $this->assertSame(0, $r2['renovaciones'], 'La segunda corrida no debe duplicar el cobro');
        $this->assertSame(1, $this->contar("SELECT COUNT(*) FROM proyectos WHERE nombre = 'Renovación de dominio 2026'"));
        $this->assertSame(1, $this->contar('SELECT COUNT(*) FROM pagos'));
        $this->assertSame(15000, $this->contar('SELECT SUM(boleta_monto) FROM pago_cuotas'));
    }

    public function testRenovacionLejanaNoCobraTodavia(): void
    {
        self::$db->exec("INSERT INTO sitios (id, cliente_id, nombre, hosting_vence, hosting_monto)
                         VALUES (1, 1, 'zz.cl', '2027-03-01', 30000)");
        $r = procesosDiarios(self::$db, 1, '2026-11-20');
        $this->assertSame(0, $r['renovaciones']);
        $this->assertSame(0, $this->contar('SELECT COUNT(*) FROM pagos'));
    }

    public function testRenovacionCobradaAvanzaUnAnoAlPasarLaFecha(): void
    {
        self::$db->exec("INSERT INTO sitios (id, cliente_id, nombre, dominio_vence, dominio_monto, dominio_cobrado)
                         VALUES (1, 1, 'zz.cl', '2026-11-01', 15000, '2026-11-01')");
        procesosDiarios(self::$db, 1, '2026-11-20');
        $this->assertSame('2027-11-01', self::$db->query('SELECT dominio_vence FROM sitios WHERE id = 1')->fetchColumn());
        $this->assertSame(0, $this->contar('SELECT COUNT(*) FROM pagos'), 'El próximo año aún está lejos: sin cobro nuevo');
    }

    public function testCotizacionVencidaSinRespuestaExpira(): void
    {
        $db = self::$db;
        $db->exec("INSERT INTO cotizaciones (id, cliente_id, numero, estado, fecha, fecha_validez, updated_at) VALUES
                   (1, 1, 'ZZ-1', 'Pendiente', '2026-10-01', '2026-10-31', '2026-10-01 10:00:00'),
                   (2, 1, 'ZZ-2', 'Enviada',   '2026-11-01', '2026-12-01', '2026-11-01 10:00:00'),
                   (3, 1, 'ZZ-3', 'Pendiente', '2026-10-01', '2026-10-31', '2026-11-10 10:00:00'),
                   (4, 1, 'ZZ-4', 'Aceptada',  '2026-10-01', '2026-10-31', '2026-10-01 10:00:00')");

        $r = procesosDiarios($db, 1, '2026-11-20');

        $this->assertSame(['ZZ-1'], $r['cotizaciones_expiradas']);
        $estados = $db->query('SELECT id, estado FROM cotizaciones ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame('Expirada', $estados[1]);
        $this->assertSame('Enviada', $estados[2], 'Aún vigente');
        $this->assertSame('Pendiente', $estados[3], 'Reactivada a mano después de vencer: no se vuelve a expirar');
        $this->assertSame('Aceptada', $estados[4]);
    }

    public function testActaRecibidaTerminaElProyecto(): void
    {
        $db = self::$db;
        $db->exec("INSERT INTO proyectos (id, cliente_id, nombre, estado) VALUES
                   (1, 1, 'ZZ Web', 'En curso'), (2, 1, 'ZZ Otra', 'En curso')");
        $db->exec("INSERT INTO entregas (id, cliente_id, proyecto_id, numero, token, estado, recibida_at) VALUES
                   (1, 1, 1, 'ENT-ZZ-1', 'tok1', 'recibida', '2026-11-19 12:00:00'),
                   (2, 1, 2, 'ENT-ZZ-2', 'tok2', 'borrador', NULL)");

        $r = procesosDiarios($db, 1, '2026-11-20');

        $this->assertSame(1, $r['proyectos_terminados']);
        $estados = $db->query('SELECT id, estado FROM proyectos ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame('Terminado', $estados[1]);
        $this->assertSame('En curso', $estados[2], 'El acta aún en borrador no termina nada');
    }

    public function testAvisoDeCuotasVencidasSeCreaUnaVezYSeBorraSolo(): void
    {
        $db = self::$db;
        $db->exec("INSERT INTO proyectos (id, cliente_id, nombre, estado) VALUES (1, 1, 'ZZ Web', 'En curso')");
        $db->exec("INSERT INTO pagos (id, cliente_id, proyecto_id, descripcion, monto_total, tipo_pago) VALUES (1, 1, 1, 'ZZ', 10000, 'boleta')");
        $db->exec("INSERT INTO pago_cuotas (id, pago_id, numero_cuota, boleta_monto, pago_monto, estado, tipo_pago, boleta_fecha)
                   VALUES (1, 1, 1, 10000, 0, 'Pendiente', 'boleta', '2026-09-01')");

        procesosDiarios($db, 1, '2026-11-20');
        procesosDiarios($db, 1, '2026-11-20');
        $avisos = "SELECT COUNT(*) FROM notificaciones WHERE mensaje LIKE '%vencida%'";
        $this->assertSame(1, $this->contar($avisos), 'Un solo aviso aunque el proceso corra dos veces');

        $db->exec("UPDATE pago_cuotas SET pago_monto = 10000, estado = 'Pagado' WHERE id = 1");
        procesosDiarios($db, 1, '2026-11-21');
        $this->assertSame(0, $this->contar($avisos), 'Ya cobrada: el aviso sin leer se borra');
    }

    public function testPapeleraAntiguaSeBorraSinArrastrarLoActivo(): void
    {
        $db = self::$db;
        // Cliente 2 en papelera hace 60 días, pero con un proyecto ACTIVO: la
        // cascada se lo llevaría, así que no se toca.
        $db->exec("INSERT INTO clientes (id, nombre, tipo, deleted_at) VALUES
                   (2, 'ZZ Viejo con activo', 'Directo', '2026-09-01 10:00:00'),
                   (3, 'ZZ Viejo vacío', 'Directo', '2026-09-01 10:00:00'),
                   (4, 'ZZ Reciente', 'Directo', '2026-11-15 10:00:00')");
        $db->exec("INSERT INTO proyectos (id, cliente_id, nombre, estado, deleted_at) VALUES
                   (1, 2, 'ZZ Activo', 'En curso', NULL),
                   (2, 1, 'ZZ Viejo', 'Cancelado', '2026-09-01 10:00:00'),
                   (3, 1, 'ZZ Viejo con pago', 'Cancelado', '2026-09-01 10:00:00')");
        $db->exec("INSERT INTO proyecto_servicios (proyecto_id, servicio_id, nombre, cantidad, precio_unitario, orden)
                   VALUES (2, NULL, 'ZZ línea', 1, 1000, 0)");
        $db->exec("INSERT INTO pagos (id, cliente_id, proyecto_id, descripcion, monto_total, tipo_pago)
                   VALUES (1, 1, 3, 'ZZ', 1000, 'boleta')");

        $r = procesosDiarios($db, 1, '2026-11-20');

        $this->assertSame([], $r['errores']);
        $this->assertSame(2, $r['papelera_vaciada'], 'Proyecto viejo sin pagos + cliente viejo vacío');
        $ids = fn(string $t) => array_map('intval', $db->query("SELECT id FROM $t ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame([1, 2, 4], $ids('clientes'), 'Se mantienen: el que tiene algo activo y el reciente');
        $this->assertSame([1, 3], $ids('proyectos'), 'Se mantiene el activo y el que aún tiene un pago activo');
        $this->assertSame(0, $this->contar('SELECT COUNT(*) FROM proyecto_servicios WHERE proyecto_id = 2'), 'Sin líneas huérfanas');
        $this->assertSame(1, $this->contar("SELECT COUNT(*) FROM notificaciones WHERE mensaje LIKE '%papelera%'"));
    }
}
