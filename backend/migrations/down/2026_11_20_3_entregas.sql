-- Revierte 2026_11_20_3_entregas.sql (ejecutar a mano).
-- Ojo: borra todas las entregas registradas. Los archivos quedan en
-- backend/uploads/actas/ y se pueden eliminar aparte.
DROP TABLE IF EXISTS `entregas`;
DELETE FROM `migraciones` WHERE archivo = '2026_11_20_3_entregas.sql';
