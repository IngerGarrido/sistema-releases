-- Soporte de pagos parciales sobre boletas.
--
-- Cuando una boleta es de $60.000 y solo se cobran $30.000, el estado
-- "Pagado" (que asume cobro completo) no aplica. Agregamos 'Parcial':
--   - Pendiente : pago_monto = 0  (sin pagar)
--   - Parcial   : 0 < pago_monto < boleta_monto  (faltó saldo)
--   - Pagado    : pago_monto >= boleta_monto    (cobrado completo)
--   - Vencido   : sin pagar y pasada la fecha
--
-- Los queries de "cobrado" (dashboard/reportes) deben sumar pago_monto
-- de cuotas en estado IN ('Pagado','Parcial') — el monto realmente
-- recibido, no el boleta_monto. Para "pendiente" se calcula el saldo:
-- (boleta_monto - pago_monto) cuando 'Parcial', o boleta_monto cuando
-- 'Pendiente'/'Vencido'.

ALTER TABLE `pago_cuotas`
  MODIFY COLUMN `estado` enum('Pendiente','Parcial','Pagado','Vencido')
  COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pendiente';
