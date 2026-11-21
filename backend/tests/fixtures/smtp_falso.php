<?php
/**
 * Servidor SMTP falso para CorreoTest (una sola conexión, sin TLS).
 * Uso: php smtp_falso.php <archivo_log> <rechazar_auth 0|1>
 * Imprime el puerto en stdout y registra todo lo que recibe.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$log = $argv[1];
$rechazar = ($argv[2] ?? '0') === '1';
$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$srv) exit(1);
$nombre = stream_socket_get_name($srv, false);
echo substr($nombre, strrpos($nombre, ':') + 1) . "\n";
fflush(STDOUT);

$c = @stream_socket_accept($srv, 10);
if (!$c) exit(1);
$out = fn(string $s) => fwrite($c, $s . "\r\n");
$rec = '';
$out('220 falso ESMTP');
$enData = false;
$authPaso = 0;
while (($l = fgets($c)) !== false) {
    $rec .= $l;
    if ($enData) {
        if ($l === ".\r\n") { $enData = false; $out('250 OK encolado'); }
        continue;
    }
    $cmd = strtoupper(substr(trim($l), 0, 10));
    if ($authPaso === 1) { $authPaso = 2; $out('334 UGFzc3dvcmQ6'); continue; }
    if ($authPaso === 2) { $authPaso = 0; $out($rechazar ? '535 5.7.8 Authentication failed' : '235 Autenticado'); continue; }
    if (str_starts_with($cmd, 'EHLO')) { fwrite($c, "250-falso\r\n250-AUTH LOGIN\r\n250 8BITMIME\r\n"); continue; }
    if (str_starts_with($cmd, 'AUTH LOGIN')) { $authPaso = 1; $out('334 VXNlcm5hbWU6'); continue; }
    if (str_starts_with($cmd, 'MAIL FROM') || str_starts_with($cmd, 'RCPT TO')) { $out('250 OK'); continue; }
    if ($cmd === 'DATA') { $enData = true; $out('354 Adelante'); continue; }
    if ($cmd === 'QUIT') { $out('221 Chao'); break; }
    $out('502 No implementado');
}
file_put_contents($log, $rec);
