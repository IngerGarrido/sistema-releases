-- Revierte 2026_11_21_6_cotizacion_respuesta.sql (ejecutar a mano).
ALTER TABLE `cotizaciones`
  DROP COLUMN `respuesta_tipo`,
  DROP COLUMN `respuesta_motivo`,
  DROP COLUMN `respuesta_texto`,
  DROP COLUMN `respuesta_at`;
DELETE FROM `migraciones` WHERE archivo = '2026_11_21_6_cotizacion_respuesta.sql';
