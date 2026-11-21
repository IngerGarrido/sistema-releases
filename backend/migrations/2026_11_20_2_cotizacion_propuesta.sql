-- Propuesta (opcional) adjunta a la cotización.
-- Inger arma la propuesta fuera del sistema (HTML + PDF) y la sube; el sistema
-- solo la guarda, la muestra en /c/{token} (pestaña "Propuesta") y adjunta el
-- PDF a los correos. Solo AGREGA columnas NULL: no toca datos existentes.
-- Los archivos viven en backend/uploads/propuestas/ (nombre aleatorio, sin
-- extensión, acceso web denegado). Revertir: migrations/down/2026_11_20_2_cotizacion_propuesta.sql

ALTER TABLE `cotizaciones`
  ADD COLUMN `propuesta_numero` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN `propuesta_html_path` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN `propuesta_pdf_path` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN `propuesta_hash` CHAR(64) DEFAULT NULL,
  ADD COLUMN `propuesta_hojas` TINYINT UNSIGNED DEFAULT NULL,
  ADD COLUMN `propuesta_vista_at` DATETIME DEFAULT NULL;
