-- Garantía de la entrega: meses de garantía (desde que el cliente recibe el
-- sitio) y marca del aviso "la garantía está por terminar" (para no repetirlo).
-- "Garantía hasta" = fecha de recepción + meses − 1 día (se calcula, no se guarda).
-- Solo AGREGA columnas: no toca datos existentes (las entregas actuales quedan con 3 meses).
-- Revertir: migrations/down/2026_11_20_4_entregas_garantia.sql

ALTER TABLE `entregas`
  ADD COLUMN `garantia_meses` TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER `plazo_dias_habiles`,
  ADD COLUMN `garantia_aviso_at` DATETIME DEFAULT NULL;
