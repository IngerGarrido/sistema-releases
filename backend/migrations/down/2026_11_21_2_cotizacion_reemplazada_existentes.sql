-- Revierte 2026_11_21_2 (ejecutar a mano): las reemplazadas vuelven a Pendiente.
UPDATE `cotizaciones` SET `estado` = 'Pendiente' WHERE `estado` = 'Reemplazada' AND aprobada_at IS NULL;
DELETE FROM `migraciones` WHERE archivo = '2026_11_21_2_cotizacion_reemplazada_existentes.sql';
