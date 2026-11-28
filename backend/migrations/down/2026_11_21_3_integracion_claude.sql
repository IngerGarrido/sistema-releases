-- Revierte 2026_11_21_3_integracion_claude.sql (ejecutar a mano).
-- Se pierden las llaves de API, el registro de actividad y la marca de origen.
DROP TABLE IF EXISTS `api_registro`;
DROP TABLE IF EXISTS `api_llaves`;
ALTER TABLE `cotizaciones`
  DROP COLUMN `propuesta_origen`,
  DROP COLUMN `propuesta_subida_at`;
ALTER TABLE `entregas`
  DROP COLUMN `acta_origen`,
  DROP COLUMN `acta_subida_at`;
DELETE FROM `migraciones` WHERE archivo = '2026_11_21_3_integracion_claude.sql';
