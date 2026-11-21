-- Revierte 2026_11_21_5_visitas.sql (ejecutar a mano).
-- Las vistas borradas en cotizaciones y actas no se recuperan.
DROP TABLE IF EXISTS `doc_visitas`;
DROP TABLE IF EXISTS `ips_panel`;
DELETE FROM `migraciones` WHERE archivo = '2026_11_21_5_visitas.sql';
