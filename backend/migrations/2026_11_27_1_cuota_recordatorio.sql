-- Recordatorio de cobro: cuándo se le recordó al cliente una boleta vencida y
-- cuántas veces. Sirve para mostrar «Recordado hace N días» y no insistir de más.
-- Solo AGREGA dos columnas: no toca datos existentes.
-- Revertir: migrations/down/2026_11_27_1_cuota_recordatorio.sql

ALTER TABLE `pago_cuotas`
  ADD COLUMN `recordado_at` DATETIME DEFAULT NULL,
  ADD COLUMN `recordatorios` INT NOT NULL DEFAULT 0;
