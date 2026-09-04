<?php
/**
 * Actualización del sistema vía descarga ZIP desde GitHub.
 * Pensado para shared hosting sin git ni SSH.
 *
 * Flujo:
 *  - GET  → consulta versión instalada (VERSION.txt) vs última en GitHub (API)
 *  - POST → descarga ZIP, descomprime, copia archivos preservando .env/uploads,
 *           actualiza VERSION.txt y aplica migraciones pendientes.
 *  - POST ?action=save_token → guarda GITHUB_TOKEN en .env
 *
 * Requiere extensiones PHP: zip, curl. Token de GitHub (privado: scope `repo`).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
@set_time_limit(120);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../migrator.php';

const GITHUB_API = 'https://api.github.com';
const USER_AGENT = 'sistema-updater/1.0';

function proyectoRoot(): string {
    $base = realpath(__DIR__ . '/../../..');
    return $base ?: dirname(__DIR__, 3);
}
function envPath(): string { return __DIR__ . '/../../.env'; }
function versionPath(): string { return proyectoRoot() . '/VERSION.txt'; }

function readVersion(): ?string {
    $p = versionPath();
    if (!is_file($p)) return null;
    $v = trim((string)@file_get_contents($p));
    return $v !== '' ? $v : null;
}
function writeVersion(string $sha): void {
    @file_put_contents(versionPath(), $sha . "\n");
}

/**
 * Sustituye/agrega una variable en .env preservando el resto.
 */
function setEnvVar(string $key, string $value): bool {
    $path = envPath();
    if (!is_file($path)) return false;
    $content = file_get_contents($path);
    $line = $key . '=' . $value;
    if (preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $content)) {
        $content = preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $line, $content);
    } else {
        $content = rtrim($content) . "\n" . $line . "\n";
    }
    return file_put_contents($path, $content) !== false;
}

function ghHeaders(string $token): array {
    $h = ['User-Agent: ' . USER_AGENT, 'Accept: application/vnd.github+json'];
    if ($token) $h[] = 'Authorization: Bearer ' . $token;
    return $h;
}

/**
 * Llamada a la API de GitHub. Devuelve ['code'=>int,'body'=>mixed].
 */
function ghApi(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ghHeaders($token),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) return ['code' => 0, 'body' => null, 'error' => $err];
    $json = json_decode($body, true);
    return ['code' => (int)$code, 'body' => $json, 'raw' => $body];
}

/**
 * Descarga el zipball de un commit/rama a $destPath. Devuelve true/false.
 */
function ghDownloadZip(string $repo, string $ref, string $token, string $destPath): array {
    $url = GITHUB_API . "/repos/{$repo}/zipball/" . rawurlencode($ref);
    $fh = @fopen($destPath, 'wb');
    if (!$fh) return ['ok' => false, 'error' => "No se pudo abrir {$destPath} para escritura"];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ghHeaders($token),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_FILE           => $fh,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if (!$ok || $code >= 400) {
        @unlink($destPath);
        return ['ok' => false, 'error' => "Descarga falló (HTTP {$code}) " . $err];
    }
    return ['ok' => true, 'size' => filesize($destPath)];
}

/**
 * Lista de rutas (relativas a la raíz) que NO deben sobrescribirse al actualizar.
 */
function rutasPreservadas(): array {
    return [
        'backend/.env',
        'backend/install.lock',
        'install.lock',
        'backend/uploads',
        'backend/cache',
        'backend/logs',
        'logs',
        'uploads',
        'VERSION.txt',
        '.git',
    ];
}

function rutaPreservada(string $rel): bool {
    foreach (rutasPreservadas() as $p) {
        if ($rel === $p || str_starts_with($rel, $p . '/')) return true;
    }
    return false;
}

/**
 * Copia recursivamente $src → $dst saltando rutas preservadas (path relativo
 * desde la raíz del proyecto). Devuelve número de archivos copiados.
 */
