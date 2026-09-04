-- Tabla pivote para soportar múltiples servicios por proyecto.
-- proyectos.servicio_id (singular) se mantiene por compatibilidad y
-- se sincroniza con el primer servicio del pivote, para no romper
-- queries existentes que lo leen.
--
-- NOTA: No usamos FOREIGN KEY contra proyectos / servicios porque en
-- algunas instalaciones el tipo de `id` (signed vs unsigned) o la
-- collation no coinciden y MySQL devolvía "errno 150". El backend
-- mantiene la integridad referencial al sincronizar el pivote
-- (DELETE + INSERT en cada save).
CREATE TABLE IF NOT EXISTS `proyecto_servicios` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `proyecto_id` int unsigned NOT NULL,
  `servicio_id` int unsigned NOT NULL,
  `orden` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_proyecto_servicio` (`proyecto_id`, `servicio_id`),
  KEY `idx_proyecto` (`proyecto_id`),
  KEY `idx_servicio` (`servicio_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Migrar el servicio_id existente de cada proyecto al pivote.
INSERT IGNORE INTO `proyecto_servicios` (`proyecto_id`, `servicio_id`, `orden`)
SELECT `id`, `servicio_id`, 0
FROM `proyectos`
WHERE `servicio_id` IS NOT NULL;
