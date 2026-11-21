-- Revierte 2026_11_20_5_notificaciones_viejas.sql (ejecutar a mano).
-- Los avisos borrados no se recuperan (eran recordatorios); solo se desmarca la migración.
DELETE FROM `migraciones` WHERE archivo = '2026_11_20_5_notificaciones_viejas.sql';
