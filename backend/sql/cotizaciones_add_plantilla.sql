-- Agregar campo plantilla_id a cotizaciones para guardar la plantilla de términos seleccionada

ALTER TABLE cotizaciones 
ADD COLUMN plantilla_id INT DEFAULT NULL COMMENT 'ID de la plantilla de términos seleccionada',
ADD INDEX idx_plantilla_id (plantilla_id);
