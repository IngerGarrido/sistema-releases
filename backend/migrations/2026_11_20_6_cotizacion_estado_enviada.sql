-- Asegura el estado 'Enviada' en cotizaciones.
-- Si la migración vieja 2026_05_20_5 corrió después de 2026_11_20_1 (pasó en
-- una instalación con migraciones viejas pendientes), el ENUM quedó sin
-- 'Enviada' y esas cotizaciones quedaron con estado vacío ('') y enviar una
-- cotización fallaba. Se repone el ENUM y se recupera el estado desde las fechas.
ALTER TABLE `cotizaciones`
  MODIFY COLUMN `estado` ENUM('Pendiente','Enviada','Aceptada','Rechazada','Expirada')
  COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pendiente';
UPDATE `cotizaciones`
   SET `estado` = CASE WHEN `aprobada_at` IS NOT NULL THEN 'Aceptada'
                       WHEN `enviada_at`  IS NOT NULL THEN 'Enviada'
                       ELSE 'Pendiente' END
 WHERE `estado` = '';
