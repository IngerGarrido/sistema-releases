<?php
/**
 * Configuración → Integraciones (con sesión de administrador).
 *
 *   GET  integraciones.php                         → { llaves, actividad (últimas 50) }
 *   POST integraciones.php?accion=crear  { nombre } → la llave en claro (se muestra UNA vez)
 *   POST integraciones.php?accion=revocar&id=N     → revoca la llave
 *
 * La llave en claro nunca se guarda: solo su SHA-256 y el prefijo para reconocerla.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../lib/integracion.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    if (($user['rol'] ?? '') !== 'admin') err('Acceso denegado', 403);

    $method = $_SERVER['REQUEST_METHOD'];
    $accion = (string)($_GET['accion'] ?? '');

    if ($method === 'GET') {
        $llaves = $db->query("SELECT id, nombre, prefijo, permisos, creada_at, ultimo_uso_at, ultimo_uso_ip, revocada_at
                              FROM api_llaves ORDER BY revocada_at IS NOT NULL, id DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($llaves as &$l) {
            $l['id'] = (int)$l['id'];
            $l['permisos'] = (array)json_decode((string)$l['permisos'], true);
        }
        unset($l);
        $actividad = $db->query("SELECT r.id, r.endpoint, r.numero, r.resultado, r.ip, r.fecha, l.nombre AS llave
                                 FROM api_registro r LEFT JOIN api_llaves l ON l.id = r.llave_id
                                 ORDER BY r.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($actividad as &$a) $a['id'] = (int)$a['id'];
        unset($a);
        ok(['llaves' => $llaves, 'actividad' => $actividad]);
    }

    if ($method === 'POST' && $accion === 'crear') {
        rateLimit('integracion.crear', 20, 3600);
        $nombre = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)(body()['nombre'] ?? ''))), 0, 80);
        if ($nombre === '') err('Ponle un nombre a la llave (ej. «Claude Code — MacBook»).');
        $llave = igNuevaLlave();
        $db->prepare("INSERT INTO api_llaves (nombre, llave_hash, prefijo, permisos, creada_por, creada_at) VALUES (?, ?, ?, ?, ?, NOW())")
           ->execute([$nombre, hash('sha256', $llave), substr($llave, 0, 8), json_encode(IG_PERMISOS), (int)$user['id']]);
        $id = (int)$db->lastInsertId();
        audit($db, (int)$user['id'], 'CREATE', 'api_llaves', "Creó la llave de API «{$nombre}»", $id);
        ok(['id' => $id, 'nombre' => $nombre, 'llave' => $llave]);
    }

    if ($method === 'POST' && $accion === 'revocar') {
        $id = (int)($_GET['id'] ?? 0);
        $st = $db->prepare("SELECT nombre FROM api_llaves WHERE id = ? AND revocada_at IS NULL");
        $st->execute([$id]);
        $nombre = $st->fetchColumn();
        if ($nombre === false) err('La llave no existe o ya estaba revocada.', 404);
        $db->prepare("UPDATE api_llaves SET revocada_at = NOW() WHERE id = ? AND revocada_at IS NULL")->execute([$id]);
        audit($db, (int)$user['id'], 'UPDATE', 'api_llaves', "Revocó la llave de API «{$nombre}»", $id);
        ok(['revocada' => true]);
    }

    err('Método no permitido', 405);
} catch (Throwable $e) {
    errSafe($e, 500, 'integraciones');
}
