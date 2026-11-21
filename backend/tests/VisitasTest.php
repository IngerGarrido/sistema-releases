<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/visitas.php';

/**
 * Qué visita a un link público cuenta como «el cliente la vio».
 */
final class VisitasTest extends TestCase
{
    private const CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    private const CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36';
    private const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';
    private const EDGE_WIN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0';

    private function srv(string $ua, string $lang = 'es-CL,es;q=0.9', string $metodo = 'GET'): array
    {
        return ['REQUEST_METHOD' => $metodo, 'HTTP_USER_AGENT' => $ua, 'HTTP_ACCEPT_LANGUAGE' => $lang];
    }

    public function testNavegadoresRealesNoSonRobots(): void
    {
        foreach ([self::CHROME_MAC, self::CHROME_ANDROID, self::SAFARI_IPHONE, self::EDGE_WIN] as $ua) {
            $this->assertFalse(visEsRobot($this->srv($ua)), $ua);
        }
    }

    public function testRobotsYVistasPreviasNoCuentan(): void
    {
        $this->assertTrue(visEsRobot($this->srv('')), 'sin User-Agent');
        $this->assertTrue(visEsRobot($this->srv(self::CHROME_MAC, '')), 'sin Accept-Language');
        $this->assertTrue(visEsRobot($this->srv(self::CHROME_MAC, 'es', 'HEAD')), 'HEAD');
        $this->assertTrue(visEsRobot($this->srv('WhatsApp/2.23.20.0 A')));
        $this->assertTrue(visEsRobot($this->srv('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')));
        $this->assertTrue(visEsRobot($this->srv('facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)')));
        $this->assertTrue(visEsRobot($this->srv('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36')));
        $this->assertTrue(visEsRobot($this->srv('curl/8.4.0')));
        $this->assertTrue(visEsRobot($this->srv('python-requests/2.31.0')));
    }

    public function testDispositivo(): void
    {
        $this->assertSame('Computador · Mac · Chrome', visDispositivo(self::CHROME_MAC));
        $this->assertSame('Celular · Android · Chrome', visDispositivo(self::CHROME_ANDROID));
        $this->assertSame('Celular · iPhone · Safari', visDispositivo(self::SAFARI_IPHONE));
        $this->assertSame('Computador · Windows · Edge', visDispositivo(self::EDGE_WIN));
        $this->assertSame('Dispositivo desconocido', visDispositivo(''));
    }

    public function testIpSeGuardaComoHashYAgrupaIpv6(): void
    {
        $k = visIpClave('190.100.20.30');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $k);
        $this->assertStringNotContainsString('190.100', $k);
        $this->assertNotSame($k, visIpClave('190.100.20.31'));
        // Mismo /64 en IPv6 = misma conexión; otro /64 = otra.
        $this->assertSame(visIpClave('2800:150:1:2::aaaa'), visIpClave('2800:150:1:2:ffff::1'));
        $this->assertNotSame(visIpClave('2800:150:1:2::1'), visIpClave('2800:150:1:3::1'));
        // IPv4 mapeada en IPv6 = la misma IPv4.
        $this->assertSame($k, visIpClave('::ffff:190.100.20.30'));
        $this->assertSame('', visIpClave('no-es-ip'));
    }
}
