<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/correo.php';

/**
 * Armado de correos (MIME) y cliente SMTP contra un servidor falso local.
 */
final class CorreoTest extends TestCase
{
    private function cfg(array $extra = []): array
    {
        return correoNormalizarConfig(array_merge([
            'host' => '127.0.0.1', 'puerto' => '', 'seguridad' => 'ninguna', 'usuario' => 'yo@x.cl', 'pass' => 'secreta',
            'from' => 'yo@x.cl', 'from_nombre' => 'Inger Garrido', 'verificar' => '1',
            'avisos' => '', 'email' => 'contacto@x.cl', 'empresa' => 'Inger Garrido',
        ], $extra));
    }

    public function testNormalizaPuertoSegunSeguridad(): void
    {
        $this->assertSame(465, correoNormalizarConfig(['host' => 'h', 'puerto' => '', 'seguridad' => 'ssl', 'usuario' => '', 'pass' => '', 'from' => 'a@b.cl', 'from_nombre' => '', 'verificar' => '1', 'avisos' => '', 'email' => '', 'empresa' => ''])['puerto']);
        $this->assertSame(587, correoNormalizarConfig(['host' => 'h', 'puerto' => '', 'seguridad' => 'tls', 'usuario' => '', 'pass' => '', 'from' => 'a@b.cl', 'from_nombre' => '', 'verificar' => '1', 'avisos' => '', 'email' => '', 'empresa' => ''])['puerto']);
        // Sin seguridad indicada: 465 → ssl.
        $this->assertSame('ssl', correoNormalizarConfig(['host' => 'h', 'puerto' => '465', 'seguridad' => '', 'usuario' => '', 'pass' => '', 'from' => 'a@b.cl', 'from_nombre' => '', 'verificar' => '1', 'avisos' => '', 'email' => '', 'empresa' => ''])['seguridad']);
    }

    public function testAvisosUsaEmailDeEmpresaSiFalta(): void
    {
        $this->assertSame('contacto@x.cl', $this->cfg()['avisos']);
        $this->assertSame('otra@x.cl', $this->cfg(['avisos' => 'otra@x.cl'])['avisos']);
    }

