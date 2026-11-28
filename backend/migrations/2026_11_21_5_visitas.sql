-- Visitas de los links públicos: que «Vista» sea del cliente y no de Inger.
--
-- 1) ips_panel: conexiones (hash de la IP, nunca la IP) desde las que se usó el
--    panel. Una visita al link desde una de ellas en los últimos 30 días no cuenta.
-- 2) doc_visitas: cada visita que sí cuenta, con fecha y dispositivo, para que
--    Inger la reconozca en la lista («Abierta 2 veces · Celular · Android»).
--    Recargar la página dentro de 30 minutos (mismo dispositivo y conexión) no suma.
-- 3) Las vistas registradas antes no distinguían a Inger del cliente: se borran
--    en cotizaciones y actas que siguen esperando respuesta (sin aprobar ni
--    recibir). La próxima visita real del cliente las vuelve a marcar.
-- Revertir: migrations/down/2026_11_21_5_visitas.sql

CREATE TABLE IF NOT EXISTS `ips_panel` (
  `ip_hash` CHAR(64) NOT NULL,
  `visto_at` DATETIME NOT NULL,
  PRIMARY KEY (`ip_hash`),
  KEY `idx_visto` (`visto_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `doc_visitas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `doc` ENUM('cotizacion','propuesta','acta') NOT NULL,
  `ref_id` INT NOT NULL,
  `visto_at` DATETIME NOT NULL,
  `dispositivo` VARCHAR(60) NOT NULL DEFAULT '',
  `ip_hash` CHAR(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_doc_ref` (`doc`, `ref_id`, `visto_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

UPDATE `cotizaciones` SET `vista_at` = NULL, `propuesta_vista_at` = NULL
 WHERE `aprobada_at` IS NULL AND `estado` IN ('Pendiente','Enviada');

UPDATE `entregas` SET `vista_at` = NULL
 WHERE `recibida_at` IS NULL;
