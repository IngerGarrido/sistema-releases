-- Revierte 2026_11_21_4_entregas_lista_no_aplica.sql (ejecutar a mano).
ALTER TABLE `entregas` DROP COLUMN `lista_no_aplica`;
DELETE FROM `migraciones` WHERE archivo = '2026_11_21_4_entregas_lista_no_aplica.sql';
