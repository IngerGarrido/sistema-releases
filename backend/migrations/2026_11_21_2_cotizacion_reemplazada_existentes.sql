-- Marca como 'Reemplazada' las versiones anteriores que seguían esperando
-- respuesta (Pendiente/Enviada) cuando ya existe una versión más nueva del
-- mismo número (COT-…-v2 frente a COT-…-v3). No toca aprobadas ni borra nada.
UPDATE `cotizaciones` c
JOIN (
  SELECT REGEXP_REPLACE(numero, '-v[0-9]+$', '') AS base,
         MAX(IF(numero REGEXP '-v[0-9]+$', CAST(REGEXP_SUBSTR(numero, '[0-9]+$') AS UNSIGNED), 1)) AS ultima
  FROM `cotizaciones`
  WHERE deleted_at IS NULL
  GROUP BY base
) v ON v.base = REGEXP_REPLACE(c.numero, '-v[0-9]+$', '')
SET c.estado = 'Reemplazada'
WHERE c.deleted_at IS NULL
  AND c.aprobada_at IS NULL
  AND c.estado IN ('Pendiente', 'Enviada')
  AND IF(c.numero REGEXP '-v[0-9]+$', CAST(REGEXP_SUBSTR(c.numero, '[0-9]+$') AS UNSIGNED), 1) < v.ultima;
