-- Mantención periódica (reemplaza la lógica "mensual por día del mes").
--
-- Por lo general una mantención se cobra cada 3, 6 o 12 meses, no mensual.
--   mantencion_frecuencia: cada cuántos meses (1, 3, 6 o 12).
--   mantencion_proxima:    fecha del próximo cobro automático. Al llegar, se
--                          crea el proyecto "Mantención <mes año>" con su cobro
--                          pendiente y la fecha avanza según la frecuencia.
-- mantencion_dia guarda el día "ancla" (para meses cortos: 31 → 28/30 → 31).
-- mantencion_ultimo_periodo queda como registro histórico (ya no se usa).

ALTER TABLE `sitios`
  ADD COLUMN `mantencion_frecuencia` tinyint unsigned NOT NULL DEFAULT 1 AFTER `mantencion_monto`;
ALTER TABLE `sitios`
  ADD COLUMN `mantencion_proxima` date DEFAULT NULL AFTER `mantencion_frecuencia`;

-- Sitios que ya tenían mantención mensual: próximo cobro = mes siguiente al
-- último generado (o este mes si aún no se generó), en su día configurado.
UPDATE `sitios`
   SET `mantencion_proxima` = DATE_ADD(
         STR_TO_DATE(CONCAT(COALESCE(`mantencion_ultimo_periodo`, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m')), '-01'), '%Y-%m-%d'),
         INTERVAL 1 MONTH) + INTERVAL (LEAST(`mantencion_dia`, 28) - 1) DAY
 WHERE `mantencion_monto` > 0 AND `mantencion_proxima` IS NULL;