    public function testCabecerasSinInyeccion(): void
    {
        $this->assertSame('Hola Bcc: x@y.cl', correoLinea("Hola\r\nBcc: x@y.cl"));
        $msg = correoArmarMensaje($this->cfg(), ['to' => 'c@x.cl', 'asunto' => "Hola\r\nBcc: malo@x.cl", 'html' => '<p>x</p>']);
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $msg);
    }

    public function testAsuntoConTildesSeCodifica(): void
    {
        $msg = correoArmarMensaje($this->cfg(), ['to' => 'c@x.cl', 'asunto' => '✓ Cotización aprobada', 'html' => '<p>x</p>']);
        $this->assertMatchesRegularExpression('/^Subject: =\?UTF-8\?B\?/m', $msg);
        preg_match('/^Subject: (.+(?:\r\n .+)*)/m', $msg, $m);
        $this->assertSame('✓ Cotización aprobada', mb_decode_mimeheader(str_replace("\r\n", '', $m[1])));
    }

    public function testMensajeConAdjunto(): void
    {
        $pdf = "%PDF-1.4\n" . random_bytes(300);
        $msg = correoArmarMensaje($this->cfg(), [
            'to' => 'c@x.cl', 'to_nombre' => 'Nicolás Pérez', 'asunto' => 'x', 'html' => '<p>Hola <a href="https://x.cl">aquí</a></p>',
            'adjuntos' => [['nombre' => 'Propuesta PRO-2026-003.pdf', 'mime' => 'application/pdf', 'datos' => $pdf]],
        ]);
        $this->assertStringContainsString('Content-Type: multipart/mixed', $msg);
        $this->assertStringContainsString('Content-Type: application/pdf; name="Propuesta PRO-2026-003.pdf"', $msg);
        // El adjunto se decodifica igual al original.
        preg_match('/filename\*=UTF-8\'\'[^\r\n]+\r\n\r\n([A-Za-z0-9+\/=\r\n]+)\r\n--/', $msg, $m);
        $this->assertSame($pdf, base64_decode(str_replace("\r\n", '', $m[1])));
        // Texto plano derivado del HTML, con el link visible.
        preg_match('/text\/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)\r\n--/', $msg, $t);
        $this->assertSame('Hola aquí (https://x.cl)', base64_decode(str_replace("\r\n", '', $t[1])));
        $this->assertStringContainsString('To: =?UTF-8?B?', $msg);
    }

    public function testEnviaPorSmtpConAuthLogin(): void
    {
        [$proc, $puerto, $log] = $this->servidorFalso();
        try {
            $cfg = $this->cfg(['puerto' => (string)$puerto]);
            // Mensaje crudo con una línea que empieza con punto (debe ir "dot-stuffed").
            $msg = "Subject: Prueba\r\n\r\nhola\n.empieza con punto\nfin\n";
            $smtp = new \CorreoSmtp($cfg, 5);
            $smtp->enviar($cfg['from'], ['c@x.cl'], $msg);
            $smtp->cerrar();
        } finally {
            $this->cerrarServidor($proc);
        }
        $t = (string)file_get_contents($log);
        $this->assertStringContainsString('AUTH LOGIN', $t);
        $this->assertStringContainsString(base64_encode('yo@x.cl'), $t);
        $this->assertStringContainsString('MAIL FROM:<yo@x.cl>', $t);
        $this->assertStringContainsString('RCPT TO:<c@x.cl>', $t);
        $this->assertStringContainsString('Subject: Prueba', $t);
        $this->assertStringContainsString("\r\n..empieza con punto\r\n", $t);
        $this->assertStringContainsString("fin\r\n.\r\n", $t);
        $this->assertStringContainsString('QUIT', $t);
    }

    public function testContrasenaRechazadaDaMensajeClaro(): void
    {
        [$proc, $puerto] = $this->servidorFalso(rechazarAuth: true);
        try {
            $cfg = $this->cfg(['puerto' => (string)$puerto]);
            $smtp = new \CorreoSmtp($cfg, 5);
            $this->expectExceptionMessage('rechazó el usuario o la contraseña');
            try { $smtp->enviar($cfg['from'], ['c@x.cl'], "Subject: x\r\n\r\nx"); } finally { $smtp->cerrar(); }
        } finally {
            $this->cerrarServidor($proc);
        }
    }

    /** Lanza un servidor SMTP mínimo en un proceso aparte. Devuelve [proc, puerto, archivo de log]. */
    private function servidorFalso(bool $rechazarAuth = false): array
    {
        $log = tempnam(sys_get_temp_dir(), 'smtp');
        $script = __DIR__ . '/fixtures/smtp_falso.php';
        $proc = proc_open([PHP_BINARY, $script, $log, $rechazarAuth ? '1' : '0'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $puerto = (int)trim((string)fgets($pipes[1]));
        $this->assertGreaterThan(0, $puerto, 'El servidor SMTP falso no arrancó');
        return [$proc, $puerto, $log];
    }

    private function cerrarServidor($proc): void
    {
        // Dar tiempo a que escriba el log tras el QUIT.
        for ($i = 0; $i < 40 && proc_get_status($proc)['running']; $i++) usleep(50000);
        if (proc_get_status($proc)['running']) proc_terminate($proc);
        proc_close($proc);
    }

    public function testDestinosVariosSeparadosPorComa(): void
    {
        $this->assertSame(['a@x.cl'], correoDestinos('a@x.cl'));
        $this->assertSame(['a@x.cl', 'b@y.cl', 'c@z.cl'], correoDestinos(' a@x.cl, b@y.cl;c@z.cl '));
        $this->assertSame(['a@x.cl'], correoDestinos('a@x.cl, A@X.cl'), 'sin repetidos');
        $this->assertSame([], correoDestinos('a@x.cl, malo'), 'uno inválido invalida todo');
        $this->assertSame([], correoDestinos(''));
        $this->assertSame([], correoDestinos('a@x.cl,b@x.cl,c@x.cl,d@x.cl,e@x.cl,f@x.cl'), 'máximo 5');
    }

    public function testCabeceraToConVariosDestinos(): void
    {
        $cfg = correoNormalizarConfig(['host' => 'h', 'puerto' => 587, 'seguridad' => 'tls', 'usuario' => 'yo@x.cl', 'pass' => '',
            'from' => 'yo@x.cl', 'from_nombre' => 'Yo', 'verificar' => '1', 'avisos' => '', 'email' => '', 'empresa' => '']);
        $uno = correoArmarMensaje($cfg, ['to' => 'a@x.cl', 'to_nombre' => 'Ana', 'asunto' => 'Hola', 'html' => '<p>x</p>']);
        $this->assertStringContainsString("To: \"Ana\" <a@x.cl>", $uno);
        $dos = correoArmarMensaje($cfg, ['to' => 'a@x.cl, b@y.cl', 'to_nombre' => 'Ana', 'asunto' => 'Hola', 'html' => '<p>x</p>']);
        $this->assertStringContainsString('To: <a@x.cl>, <b@y.cl>', $dos);
    }

    public function testPlantillaConEstiloDeLosDocumentos(): void
    {
        $html = correoPlantilla('Trabajo terminado en x.cl', ['Hola <b>Ana</b>'], ['texto' => 'Ver acta', 'url' => 'https://s/e/t'],
            [['Acta', 'ENT-2026-005']], 'pie', ['etiqueta' => 'Acta de entrega · ENT-2026-005']);
        $this->assertStringContainsString('Acta de entrega · ENT-2026-005', $html, 'etiqueta en el encabezado');
        $this->assertStringContainsString('Trabajo terminado en x.cl<span style="color:#2340E0">.</span>', $html, 'título con punto azul');
        $this->assertStringContainsString('#0C1B4A', $html, 'encabezado navy');
        $this->assertStringContainsString('ENT-2026-005</td>', $html, 'datos en el recuadro');
        // Un título que ya termina en punto no queda con dos.
        $this->assertStringNotContainsString('x.cl.<span', correoPlantilla('Listo x.cl.', []));
    }
}
