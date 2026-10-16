-- Aumenta la precisión de descuento_global en cotizaciones de DECIMAL(5,2) a
-- DECIMAL(7,4). Permite porcentajes con 4 decimales (ej: 3.2882 %), útil cuando
-- el usuario quiere un monto fijo de descuento "cerrado" (ej: $50.000 exactos
-- sobre un subtotal que no da un % redondo). 5,2 sólo permitía hasta 999.99 y
-- 2 decimales — riesgo de overflow al convertir un monto $ a %.
ALTER TABLE `cotizaciones`
  MODIFY COLUMN `descuento_global` DECIMAL(7,4) NOT NULL DEFAULT 0.0000;
