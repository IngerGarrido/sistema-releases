<?php
require_once __DIR__ . '/../config.php';
cors();

// Backups y restores pueden tardar varios minutos en bases grandes.
// Subimos el límite de ejecución a 5 minutos (default PHP = 30s).
@set_time_limit(300);
@ini_set('memory_limit', '256M');

$backupDir = __DIR__ . '/../backups';
if (!is_dir($backupDir)) mkdir($backupDir, 0750, true);

/**
 * Genera SQL en streaming (escribe directo al handle, no acumula en RAM).
 */
function streamBackupSQL(PDO $db, string $dbName, $fh): void {
    $w = function($s) use ($fh) { fwrite($fh, $s); };

    $w("-- Backup SQL\n-- Base: `{$dbName}`\n-- Fecha: " . date('Y-m-d H:i:s') . "\n");
    $w("SET SQL_MODE=\"NO_AUTO_VALUE_ON_ZERO\";\nSET time_zone=\"+00:00\";\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables = $db->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $tableRow) {
        $table = $tableRow[0];
        $qt = '`' . str_replace('`', '``', $table) . '`';
        $w("-- Tabla {$qt}\nDROP TABLE IF EXISTS {$qt};\n");

        $create = $db->query('SHOW CREATE TABLE ' . $qt)->fetch(PDO::FETCH_ASSOC);
        $w(($create['Create Table'] ?? array_values($create)[1]) . ";\n\n");

        $stmt = $db->query('SELECT * FROM ' . $qt);
        $cols = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $cols[] = '`' . str_replace('`', '``', $stmt->getColumnMeta($i)['name']) . '`';
        }
        $colsStr = implode(', ', $cols);

        $batch = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string)$v), $row);
            $batch[] = '(' . implode(', ', $vals) . ')';
            if (count($batch) >= 100) {
                $w("INSERT INTO {$qt} ({$colsStr}) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if (!empty($batch)) {
            $w("INSERT INTO {$qt} ({$colsStr}) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        $w("\n");
    }
    $w("SET FOREIGN_KEY_CHECKS=1;\n");
}

function safeBackupName(string $file, string $backupDir): ?string {
    $file = basename(preg_replace('/[^a-zA-Z0-9_.-]/', '', $file));
    if (!str_ends_with($file, '.sql')) $file .= '.sql';
    $real = realpath($backupDir . '/' . $file);
    if ($real === false) return null;
    // Asegurar que sigue dentro del backupDir (anti path traversal)
    if (strpos($real, realpath($backupDir)) !== 0) return null;
    return $real;
}

try {
    $db = getDB();
    $user = requireAuth();
    if (($user['rol'] ?? '') !== 'admin') err('No autorizado', 403);

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $dbName = DB_NAME;

    // ----- POST action=restore : restaurar backup -----
    if ($method === 'POST' && $action === 'restore') {
        $b = body();
        $file = $b['file'] ?? '';
        $real = safeBackupName($file, $backupDir);
        if (!$real || !is_file($real)) err('Archivo no encontrado', 404);

        // Snapshot previo de seguridad
        $snap = $backupDir . '/pre_restore_' . date('Y-m-d_H-i-s') . '.sql';
        $fh = fopen($snap, 'w');
        if ($fh) { streamBackupSQL($db, $dbName, $fh); fclose($fh); }

        // Ejecutar restore (statements separados por ;)
        $sql = file_get_contents($real);
        $db->exec("SET FOREIGN_KEY_CHECKS=0");
        // Dividir por ; al final de línea (heurística suficiente para backups generados por este script)
        $statements = preg_split('/;\s*\n/', $sql);
        $count = 0;
        foreach ($statements as $stmt) {
            // Quitar líneas de comentario SQL ("-- ...") manteniendo el SQL real.
            // ANTES descartábamos el statement completo si empezaba con "--",
            // pero los comentarios pueden preceder al DROP/CREATE en la misma
            // pieza después del split, dejando tablas sin recrear.
            $stmt = preg_replace('/^\s*--[^\n]*\n?/m', '', $stmt);
            $stmt = trim($stmt);
            if ($stmt === '') continue;
            $db->exec($stmt);
            $count++;
        }
        $db->exec("SET FOREIGN_KEY_CHECKS=1");

        audit($db, (int)$user['id'], 'backup.restore', 'backup', 'Restaurado: ' . basename($real) . ' (snapshot previo: ' . basename($snap) . ')');
        ok(['restored' => basename($real), 'statements' => $count, 'snapshot' => basename($snap)]);
    }

    // ----- GET: listar o descargar -----
    if ($method === 'GET') {
        $file = $_GET['file'] ?? null;

        if ($file) {
            $real = safeBackupName($file, $backupDir);
            if (!$real || !is_file($real)) err('Archivo no encontrado', 404);

            audit($db, (int)$user['id'], 'backup.download', 'backup', 'Descarga: ' . basename($real));

            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . basename($real) . '"');
            header('Content-Length: ' . filesize($real));
            header('X-Checksum-SHA256: ' . hash_file('sha256', $real));
            header('Cache-Control: no-store');
            readfile($real);
            exit;
        }

        // Listar
        $files = glob($backupDir . '/*.sql') ?: [];
        $backups = [];
        foreach ($files as $fp) {
            $stat = stat($fp);
            $backups[] = [
                'filename'       => basename($fp),
                'size'           => $stat['size'],
                'size_formatted' => number_format($stat['size'] / 1024, 2) . ' KB',
                'created_at'     => date('Y-m-d H:i:s', $stat['mtime']),
                'timestamp'      => $stat['mtime'],
                'sha256'         => hash_file('sha256', $fp),
            ];
        }
        usort($backups, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
        ok(['backups' => $backups]);
    }

    // ----- POST: generar nuevo backup -----
    if ($method === 'POST') {
        rateLimit('backup.create', 5, 600); // máx 5 backups manuales / 10 min por IP
        $filename = 'backup_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $dbName) . '_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $backupDir . '/' . $filename;

        $fh = fopen($filepath, 'w');
        if (!$fh) err('No se pudo abrir archivo para escritura', 500);
        streamBackupSQL($db, $dbName, $fh);
        fclose($fh);

        $stat = stat($filepath);
        $sha = hash_file('sha256', $filepath);
        audit($db, (int)$user['id'], 'backup.create', 'backup', "Backup creado: {$filename} ({$stat['size']} bytes, sha256={$sha})");

        ok([
            'message' => 'Backup generado',
            'backup' => [
                'filename'       => $filename,
                'size'           => $stat['size'],
                'size_formatted' => number_format($stat['size'] / 1024, 2) . ' KB',
                'created_at'     => date('Y-m-d H:i:s', $stat['mtime']),
                'sha256'         => $sha,
            ]
        ]);
    }

    // ----- DELETE -----
    if ($method === 'DELETE') {
        $file = $_GET['file'] ?? null;
        if (!$file) err('Archivo no especificado');
        $real = safeBackupName($file, $backupDir);
        if (!$real || !is_file($real)) err('Archivo no encontrado', 404);

        if (!unlink($real)) err('Error al eliminar', 500);
        audit($db, (int)$user['id'], 'backup.delete', 'backup', 'Eliminado: ' . basename($real));
        ok(['message' => 'Backup eliminado']);
    }

    err('Método no permitido', 405);

} catch (Throwable $e) {
    errSafe($e, 500, 'backup.php');
}
