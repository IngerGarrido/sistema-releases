-- Revierte 2026_11_20_4_entregas_garantia.sql (ejecutar a mano).
-- Se pierden los meses de garantía elegidos en cada entrega.
ALTER TABLE `entregas`
  DROP COLUMN `garantia_meses`,
  DROP COLUMN `garantia_aviso_at`;
DELETE FROM `migraciones` WHERE archivo = '2026_11_20_4_entregas_garantia.sql';
