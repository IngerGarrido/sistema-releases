<?php
/**
 * Acciones rápidas del Panel y la ficha del cliente (lib/cobro_correo.php):
 *   GET  ?accion=recordar&cuota=N   → vista previa del recordatorio (a quién y qué dice)
 *   POST ?accion=recordar&cuota=N   → lo envía { to?, texto? }
 *   POST ?accion=renovar            → { sitio_id, que: 'dominio'|'hosting' } corre la fecha un año
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
cors();

try {
    $db = getDB();
    $user = requireAuth();
    require_once __DIR__ . '/../lib/cobro_correo.php';
    $metodo = $_SERVER['REQUEST_METHOD'];
    $accion = (string)($_GET['accion'] ?? '');

    if ($accion === 'recordar') {
        $cuotaId = (int)($_GET['cuota'] ?? 0);
        if ($metodo === 'GET') {
            $r = cobroRecordatorio($db, $cuotaId);
            if (!$r) err('Esa cuota ya no tiene saldo por cobrar.', 404);
            ok($r + ['correo_configurado' => correoConfigurado($db), 'cada_dias' => COBRO_RECORDAR_CADA_DIAS]);
        }
        if ($metodo === 'POST') {
            rateLimit('cobro.recordar', 20, 3600);
            if (!correoConfigurado($db)) err('Configura primero el correo en Configuración → Correo.', 409);
            $b = body();
            try {
                $r = cobroRecordar($db, $cuotaId, isset($b['to']) ? (string)$b['to'] : null, isset($b['texto']) ? (string)$b['texto'] : null);
            } catch (RuntimeException $e) {
                err($e->getMessage(), 422);
            }
            audit($db, (int)$user['id'], 'UPDATE', 'pagos', 'Recordó por correo la boleta ' . ($r['boleta'] ?: '(sin número)') . " a {$r['cliente']}", (int)$r['pago_id']);
            ok(['enviado_a' => $r['enviado_a']]);
        }
    }

    if ($accion === 'renovar' && $metodo === 'POST') {
        $b = body();
        $que = (string)($b['que'] ?? '');
        $sitioId = (int)($b['sitio_id'] ?? 0);
        $nueva = sitioMarcarRenovado($db, $sitioId, $que);
        if (!$nueva) err('Ese sitio no tiene fecha de ' . ($que === 'hosting' ? 'hosting' : 'dominio') . '.', 404);
        audit($db, (int)$user['id'], 'UPDATE', 'sitios', "Marcó renovado el $que del sitio #$sitioId (vence el $nueva)", $sitioId);
        ok(['vence' => $nueva]);
    }

    err('Acción no válida', 400);
} catch (Throwable $e) {
    errSafe($e, 500, 'panel_acciones.php');
}
