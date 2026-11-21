<?php
/**
 * docs_auto.php — Documentos "automáticos" (solo consulta).
 *
 * La propuesta adjunta de una cotización y el acta de una entrega ya viven en
 * sus módulos; aquí se arman al consultar para listarlos junto a los documentos
 * del proyecto / sitio / cliente. No se copian archivos ni se crean filas en
 * `documentos`: se editan o reemplazan desde Cotizaciones y Entregas.
 *
 * Alcance: 'proyecto' | 'sitio' | 'cliente'.
 *   - propuesta: cotizaciones vigentes con propuesta. Por proyecto/sitio, solo las
 *     ligadas a un proyecto (cotizaciones.proyecto_id); por cliente, todas.
 *   - acta: entregas con acta subida (entregas.html_path).
 */

const DOCS_AUTO_ALCANCES = ['proyecto', 'sitio', 'cliente'];

/** Condición WHERE según el alcance (p = proyectos con alias p). */
function docsAutoWhere(string $alcance, string $alias): string {
    if ($alcance === 'proyecto') return "$alias.proyecto_id = ?";
    if ($alcance === 'sitio')    return "p.sitio_id = ?";
    return "$alias.cliente_id = ?";
}

/**
 * Documentos automáticos de un proyecto, sitio o cliente, con la misma forma
 * que las filas de `documentos` + { automatico: 'propuesta'|'acta', ref_id }.
 */
function docsAutomaticos(PDO $db, string $alcance, int $id): array {
    if (!in_array($alcance, DOCS_AUTO_ALCANCES, true) || $id <= 0) return [];
    $out = [];

    $join = $alcance === 'cliente' ? 'LEFT JOIN' : 'INNER JOIN';
    $st = $db->prepare("
        SELECT c.id, c.numero, c.cliente_id, c.proyecto_id, c.fecha, c.propuesta_numero,
               (c.propuesta_pdf_path IS NOT NULL) AS con_pdf,
               p.sitio_id, p.nombre AS proyecto_nombre, s.nombre AS sitio_nombre
        FROM cotizaciones c
        $join proyectos p ON p.id = c.proyecto_id AND p.deleted_at IS NULL
        LEFT JOIN sitios s ON s.id = p.sitio_id AND s.deleted_at IS NULL
        WHERE " . docsAutoWhere($alcance, 'c') . "
          AND c.deleted_at IS NULL AND c.propuesta_html_path IS NOT NULL
        ORDER BY c.id DESC");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $num = trim((string)($r['propuesta_numero'] ?? ''));
        $out[] = docsAutoFila($r, 'propuesta', 'propuesta',
            'Propuesta' . ($num !== '' ? " $num" : '') . " · cotización {$r['numero']}", $r['fecha']);
    }

    $join = $alcance === 'sitio' ? 'INNER JOIN' : 'LEFT JOIN';
    $st = $db->prepare("
        SELECT e.id, e.numero, e.cliente_id, e.proyecto_id, e.fecha_entrega, e.estado,
               (e.pdf_path IS NOT NULL) AS con_pdf,
               p.sitio_id, p.nombre AS proyecto_nombre, s.nombre AS sitio_nombre
        FROM entregas e
        $join proyectos p ON p.id = e.proyecto_id AND p.deleted_at IS NULL
        LEFT JOIN sitios s ON s.id = p.sitio_id AND s.deleted_at IS NULL
        WHERE " . docsAutoWhere($alcance, 'e') . " AND e.html_path IS NOT NULL
        ORDER BY e.id DESC");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = docsAutoFila($r, 'acta', 'contrato', "Acta de entrega {$r['numero']}", $r['fecha_entrega']);
    }
    return $out;
}

function docsAutoFila(array $r, string $tipoAuto, string $tipoDoc, string $titulo, ?string $fecha): array {
    $proyNombre = $r['proyecto_nombre'] ?? null;
    $sitio = $r['sitio_nombre'] ?? null;
    return [
        'id'              => "auto-$tipoAuto-" . (int)$r['id'],
        'automatico'      => $tipoAuto,
        'ref_id'          => (int)$r['id'],
        'con_pdf'         => (bool)$r['con_pdf'],
        'cliente_id'      => (int)$r['cliente_id'],
        'proyecto_id'     => $proyNombre !== null && $r['proyecto_id'] ? (int)$r['proyecto_id'] : null,
        'sitio_id'        => $r['sitio_id'] !== null ? (int)$r['sitio_id'] : null,
        'tipo'            => $tipoDoc,
        'titulo'          => $titulo,
        'url'             => null,
        'es_archivo'      => false,
        'fecha'           => $fecha ?: null,
        'notas'           => null,
        'proyecto_nombre' => $proyNombre,
        'sitio_nombre'    => $sitio,
        'etiqueta'        => $proyNombre !== null ? etiquetaProyecto($proyNombre, $sitio) : ($sitio ?: null),
    ];
}

/**
 * Conteo de documentos automáticos por proyecto o por sitio (para el número del
 * botón "Documentos" en las listas). Devuelve [id => n].
 */
function docsAutoConteo(PDO $db, string $alcance, array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids || !in_array($alcance, ['proyecto', 'sitio'], true)) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $col = $alcance === 'proyecto' ? 'p.id' : 'p.sitio_id';
    $n = [];
    $sumar = function (string $sql) use ($db, $ids, &$n) {
        $st = $db->prepare($sql);
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $n[(int)$r['k']] = ($n[(int)$r['k']] ?? 0) + (int)$r['n'];
    };
    try {
        $sumar("SELECT $col AS k, COUNT(*) AS n FROM cotizaciones c
                INNER JOIN proyectos p ON p.id = c.proyecto_id AND p.deleted_at IS NULL
                WHERE $col IN ($ph) AND c.deleted_at IS NULL AND c.propuesta_html_path IS NOT NULL GROUP BY $col");
        $sumar("SELECT $col AS k, COUNT(*) AS n FROM entregas e
                INNER JOIN proyectos p ON p.id = e.proyecto_id AND p.deleted_at IS NULL
                WHERE $col IN ($ph) AND e.html_path IS NOT NULL GROUP BY $col");
    } catch (Throwable $e) { /* el conteo es informativo */ }
    return $n;
}
