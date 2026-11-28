-- Revierte 2026_11_20_6_cotizacion_estado_enviada.sql (ejecutar a mano).
-- No se quita 'Enviada' del ENUM (el sistema lo necesita); solo se desmarca.
DELETE FROM `migraciones` WHERE archivo = '2026_11_20_6_cotizacion_estado_enviada.sql';
