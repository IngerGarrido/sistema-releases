-- Recuerda el modo elegido por el usuario para el descuento global de
-- una cotización: porcentaje (%) o fijo ($). Al re-abrir la cotización
-- se muestra el descuento en el mismo formato que la persona ingresó.
ALTER TABLE `cotizaciones`
  ADD COLUMN `descuento_global_tipo` ENUM('porcentaje', 'fijo') NOT NULL DEFAULT 'porcentaje' AFTER `descuento_global`;
