-- Respuesta del cliente desde el link de la cotización (además de aprobar):
--   'comentario' = quiere ajustar algo: la cotización sigue abierta y en la lista
--                  aparece «Con comentarios».
--   'rechazo'    = no va a seguir: la cotización pasa a Rechazada y el link
--                  muestra que avisó (no da «no encontrada»).
-- Se guarda solo la última respuesta; cada una llega además por correo y a la campana.
-- Solo AGREGA columnas vacías: no toca datos existentes.
-- Revertir: migrations/down/2026_11_21_6_cotizacion_respuesta.sql

ALTER TABLE `cotizaciones`
  ADD COLUMN `respuesta_tipo` ENUM('comentario','rechazo') DEFAULT NULL,
  ADD COLUMN `respuesta_motivo` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN `respuesta_texto` TEXT DEFAULT NULL,
  ADD COLUMN `respuesta_at` DATETIME DEFAULT NULL;
