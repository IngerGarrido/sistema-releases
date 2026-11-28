<?php
// ============================================================
// Migration runner.
// Escanea backend/migrations/*.sql, compara con tabla `migraciones`
// y ejecuta solo las pendientes en orden alfabético.
// Idempotente: corre N veces sin efectos secundarios.
// ============================================================

require_once __DIR__ . '/config.php';

const MIGRATIONS_DIR = __DIR__ . '/migrations';

/**
 * Asegura que la tabla `migraciones` existe (bootstrap).
 * Esto es necesario porque la primera migración crea la tabla en sí.
 */
function ensureMigrationsTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS migraciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        archivo VARCHAR(255) NOT NULL UNIQUE,
        ejecutada_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        duracion_ms INT DEFAULT NULL,
        checksum CHAR(64) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Lista todos los .sql en migrations/ ordenados.
 */
function listMigrationFiles(): array {
    if (!is_dir(MIGRATIONS_DIR)) return [];
    $files = glob(MIGRATIONS_DIR . '/*.sql');
    sort($files, SORT_STRING);
    return array_map('basename', $files);
}

/**
 * Lista archivos ya aplicados en BD.
 */
function listAppliedMigrations(PDO $db): array {
    ensureMigrationsTable($db);
    $stmt = $db->query("SELECT archivo, ejecutada_at, duracion_ms, checksum FROM migraciones ORDER BY archivo");
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['archivo']] = $r;
    }
    return $out;
}

/**
 * Calcula el sha256 del contenido de una migración. Usado para detectar
 * cambios accidentales en archivos ya aplicados (warning, no error).
 */
function migrationChecksum(string $path): string {
    return hash_file('sha256', $path) ?: '';
}

/**
 * Devuelve estado completo: aplicadas, pendientes, total.
 */
function migrationStatus(PDO $db): array {
    $all = listMigrationFiles();
    $applied = listAppliedMigrations($db);
    $items = [];
    foreach ($all as $f) {
        $items[] = [
            'archivo' => $f,
            'aplicada' => isset($applied[$f]),
            'ejecutada_at' => $applied[$f]['ejecutada_at'] ?? null,
            'duracion_ms' => isset($applied[$f]) ? (int)$applied[$f]['duracion_ms'] : null,
        ];
    }
    $pendientes = array_values(array_filter($items, fn($i) => !$i['aplicada']));
    return [
        'items' => $items,
        'pendientes' => $pendientes,
        'total' => count($items),
        'aplicadas' => count($applied),
        'por_aplicar' => count($pendientes),
    ];
}

/**
 * Ejecuta un archivo de migración. Cada statement (separado por ;) se ejecuta secuencialmente.
 * Devuelve [ok, log[]].
 */
function runMigrationFile(PDO $db, string $filename): array {
    $path = MIGRATIONS_DIR . '/' . $filename;
    if (!is_file($path)) {
        return ['ok' => false, 'log' => ["Archivo no encontrado: $filename"]];
    }
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        return ['ok' => false, 'log' => ["Archivo vacío: $filename"]];
    }
    $checksum = hash('sha256', $sql);
    $log = ["▶ Ejecutando $filename"];
    $start = microtime(true);

    // Quitar comentarios `-- ...` línea por línea antes de partir por `;`.
    $clean = preg_replace('/^\s*--[^\r\n]*$/m', '', $sql);
    $statements = array_filter(
        array_map('trim', preg_split('/;\s*[\r\n]+/', $clean)),
        fn($s) => $s !== ''
    );

    // Errores idempotentes a ignorar (objeto ya existe).
    // 1050: Table already exists | 1060: Duplicate column | 1061: Duplicate key name
    // 1091: Can't DROP (doesn't exist) | 1826: Duplicate FK name
    $idempotent = [1050, 1060, 1061, 1091, 1826];

    try {
        foreach ($statements as $stmt) {
            try {
                $db->exec($stmt);
            } catch (PDOException $pe) {
                $code = (int)($pe->errorInfo[1] ?? 0);
                if (in_array($code, $idempotent, true)) {
                    $log[] = "  ↳ omitido (ya existe, MySQL " . $code . ")";
                    continue;
                }
                throw $pe;
            }
        }
        $duration = (int)round((microtime(true) - $start) * 1000);
        $ins = $db->prepare("INSERT INTO migraciones (archivo, duracion_ms, checksum) VALUES (?, ?, ?)");
        $ins->execute([$filename, $duration, $checksum]);
        $log[] = "✔ OK en {$duration}ms (" . count($statements) . " statements)";
        return ['ok' => true, 'log' => $log, 'duracion_ms' => $duration];
    } catch (Throwable $e) {
        $log[] = "✘ ERROR: " . $e->getMessage();
        return ['ok' => false, 'log' => $log, 'error' => $e->getMessage()];
    }
}

/**
 * Ejecuta todas las migraciones pendientes en orden.
 * Se detiene al primer error.
 */
function runPendingMigrations(PDO $db): array {
    $status = migrationStatus($db);
    $logs = [];
    $aplicadas = 0;
    if (empty($status['pendientes'])) {
        return ['ok' => true, 'logs' => ['Sin migraciones pendientes.'], 'aplicadas' => 0];
    }
    foreach ($status['pendientes'] as $p) {
        $r = runMigrationFile($db, $p['archivo']);
        $logs = array_merge($logs, $r['log']);
        if (!$r['ok']) {
            return ['ok' => false, 'logs' => $logs, 'aplicadas' => $aplicadas, 'error' => $r['error'] ?? 'fallo'];
        }
        $aplicadas++;
    }
    return ['ok' => true, 'logs' => $logs, 'aplicadas' => $aplicadas];
}

/**
 * Marca un archivo como aplicado SIN ejecutarlo. Útil cuando el installer
 * importa schema.sql consolidado y necesita registrar las migraciones
 * embebidas como ya aplicadas (para no reaplicarlas más tarde).
 */
function markMigrationApplied(PDO $db, string $filename): void {
    ensureMigrationsTable($db);
    $path = MIGRATIONS_DIR . '/' . $filename;
    $checksum = is_file($path) ? hash('sha256', file_get_contents($path)) : null;
    $ins = $db->prepare("INSERT IGNORE INTO migraciones (archivo, duracion_ms, checksum) VALUES (?, 0, ?)");
    $ins->execute([$filename, $checksum]);
}

function markAllMigrationsAsApplied(PDO $db): int {
    $files = listMigrationFiles();
    foreach ($files as $f) markMigrationApplied($db, $f);
    return count($files);
}
