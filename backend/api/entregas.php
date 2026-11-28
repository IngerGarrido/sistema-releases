<?php
/**
 * API del módulo Entregas (con sesión de administrador).
 *
 *   GET    entregas.php                         → lista (?estado, ?cliente_id, ?proyecto_id, ?q)
 *   GET    entregas.php?id=N                    → detalle
 *   GET    entregas.php?accion=sugerir&proyecto_id=N → datos para una entrega nueva desde un proyecto
 *   GET    entregas.php?accion=checklist        → casillas de la lista de revisión
 *   GET    entregas.php?id=N&accion=acta_html|acta_pdf → ver el acta (panel)
 *   POST   entregas.php                         → crear
 *   PUT    entregas.php?id=N                    → editar (datos, checklist, notas)
 *   POST   entregas.php?id=N&accion=acta        → subir/reemplazar acta (multipart html + pdf)
 *   POST   entregas.php?id=N&accion=enviar      → correo al cliente { to, mensaje, forzar }
 *   POST   entregas.php?id=N&accion=link        → link público (?p=1 para vista previa)
 *   DELETE entregas.php?id=N                    → eliminar (y sus archivos)
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../lib/entregas.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    if (($user['rol'] ?? '') !== 'admin') err('Acceso denegado', 403);

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST' && isset($_GET['_method'])) $method = strtoupper($_GET['_method']);
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $accion = (string)($_GET['accion'] ?? '');

    $cargar = function (int $id) use ($db): array {
        $e = entCargar($db, $id);
        if (!$e) err('Entrega no encontrada', 404);
        return $e;
    };

    // ─── GET ──────────────────────────────────────────────────────────
    if ($method === 'GET') {
        if ($accion === 'checklist') ok(ENT_CHECKLIST);

        if ($accion === 'sugerir') {
            $pid = (int)($_GET['proyecto_id'] ?? 0);
            $st = $db->prepare("SELECT p.id, p.nombre, p.cliente_id, s.url_principal, s.nombre AS sitio_nombre
                                FROM proyectos p LEFT JOIN sitios s ON s.id = p.sitio_id WHERE p.id = ? AND p.deleted_at IS NULL");
            $st->execute([$pid]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) err('Proyecto no encontrado', 404);
            $ex = $db->prepare("SELECT id FROM entregas WHERE proyecto_id = ? ORDER BY id DESC LIMIT 1");
            $ex->execute([$pid]);
            // Cotización del proyecto: la aceptada más reciente; si no, la última.
            $cq = $db->prepare("SELECT id FROM cotizaciones WHERE proyecto_id = ? AND deleted_at IS NULL
                                ORDER BY (estado = 'Aceptada') DESC, id DESC LIMIT 1");
            $cq->execute([$pid]);
            $cotId = (int)$cq->fetchColumn();
            ok([
                'existente_id'  => ($x = $ex->fetchColumn()) ? (int)$x : null,
                'cliente_id'    => (int)$p['cliente_id'],
                'proyecto_id'   => (int)$p['id'],
                'cotizacion_id' => $cotId ?: null,
                'dominio'       => entDominio($p['url_principal']) ?: (string)($p['sitio_nombre'] ?? ''),
                'numero'        => $cotId ? (string)entNumeroDesdeCotizacion($db, $cotId) : '',
                'fecha_entrega' => cotAhora()->format('Y-m-d'),
                'plazo_dias_habiles' => 5,
            ]);
        }

        if ($id && in_array($accion, ['acta_html', 'acta_pdf'], true)) {
            $e = $cargar($id);
            if (!$e['html_path']) err('Esta entrega no tiene acta', 404);
            if ($accion === 'acta_html') dsEnviarHtml('actas', $e['html_path'], false);
            dsEnviarPdf('actas', $e['pdf_path'], 'Acta de entrega ' . $e['numero'] . '.pdf', false);
        }

        if ($id) {
            $e = $cargar($id);
            $out = entPublicoPanel($e);
            $out['checklist_faltan'] = entChecklistFaltan($e['checklist'], !empty($e['lista_no_aplica']));
            require_once __DIR__ . '/../lib/visitas.php';
            $out['visitas'] = visResumen($db, 'acta', [(int)$e['id']])[(int)$e['id']] ?? null;
            ok($out);
        }

        // Respaldo del cron: al abrir la lista también corren los procesos
        // automáticos (plazos, garantías, proyectos entregados…).
        require_once __DIR__ . '/../lib/procesos.php';
        procesosDiarios($db, (int)$user['id']);

        $where = ['1=1'];
        $params = [];
        if (!empty($_GET['estado']) && in_array($_GET['estado'], ENT_ESTADOS, true)) { $where[] = 'e.estado = ?'; $params[] = $_GET['estado']; }
        if (!empty($_GET['cliente_id'])) { $where[] = 'e.cliente_id = ?'; $params[] = (int)$_GET['cliente_id']; }
        if (!empty($_GET['proyecto_id'])) { $where[] = 'e.proyecto_id = ?'; $params[] = (int)$_GET['proyecto_id']; }
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(e.numero LIKE ? OR e.dominio LIKE ? OR cl.nombre LIKE ? OR cl.nombre_agencia LIKE ? OR p.nombre LIKE ?)';
            $like = '%' . escapeLike($q) . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $st = $db->prepare("SELECT e.id FROM entregas e
                            LEFT JOIN clientes cl ON cl.id = e.cliente_id
                            LEFT JOIN proyectos p ON p.id = e.proyecto_id
                            WHERE " . implode(' AND ', $where) . " ORDER BY e.id DESC LIMIT 500");
        $st->execute($params);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            $e = entCargar($db, (int)$eid);
            if (!$e) continue;
            $r = entPublicoPanel($e);
            $r['checklist_faltan'] = count(entChecklistFaltan($e['checklist'], !empty($e['lista_no_aplica'])));
            $rows[] = $r;
        }
        // Visitas del cliente al acta (cuántas veces, cuándo y desde qué dispositivo).
        require_once __DIR__ . '/../lib/visitas.php';
        $vis = visResumen($db, 'acta', array_column($rows, 'id'));
        foreach ($rows as &$r) $r['visitas'] = $vis[$r['id']] ?? null;
        unset($r);
        ok($rows);
    }

    // ─── Validación común de datos ────────────────────────────────────
    $leerDatos = function (array $b, ?array $actual) use ($db): array {
        $d = [];
        $tiene = fn($k) => array_key_exists($k, $b);
        if (!$actual || $tiene('cliente_id')) {
            $cid = (int)($b['cliente_id'] ?? 0);
            $c = $db->prepare("SELECT id FROM clientes WHERE id = ? AND deleted_at IS NULL");
            $c->execute([$cid]);
            if (!$c->fetchColumn()) err('Elige el cliente.');
            $d['cliente_id'] = $cid;
        }
        foreach (['proyecto_id' => 'proyectos', 'cotizacion_id' => 'cotizaciones'] as $k => $tabla) {
            if (!$tiene($k)) continue;
            $v = (int)($b[$k] ?? 0);
            if ($v) {
                $c = $db->prepare("SELECT cliente_id FROM $tabla WHERE id = ?");
                $c->execute([$v]);
                $duenio = $c->fetchColumn();
                if ($duenio === false) err($k === 'proyecto_id' ? 'El proyecto no existe.' : 'La cotización no existe.');
                $cli = $d['cliente_id'] ?? (int)($actual['cliente_id'] ?? 0);
                if ((int)$duenio !== $cli) err($k === 'proyecto_id' ? 'El proyecto es de otro cliente.' : 'La cotización es de otro cliente.');
            }
            $d[$k] = $v ?: null;
        }
        if ($tiene('numero') || !$actual) {
            $n = mb_substr(trim((string)($b['numero'] ?? '')), 0, 20);
            if ($n === '') {
                // Sin número escrito: el de la cotización (COT-2026-005 → ENT-2026-005).
                $cot = (int)($d['cotizacion_id'] ?? $actual['cotizacion_id'] ?? 0);
                $n = $cot ? (string)entNumeroDesdeCotizacion($db, $cot, (int)($actual['id'] ?? 0)) : '';
                if ($n === '') err('Sin cotización, escribe el número del acta (ej. ENT-2026-010).');
            }
            $u = $db->prepare("SELECT id FROM entregas WHERE numero = ? AND id <> ?");
            $u->execute([$n, (int)($actual['id'] ?? 0)]);
            if ($u->fetchColumn()) err("Ya existe una entrega con el número $n.");
            $d['numero'] = $n;
        }
        // Solo el dominio: «https://www.x.cl/» → «x.cl» (va en el asunto del correo y en el acta).
        if ($tiene('dominio')) $d['dominio'] = mb_substr(entDominio((string)$b['dominio']), 0, 150) ?: null;
        if ($tiene('fecha_entrega')) {
            $f = (string)($b['fecha_entrega'] ?? '');
            $d['fecha_entrega'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) ? $f : null;
        }
        if ($tiene('plazo_dias_habiles') || !$actual) {
            $pz = (int)($b['plazo_dias_habiles'] ?? 5);
            if ($pz < 1 || $pz > 30) err('El plazo debe estar entre 1 y 30 días hábiles.');
            $d['plazo_dias_habiles'] = $pz;
        }
        if ($tiene('garantia_meses')) {
            $gm = (int)$b['garantia_meses'];
            if ($gm < 0 || $gm > 60) err('La garantía debe estar entre 0 y 60 meses.');
            $d['garantia_meses'] = $gm;
        }
        if ($tiene('notas')) $d['notas'] = mb_substr(trim((string)$b['notas']), 0, 5000) ?: null;
        if ($tiene('lista_no_aplica')) $d['lista_no_aplica'] = !empty($b['lista_no_aplica']) ? 1 : 0;
        if ($tiene('checklist')) {
            $chk = [];
            $claves = entChecklistClaves();
            foreach ((array)$b['checklist'] as $k => $v) if (in_array($k, $claves, true) && $v) $chk[$k] = true;
            $d['checklist'] = json_encode((object)$chk);
        }
        return $d;
    };

    // ─── CREAR ────────────────────────────────────────────────────────
    if ($method === 'POST' && !$id) {
        $d = $leerDatos(body(), null);
        $d['token'] = cotNuevoToken();
        $d['estado'] = 'borrador';
        $db->prepare("INSERT INTO entregas (" . implode(', ', array_keys($d)) . ") VALUES (" . implode(', ', array_fill(0, count($d), '?')) . ")")
           ->execute(array_values($d));
        $nuevo = (int)$db->lastInsertId();
        audit($db, $user['id'], 'CREATE', 'entregas', "Creó la entrega {$d['numero']}", $nuevo);
        ok(entPublicoPanel(entCargar($db, $nuevo)) + ['checklist_faltan' => entChecklistFaltan($d['checklist'] ?? null)]);
    }

    if (!$id) err('Falta el id', 400);
    $e = $cargar($id);
    $recibida = in_array($e['estado'], ['recibida', 'recibida_por_plazo'], true);

    // ─── EDITAR ───────────────────────────────────────────────────────
    if ($method === 'PUT') {
        $d = $leerDatos(body(), $e);
        // Ya enviada: los datos que vio el cliente no cambian (sí notas y checklist).
        if ($e['estado'] !== 'borrador') {
            $d = array_intersect_key($d, array_flip(['notas', 'checklist', 'lista_no_aplica', 'dominio', 'cotizacion_id', 'proyecto_id', 'garantia_meses']));
        }
        // Si cambian los meses, el aviso de fin de garantía se vuelve a calcular.
        if (isset($d['garantia_meses']) && (int)$d['garantia_meses'] !== (int)($e['garantia_meses'] ?? -1)) $d['garantia_aviso_at'] = null;
        if ($d) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($d)));
            $db->prepare("UPDATE entregas SET $sets WHERE id = ?")->execute([...array_values($d), $id]);
        }
        $n = entCargar($db, $id);
        ok(entPublicoPanel($n) + ['checklist_faltan' => entChecklistFaltan($n['checklist'], !empty($n['lista_no_aplica']))]);
    }

    // ─── ACTA (HTML + PDF) ────────────────────────────────────────────
    if ($method === 'POST' && $accion === 'acta') {
        if (!in_array($e['estado'], ['borrador', 'con_observaciones'], true)) {
            err('El acta ya fue enviada: solo se puede reemplazar en borrador o cuando el cliente dejó observaciones.', 409);
        }
        // Reemplazo: basta con subir el archivo que cambió (el otro se conserva).
        // Misma función que la integración con Claude (lib/doc_subido.php).
        try {
            dsGuardarActa($db, $e, $_FILES['html'] ?? null, $_FILES['pdf'] ?? null, (bool)$e['html_path'], 'manual');
        } catch (DomainException $ex) {
            err($ex->getMessage());
        } catch (DsConflicto $ex) {
            err($ex->getMessage(), 409);
        }
        audit($db, $user['id'], 'UPDATE', 'entregas', ($e['html_path'] ? 'Reemplazó' : 'Subió') . " el acta de la entrega {$e['numero']}", $id);
        ok(entPublicoPanel(entCargar($db, $id)));
    }

    // ─── LINK ─────────────────────────────────────────────────────────
    if ($method === 'POST' && $accion === 'link') {
        ok(['link' => entLink($e['token']), 'preview' => entLink($e['token']) . '?p=1']);
    }

    // ─── ENVIAR ───────────────────────────────────────────────────────
    if ($method === 'POST' && $accion === 'enviar') {
        rateLimit('ent.enviar', 30, 3600);
        if ($recibida) err('Esta entrega ya fue recibida.', 409);
        if (!$e['html_path'] || !$e['pdf_path']) err('Sube el acta (HTML y PDF) antes de enviarla.', 409);
        if (!correoConfigurado($db)) err('Primero configura el correo en Configuración → Correo.', 409);
        $b = body();
        // Uno o varios correos separados por coma.
        $destinos = correoDestinos((string)($b['to'] ?? ''));
        if (!$destinos) err('Escribe uno o más correos válidos, separados por coma (máximo ' . CORREO_MAX_DESTINOS . ').');
        $to = implode(', ', $destinos);
        $noAplica = !empty($e['lista_no_aplica']);
        $faltan = entChecklistFaltan($e['checklist'], $noAplica);
        if ($faltan && empty($b['forzar'])) err('Faltan ' . count($faltan) . ' casillas de la lista de revisión.', 409);
        entRecordarUrl($db);
        try {
            entCorreoEnvio($db, $e, $to, mb_substr(trim((string)($b['mensaje'] ?? '')), 0, 2000));
        } catch (Throwable $ex) {
            err('No se pudo enviar el correo: ' . $ex->getMessage(), 502);
        }
        // Cliente sin correo en su ficha: se guarda el primero (si Inger lo pidió).
        if (!empty($b['guardar_en_cliente'])) clienteGuardarCorreoSiFalta($db, (int)$e['cliente_id'], $destinos[0]);
        $ahora = cotAhora();
        $vence = entSumarHabiles($ahora, (int)$e['plazo_dias_habiles'], $db)->format('Y-m-d');
        $db->prepare("UPDATE entregas SET estado = 'enviada', enviada_at = ?, enviada_a = ?, plazo_vence = ?,
                             fecha_entrega = COALESCE(fecha_entrega, ?) WHERE id = ?")
           ->execute([$ahora->format('Y-m-d H:i:s'), $to, $vence, $ahora->format('Y-m-d'), $id]);
        audit($db, $user['id'], 'UPDATE', 'entregas', "Envió la entrega {$e['numero']} a $to" . ($noAplica ? ' (lista de revisión: no aplica)' : ($faltan ? ' (con ' . count($faltan) . ' casillas sin marcar)' : '')), $id);
        $n = entCargar($db, $id);
        ok(entPublicoPanel($n) + ['checklist_faltan' => $faltan]);
    }

    // ─── ELIMINAR ─────────────────────────────────────────────────────
    if ($method === 'DELETE') {
        $db->prepare("DELETE FROM entregas WHERE id = ?")->execute([$id]);
        dsBorrar('actas', array_filter([$e['html_path'], $e['pdf_path']]));
        audit($db, $user['id'], 'HARD_DELETE', 'entregas', "Eliminó la entrega {$e['numero']}", $id);
        ok(['eliminada' => true]);
    }

    err('Método no permitido', 405);
} catch (Throwable $e) {
    errSafe($e, 500, 'entregas');
}
