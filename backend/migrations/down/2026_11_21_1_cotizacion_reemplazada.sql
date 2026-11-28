-- Revierte 2026_11_21_1_cotizacion_reemplazada.sql (ejecutar a mano).
UPDATE `cotizaciones` SET `estado` = 'Rechazada' WHERE `estado` = 'Reemplazada';
ALTER TABLE `cotizaciones`
  MODIFY COLUMN `estado` ENUM('Pendiente','Enviada','Aceptada','Rechazada','Expirada')
  COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pendiente';
DELETE FROM `migraciones` WHERE archivo = '2026_11_21_1_cotizacion_reemplazada.sql';