function copiarSobre(string $src, string $dst, string $rootRel = ''): int {
    $count = 0;
    $items = scandir($src);
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        $srcPath = $src . '/' . $it;
        $rel = $rootRel === '' ? $it : ($rootRel . '/' . $it);
        if (rutaPreservada($rel)) continue;
        $dstPath = $dst . '/' . $it;
        if (is_dir($srcPath)) {
            if (!is_dir($dstPath)) @mkdir($dstPath, 0755, true);
            $count += copiarSobre($srcPath, $dstPath, $rel);
        } else {
            if (@copy($srcPath, $dstPath)) $count++;
        }
    }
    return $count;
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $it) {
        if ($it === '.' || $it === '..') continue;
        $p = $dir . '/' . $it;
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

// ─────────────────────────────────────────────────────────────
try {
    cors();
    $db = getDB();
    $user = requireAuth();
    if ($user['rol'] !== 'admin') err('Solo administradores pueden actualizar el sistema', 403);

    $method = $_SERVER['REQUEST_METHOD'];
    $repo   = getenv('GITHUB_REPO')   ?: 'IngerGarrido/sistema-releases';
    $branch = getenv('GITHUB_BRANCH') ?: 'main';
    $token  = getenv('GITHUB_TOKEN') ?: ''; // opcional: solo para subir rate limit

    // Guardar token desde el panel
    if ($method === 'POST' && ($_GET['action'] ?? '') === 'save_token') {
        $b = body();
        $newToken = trim((string)($b['token'] ?? ''));
        if ($newToken === '') err('Token vacío', 400);
        if (!setEnvVar('GITHUB_TOKEN', $newToken)) err('No se pudo escribir .env', 500);
        audit($db, $user['id'], 'CONFIG', 'sistema', 'Token de GitHub actualizado', null);
        ok(['ok' => true, 'message' => 'Token guardado. Recarga para verificar.']);
    }

    // Falta extensión
    $faltaZip = !class_exists('ZipArchive');
    $faltaCurl = !function_exists('curl_init');

    // ──── GET: estado ────
    if ($method === 'GET') {
        $instalada = readVersion();
        $resp = [
            'es_git'           => true,        // semántica conservada por compat. con UI
            'git_disponible'   => !$faltaZip && !$faltaCurl,
            'modo'             => 'zip',
            'repo'             => $repo,
            'branch'           => $branch,
            'tiene_token'      => $token !== '',
            'falta_zip'        => $faltaZip,
            'falta_curl'       => $faltaCurl,
            'version_instalada'=> $instalada,
            'commit_corto'     => $instalada ? substr($instalada, 0, 7) : null,
            'commit'           => $instalada,
            'commit_fecha'     => null,
            'commit_msg'       => null,
            'behind'           => 0,
            'dirty'            => false,
            'archivos_modificados' => [],
            'tiene_remoto'     => !$faltaZip && !$faltaCurl,
            'upstream'         => "{$repo}@{$branch}",
            'migraciones'      => migrationStatus($db),
        ];

        if ($faltaZip || $faltaCurl) {
            ok($resp);
        }

        // Consultar último commit en GitHub
        $r = ghApi(GITHUB_API . "/repos/{$repo}/commits/" . rawurlencode($branch), $token);
        if ($r['code'] === 401) err('El token de GitHub es inválido o expiró', 401);
        if ($r['code'] === 404) err("Repo {$repo} no encontrado o sin acceso", 404);
        if ($r['code'] >= 400 || !is_array($r['body'])) {
            err('No se pudo consultar GitHub (HTTP ' . $r['code'] . ')', 502);
        }
        $sha = $r['body']['sha'] ?? null;
        if ($sha) {
            $resp['version_disponible'] = $sha;
            $resp['version_disponible_corta'] = substr($sha, 0, 7);
            $resp['commit_msg']    = $r['body']['commit']['message'] ?? null;
            $resp['commit_fecha']  = $r['body']['commit']['author']['date'] ?? null;
            if ($instalada && $sha !== $instalada) {
                $resp['behind'] = 1;
            } elseif (!$instalada) {
                // No tenemos VERSION.txt → tratamos como "al día con el current" y lo guardamos
                writeVersion($sha);
                $resp['version_instalada'] = $sha;
                $resp['commit_corto'] = substr($sha, 0, 7);
            }
        }
        ok($resp);
    }

    // ──── POST: ejecutar update ────
    if ($method === 'POST') {
        rateLimit('actualizar.run', 3, 60);
        $logs = [];

        if ($faltaZip || $faltaCurl) err('Faltan extensiones PHP (zip/curl)', 500);

        // 1. SHA actual (no se muestra al usuario; se usa para auditoría)
        $instalada = readVersion();

        // 2. SHA remoto
        $r = ghApi(GITHUB_API . "/repos/{$repo}/commits/" . rawurlencode($branch), $token);
        if ($r['code'] === 401) err('Token inválido', 401);
        if ($r['code'] >= 400)  err('No se pudo consultar GitHub (HTTP ' . $r['code'] . ')', 502);
        $shaNuevo = $r['body']['sha'] ?? null;
        if (!$shaNuevo) err('GitHub no devolvió un SHA', 502);
        $fechaNueva = $r['body']['commit']['author']['date'] ?? $r['body']['commit']['committer']['date'] ?? null;
        if ($fechaNueva) {
            $fechaFmt = (new DateTime($fechaNueva))->setTimezone(new DateTimeZone('America/Santiago'))->format('d/m/Y H:i');
            $logs[] = "Nueva versión: publicada el {$fechaFmt}";
        } else {
            $logs[] = 'Versión disponible: ' . substr($shaNuevo, 0, 7);
        }

        if ($instalada === $shaNuevo) {
            ok(['logs' => array_merge($logs, ['Ya estás en la última versión.']), 'commit_anterior' => substr($shaNuevo, 0, 7), 'commit_nuevo' => substr($shaNuevo, 0, 7), 'migraciones_aplicadas' => 0, 'status' => null]);
        }

        // 3. Descargar zipball
        $tmpDir = sys_get_temp_dir() . '/sistema-update-' . bin2hex(random_bytes(4));
        @mkdir($tmpDir, 0755, true);
        $zipFile = $tmpDir . '/release.zip';
        $logs[] = 'Descargando paquete…';
        $dl = ghDownloadZip($repo, $shaNuevo, $token, $zipFile);
        if (!$dl['ok']) {
            rrmdir($tmpDir);
            audit($db, $user['id'], 'UPDATE_FAIL', 'sistema', 'Descarga falló: ' . ($dl['error'] ?? ''), null);
            err($dl['error'] ?? 'Descarga falló', 502);
        }
        $logs[] = 'Descargado: ' . round($dl['size'] / 1024) . ' KB';

        // 4. Extraer
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            rrmdir($tmpDir);
            err('No se pudo abrir el ZIP descargado', 500);
        }
        $extractDir = $tmpDir . '/extracted';
        @mkdir($extractDir, 0755, true);
        $zip->extractTo($extractDir);
        $zip->close();
        $logs[] = 'Extraído correctamente.';

        // 5. Identificar carpeta raíz (formato: <user>-<repo>-<sha7>/)
        $inner = null;
        foreach (scandir($extractDir) as $it) {
            if ($it === '.' || $it === '..') continue;
            $p = $extractDir . '/' . $it;
            if (is_dir($p)) { $inner = $p; break; }
        }
        if (!$inner) {
            rrmdir($tmpDir);
            err('Estructura inesperada en el ZIP', 500);
        }

        // 6. Limpiar dist viejo (los hashes de assets cambian en cada build;
        //    si no se limpia, se acumulan versiones obsoletas en el server).
        $root = proyectoRoot();
        $distAssets = $root . '/frontend/dist/assets';
        if (is_dir($distAssets)) {
            rrmdir($distAssets);
            $logs[] = 'Limpieza de assets antiguos.';
        }

        // 7. Copiar sobre el proyecto, preservando rutas críticas
        $copied = copiarSobre($inner, $root);
        $logs[] = "Archivos copiados: {$copied}";

        // 7. Limpiar tmp
        rrmdir($tmpDir);

        // 8. Actualizar VERSION.txt
        writeVersion($shaNuevo);
        $logs[] = 'Versión registrada.';

        // 9. Aplicar migraciones
        $mig = runPendingMigrations($db);
        $logs[] = '';
        $logs[] = '— Migraciones —';
        $logs = array_merge($logs, $mig['logs'] ?? []);

        $resumen = ($instalada ? substr($instalada, 0, 7) : '?') . ' → ' . substr($shaNuevo, 0, 7) . " · {$mig['aplicadas']} migración(es)";
        audit($db, $user['id'], 'UPDATE_OK', 'sistema', $resumen, null);

        // 10. Re-leer estado
        $resp = [
            'logs'              => $logs,
            'commit_anterior'   => $instalada ? substr($instalada, 0, 7) : null,
            'commit_nuevo'      => substr($shaNuevo, 0, 7),
            'commit_msg'        => $r['body']['commit']['message'] ?? null,
            'migraciones_aplicadas' => $mig['aplicadas'],
            'status'            => null,
        ];
        ok($resp);
    }

    err('Método no permitido', 405);

} catch (Throwable $e) {
    errSafe($e, 500, 'admin/actualizar');
}
