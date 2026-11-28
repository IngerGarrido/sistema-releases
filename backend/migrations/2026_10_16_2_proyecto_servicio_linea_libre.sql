-- Línea libre en los servicios de un proyecto (sin servicio del catálogo).
--
-- Antes: cada fila de proyecto_servicios DEBÍA apuntar a un servicio del
-- catálogo (servicio_id NOT NULL). No se podía registrar trabajo puntual que
-- no encaja con ningún servicio existente.
--
-- Ahora: servicio_id puede ser NULL y la fila lleva su propio `nombre`.
--   - Línea de catálogo: servicio_id = id, nombre = NULL (se resuelve del catálogo).
--   - Línea libre:       servicio_id = NULL, nombre = texto, precio_unitario = precio.
-- La UNIQUE(proyecto_id, servicio_id) sigue existiendo: MySQL trata cada NULL
-- como distinto, así que admite varias líneas libres en el mismo proyecto.

ALTER TABLE `proyecto_servicios`
  MODIFY COLUMN `servicio_id` int unsigned NULL,
  ADD COLUMN `nombre` varchar(200) NULL DEFAULT NULL AFTER `servicio_id`;
