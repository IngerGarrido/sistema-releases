-- Precio por línea en los servicios de un proyecto (override del catálogo).
--
-- Antes: el precio de cada servicio venía fijo del catálogo (servicios.precio).
-- No se podía expresar trabajo cuyo precio varía por encargo (ej. "Cambios"
-- que una vez vale $80.000 y otra $150.000) sin ensuciar el catálogo.
--
-- Ahora: cada fila puede llevar su propio `precio_unitario`. NULL = usar el
-- precio del catálogo (comportamiento anterior, no rompe filas existentes).
-- El presupuesto = Σ (COALESCE(precio_unitario, servicios.precio) × cantidad).

ALTER TABLE `proyecto_servicios`
  ADD COLUMN `precio_unitario` int NULL DEFAULT NULL AFTER `cantidad`;
