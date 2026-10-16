-- Abonos por fecha sobre cada cuota/boleta.
--
-- PROBLEMA: cada cuota tenía UN solo pago_monto + UNA sola pago_fecha. Si una
-- boleta se cobraba en partes (parcial en mayo, saldo en junio), no había forma
-- de saber qué monto entró en qué mes: todo quedaba con una única fecha.
--
-- SOLUCIÓN: tabla pago_abonos. Cada abono tiene su propio monto y fecha. La
-- cuota sigue siendo la "boleta"; los abonos son los cobros dentro de ella.
--
-- Compatibilidad: pago_cuotas.pago_monto se mantiene como CACHÉ = SUM(abonos) y
-- pago_fecha = MAX(abonos.fecha). Así todas las consultas de totales agregados
-- (por cliente, saldos pendientes, listados) siguen funcionando sin cambios.
-- Solo las consultas POR MES/PERÍODO (dashboard, reportes) leen pago_abonos.

CREATE TABLE IF NOT EXISTS `pago_abonos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cuota_id` int NOT NULL,
  `monto` int NOT NULL DEFAULT '0',
  `fecha` date NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cuota_id` (`cuota_id`),
  KEY `idx_fecha` (`fecha`),
  CONSTRAINT `fk_abono_cuota` FOREIGN KEY (`cuota_id`)
    REFERENCES `pago_cuotas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Backfill: 1 abono por cada cuota ya cobrada (Pagado/Parcial con monto > 0).
-- Fecha: pago_fecha si existe; si no, la fecha de la boleta; último recurso, hoy.
-- Solo inserta si la cuota aún no tiene abonos (idempotente / seguro de re-correr).
INSERT INTO `pago_abonos` (`cuota_id`, `monto`, `fecha`)
SELECT pc.id,
       pc.pago_monto,
       COALESCE(pc.pago_fecha, pc.boleta_fecha, CURDATE())
FROM `pago_cuotas` pc
WHERE pc.estado IN ('Pagado', 'Parcial')
  AND pc.pago_monto > 0
  AND NOT EXISTS (
    SELECT 1 FROM `pago_abonos` pa WHERE pa.cuota_id = pc.id
  );
