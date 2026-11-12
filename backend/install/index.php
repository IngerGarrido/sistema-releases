<?php
/**
 * Instalador web del sistema (estilo WordPress).
 *
 * Pasos:
 *   1. Bienvenida + verificación de requisitos
 *   2. Conexión a base de datos
 *   3. Crear tablas (schema.sql)
 *   4. Crear usuario administrador
 *   5. Configuración de empresa
 *   6. Final (escribe .env + lock + redirige)
 *
 * Seguridad:
 *   - Si existe ../install.lock o ../.env con DB_NAME, bloquea el instalador.
 *   - Sólo permite GET/POST. Sesión PHP para acarrear datos entre pasos.
 *   - No imprime contraseñas en HTML.
 */

session_start();

$ROOT  = realpath(__DIR__ . '/..');           // backend/
$LOCK  = $ROOT . '/install.lock';
$ENV   = $ROOT . '/.env';
$SCHEMA = __DIR__ . '/schema.sql';

// ─── Bloqueo si ya está instalado ──────────────────────────────
$already_installed = file_exists($LOCK);
$action            = $_GET['action'] ?? '';
if ($already_installed && $action !== 'force') {
    render_installed($LOCK);
    exit;
}

// ─── Estado entre pasos en sesión ──────────────────────────────
if (!isset($_SESSION['install'])) $_SESSION['install'] = [];
$S = &$_SESSION['install'];

$step    = max(1, min(6, (int)($_GET['step'] ?? 1)));
$errors  = [];
$success = '';

// ─── Manejo de POST por paso ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_step = (int)($_POST['step'] ?? 0);

    if ($post_step === 2) {
        // Validar y guardar credenciales BD
        $host = trim($_POST['db_host'] ?? 'localhost');
        $port = trim($_POST['db_port'] ?? '3306');
        $name = trim($_POST['db_name'] ?? '');
        $user = trim($_POST['db_user'] ?? '');
        $pass = (string)($_POST['db_pass'] ?? '');

        if ($host === '' || $name === '' || $user === '') {
            $errors[] = 'Host, nombre de base de datos y usuario son obligatorios.';
        }

        if (empty($errors)) {
            // Probar conexión sin DB primero (para poder crearla si falta)
            try {
                $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                // Intentar crear la BD si no existe
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $name) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                // Reconectar usando la BD
                $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                $S['db'] = compact('host', 'port', 'name', 'user', 'pass');
                header('Location: index.php?step=3');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'No se pudo conectar a MySQL: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
            }
        }
        $step = 2;
    }

    if ($post_step === 3) {
        // Ejecutar schema
        if (empty($S['db'])) {
            $errors[] = 'Falta configurar la base de datos. Vuelve al paso 2.';
            $step = 2;
        } else {
            try {
                $pdo = pdo_from_session($S['db']);
                $sql = file_get_contents($SCHEMA);
                if ($sql === false) throw new RuntimeException('No se encontró schema.sql');
                // Ejecutar como un único bloque (mysqldump produce SQL válido por sentencias).
                $pdo->exec($sql);

                // Marcar todas las migraciones existentes como ya aplicadas
                // (el schema consolidado ya las incluye; no debemos reaplicar).
                $pdo->exec("CREATE TABLE IF NOT EXISTS migraciones (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    archivo VARCHAR(255) NOT NULL UNIQUE,
                    ejecutada_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    duracion_ms INT DEFAULT NULL,
                    checksum CHAR(64) DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $migDir = realpath(__DIR__ . '/../migrations');
                if ($migDir && is_dir($migDir)) {
                    $files = glob($migDir . '/*.sql');
                    sort($files, SORT_STRING);
                    $ins = $pdo->prepare("INSERT IGNORE INTO migraciones (archivo, duracion_ms, checksum) VALUES (?, 0, ?)");
                    foreach ($files as $f) {
                        $ins->execute([basename($f), hash_file('sha256', $f)]);
                    }
                }

                $S['schema_ok'] = true;
                header('Location: index.php?step=4');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Error creando tablas: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
                $step = 3;
            }
        }
    }

    if ($post_step === 4) {
        // Crear usuario admin
        $nombre = trim($_POST['admin_nombre'] ?? '');
        $email  = strtolower(trim($_POST['admin_email'] ?? ''));
        $pass   = (string)($_POST['admin_pass'] ?? '');
        $pass2  = (string)($_POST['admin_pass2'] ?? '');

        if (mb_strlen($nombre) < 2)                    $errors[] = 'El nombre es muy corto.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email inválido.';
        if (mb_strlen($pass) < 8)                      $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
        if ($pass !== $pass2)                          $errors[] = 'Las contraseñas no coinciden.';

        if (empty($errors)) {
            try {
                $pdo = pdo_from_session($S['db']);
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                // Si ya existe un admin con ese email, lo actualizamos
                $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $upd = $pdo->prepare("UPDATE usuarios SET nombre=?, password=?, rol='admin', activo=1 WHERE id=?");
                    $upd->execute([$nombre, $hash, $existing['id']]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, rol, activo) VALUES (?, ?, ?, 'admin', 1)");
                    $ins->execute([$nombre, $email, $hash]);
                }
                $S['admin_email'] = $email;
                header('Location: index.php?step=5');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Error creando usuario: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
            }
        }
        $step = 4;
    }

    if ($post_step === 5) {
        // Datos de empresa
        $nombre   = trim($_POST['empresa_nombre'] ?? '');
        $frontend = trim($_POST['frontend_url']    ?? '');

        if ($nombre === '')        $errors[] = 'El nombre de la empresa es obligatorio.';
        if ($frontend === '')      $errors[] = 'La URL del frontend es obligatoria.';

        if (empty($errors)) {
            try {
                $pdo = pdo_from_session($S['db']);
                $upd = $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES ('nombre_empresa', ?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
                $upd->execute([$nombre]);
                $S['frontend_url'] = $frontend;

                // Escribir .env final + lock
                write_env($ENV, $S['db'], $frontend);
                @file_put_contents($LOCK, date('c') . " | instalado por " . ($S['admin_email'] ?? '?') . "\n");
                $S['done'] = true;
                header('Location: index.php?step=6');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Error finalizando instalación: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
            }
        }
        $step = 5;
    }
}

