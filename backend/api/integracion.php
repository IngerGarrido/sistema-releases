<?php
/**
 * API de integración (Claude Code → sistema). Solo con llave (Authorization: Bearer igs_…).
 * Rutas (ver .htaccess):
 *   GET  /api/integracion/cotizaciones?numero=COT-2026-006   → a qué cotización va (sin montos)
 *   POST /api/integracion/documentos  (multipart)             → sube propuesta o acta (HTML + PDF)
 *
 * Rellena los MISMOS campos que la subida manual del panel, con la MISMA función
 * (dsGuardarPropuesta / dsGuardarActa en lib/doc_subido.php). Nunca envía
 * correos ni cambia estados: enviar sigue siendo un botón de Inger.
 * Spec: INSTRUCCIONES-integracion-claude.md (web-inger-nueva/propuestas/documentos).
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/integracion.php';
require_once __DIR__ . '/../lib/entregas.php';   // + cotizacion_doc.php y doc_subido.php

igCabeceras();

$ruta = (string)($_GET['r'] ?? '');
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$endpoint = "$metodo $ruta";

try {
    $db = getDB();

    // ─── GET cotizaciones?numero= ─────────────────────────────────────
    if ($ruta === 'cotizaciones' && $metodo === 'GET') {
        $numero = trim((string)($_GET['numero'] ?? ''));
        $llave = igAutenticar($db, 'documentos:subir', $endpoint, $numero ?: null);
        $lid = (int)$llave['id'];
        if (!preg_match(IG_RE_COT, $numero)) { igRegistrar($db, $lid, $endpoint, $numero, 'numero_invalido'); igResponder(422, ['ok' => false, 'error' => 'numero_invalido']); }
        $c = igCotizacion($db, $numero);
        if (!$c) { igRegistrar($db, $lid, $endpoint, $numero, 'no_existe'); igResponder(404, ['ok' => false, 'error' => 'no_existe']); }
        igRegistrar($db, $lid, $endpoint, $numero, 'ok');
        igResponder(200, [
            'ok' => true, 'id' => (int)$c['id'], 'numero' => $c['numero'], 'cliente' => $c['cliente'], 'estado' => $c['estado'],
            'bloqueada' => igCotBloqueada($c),
            'propuesta' => $c['propuesta_html_path'] ? [
                'numero' => $c['propuesta_numero'], 'origen' => $c['propuesta_origen'], 'subida_at' => $c['propuesta_subida_at'],
            ] : null,
        ]);
    }

    // ─── POST documentos ──────────────────────────────────────────────
    if ($ruta === 'documentos' && $metodo === 'POST') {
        // Cuerpo más grande que post_max_size: PHP deja $_POST y $_FILES vacíos.
        $largo = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($largo > 0 && $largo > dsIniBytes('post_max_size')) {
            $llave = igAutenticar($db, 'documentos:subir', $endpoint, null);
            igRegistrar($db, (int)$llave['id'], $endpoint, null, 'muy_grande');
            igResponder(413, ['ok' => false, 'error' => 'muy_grande', 'detalle' => 'El servidor acepta hasta ' . dsMB(dsIniBytes('post_max_size')) . ' por subida.']);
        }
        $tipo = (string)($_POST['tipo'] ?? '');
        $numero = trim((string)($_POST['numero'] ?? ''));
        $cotNum = trim((string)($_POST['cotizacion'] ?? ''));
        $clienteId = (int)($_POST['cliente_id'] ?? 0);
        $reemplazar = ($_POST['reemplazar'] ?? '') === '1';
        $llave = igAutenticar($db, 'documentos:subir', $endpoint, $numero ?: null);
        $lid = (int)$llave['id'];
        $falla = function (int $code, string $error, array $extra = []) use ($db, $lid, $endpoint, $numero): never {
            igRegistrar($db, $lid, $endpoint, $numero ?: null, $error);
            igResponder($code, ['ok' => false, 'error' => $error] + $extra);
        };

        if (!in_array($tipo, ['propuesta', 'acta'], true)) $falla(422, 'tipo_invalido');
        if (!preg_match($tipo === 'propuesta' ? IG_RE_PRO : IG_RE_ENT, $numero)) $falla(422, 'numero_invalido');
        if ($cotNum !== '' && !preg_match(IG_RE_COT, $cotNum)) $falla(422, 'cotizacion_invalida');
        // La integración siempre manda los dos archivos (no hay reemplazo parcial).
        foreach (['html', 'pdf'] as $k) {
            if (($_FILES[$k]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) $falla(422, 'faltan_archivos');
        }
        $usuario = igUsuarioAuditoria($db, $llave);
        $base = cotBaseUrl();

        // ── Propuesta ──
        if ($tipo === 'propuesta') {
            if ($cotNum === '') $falla(422, 'falta_cotizacion');
            $c = igCotizacion($db, $cotNum);
            if (!$c) $falla(404, 'no_existe');
            if (igCotBloqueada($c)) $falla(409, 'bloqueada', ['estado' => $c['estado']]);
            $tenia = (bool)$c['propuesta_html_path'];
            if ($tenia && !$reemplazar) {
                $falla(409, 'ya_existe', ['subida_at' => $c['propuesta_subida_at'], 'origen' => $c['propuesta_origen']]);
            }
            try {
                dsGuardarPropuesta($db, $c, $_FILES['html'], $_FILES['pdf'], false, $numero, 'claude');
            } catch (DomainException $e) {
                $falla(422, 'archivo_invalido', ['detalle' => $e->getMessage()]);
            } catch (DsConflicto $e) {
                $falla(409, 'bloqueada');
            }
            if ($usuario) audit($db, $usuario, 'UPDATE', 'cotizaciones', ($tenia ? 'Reemplazó' : 'Adjuntó') . " la propuesta $numero de la cotización #{$c['numero']} (desde Claude · llave «{$llave['nombre']}»)", (int)$c['id']);
            igRegistrar($db, $lid, $endpoint, $numero, $tenia ? 'reemplazada' : 'creada');
            igResponder($tenia ? 200 : 201, [
                'ok' => true, 'tipo' => 'propuesta', 'numero' => $numero, 'cotizacion' => $c['numero'],
                'estado' => $c['estado'], 'panel_url' => $base . '/#/cotizaciones/' . (int)$c['id'],
            ]);
        }

        // ── Acta ──
        $c = null;
        if ($cotNum !== '') {
            $c = igCotizacion($db, $cotNum);
            if (!$c) $falla(404, 'no_existe');
            if ($clienteId && $clienteId !== (int)$c['cliente_id']) $falla(422, 'cliente_no_calza');
            // El acta lleva el número de su cotización: COT-2026-005(-v2) → ENT-2026-005(-2).
            preg_match('/^COT-(\d{4}-\d+)/', $c['numero'], $mc);
            preg_match('/^ENT-(\d{4}-\d+)/', $numero, $me);
            if (($mc[1] ?? '') !== ($me[1] ?? '')) $falla(422, 'numero_no_calza', ['esperado' => 'ENT-' . ($mc[1] ?? '')]);
            $clienteId = (int)$c['cliente_id'];
        }
        $st = $db->prepare("SELECT * FROM entregas WHERE numero = ? LIMIT 1");
        $st->execute([$numero]);
        $e = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($e) {
            if ($e['estado'] !== 'borrador') $falla(409, 'bloqueada', ['estado' => $e['estado']]);
            if ($c && $e['cotizacion_id'] && (int)$e['cotizacion_id'] !== (int)$c['id']) $falla(409, 'otra_cotizacion');
            $tenia = (bool)$e['html_path'];
            if ($tenia && !$reemplazar) $falla(409, 'ya_existe', ['subida_at' => $e['acta_subida_at'], 'origen' => $e['acta_origen']]);
            try {
                dsGuardarActa($db, $e, $_FILES['html'], $_FILES['pdf'], false, 'claude');
            } catch (DomainException $ex) {
                $falla(422, 'archivo_invalido', ['detalle' => $ex->getMessage()]);
            } catch (DsConflicto $ex) {
                $falla(409, 'bloqueada');
            }
            $entregaId = (int)$e['id'];
            $codigo = $tenia ? 200 : 201;
            if ($usuario) audit($db, $usuario, 'UPDATE', 'entregas', ($tenia ? 'Reemplazó' : 'Subió') . " el acta de la entrega $numero (desde Claude · llave «{$llave['nombre']}»)", $entregaId);
        } else {
            // Entrega nueva en borrador, vinculada a la cotización (y su cliente/proyecto) o al cliente.
            if (!$clienteId) $falla(422, 'falta_cliente');
            $ck = $db->prepare("SELECT id FROM clientes WHERE id = ? AND deleted_at IS NULL");
            $ck->execute([$clienteId]);
            if (!$ck->fetchColumn()) $falla(404, 'cliente_no_existe');
            $db->beginTransaction();
            try {
                $db->prepare("INSERT INTO entregas (cliente_id, proyecto_id, cotizacion_id, numero, token, plazo_dias_habiles, estado)
                              VALUES (?, ?, ?, ?, ?, 5, 'borrador')")
                   ->execute([$clienteId, $c['proyecto_id'] ?? null, $c['id'] ?? null, $numero, cotNuevoToken()]);
                $entregaId = (int)$db->lastInsertId();
                dsGuardarActa($db, ['id' => $entregaId, 'html_path' => null, 'pdf_path' => null], $_FILES['html'], $_FILES['pdf'], false, 'claude');
                $db->commit();
            } catch (DomainException $ex) {
                $db->rollBack();
                $falla(422, 'archivo_invalido', ['detalle' => $ex->getMessage()]);
            } catch (Throwable $ex) {
                if ($db->inTransaction()) $db->rollBack();
                throw $ex;
            }
            $tenia = false;
            $codigo = 201;
            if ($usuario) audit($db, $usuario, 'CREATE', 'entregas', "Creó la entrega $numero con su acta (desde Claude · llave «{$llave['nombre']}»)", $entregaId);
        }
        igRegistrar($db, $lid, $endpoint, $numero, $tenia ? 'reemplazada' : 'creada');
        igResponder($codigo, [
            'ok' => true, 'tipo' => 'acta', 'numero' => $numero, 'cotizacion' => $c['numero'] ?? null,
            'estado' => 'borrador', 'panel_url' => $base . '/#/entregas/' . $entregaId,
        ]);
    }

    igResponder(404, ['ok' => false, 'error' => 'ruta_no_existe']);
} catch (Throwable $e) {
    if (function_exists('logError')) logError('Integración: excepción', ['message' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
    if (isset($db)) igRegistrar($db, isset($lid) ? (int)$lid : null, $endpoint, $numero ?? null, 'error_interno');
    igResponder(500, ['ok' => false, 'error' => 'error_interno']);
}

// ─── Ayudas ───────────────────────────────────────────────────────────

function igCotizacion(PDO $db, string $numero): ?array {
    $st = $db->prepare("SELECT c.id, c.numero, c.estado, c.cliente_id, c.proyecto_id, c.aprobada_at,
                               c.propuesta_numero, c.propuesta_html_path, c.propuesta_pdf_path,
                               c.propuesta_origen, c.propuesta_subida_at,
                               COALESCE(NULLIF(cl.nombre_agencia, ''), cl.nombre) AS cliente
                        FROM cotizaciones c LEFT JOIN clientes cl ON cl.id = c.cliente_id
                        WHERE c.numero = ? AND c.deleted_at IS NULL LIMIT 1");
    $st->execute([$numero]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
