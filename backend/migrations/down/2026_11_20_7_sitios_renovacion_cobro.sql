-- Revierte 2026_11_20_7_sitios_renovacion_cobro.sql (ejecutar a mano).
-- Se pierden los montos de renovación (los proyectos y cobros ya creados quedan).
ALTER TABLE `sitios`
  DROP COLUMN `dominio_monto`, DROP COLUMN `hosting_monto`,
  DROP COLUMN `dominio_cobrado`, DROP COLUMN `hosting_cobrado`;
DELETE FROM `migraciones` WHERE archivo = '2026_11_20_7_sitios_renovacion_cobro.sql';