// ─── Render ────────────────────────────────────────────────────
render_page($step, $errors, $success, $S);

// ═════════════════════════════════════════════════════════════
// FUNCIONES
// ═════════════════════════════════════════════════════════════

function pdo_from_session(array $db): PDO {
    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
    return new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function write_env(string $envPath, array $db, string $frontend): void {
    // Generar APP_KEY aleatorio (para futuros usos, no obligatorio hoy)
    $appKey = bin2hex(random_bytes(32)); // 64 hex chars (AES-256)
    $env = "# Generado por el instalador el " . date('c') . "\n";
    $env .= "DB_HOST={$db['host']}\n";
    $env .= "DB_NAME={$db['name']}\n";
    $env .= "DB_USER={$db['user']}\n";
    $env .= "DB_PASS={$db['pass']}\n";
    $env .= "DB_PORT={$db['port']}\n\n";
    $env .= "APP_ENV=production\n";
    $env .= "APP_DEBUG=false\n";
    $env .= "APP_KEY={$appKey}\n\n";
    $env .= "CORS_ORIGIN={$frontend}\n";
    $env .= "FRONTEND_URL={$frontend}\n\n";
    $env .= "LOG_LEVEL=info\n";
    $env .= "LOG_FILE=logs/app.log\n\n";
    $env .= "CACHE_DRIVER=file\n";
    $env .= "CACHE_TTL=300\n\n";
    $env .= "SMTP_HOST=\n";
    $env .= "SMTP_PORT=587\n";
    $env .= "SMTP_USER=\n";
    $env .= "SMTP_PASS=\n";
    $env .= "SMTP_FROM=noreply@" . parse_url($frontend, PHP_URL_HOST) . "\n";
    $env .= "SMTP_FROM_NAME=Sistema\n\n";
    $env .= "# Actualización automática (repo público en GitHub)\n";
    $env .= "GITHUB_REPO=IngerGarrido/sistema-releases\n";
    $env .= "GITHUB_BRANCH=main\n";
    $env .= "# Opcional: solo si necesitas subir el rate limit de GitHub (60 req/h → 5000 req/h)\n";
    $env .= "GITHUB_TOKEN=\n";

    if (file_put_contents($envPath, $env) === false) {
        throw new RuntimeException("No se pudo escribir {$envPath}. Verifica permisos.");
    }
    @chmod($envPath, 0640);
}

function check_requirements(): array {
    $checks = [];
    $checks[] = [
        'label' => 'PHP 8.0 o superior',
        'ok'    => version_compare(PHP_VERSION, '8.0.0', '>='),
        'hint'  => 'Versión actual: ' . PHP_VERSION,
    ];
    $exts = ['pdo_mysql', 'mbstring', 'json', 'openssl', 'fileinfo'];
    foreach ($exts as $ext) {
        $checks[] = [
            'label' => "Extensión {$ext}",
            'ok'    => extension_loaded($ext),
            'hint'  => extension_loaded($ext) ? 'OK' : 'Falta. Habilita en el panel de PHP.',
        ];
    }
    $backend = realpath(__DIR__ . '/..');
    foreach (['', '/uploads', '/logs', '/cache'] as $d) {
        $p = $backend . $d;
        if ($d !== '' && !is_dir($p)) @mkdir($p, 0755, true);
        $checks[] = [
            'label' => 'Permiso de escritura en ' . ($d === '' ? 'backend/' : "backend{$d}/"),
            'ok'    => is_writable($p),
            'hint'  => is_writable($p) ? 'Escribible' : 'No escribible. chmod 755 en cPanel.',
        ];
    }
    return $checks;
}

function render_installed(string $lock): void {
    $when = trim(@file_get_contents($lock));
    layout_start('Sistema ya instalado');
    echo '<div class="card">';
    echo '<h2>✓ El sistema ya está instalado</h2>';
    echo '<p>Por seguridad, no es posible volver a ejecutar el instalador.</p>';
    echo '<p style="color:#666; font-size:13px;">Marca de instalación:<br><code>' . htmlspecialchars($when) . '</code></p>';
    echo '<p style="margin-top:16px"><strong>Si necesitas re-instalar:</strong></p>';
    echo '<ol style="font-size:14px; line-height:1.7">';
    echo '<li>Elimina el archivo <code>backend/install.lock</code></li>';
    echo '<li>Elimina el archivo <code>backend/.env</code> (opcional)</li>';
    echo '<li><strong>Elimina el directorio <code>backend/install/</code> al terminar.</strong></li>';
    echo '</ol>';
    echo '<p style="margin-top:20px"><a class="btn" href="../../">Ir al sistema →</a></p>';
    echo '</div>';
    layout_end();
}

function render_page(int $step, array $errors, string $success, array $S): void {
    layout_start("Instalador · Paso {$step} de 6");
    ?>
    <div class="wizard">
        <ol class="steps">
            <?php $titulos = ['Inicio','Base de datos','Crear tablas','Administrador','Empresa','Listo']; ?>
            <?php foreach ($titulos as $i => $t):
                $n = $i + 1;
                $cls = $n < $step ? 'done' : ($n === $step ? 'cur' : '');
            ?>
                <li class="<?= $cls ?>"><span><?= $n ?></span><?= $t ?></li>
            <?php endforeach; ?>
        </ol>

        <?php if ($errors): ?>
            <div class="alert err">
                <strong>Hay errores:</strong>
                <ul><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <div class="card">
        <?php
        switch ($step) {
            case 1: render_step1(); break;
            case 2: render_step2($S); break;
            case 3: render_step3($S); break;
            case 4: render_step4(); break;
            case 5: render_step5($S); break;
            case 6: render_step6(); break;
        }
        ?>
        </div>
    </div>
    <?php
    layout_end();
}

function render_step1(): void {
    $checks = check_requirements();
    $all_ok = array_reduce($checks, fn($a, $c) => $a && $c['ok'], true);
    ?>
    <h2>Bienvenido al instalador</h2>
    <p>Este asistente configurará tu CRM en pocos pasos. Asegúrate de tener a mano:</p>
    <ul>
        <li>Datos de tu base de datos MySQL (host, usuario, contraseña, nombre).</li>
        <li>Un email y contraseña para el primer usuario administrador.</li>
        <li>La URL donde estará tu sistema (ej: <code>https://tudominio.com</code>).</li>
    </ul>
    <h3 style="margin-top:24px">Verificación de requisitos</h3>
    <table class="req">
        <?php foreach ($checks as $c): ?>
            <tr>
                <td class="<?= $c['ok'] ? 'ok' : 'err' ?>"><?= $c['ok'] ? '✓' : '✗' ?></td>
                <td><?= htmlspecialchars($c['label']) ?></td>
                <td style="color:#666; font-size:12px"><?= htmlspecialchars($c['hint']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <div class="actions">
        <?php if ($all_ok): ?>
            <a class="btn" href="?step=2">Continuar →</a>
        <?php else: ?>
            <div class="alert warn">Corrige los problemas marcados arriba antes de continuar.</div>
            <a class="btn ghost" href="?step=1">Volver a verificar</a>
        <?php endif; ?>
    </div>
    <?php
}

function render_step2(array $S): void {
    $db = $S['db'] ?? ['host'=>'localhost','port'=>'3306','name'=>'','user'=>'','pass'=>''];
    ?>
    <h2>Base de datos</h2>
    <p>Ingresa los datos de tu base de datos MySQL. Si la base de datos no existe, intentaremos crearla.</p>
    <form method="post">
        <input type="hidden" name="step" value="2">
        <label>Servidor (Host)
            <input type="text" name="db_host" value="<?= h($db['host']) ?>" placeholder="localhost" required>
        </label>
        <label>Puerto
            <input type="text" name="db_port" value="<?= h($db['port']) ?>" placeholder="3306">
        </label>
        <label>Nombre de la base de datos
            <input type="text" name="db_name" value="<?= h($db['name']) ?>" placeholder="micrm_db" required>
        </label>
        <label>Usuario
            <input type="text" name="db_user" value="<?= h($db['user']) ?>" required>
        </label>
        <label>Contraseña
            <input type="password" name="db_pass" placeholder="••••••••">
        </label>
        <div class="actions">
            <a class="btn ghost" href="?step=1">← Atrás</a>
            <button class="btn" type="submit">Probar conexión y continuar →</button>
        </div>
    </form>
    <?php
}

function render_step3(array $S): void {
    if (empty($S['db'])) { echo '<p>Falta configurar la base de datos. <a href="?step=2">Ir al paso 2</a>.</p>'; return; }
    ?>
    <h2>Crear tablas</h2>
    <p>Vamos a crear todas las tablas necesarias en la base de datos <code><?= h($S['db']['name']) ?></code>.</p>
    <p style="color:#666; font-size:13px">Esto es seguro: si las tablas ya existen, no las sobrescribiremos sin avisarte. Asegúrate de que la BD esté <strong>vacía</strong>.</p>
    <form method="post">
        <input type="hidden" name="step" value="3">
        <div class="actions">
            <a class="btn ghost" href="?step=2">← Atrás</a>
            <button class="btn" type="submit">Crear tablas →</button>
        </div>
    </form>
    <?php
}

function render_step4(): void {
    ?>
    <h2>Usuario administrador</h2>
    <p>Crea el primer usuario administrador. Podrás crear más usuarios desde el sistema una vez instalado.</p>
    <form method="post">
        <input type="hidden" name="step" value="4">
        <label>Nombre completo
            <input type="text" name="admin_nombre" required minlength="2" placeholder="Tu nombre">
        </label>
        <label>Email
            <input type="email" name="admin_email" required placeholder="tu@email.com">
        </label>
        <label>Contraseña (mínimo 8 caracteres)
            <input type="password" name="admin_pass" required minlength="8">
        </label>
        <label>Repetir contraseña
            <input type="password" name="admin_pass2" required minlength="8">
        </label>
        <div class="actions">
            <a class="btn ghost" href="?step=3">← Atrás</a>
            <button class="btn" type="submit">Crear administrador →</button>
        </div>
    </form>
    <?php
}

function render_step5(array $S): void {
    $guess = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    ?>
    <h2>Datos de la empresa</h2>
    <p>Personaliza el sistema con el nombre y URL de tu empresa.</p>
    <form method="post">
        <input type="hidden" name="step" value="5">
        <label>Nombre de la empresa
            <input type="text" name="empresa_nombre" required placeholder="Mi Estudio">
        </label>
        <label>URL del sistema (frontend)
            <input type="url" name="frontend_url" required value="<?= h($guess) ?>" placeholder="https://crm.tuempresa.com">
            <small style="color:#666">Esta URL se usa para CORS y los links de email.</small>
        </label>
        <div class="actions">
            <a class="btn ghost" href="?step=4">← Atrás</a>
            <button class="btn" type="submit">Finalizar instalación →</button>
        </div>
    </form>
    <?php
}

function render_step6(): void {
    ?>
    <h2 style="color:#16a34a">✓ ¡Instalación completada!</h2>
    <p>Tu sistema está listo para usarse. <strong>Por seguridad, elimina ahora el directorio</strong> <code>backend/install/</code>.</p>
    <div class="alert warn" style="margin:18px 0">
        <strong>Importante:</strong> dejar el instalador accesible es un riesgo de seguridad.
        Elimina la carpeta <code>install/</code> apenas verifiques que el sistema funciona.
    </div>
    <p>Siguientes pasos sugeridos:</p>
    <ol>
        <li>Inicia sesión con el usuario administrador que creaste.</li>
        <li>Ve a <strong>Configuración</strong> y completa los datos de tu empresa (logo, colores, meta mensual).</li>
        <li>Crea tu primer cliente y proyecto.</li>
    </ol>
    <div class="actions">
        <a class="btn" href="../../">Ir al sistema →</a>
    </div>
    <?php
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function layout_start(string $title): void {
    ?>
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<style>
* { box-sizing: border-box; }
body { font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background:#f3f4f7; color:#222; margin:0; padding:0; }
.wrap { max-width: 720px; margin: 40px auto; padding: 0 16px; }
h1 { font-size: 22px; margin: 0 0 24px; color:#1e2340; }
h2 { font-size: 20px; margin: 0 0 14px; }
h3 { font-size: 16px; margin: 18px 0 8px; }
p { margin: 0 0 12px; }
code { background:#eef0f4; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
.card { background:#fff; border:1px solid #e3e6ec; border-radius:12px; padding: 24px 28px; box-shadow: 0 4px 14px rgba(0,0,0,.04); }
.steps { display:flex; list-style:none; padding:0; margin:0 0 22px; gap: 6px; font-size: 13px; }
.steps li { flex:1; padding: 10px 6px; text-align:center; background:#fff; border:1px solid #e3e6ec; border-radius:8px; color:#777; }
.steps li.cur { background:#1e2340; color:#fff; border-color:#1e2340; font-weight:600; }
.steps li.done { background:#16a34a22; color:#15803d; border-color:#16a34a55; }
.steps li span { display:inline-block; min-width:18px; margin-right:4px; font-weight:700; }
label { display:block; margin: 14px 0 6px; font-weight: 600; font-size: 14px; }
input[type=text], input[type=password], input[type=email], input[type=url] {
  width: 100%; padding: 9px 11px; border:1px solid #cdd2da; border-radius: 7px; font-size: 14px;
  background:#fafbfc;
}
input:focus { outline: 2px solid #3b5bdb44; border-color:#3b5bdb; background:#fff; }
small { display:block; margin-top: 4px; }
.btn { display:inline-block; background:#3b5bdb; color:#fff; padding: 10px 20px; border-radius: 7px;
       text-decoration: none; border: none; cursor: pointer; font-size: 14px; font-weight: 600; }
.btn:hover { background:#2c47b8; }
.btn.ghost { background:#eef0f4; color:#222; }
.actions { display:flex; justify-content: space-between; gap:10px; margin-top: 22px; }
.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; font-size: 14px; }
.alert.err { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.alert.warn { background:#fef3c7; color:#92400e; border:1px solid #fcd34d; }
.alert ul { margin:6px 0 0; padding-left: 20px; }
table.req { width:100%; border-collapse: collapse; margin-top: 10px; }
table.req td { padding: 7px 8px; border-bottom: 1px solid #eef0f4; font-size: 14px; }
table.req td.ok { color:#16a34a; font-weight:700; }
table.req td.err { color:#dc2626; font-weight:700; }
</style>
</head><body><div class="wrap">
<h1>🛠️ Instalador del Sistema</h1>
    <?php
}

function layout_end(): void {
    echo '</div></body></html>';
}
