-- Lista de revisión "no aplica": para trabajos acotados (un rediseño de páginas,
-- un complemento) donde la lista pensada para sitios nuevos no corresponde.
-- Con 1, el envío no pide "Enviar igual" y la auditoría lo anota como "no aplica".
-- Solo AGREGA una columna con 0 por defecto: no toca datos existentes.
-- Revertir: migrations/down/2026_11_21_4_entregas_lista_no_aplica.sql

ALTER TABLE `entregas`
  ADD COLUMN `lista_no_aplica` TINYINT(1) NOT NULL DEFAULT 0 AFTER `checklist`;
