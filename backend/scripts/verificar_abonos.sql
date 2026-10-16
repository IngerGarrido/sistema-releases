-- ─────────────────────────────────────────────────────────────────────────
-- verificar_abonos.sql — chequeo de consistencia entre la caché y los abonos
--
-- CONTEXTO
-- `pago_cuotas.pago_monto` es una CACHÉ de SUM(pago_abonos.monto) de esa cuota.
-- Todo el código que escribe pagos (backend/api/pagos.php, POST y PUT) actualiza
-- ambas cosas dentro de la misma transacción, así que en uso normal no pueden
-- desalinearse. Este script existe como red de seguridad por si alguien edita
-- la base a mano (SQL directo, restauración parcial de backup, etc.).
--
-- USO
--   mysql -u root -p sistema_local < backend/scripts/verificar_abonos.sql
--
-- NO modifica nada: solo reporta. La reparación está comentada al final.
-- ─────────────────────────────────────────────────────────────────────────

-- 1) Cuotas donde la caché NO coincide con la suma de sus abonos
SELECT
    pc.id            AS cuota_id,
    pc.pago_id,
    pc.estado,
    pc.boleta_monto,
    pc.pago_monto                                   AS cache_pago_monto,
    COALESCE((SELECT SUM(pa.monto) FROM pago_abonos pa
              WHERE pa.cuota_id = pc.id), 0)        AS suma_abonos,
    pc.pago_monto - COALESCE((SELECT SUM(pa.monto) FROM pago_abonos pa
                              WHERE pa.cuota_id = pc.id), 0) AS diferencia
FROM pago_cuotas pc
WHERE pc.pago_monto <> COALESCE((SELECT SUM(pa.monto) FROM pago_abonos pa
                                 WHERE pa.cuota_id = pc.id), 0);
-- Sin filas = todo consistente.

-- 2) Cuotas cuyo estado no corresponde a su monto cobrado
SELECT pc.id AS cuota_id, pc.estado, pc.boleta_monto, pc.pago_monto,
       CASE
           WHEN pc.pago_monto <= 0                                    THEN 'Pendiente'
           WHEN pc.boleta_monto > 0 AND pc.pago_monto < pc.boleta_monto THEN 'Parcial'
           ELSE 'Pagado'
       END AS estado_esperado
FROM pago_cuotas pc
WHERE pc.estado <> CASE
           WHEN pc.pago_monto <= 0                                    THEN 'Pendiente'
           WHEN pc.boleta_monto > 0 AND pc.pago_monto < pc.boleta_monto THEN 'Parcial'
           ELSE 'Pagado'
       END;

-- ─────────────────────────────────────────────────────────────────────────
-- REPARACIÓN (descomentar solo tras revisar el reporte de arriba)
--
-- ¡HAZ BACKUP ANTES! Y decide la dirección correcta según el caso:
--
-- A) Los ABONOS son la verdad (se registraron con sus fechas reales)
--    → recalcular la caché desde los abonos:
--
-- UPDATE pago_cuotas pc
-- SET pc.pago_monto = COALESCE((SELECT SUM(pa.monto) FROM pago_abonos pa
--                               WHERE pa.cuota_id = pc.id), 0);
--
-- B) La CUOTA es la verdad (la caché refleja la última edición real y el
--    abono quedó viejo, p.ej. residuo del backfill de la migración)
--    → ajustar el abono único de esa cuota:
--
-- UPDATE pago_abonos SET monto = <monto_correcto> WHERE cuota_id = <id>;
--
-- Tras cualquier reparación, volver a correr las consultas 1) y 2).
-- ─────────────────────────────────────────────────────────────────────────
