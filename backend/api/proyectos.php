<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config.php';
    cors();
    $db = getDB();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];
    // Soporte para method override via query string (POST con _method=PUT)
    if ($method === 'POST' && isset($_GET['_method'])) {
        $method = strtoupper($_GET['_method']);
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

    // Helper para limpiar strings
    $clean = function($str) { return trim((string)($str ?? '')); };

    // --- GET: LISTAR ---
    // Soporta paginación: ?page=X&per_page=Y
    // --- GET ?lista=1: opciones para los selectores de los formularios ---
    // Liviano y acotado, para que los formularios no dependan de cargar TODOS los
    // proyectos (crecen para siempre: mantenciones y renovaciones se crean solas).
    //   cliente_id=N   → los proyectos de ese cliente (Pagos, Cotizaciones)
    //   para=entrega   → los que aún no tienen acta y sí la llevan (Entregas)
    //   incluir=N      → ese proyecto siempre viene (el ya elegido al editar)
    if ($method === 'GET' && !empty($_GET['lista'])) {
        $cond = []; $par = [];
        $clienteId = (int)($_GET['cliente_id'] ?? 0);
        $incluir = (int)($_GET['incluir'] ?? 0);
        if ($clienteId) { $cond[] = "p.cliente_id = ?"; $par[] = $clienteId; }
        elseif (($_GET['para'] ?? '') === 'entrega') {
            $cond[] = "p.estado IN ('En curso', 'Terminado')
                       AND CONCAT(COALESCE(p.tipo_proyecto, ''), ' ', p.nombre) NOT REGEXP 'mantenci|renovaci'
                       AND NOT EXISTS (SELECT 1 FROM entregas e WHERE e.proyecto_id = p.id)";
        }
        $base = $cond ? '(' . implode(' AND ', $cond) . ')' : '0';
        if ($incluir) { $base = "($base OR p.id = ?)"; $par[] = $incluir; }
        $st = $db->prepare("SELECT p.id, p.nombre, p.cliente_id, p.sitio_id, p.estado, p.tipo_proyecto, p.presupuesto,
                                   p.fecha_inicio, p.fecha_termino, s.nombre AS sitio_nombre,
                                   COALESCE(NULLIF(c.nombre_agencia, ''), c.nombre) AS cliente_nombre
                            FROM proyectos p
                            LEFT JOIN clientes c ON c.id = p.cliente_id
                            LEFT JOIN sitios s ON s.id = p.sitio_id
                            WHERE p.deleted_at IS NULL AND $base
                            ORDER BY p.id DESC LIMIT 500");
        $st->execute($par);
        $filasLista = $st->fetchAll(PDO::FETCH_ASSOC);
        // Cobrado / falta (lib/saldos.php): el formulario de pagos oculta los ya pagados.
        $res = [];
        if ($filasLista) {
            require_once __DIR__ . '/../lib/saldos.php';
            $idsL = array_map(fn($x) => (int)$x['id'], $filasLista);
            $res = saldoResumenPor(saldosCuotas($db, 'pg.proyecto_id IN (' . implode(',', $idsL) . ')'), 'proyecto_id');
        }
        foreach ($filasLista as &$f) {
            $f['id'] = (int)$f['id']; $f['cliente_id'] = (int)$f['cliente_id'];
            $f['sitio_id'] = $f['sitio_id'] !== null ? (int)$f['sitio_id'] : null;
            $f['presupuesto'] = (int)$f['presupuesto'];
            $f['cobrado'] = $res[$f['id']]['cobrado'] ?? 0;
            $f['falta'] = $res[$f['id']]['falta'] ?? 0;
        }
        unset($f);
        ok($filasLista);
    }

    if ($method === 'GET') {
        $where = $esPapelera ? "WHERE p.deleted_at IS NOT NULL" : "WHERE p.deleted_at IS NULL";

        // Búsqueda server-side
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $params = [];
        if ($q !== '') {
            $where .= " AND (p.nombre LIKE ? OR p.tipo_proyecto LIKE ? OR c.nombre LIKE ? OR c.nombre_agencia LIKE ?)";
            $like = '%' . escapeLike($q) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        // Filtro por sitio (ej: documentos de un sitio → elegir uno de sus proyectos)
        if (!empty($_GET['sitio_id'])) {
            $where .= " AND p.sitio_id = ?";
            $params[] = (int)$_GET['sitio_id'];
        }

        // Número por pestaña (respeta búsqueda y sitio, no el estado).
        $conteo = null;
        if (!$esPapelera) {
            $st = $db->prepare("SELECT p.estado, COUNT(*) n FROM proyectos p LEFT JOIN clientes c ON p.cliente_id = c.id {$where} GROUP BY p.estado");
            $st->execute($params);
            $conteo = ['Todos' => 0];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $conteo[$r['estado']] = (int)$r['n']; $conteo['Todos'] += (int)$r['n']; }
        }

        // Filtro por estado (server-side, para que aplique a todo el dataset)
        $estado = isset($_GET['estado']) ? trim((string)$_GET['estado']) : '';
        if ($estado !== '' && $estado !== 'Todos') {
            $where .= " AND p.estado = ?";
            $params[] = $estado;
        }

        // Parámetros de paginación
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
        $per_page = isset($_GET['per_page']) ? max(1, min(1000, (int)$_GET['per_page'])) : null;

        // Contar total (necesita JOIN si filtramos por cliente)
        $countSql = "SELECT COUNT(*) FROM proyectos p LEFT JOIN clientes c ON p.cliente_id = c.id {$where}";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Vista agrupada (?agrupar=1): la página es de SITIOS completos, no de
        // proyectos. Se eligen los sitios de la página (primero los que tienen
        // trabajo activo, después por lo más reciente) y se traen TODOS sus
        // proyectos del filtro: así un sitio nunca se parte ni muestra un total a
        // medias, y la lista no tiene techo aunque haya miles de proyectos.
        $agrupar = !empty($_GET['agrupar']) && $page && $per_page && !$esPapelera;
        $totalProyectos = $total;
        $ordenSitios = [];
        if ($agrupar) {
            $from = "FROM proyectos p LEFT JOIN clientes c ON p.cliente_id = c.id LEFT JOIN sitios s ON p.sitio_id = s.id {$where}";
            $stG = $db->prepare("SELECT COUNT(DISTINCT COALESCE(s.nombre, '')) {$from}");
            $stG->execute($params);
            $total = (int)$stG->fetchColumn();
            $offsetG = ($page - 1) * $per_page;
            $stG = $db->prepare("SELECT COALESCE(s.nombre, '') AS k,
                                        MAX(p.estado IN ('En curso', 'Cotizado', 'Pausado')) AS activo, MAX(p.id) AS ultimo
                                 {$from}
                                 GROUP BY k
                                 ORDER BY (k = '') ASC, activo DESC, ultimo DESC
                                 LIMIT {$per_page} OFFSET {$offsetG}");
            $stG->execute($params);
            $ordenSitios = $stG->fetchAll(PDO::FETCH_COLUMN);
            if ($ordenSitios) {
                $where .= " AND COALESCE(s.nombre, '') IN (" . implode(',', array_fill(0, count($ordenSitios), '?')) . ")";
                foreach ($ordenSitios as $k) $params[] = $k;
            } else {
                $where .= " AND 1 = 0";
            }
        }

        $sql = "SELECT p.*, c.nombre as cliente_nombre_real, c.nombre_agencia,
                       s.nombre AS sitio_nombre, s.url_principal AS sitio_url
                FROM proyectos p
                LEFT JOIN clientes c ON p.cliente_id = c.id
                LEFT JOIN sitios s ON p.sitio_id = s.id
                {$where}
                ORDER BY p.id DESC";

        // Aplicar paginación
        if ($page && $per_page && !$agrupar) {
            $offset = ($page - 1) * $per_page;
            $sql .= " LIMIT {$per_page} OFFSET {$offset}";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Agrupado: los proyectos salen en el orden de sus sitios (el de la página).
        if ($agrupar && $ordenSitios) {
            $pos = array_flip($ordenSitios);
            usort($data, fn($a, $b) => [$pos[(string)($a['sitio_nombre'] ?? '')] ?? 0, -(int)$a['id']] <=> [$pos[(string)($b['sitio_nombre'] ?? '')] ?? 0, -(int)$b['id']]);
        }

        // Formatear para el frontend
        foreach ($data as &$r) {
            $r['id'] = (int)$r['id'];
            $r['cliente_id'] = (int)$r['cliente_id'];
            $r['sitio_id'] = $r['sitio_id'] ? (int)$r['sitio_id'] : null;
            $r['servicio_id'] = $r['servicio_id'] ? (int)$r['servicio_id'] : null;
            $r['presupuesto'] = (int)$r['presupuesto'];
            $r['cliente_nombre'] = !empty($r['nombre_agencia']) ? $r['nombre_agencia'] : $r['cliente_nombre_real'];
        }
        unset($r);

        // Cargar TODOS los servicios asociados (pivote) en una sola query
        // para evitar N+1. Si un proyecto no tiene filas en el pivote pero sí
        // tiene servicio_id legacy, lo respaldamos.
        $ids = array_map(fn($x) => (int)$x['id'], $data);
        $svcByProy = [];
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            // precio = precio por línea si existe (override), si no el del catálogo.
            // nombre = texto propio de la línea libre (NULL en líneas de catálogo).
            $st = $db->prepare("
                SELECT ps.proyecto_id, ps.servicio_id, ps.nombre, ps.cantidad,
                       COALESCE(ps.precio_unitario, s.precio, 0) AS precio
                FROM proyecto_servicios ps
                LEFT JOIN servicios s ON s.id = ps.servicio_id
                WHERE ps.proyecto_id IN ($ph)
                ORDER BY ps.orden ASC, ps.id ASC
            ");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $svcByProy[(int)$row['proyecto_id']][] = [
                    'servicio_id' => $row['servicio_id'] !== null ? (int)$row['servicio_id'] : null,
                    'nombre'      => $row['nombre'],   // solo líneas libres
                    'cantidad'    => max(1, (int)$row['cantidad']),
                    'precio'      => (int)$row['precio'],
                ];
            }
        }
        foreach ($data as &$r) {
            $lista = $svcByProy[$r['id']] ?? [];
            if (empty($lista) && !empty($r['servicio_id'])) $lista = [['servicio_id' => (int)$r['servicio_id'], 'nombre' => null, 'cantidad' => 1, 'precio' => 0]];
            $r['servicios'] = $lista;                                        // [{servicio_id, nombre, cantidad, precio}]
            // Compat: solo ids de catálogo (las líneas libres no tienen servicio_id).
            $r['servicios_ids'] = array_values(array_filter(array_map(fn($x) => $x['servicio_id'], $lista), fn($v) => $v));
        }
        unset($r);

        // Resumen de dinero por proyecto, traído de Pagos (fuente única de la
        // plata) con lib/saldos.php: mismos números que Panel, Pagos y Reportes.
        $cobradoByProy = [];
        $porCobrarByProy = [];
        $resByProy = [];
        if (!empty($ids)) {
            require_once __DIR__ . '/../lib/saldos.php';
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (saldoResumenPor(saldosCuotas($db, "pg.proyecto_id IN ($ph)", $ids), 'proyecto_id') as $pid => $res) {
                $cobradoByProy[$pid]   = $res['cobrado'];
                $porCobrarByProy[$pid] = $res['falta'];
                $resByProy[$pid] = $res;
            }
        }
        foreach ($data as &$r) {
            $r['cobrado']    = $cobradoByProy[$r['id']]   ?? 0;
            $r['falta']      = $porCobrarByProy[$r['id']] ?? 0; // por cobrar + por emitir
            $r['total_cobro'] = $resByProy[$r['id']]['total'] ?? 0;      // suma de las cuotas
            $r['por_cobrar']  = $resByProy[$r['id']]['por_cobrar'] ?? 0; // boletas emitidas con saldo
            $r['por_emitir']  = $resByProy[$r['id']]['por_emitir'] ?? 0;
            $r['vencido']     = $resByProy[$r['id']]['vencido'] ?? 0;
        }
        unset($r);

        // Cantidad de documentos/enlaces por proyecto (si ya corrió la migración).
        $docsByProy = [];
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stD = $db->prepare("SELECT proyecto_id, COUNT(*) AS n FROM documentos WHERE proyecto_id IN ($ph) GROUP BY proyecto_id");
            $stD->execute($ids);
            foreach ($stD->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $docsByProy[(int)$row['proyecto_id']] = (int)$row['n'];
            }
        }
        // + propuesta y acta (documentos automáticos, solo consulta).
        require_once __DIR__ . '/../lib/docs_auto.php';
        foreach (docsAutoConteo($db, 'proyecto', $ids) as $pid => $n) $docsByProy[$pid] = ($docsByProy[$pid] ?? 0) + $n;
        foreach ($data as &$r) $r['documentos_count'] = $docsByProy[$r['id']] ?? 0;
        unset($r);

        // Etapa de entrega: la entrega más reciente de cada proyecto (si ya
        // corrió la migración de Entregas). null = sin acta todavía.
        $entByProy = [];
        try {
            if (!empty($ids)) {
                require_once __DIR__ . '/../lib/entregas.php';
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $garSql = ENT_GARANTIA_HASTA_SQL;   // recepción + meses − 1 día
                $stE = $db->prepare("SELECT e.proyecto_id, e.id, e.numero, e.estado, e.plazo_vence, e.recibida_at,
                                            $garSql AS garantia_hasta
                                     FROM entregas e
                                     WHERE e.proyecto_id IN ($ph)
                                       AND e.id = (SELECT MAX(e2.id) FROM entregas e2 WHERE e2.proyecto_id = e.proyecto_id)");
                $stE->execute($ids);
                foreach ($stE->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $entByProy[(int)$row['proyecto_id']] = [
                        'id'          => (int)$row['id'],
                        'numero'      => $row['numero'],
                        'estado'      => $row['estado'],
                        'plazo_vence' => $row['plazo_vence'],
                        'recibida_at' => $row['recibida_at'],
                        'garantia_hasta' => $row['garantia_hasta'],
                    ];
                }
            }
        } catch (Throwable $e) { /* sin entregas: el listado sigue */ }
        foreach ($data as &$r) $r['entrega'] = $entByProy[$r['id']] ?? null;
        unset($r);

        // Responder con o sin paginación
        if ($page && $per_page) {
            echo json_encode([
                'ok' => true, 
                'data' => $data,
                'conteo' => $conteo,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => $total,   // agrupado: cantidad de sitios
                    'total_pages' => (int)ceil($total / $per_page),
                    'total_proyectos' => $totalProyectos,
                ]
            ]);
        } else {
            echo json_encode(['ok' => true, 'data' => $data]);
        }
        exit;
    }

    // --- RESTAURAR PROYECTO ---
    // DEBE IR ANTES de POST/CREAR para que no se confunda
    if ($method === 'POST' && $esRestore && $id) {
        $stmt = $db->prepare("UPDATE proyectos SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            audit($db, $user['id'], 'RESTORE', 'proyectos', "Restauró proyecto #{$id}", $id);
            echo json_encode(['ok' => true, 'message' => 'Proyecto restaurado']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Proyecto no encontrado o no estaba eliminado']);
        }
        exit;
    }

    // Helper: sincroniza la tabla pivote con la lista de servicios elegida.
    // Borra los existentes y vuelve a insertarlos en el orden recibido.
    // También actualiza proyectos.servicio_id legacy con el primero.
    // Acepta $servicios como [{servicio_id, cantidad}] (nuevo) o [id, ...] (compat).
    // Acepta $servicios como [{servicio_id, nombre?, cantidad, precio?}] o [id, ...].
    // Dos tipos de línea:
    //   - Catálogo:  servicio_id > 0. `precio` (opcional) sobrescribe el del
    //                catálogo; NULL = usar servicios.precio. Sin duplicar servicio.
    //   - Libre:     sin servicio_id pero con `nombre`. Guarda servicio_id NULL,
    //                el nombre y su precio (sin fallback al catálogo).
    $syncServicios = function(int $proyecto_id, $servicios) use ($db) {
        $db->prepare("DELETE FROM proyecto_servicios WHERE proyecto_id = ?")->execute([$proyecto_id]);
        $rows = [];      // filas finales, en orden
        $vistos = [];    // servicio_id de catálogo ya agregados (dedup)
        foreach ((array)$servicios as $s) {
            if (is_array($s)) {
                $sid    = (int)($s['servicio_id'] ?? 0);
                $cant   = max(1, (int)($s['cantidad'] ?? 1));
                $nombre = trim((string)($s['nombre'] ?? ''));
                $tienePrecio = isset($s['precio']) && $s['precio'] !== '' && $s['precio'] !== null;
                $precio = $tienePrecio ? max(0, (int)$s['precio']) : null;
            } else {
                $sid = (int)$s; $cant = 1; $nombre = ''; $precio = null;
            }
            if ($sid > 0) {
                if (isset($vistos[$sid])) continue;      // no repetir servicio de catálogo
                $vistos[$sid] = true;
                $rows[] = ['servicio_id' => $sid, 'nombre' => null, 'cantidad' => $cant, 'precio' => $precio];
            } elseif ($nombre !== '') {
                // Línea libre: el precio es obligatorio (no hay catálogo), 0 si falta.
                $rows[] = ['servicio_id' => null, 'nombre' => $nombre, 'cantidad' => $cant, 'precio' => $precio ?? 0];
            }
            // (sin servicio_id y sin nombre → se ignora)
        }
        if (!empty($rows)) {
            $ins = $db->prepare("INSERT INTO proyecto_servicios (proyecto_id, servicio_id, nombre, cantidad, precio_unitario, orden) VALUES (?, ?, ?, ?, ?, ?)");
            $orden = 0;
            foreach ($rows as $r) $ins->execute([$proyecto_id, $r['servicio_id'], $r['nombre'], $r['cantidad'], $r['precio'], $orden++]);
        }
        // servicio_id legacy del proyecto = primer servicio de catálogo (o NULL).
        $primero = null;
        foreach ($rows as $r) { if ($r['servicio_id']) { $primero = $r['servicio_id']; break; } }
        $db->prepare("UPDATE proyectos SET servicio_id = ? WHERE id = ?")->execute([$primero, $proyecto_id]);
    };

    // --- POST: CREAR ---
    if ($method === 'POST') {
        $b = body();
        // Servicios: preferimos la forma nueva con cantidad; caemos a la lista
        // de ids (compat) o al servicio_id legacy. El campo legacy queda con el 1º.
        $serviciosData = $b['servicios'] ?? $b['servicios_ids'] ?? (!empty($b['servicio_id']) ? [(int)$b['servicio_id']] : []);
        $primero = null;
        foreach ((array)$serviciosData as $s) {
            $sid = is_array($s) ? (int)($s['servicio_id'] ?? 0) : (int)$s;
            if ($sid > 0) { $primero = $sid; break; }
        }

        // Proyecto + líneas + cobro automático: todo o nada.
        $db->beginTransaction();
        $sql = "INSERT INTO proyectos (cliente_id, sitio_id, servicio_id, nombre, descripcion, tipo_proyecto, estado, fecha_inicio, fecha_termino, presupuesto, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $db->prepare($sql)->execute([
            (int)$b['cliente_id'],
            !empty($b['sitio_id']) ? (int)$b['sitio_id'] : null,
            $primero,
            $clean($b['nombre']),
            $clean($b['descripcion']),
            $clean($b['tipo_proyecto']),
            $clean($b['estado'] ?? 'Cotizado'),
            !empty($b['fecha_inicio']) ? $b['fecha_inicio'] : null,
            !empty($b['fecha_termino']) ? $b['fecha_termino'] : null,
            (int)($b['presupuesto'] ?? 0)
        ]);
        $proyecto_id = (int)$db->lastInsertId();

        $syncServicios($proyecto_id, $serviciosData);

        // Cobro pendiente automático: si el proyecto tiene estimado y se pidió
        // generar el cobro, creamos un pago PENDIENTE vinculado (monto a cobrar
        // = estimado, 1 cuota, sin abonos). Así el "por cobrar" aparece solo,
        // sin tener que crear un pago vacío a mano; al cobrar se registra el
        // abono en ese pago. tipo por defecto 'boleta' (editable al cobrar).
        $estimado = (int)($b['presupuesto'] ?? 0);
        $generarCobro = !isset($b['generar_cobro']) || !empty($b['generar_cobro']);
        if ($generarCobro && $estimado > 0) {
            $tipoCobro = ($b['tipo_cobro'] ?? 'boleta') === 'directo' ? 'directo' : 'boleta';
            $db->prepare("INSERT INTO pagos (cliente_id, proyecto_id, descripcion, monto_total, tipo_pago)
                          VALUES (?, ?, ?, ?, ?)")
               ->execute([(int)$b['cliente_id'], $proyecto_id, $clean($b['nombre']), $estimado, $tipoCobro]);
            $pago_id = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO pago_cuotas (pago_id, numero_cuota, boleta_monto, pago_monto, estado, tipo_pago)
                          VALUES (?, 1, ?, 0, 'Pendiente', ?)")
               ->execute([$pago_id, $estimado, $tipoCobro]);
        }

        $db->commit();

        audit($db, $user['id'], 'CREATE', 'proyectos', "Creó proyecto #{$proyecto_id}: {$b['nombre']}", $proyecto_id);

        echo json_encode(['ok' => true, 'id' => $proyecto_id]);
        exit;
    }

    // --- EDITAR PROYECTO (PUT) ---
        if ($method === 'PUT' && $id) {
            $b = body();
            // PUT parcial: los campos que NO vienen conservan su valor actual
            // (ej: cambiar solo el estado desde la insignia de la lista).
            $cur = $db->prepare("SELECT * FROM proyectos WHERE id = ? AND deleted_at IS NULL");
            $cur->execute([(int)$id]);
            $actual = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$actual) err('Proyecto no encontrado', 404);
            foreach (['cliente_id', 'sitio_id', 'nombre', 'descripcion', 'tipo_proyecto', 'estado',
                      'fecha_inicio', 'fecha_termino', 'presupuesto'] as $k) {
                if (!array_key_exists($k, $b)) $b[$k] = $actual[$k];
            }
            // Las líneas de servicio solo se reemplazan si vienen en la petición.
            $serviciosVienen = array_key_exists('servicios', $b) || array_key_exists('servicios_ids', $b) || array_key_exists('servicio_id', $b);
            $serviciosData = $b['servicios'] ?? $b['servicios_ids'] ?? (!empty($b['servicio_id']) ? [(int)$b['servicio_id']] : []);
            $primero = $serviciosVienen ? null : ($actual['servicio_id'] !== null ? (int)$actual['servicio_id'] : null);
            foreach ($serviciosVienen ? (array)$serviciosData : [] as $s) {
                $sid = is_array($s) ? (int)($s['servicio_id'] ?? 0) : (int)$s;
                if ($sid > 0) { $primero = $sid; break; }
            }

            $sql = "UPDATE proyectos SET
                    cliente_id = ?,
                    sitio_id = ?,
                    servicio_id = ?,
                    nombre = ?,
                    descripcion = ?,
                    tipo_proyecto = ?,
                    estado = ?,
                    fecha_inicio = ?,
                    fecha_termino = ?,
                    presupuesto = ?,
                    updated_at = NOW()
                    WHERE id = ? AND deleted_at IS NULL";

            $stmt = $db->prepare($sql);

            $stmt->execute([
                (int)$b['cliente_id'],
                !empty($b['sitio_id']) ? (int)$b['sitio_id'] : null,
                $primero,
                $clean($b['nombre']),
                $clean($b['descripcion']),
                $clean($b['tipo_proyecto']),
                $clean($b['estado']),
                !empty($b['fecha_inicio']) ? $b['fecha_inicio'] : null,
                !empty($b['fecha_termino']) ? $b['fecha_termino'] : null,
                (int)($b['presupuesto'] ?? 0),
                (int)$id // El ID del proyecto para el WHERE
            ]);

            if ($serviciosVienen) $syncServicios((int)$id, $serviciosData);

            // Los documentos del proyecto siguen a su cliente y sitio.
            $db->prepare("UPDATE documentos SET cliente_id = ?, sitio_id = ? WHERE proyecto_id = ?")
               ->execute([(int)$b['cliente_id'], !empty($b['sitio_id']) ? (int)$b['sitio_id'] : null, (int)$id]);

            // Si el proyecto cambia de cliente, lo suyo se mueve con él: pagos,
            // cotizaciones y entregas (si no, cada pantalla mostraba otro cliente).
            if ((int)$b['cliente_id'] !== (int)$actual['cliente_id']) {
                foreach (['pagos', 'cotizaciones', 'entregas'] as $tabla) {
                    try {
                        $db->prepare("UPDATE {$tabla} SET cliente_id = ? WHERE proyecto_id = ?")
                           ->execute([(int)$b['cliente_id'], (int)$id]);
                    } catch (Throwable $e) { /* tabla aún sin migrar (entregas) */ }
                }
            }

            audit($db, $user['id'], 'UPDATE', 'proyectos', "Editó proyecto #{$id}: {$b['nombre']}", $id);

            echo json_encode(['ok' => true]);
            exit;
        }

    // --- DELETE ---
    if ($method === 'DELETE' && $id) {
        $info = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
        $info->execute([$id]);
        $proy = $info->fetch();
        
        if (!$proy) {
            echo json_encode(['ok' => false, 'error' => 'Proyecto no encontrado']);
            exit;
        }
        
        if ($esHard) {
            $db->prepare("DELETE FROM proyectos WHERE id = ?")->execute([$id]);
            audit($db, $user['id'], 'HARD_DELETE', 'proyectos', "Eliminó PERMANENTEMENTE proyecto #{$id}: " . $proy['nombre'], $id);
            echo json_encode(['ok' => true, 'message' => 'Proyecto eliminado permanentemente']);
        } else {
            $db->prepare("UPDATE proyectos SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
            audit($db, $user['id'], 'SOFT_DELETE', 'proyectos', "Envió a papelera proyecto #{$id}: " . $proy['nombre'], $id);
            echo json_encode(['ok' => true, 'message' => 'Proyecto enviado a papelera']);
        }
        exit;
    }

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if (function_exists('logError')) {
        logError('Error en proyectos.php', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    }
    errSafe($e, 500);
}