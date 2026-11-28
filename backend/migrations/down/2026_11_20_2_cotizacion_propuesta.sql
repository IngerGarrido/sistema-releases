-- Revierte 2026_11_20_2_cotizacion_propuesta.sql (ejecutar a mano).
-- Ojo: borra el vínculo a las propuestas subidas; los archivos quedan en
-- backend/uploads/propuestas/ y se pueden eliminar aparte.
ALTER TABLE `cotizaciones`
  DROP COLUMN `propuesta_numero`,
  DROP COLUMN `propuesta_html_path`,
  DROP COLUMN `propuesta_pdf_path`,
  DROP COLUMN `propuesta_hash`,
  DROP COLUMN `propuesta_hojas`,
  DROP COLUMN `propuesta_vista_at`;
DELETE FROM `migraciones` WHERE archivo = '2026_11_20_2_cotizacion_propuesta.sql';
