-- Renovación de dominio / hosting como cobro automático (opcional por sitio).
-- Con monto > 0, 30 días antes del vencimiento se crea el proyecto
-- "Renovación de dominio AAAA" con su cobro pendiente en Pagos (una vez por
-- vencimiento: *_cobrado guarda el vencimiento ya cobrado) y, pasada la fecha,
-- el vencimiento avanza un año. Sin monto: solo el aviso en "Mi semana".
ALTER TABLE `sitios`
  ADD COLUMN `dominio_monto` int unsigned DEFAULT NULL AFTER `hosting_vence`,
  ADD COLUMN `hosting_monto` int unsigned DEFAULT NULL AFTER `dominio_monto`,
  ADD COLUMN `dominio_cobrado` date DEFAULT NULL AFTER `hosting_monto`,
  ADD COLUMN `hosting_cobrado` date DEFAULT NULL AFTER `dominio_cobrado`;
