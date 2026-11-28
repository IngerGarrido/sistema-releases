<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

function nextNumero(PDO $db) {
    // Asume que la transacción está abierta por el caller.
    $pre = (string)$db->query("SELECT valor FROM configuracion WHERE clave = 'cotizacion_prefijo'")->fetchColumn();
    if ($pre === false || $pre === '') $pre = 'COT';
    $year = date('Y');

    // 1) Lee el contador (FOR UPDATE: bloquea para evitar duplicados en POSTs paralelos)
    $st = $db->prepare("SELECT CAST(valor AS UNSIGNED) AS num FROM configuracion WHERE clave = 'cotizacion_siguiente' FOR UPDATE");
    $st->execute();
    $confNum = (int)($st->fetchColumn() ?: 1);

    // 2) Mira el mayor número realmente existente este año en cotizaciones.
    //    Si el contador quedó atrasado (p.ej. se reseteó a 1 pero ya había
    //    cotizaciones), usamos max+1 para no chocar con la clave UNIQUE.
    $st2 = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(numero, '-', -1) AS UNSIGNED)) AS maxn
                         FROM cotizaciones
                         WHERE numero LIKE ?");
    $st2->execute([$pre . '-' . $year . '-%']);
    $maxn = (int)($st2->fetchColumn() ?: 0);

    $num = max($confNum, $maxn + 1);

    return $pre . '-' . $year . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../lib/cotizacion_doc.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];
    // Soporte para method override via query string (POST con _method=PUT)
    if ($method === 'POST' && isset($_GET['_method'])) {
        $method = strtoupper($_GET['_method']);
    }

    if ($user['rol'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if (!$id) {
        $path = explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $last = end($path);
        $id = is_numeric($last) ? (int)$last : null;
    }

    // Detectar acción especial
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $esRestore = strpos($uri, 'restore') !== false || isset($_GET['restore']);
    $esHard = isset($_GET['hard']) && $_GET['hard'] == '1';
    $esPapelera = isset($_GET['papelera']) && $_GET['papelera'] == '1';

    $limpiar = function($str) {
        return trim(str_replace("\0", "", (string)($str ?? '')));
    };

    // --- PROPUESTA (opcional): ver con sesión desde el panel ---
    $accionG = $_GET['accion'] ?? '';
    if ($method === 'GET' && $id && in_array($accionG, ['propuesta_html', 'propuesta_pdf'], true)) {
        require_once __DIR__ . '/../lib/doc_subido.php';
        $st = $db->prepare("SELECT numero, propuesta_numero, propuesta_html_path, propuesta_pdf_path FROM cotizaciones WHERE id = ?");
        $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c || !$c['propuesta_html_path']) err('Esta cotización no tiene propuesta', 404);
        if ($accionG === 'propuesta_html') dsEnviarHtml('propuestas', $c['propuesta_html_path'], false);
        dsEnviarPdf('propuestas', $c['propuesta_pdf_path'], 'Propuesta ' . ($c['propuesta_numero'] ?: $c['numero']) . '.pdf', false);
    }

    // --- GET: LISTAR O VER DETALLE ---
   if ($method === 'GET') {
        if ($id) {
            // (Esta parte para ver una sola cotización está bien)
            $sql = "SELECT * FROM cotizaciones WHERE id = ?";
            $s = $db->prepare($sql);
            $s->execute([$id]);
            $cot = $s->fetch(PDO::FETCH_ASSOC);

            if (!$cot) {
                echo json_encode(['ok' => false, 'error' => 'No encontrada']);
                exit;
            }

            $cot['id'] = (int)$cot['id'];
            $cot['cliente_id'] = (int)$cot['cliente_id'];
            $cot['proyecto_id'] = !empty($cot['proyecto_id']) ? (int)$cot['proyecto_id'] : null;
            $cot['plantilla_id'] = !empty($cot['plantilla_id']) ? (int)$cot['plantilla_id'] : null;

            $si = $db->prepare("SELECT * FROM cotizacion_items WHERE cotizacion_id = ? ORDER BY orden");
            $si->execute([$id]);
            $items = $si->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as &$it) {
                $it['id'] = (int)$it['id'];
                $it['servicio_id'] = !empty($it['servicio_id']) ? (int)$it['servicio_id'] : null;
                $it['cantidad'] = (float)($it['cantidad'] ?? 1);
                $it['precio_unitario'] = (float)($it['precio_unitario'] ?? 0);
            }
            
            $cot['items'] = $items;
            $cot['link'] = !empty($cot['token']) ? cotLinkPublico($cot['token']) : null;
            $cot['propuesta'] = cotPropuestaInfo($cot);
            unset($cot['token'], $cot['aprobada_user_agent'], $cot['propuesta_html_path'], $cot['propuesta_pdf_path'],
                  $cot['propuesta_hash'], $cot['propuesta_hojas'], $cot['propuesta_vista_at'], $cot['propuesta_numero'],
                  $cot['propuesta_origen'], $cot['propuesta_subida_at']);
            echo json_encode(['ok' => true, 'data' => $cot]);
            exit;
        }

        // --- LISTADO GENERAL CON PAGINACIÓN ---
        $where = $esPapelera ? "WHERE c.deleted_at IS NOT NULL" : "WHERE c.deleted_at IS NULL";

        // Búsqueda server-side
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $params = [];
        if ($q !== '') {
            $where .= " AND (c.numero LIKE ? OR cl.nombre LIKE ? OR cl.nombre_agencia LIKE ? OR p.nombre LIKE ?)";
            $like = '%' . escapeLike($q) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        // Base sin el filtro de estado: para el número de cada pestaña.
        $whereBase = $where; $paramsBase = $params;

        // Filtro por estado (server-side). "Por vencer" es un filtro virtual:
        // cotizaciones pendientes cuya validez está próxima o ya vencida.
        $estado = isset($_GET['estado']) ? trim((string)$_GET['estado']) : '';
        if ($estado === 'Por vencer') {
            $where .= " AND c.estado IN ('Pendiente','Enviada')
                        AND c.fecha_validez IS NOT NULL
                        AND c.fecha_validez <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($estado !== '' && $estado !== 'Todos') {
            $where .= " AND c.estado = ?";
            $params[] = $estado;
        }

        // Solo las de un cliente (selector del formulario de Entregas).
        if (!empty($_GET['cliente_id'])) { $where .= " AND c.cliente_id = ?"; $params[] = (int)$_GET['cliente_id']; }

        // Grupo (?grupo=): 'abiertas' = lo que pide acción (sin enviar, esperando respuesta
        // o aprobada sin proyecto): se traen completas, nunca son muchas. 'cerradas' = el
        // resto (historial): se pide de a una página.
        $abierta = "(c.estado IN ('Pendiente','Enviada') OR (c.estado = 'Aceptada' AND (c.proyecto_id IS NULL OR c.proyecto_id = 0)))";
        $grupo = (string)($_GET['grupo'] ?? '');
        if ($grupo === 'abiertas')     $where .= " AND {$abierta}";
        elseif ($grupo === 'cerradas') $where .= " AND NOT {$abierta}";
        // Rango de fechas (fecha de la cotización), en el servidor.
        foreach (['desde' => '>=', 'hasta' => '<='] as $k => $op) {
            $v = (string)($_GET[$k] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $where .= " AND c.fecha {$op} ?"; $params[] = $v; }
        }

        // 1. Lógica de Paginación con cap a 100 (consistencia con otros endpoints)
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $per_page = isset($_GET['per_page']) ? max(1, min(1000, (int)$_GET['per_page'])) : 10;
        $offset = ($page - 1) * $per_page;

        // 2. Contar Total (con JOINs por filtro server-side)
        $countSql = "SELECT COUNT(*) FROM cotizaciones c
                     LEFT JOIN clientes cl ON cl.id = c.cliente_id
                     LEFT JOIN proyectos p ON p.id = c.proyecto_id
                     {$where}";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = $countStmt->fetchColumn();
        $totalPages = (int)ceil($totalItems / $per_page);

        // 3. Consulta con LIMIT y OFFSET
        $sql = "SELECT c.id, c.numero, c.fecha, c.fecha_validez, c.estado, c.proyecto_id, c.cliente_id, c.deleted_at,
                       c.enviada_at, c.vista_at, c.aprobada_at, c.aprobada_nombre, (c.token IS NOT NULL) AS tiene_link,
                       c.propuesta_numero, (c.propuesta_html_path IS NOT NULL) AS tiene_propuesta,
                       c.respuesta_tipo, c.respuesta_motivo, c.respuesta_texto, c.respuesta_at,
                       cl.nombre as cliente_nom, cl.email as cliente_email,
                       cl.nombre_agencia as cliente_age, p.nombre as proyecto_nom, sp.nombre as sitio_nom,
                       /* Total real = neto - descuento global. Antes mostraba
                          sólo el neto, que confundía al usuario. */
                       ROUND(
                         COALESCE((SELECT SUM(ci.cantidad * ci.precio_unitario)
                                   FROM cotizacion_items ci WHERE ci.cotizacion_id = c.id), 0)
                         * (1 - COALESCE(c.descuento_global, 0) / 100)
                       , 0) as total_calculado
                FROM cotizaciones c
                LEFT JOIN clientes cl ON cl.id = c.cliente_id
                LEFT JOIN proyectos p ON p.id = c.proyecto_id
                LEFT JOIN sitios sp ON sp.id = p.sitio_id
                {$where}
                ORDER BY c.id DESC
                LIMIT $per_page OFFSET $offset";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = [];
        while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cliente_display = !empty($r['cliente_age']) ? $limpiar($r['cliente_age']) : $limpiar($r['cliente_nom']);
            
            $data[] = [
                'id'              => (int)$r['id'],
                'numero'          => $limpiar($r['numero']),
                'fecha'           => $r['fecha'],
                'fecha_validez'   => $r['fecha_validez'],
                'estado'          => $limpiar($r['estado']),
                'proyecto_id'     => !empty($r['proyecto_id']) ? (int)$r['proyecto_id'] : 0,
                'cliente_id'      => (int)$r['cliente_id'],
                'cliente_nombre'  => $cliente_display,
                'proyecto_nombre' => $limpiar($r['proyecto_nom'] ?? 'Sin proyecto'),
                // "Sitio – Proyecto" para distinguir varias cotizaciones del mismo cliente
                'proyecto_label'  => !empty($r['proyecto_nom']) ? etiquetaProyecto($limpiar($r['proyecto_nom']), $r['sitio_nom'] ? $limpiar($r['sitio_nom']) : null) : null,
                'total'           => (float)($r['total_calculado'] ?? 0),
                'deleted_at'      => $r['deleted_at'],
                'enviada_at'      => $r['enviada_at'],
                'vista_at'        => $r['vista_at'],
                'aprobada_at'     => $r['aprobada_at'],
                'aprobada_nombre' => $r['aprobada_nombre'],
                'tiene_link'      => (bool)$r['tiene_link'],
                'cliente_email'   => (string)($r['cliente_email'] ?? ''),
                'tiene_propuesta' => (bool)$r['tiene_propuesta'],
                'propuesta_numero'=> $r['propuesta_numero'],
                // Respuesta del cliente desde el link (sin aprobar): comentario o rechazo.
                'respuesta'       => $r['respuesta_tipo'] ? [
                    'tipo' => $r['respuesta_tipo'], 'texto' => (string)$r['respuesta_texto'], 'at' => $r['respuesta_at'],
                    'motivo' => $r['respuesta_motivo'] ? (COT_MOTIVOS[$r['respuesta_motivo']] ?? $r['respuesta_motivo']) : null,
                ] : null,
            ];
        }

        // Número por pestaña (respeta la búsqueda, no el estado).
        $conteo = null;
        if (!$esPapelera) {
            $from = "FROM cotizaciones c LEFT JOIN clientes cl ON cl.id = c.cliente_id LEFT JOIN proyectos p ON p.id = c.proyecto_id";
            $st = $db->prepare("SELECT c.estado, COUNT(*) n {$from} {$whereBase} GROUP BY c.estado");
            $st->execute($paramsBase);
            $conteo = ['Todos' => 0];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $conteo[$r['estado']] = (int)$r['n']; $conteo['Todos'] += (int)$r['n']; }
            $st = $db->prepare("SELECT COUNT(*) {$from} {$whereBase} AND c.estado IN ('Pendiente','Enviada')
                                AND c.fecha_validez IS NOT NULL AND c.fecha_validez <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
            $st->execute($paramsBase);
            $conteo['Por vencer'] = (int)$st->fetchColumn();
        }

        // Visitas del cliente al link (cuántas veces, cuándo y desde qué dispositivo).
        require_once __DIR__ . '/../lib/visitas.php';
        $vis = visResumen($db, 'cotizacion', array_column($data, 'id'));
        foreach ($data as &$fila) $fila['visitas'] = $vis[$fila['id']] ?? null;
        unset($fila);

        // 4. Respuesta con el objeto pagination (Exactamente como lo espera tu React)
        echo json_encode([
            'ok' => true, 
            'data' => $data,
            'conteo' => $conteo,
            'pagination' => [
                'total' => (int)$totalItems,
                'page' => (int)$page,
                'per_page' => (int)$per_page,
                'total_pages' => (int)$totalPages
            ]
        ]);
        exit;
    }

    // --- RESTAURAR COTIZACIÓN ---
    // DEBE IR ANTES de POST/PUT para que no se confunda
    if ($method === 'POST' && $esRestore && $id) {
        $stmt = $db->prepare("UPDATE cotizaciones SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            audit($db, $user['id'], 'RESTORE', 'cotizaciones', "Restauró cotización #{$id}", $id);
            echo json_encode(['ok' => true, 'message' => 'Cotización restaurada']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Cotización no encontrada o no estaba eliminada']);
        }
        exit;
    }

    $accion = $_GET['accion'] ?? '';

    // --- LINK DE APROBACIÓN: lo crea si no existe y marca la cotización como Enviada ---
    if ($method === 'POST' && $accion === 'link' && $id) {
        $st = $db->prepare("SELECT id, numero, token, estado, aprobada_at FROM cotizaciones WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) err('Cotización no encontrada', 404);
        $token = $c['token'];
        if (!$token) {
            $token = cotNuevoToken();
            $db->prepare("UPDATE cotizaciones SET token = ? WHERE id = ? AND token IS NULL")->execute([$token, $id]);
        }
        if ($c['estado'] === 'Pendiente' && !$c['aprobada_at']) {
            $db->prepare("UPDATE cotizaciones SET estado = 'Enviada', enviada_at = COALESCE(enviada_at, NOW()) WHERE id = ?")->execute([$id]);
            audit($db, $user['id'], 'UPDATE', 'cotizaciones', "Envió link de aprobación de cotización #{$c['numero']}", $id);
        }
        ok(['link' => cotLinkPublico($token)]);
    }

    // --- PROPUESTA (opcional): subir / reemplazar (multipart: numero, html, pdf) o quitar ---
    if ($id && $accion === 'propuesta' && in_array($method, ['POST', 'DELETE'], true)) {
        require_once __DIR__ . '/../lib/doc_subido.php';
        $st = $db->prepare("SELECT id, numero, aprobada_at, propuesta_numero, propuesta_html_path, propuesta_pdf_path FROM cotizaciones WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) err('Cotización no encontrada', 404);
        // Aprobada → la propuesta queda bloqueada igual que la cotización.
        if ($c['aprobada_at']) err('La cotización fue aprobada en línea: su propuesta ya no se puede cambiar ni quitar. Crea una nueva versión.', 409);
        $viejas = array_filter([$c['propuesta_html_path'], $c['propuesta_pdf_path']]);

        if ($method === 'DELETE') {
            $db->prepare("UPDATE cotizaciones SET propuesta_numero = NULL, propuesta_html_path = NULL, propuesta_pdf_path = NULL,
                                 propuesta_hash = NULL, propuesta_hojas = NULL, propuesta_vista_at = NULL,
                                 propuesta_origen = NULL, propuesta_subida_at = NULL
                          WHERE id = ? AND aprobada_at IS NULL")->execute([$id]);
            dsBorrar('propuestas', $viejas);
            audit($db, $user['id'], 'UPDATE', 'cotizaciones', "Quitó la propuesta de la cotización #{$c['numero']}", $id);
            ok(['propuesta' => null]);
        }

        $numero = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['numero'] ?? ''))), 0, 20);
        $vienenArchivos = !empty($_FILES['html']) || !empty($_FILES['pdf']);
        if (!$vienenArchivos && !$c['propuesta_html_path']) err('Sube el HTML y el PDF de la propuesta.');
        if ($vienenArchivos) {
            // Reemplazo: basta con subir el archivo que cambió (el otro se conserva).
            // Misma función que la integración con Claude (lib/doc_subido.php).
            try {
                dsGuardarPropuesta($db, $c, $_FILES['html'] ?? null, $_FILES['pdf'] ?? null,
                                   (bool)$c['propuesta_html_path'], $numero, 'manual');
            } catch (DomainException $e) {
                err($e->getMessage());
            } catch (DsConflicto $e) {
                err($e->getMessage(), 409);
            }
            audit($db, $user['id'], 'UPDATE', 'cotizaciones', ($viejas ? 'Reemplazó' : 'Adjuntó') . " la propuesta " . ($numero ?: '') . " de la cotización #{$c['numero']}", $id);
        } else {
            $db->prepare("UPDATE cotizaciones SET propuesta_numero = ? WHERE id = ? AND aprobada_at IS NULL")->execute([$numero ?: null, $id]);
        }
        $r = $db->prepare("SELECT * FROM cotizaciones WHERE id = ?");
        $r->execute([$id]);
        ok(['propuesta' => cotPropuestaInfo($r->fetch(PDO::FETCH_ASSOC))]);
    }

    // --- ENVIAR AL CLIENTE: correo con el link de aprobación { to, mensaje } ---
    if ($method === 'POST' && $accion === 'enviar' && $id) {
        require_once __DIR__ . '/../lib/cotizacion_correo.php';
        rateLimit('cot.enviar', 30, 3600);
        if (!correoConfigurado($db)) err('Primero configura el correo en Configuración → Correo.', 409);
        $b = body();
        $st = $db->prepare("SELECT id, numero, token, estado, aprobada_at, cliente_id FROM cotizaciones WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) err('Cotización no encontrada', 404);
        if ($c['aprobada_at']) err('Esta cotización ya fue aprobada.', 409);
        if ($c['estado'] === 'Rechazada') err('La cotización está rechazada: cámbiale el estado antes de enviarla.', 409);
        // Uno o varios correos separados por coma.
        $destinos = correoDestinos((string)($b['to'] ?? ''));
        if (!$destinos) err('Escribe uno o más correos válidos, separados por coma (máximo ' . CORREO_MAX_DESTINOS . ').');
        $to = implode(', ', $destinos);
        $mensaje = mb_substr(trim((string)($b['mensaje'] ?? '')), 0, 2000);

        if (!$c['token']) {
            $db->prepare("UPDATE cotizaciones SET token = ? WHERE id = ? AND token IS NULL")->execute([cotNuevoToken(), $id]);
        }
        $d = cotCargar($db, $id);
        if (!$d || !$d['q']['token']) err('No se pudo preparar el link de la cotización', 500);
        if (cotEstadoPublico($d['q']) === 'vencida') err('La cotización está vencida: actualiza la fecha de validez antes de enviarla.', 409);
        try {
            cotCorreoEnvio($db, $d, $to, $mensaje);
        } catch (Throwable $e) {
            err('No se pudo enviar el correo: ' . $e->getMessage(), 502);
        }
        // Cliente sin correo en su ficha: se guarda el primero (si Inger lo pidió).
        if (!empty($b['guardar_en_cliente'])) clienteGuardarCorreoSiFalta($db, (int)$c['cliente_id'], $destinos[0]);
        // Al reenviar, el comentario del cliente se da por atendido (deja de aparecer «Con comentarios»).
        $db->prepare("UPDATE cotizaciones SET estado = IF(estado = 'Pendiente', 'Enviada', estado), enviada_at = NOW(),
                             respuesta_tipo = IF(respuesta_tipo = 'comentario', NULL, respuesta_tipo) WHERE id = ?")->execute([$id]);
        audit($db, $user['id'], 'UPDATE', 'cotizaciones', "Envió por correo la cotización #{$c['numero']} a {$to}", $id);
        ok(['enviado' => true, 'to' => $to, 'link' => cotLinkPublico((string)$d['q']['token'])]);
    }

    // --- NUEVA VERSIÓN: copia de una cotización (ej. aprobada) con número -v2, -v3… ---
    if ($method === 'POST' && $accion === 'nueva_version' && $id) {
        $st = $db->prepare("SELECT * FROM cotizaciones WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$id]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) err('Cotización no encontrada', 404);
        $base = preg_replace('/-v\d+$/', '', (string)$o['numero']);
        $db->beginTransaction();
        $ex = $db->prepare("SELECT numero FROM cotizaciones WHERE numero = ? OR numero LIKE ?");
        $ex->execute([$base, escapeLike($base) . '-v%']);
        $max = 1;
        foreach ($ex->fetchAll(PDO::FETCH_COLUMN) as $n) {
            if (preg_match('/-v(\d+)$/', $n, $m)) $max = max($max, (int)$m[1]);
        }
        $numero = $base . '-v' . ($max + 1);
        $validez = null;
        if (!empty($o['fecha']) && !empty($o['fecha_validez'])) {
            $dias = (int)((strtotime($o['fecha_validez']) - strtotime($o['fecha'])) / 86400);
            $validez = date('Y-m-d', strtotime('+' . max($dias, 0) . ' days'));
        }
        $cols = ['numero' => $numero, 'proyecto_id' => $o['proyecto_id'], 'cliente_id' => $o['cliente_id'],
                 'fecha' => date('Y-m-d'), 'fecha_validez' => $validez, 'estado' => 'Pendiente', 'notas' => $o['notas'],
                 'descuento_global' => $o['descuento_global'], 'plantilla_id' => $o['plantilla_id'],
                 'forma_pago' => $o['forma_pago'], 'plazo_estimado' => $o['plazo_estimado'],
                 'terminos_obligaciones' => $o['terminos_obligaciones'], 'terminos_desarrollo' => $o['terminos_desarrollo'],
                 'terminos_condiciones' => $o['terminos_condiciones']];
        $cols['descuento_global_tipo'] = $o['descuento_global_tipo'];
        // Propuesta: se copia (archivos nuevos) para que la versión anterior quede intacta.
        if (!empty($o['propuesta_html_path'])) {
            require_once __DIR__ . '/../lib/doc_subido.php';
            $h = dsCopiar('propuestas', $o['propuesta_html_path']);
            $pf = dsCopiar('propuestas', $o['propuesta_pdf_path']);
            if ($h && $pf) {
                $cols += ['propuesta_numero' => $o['propuesta_numero'], 'propuesta_html_path' => $h, 'propuesta_pdf_path' => $pf,
                          'propuesta_hash' => $o['propuesta_hash'], 'propuesta_hojas' => $o['propuesta_hojas'],
                          'propuesta_origen' => $o['propuesta_origen'], 'propuesta_subida_at' => $o['propuesta_subida_at']];
            }
        }
        $db->prepare("INSERT INTO cotizaciones (" . implode(', ', array_keys($cols)) . ") VALUES ("
                     . implode(', ', array_fill(0, count($cols), '?')) . ")")
           ->execute(array_values($cols));
        $nuevo = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO cotizacion_items (cotizacion_id, servicio_id, descripcion, cantidad, unidad, precio_unitario, descuento, orden)
                      SELECT ?, servicio_id, descripcion, cantidad, unidad, precio_unitario, descuento, orden
                      FROM cotizacion_items WHERE cotizacion_id = ?")->execute([$nuevo, $id]);
        // Las versiones anteriores que esperaban respuesta quedan Reemplazadas
        // (no cuentan como por atender ni se vencen). La aprobada no se toca.
        $db->prepare("UPDATE cotizaciones SET estado = 'Reemplazada'
                      WHERE (numero = ? OR numero LIKE ?) AND id <> ? AND deleted_at IS NULL
                        AND estado IN ('Pendiente', 'Enviada') AND aprobada_at IS NULL")
           ->execute([$base, escapeLike($base) . '-v%', $nuevo]);
        $db->commit();
        audit($db, $user['id'], 'CREATE', 'cotizaciones', "Creó cotización #{$numero} (nueva versión de #{$o['numero']})", $nuevo);
        ok(['id' => $nuevo, 'numero' => $numero]);
    }

   // --- POST (CREAR) O PUT (EDITAR) ---
    if ($method === 'POST' || ($method === 'PUT' && $id)) {
        $b = body();

        // PUT parcial: los campos que NO vienen conservan su valor actual.
        // Antes, cambiar el estado desde la insignia de la lista (que manda la
        // fila del listado, sin ítems/notas/descuento) BORRABA los ítems y
        // reseteaba notas, descuento y plantilla.
        $itemsVienen = array_key_exists('items', $b);
        if ($method === 'PUT') {
            $cur = $db->prepare("SELECT * FROM cotizaciones WHERE id = ? AND deleted_at IS NULL");
            $cur->execute([$id]);
            $actual = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$actual) err('Cotización no encontrada', 404);
            // Aprobada en línea → bloqueada: lo guardado debe coincidir con lo aprobado.
            if (!empty($actual['aprobada_at'])) {
                err('Esta cotización fue aprobada en línea y no se puede editar. Crea una nueva versión.', 409);
            }
            foreach (['numero', 'proyecto_id', 'cliente_id', 'fecha', 'fecha_validez', 'estado', 'notas',
                      'descuento_global', 'descuento_global_tipo', 'plantilla_id', 'forma_pago', 'plazo_estimado'] as $k) {
                if (!array_key_exists($k, $b) && array_key_exists($k, $actual)) $b[$k] = $actual[$k];
            }
        }

        if (empty($b['cliente_id'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Debes seleccionar un Cliente.']);
            exit;
        }

        $db->beginTransaction();

        if ($method === 'POST') {
            if (empty($b['numero'])) {
                $b['numero'] = nextNumero($db);
                // Avanzar el contador al número ACTUAL + 1 (no a +1 del valor
                // anterior). Así si el contador estaba atrasado, queda
                // sincronizado con la realidad y no vuelve a generar duplicados.
                $usado = (int)substr($b['numero'], strrpos($b['numero'], '-') + 1);
                $db->prepare("INSERT INTO configuracion (clave, valor) VALUES ('cotizacion_siguiente', ?) ON DUPLICATE KEY UPDATE valor = ?")
                   ->execute([$usado + 1, $usado + 1]);
            }

            $dctoTipo = (($b['descuento_global_tipo'] ?? '') === 'fijo') ? 'fijo' : 'porcentaje';
            $sql = "INSERT INTO cotizaciones (numero, proyecto_id, cliente_id, fecha, fecha_validez, estado, notas, descuento_global, descuento_global_tipo, plantilla_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $params = [
                $limpiar($b['numero']),
                !empty($b['proyecto_id']) ? (int)$b['proyecto_id'] : null,
                (int)$b['cliente_id'],
                $b['fecha'],
                !empty($b['fecha_validez']) ? $b['fecha_validez'] : null,
                $limpiar($b['estado'] ?? 'Pendiente'),
                $limpiar($b['notas'] ?? null),
                (float)($b['descuento_global'] ?? 0),
                $dctoTipo,
                !empty($b['plantilla_id']) ? (int)$b['plantilla_id'] : null
            ];
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $cot_id = (int)$db->lastInsertId();
        } else {
            // --- ACTUALIZAR CABECERA ---
            $dctoTipo = (($b['descuento_global_tipo'] ?? '') === 'fijo') ? 'fijo' : 'porcentaje';
            $sql = "UPDATE cotizaciones SET
                    numero=?, proyecto_id=?, cliente_id=?, fecha=?, fecha_validez=?,
                    estado=?, notas=?, descuento_global=?, descuento_global_tipo=?,
                    plantilla_id=?, updated_at=NOW()
                    WHERE id=? AND deleted_at IS NULL";
            $params = [
                $b['numero'],
                !empty($b['proyecto_id']) ? (int)$b['proyecto_id'] : null,
                (int)$b['cliente_id'],
                $b['fecha'],
                !empty($b['fecha_validez']) ? $b['fecha_validez'] : null,
                $b['estado'] ?: 'Pendiente',
                $b['notas'] ?: null,
                (float)($b['descuento_global'] ?? 0),
                $dctoTipo,
                !empty($b['plantilla_id']) ? (int)$b['plantilla_id'] : null,
                $id
            ];
            $db->prepare($sql)->execute($params);

            // --- ¡ESTO ES LO QUE FALTABA! ---
            $cot_id = $id;

            // Reemplazar ítems SOLO si la petición los trae (edición completa
            // desde el formulario). Un cambio de estado no los toca.
            if ($itemsVienen) {
                $db->prepare("DELETE FROM cotizacion_items WHERE cotizacion_id = ?")->execute([$cot_id]);
            }
        }

        // --- INSERTAR ÍTEMS (Ahora $cot_id siempre tiene valor) ---
        if (($method === 'POST' || $itemsVienen) && !empty($b['items']) && is_array($b['items'])) {
            $si = $db->prepare("INSERT INTO cotizacion_items (cotizacion_id, servicio_id, descripcion, cantidad, unidad, precio_unitario, orden) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($b['items'] as $k => $it) {
                $si->execute([
                    $cot_id, 
                    !empty($it['servicio_id']) ? (int)$it['servicio_id'] : null,
                    $limpiar($it['descripcion']), 
                    (float)($it['cantidad'] ?? 1), 
                    $limpiar($it['unidad'] ?? 'Servicio'), 
                    (float)($it['precio_unitario'] ?? 0), 
                    $k
                ]);
            }
        }

        // Condiciones comerciales de la pág. 1.
        $db->prepare("UPDATE cotizaciones SET forma_pago = ?, plazo_estimado = ? WHERE id = ?")->execute([
            mb_substr(safeText($b['forma_pago'] ?? ''), 0, 255) ?: null,
            mb_substr(safeText($b['plazo_estimado'] ?? ''), 0, 150) ?: null,
            $cot_id,
        ]);
        // Textos de la plantilla: se copian al crear (o al cambiar de plantilla).
        // Así, si después se edita la plantilla, esta cotización no cambia.
        $plantillaNueva = !empty($b['plantilla_id']) ? (int)$b['plantilla_id'] : null;
        $plantillaAntes = ($method === 'PUT' && !empty($actual['plantilla_id'])) ? (int)$actual['plantilla_id'] : null;
        if ($method === 'POST' || $plantillaNueva !== $plantillaAntes) {
            cotCopiarTerminos($db, $cot_id, $plantillaNueva);
        }

        $db->commit();

        // Auditoría
        $accion = ($method === 'POST') ? 'CREATE' : 'UPDATE';
        $detalle = ($method === 'POST') ? "Creó cotización #{$b['numero']}" : "Editó cotización ID {$id}";
        audit($db, $user['id'], $accion, 'cotizaciones', $detalle, $cot_id);
        
        ok(['id' => (int)$cot_id]);
    }

    // --- DELETE ---
    if ($method === 'DELETE' && $id) {
        $info = $db->prepare("SELECT numero FROM cotizaciones WHERE id = ?");
        $info->execute([$id]);
        $cot = $info->fetch();
        
        if (!$cot) {
            echo json_encode(['ok' => false, 'error' => 'Cotización no encontrada']);
            exit;
        }
        
        if ($esHard) {
            $pp = $db->prepare("SELECT propuesta_html_path, propuesta_pdf_path FROM cotizaciones WHERE id = ?");
            $pp->execute([$id]);
            $archivos = array_values(array_filter((array)$pp->fetch(PDO::FETCH_NUM)));
            $db->prepare("DELETE FROM cotizaciones WHERE id = ?")->execute([$id]);
            if ($archivos) { require_once __DIR__ . '/../lib/doc_subido.php'; dsBorrar('propuestas', $archivos); }
            audit($db, $user['id'], 'HARD_DELETE', 'cotizaciones', "Eliminó PERMANENTEMENTE cotización #{$cot['numero']}", $id);
            echo json_encode(['ok' => true, 'message' => 'Cotización eliminada permanentemente']);
        } else {
            $db->prepare("UPDATE cotizaciones SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
            audit($db, $user['id'], 'SOFT_DELETE', 'cotizaciones', "Envió a papelera cotización #{$cot['numero']}", $id);
            echo json_encode(['ok' => true, 'message' => 'Cotización enviada a papelera']);
        }
        exit;
    }

} catch (Throwable $e) {
    // Defensivo: si algo dentro de errSafe falla (poco probable) igual
    // devolvemos JSON con el detalle del error real, para no terminar con
    // body vacío y "Respuesta inválida" en el frontend.
    try { if (isset($db) && $db->inTransaction()) $db->rollBack(); } catch (Throwable $_) { /* ignorar */ }
    try {
        if (function_exists('logError')) {
            logError('Error en cotizaciones.php', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()]);
        } else {
            error_log('cotizaciones.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    } catch (Throwable $_) { /* ignorar */ }
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
        'where' => basename($e->getFile()) . ':' . $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}