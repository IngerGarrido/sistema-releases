-- Eliminar el estado "Vencido" del flujo de cuotas.
--
-- "Vencido" es información derivada (boleta_fecha < hoy AND estado != 'Pagado'),
-- no un estado que el usuario maneje manualmente. Mantenerlo en el ENUM
-- confundía la UX: el usuario veía 4 opciones cuando en realidad solo gestiona 3.
--
-- Estados finales:
--   - Pendiente : sin cobrar (pago_monto = 0). Si la fecha está pasada, la UI
--                 lo presenta visualmente como "vencido" pero el estado interno
--                 sigue siendo Pendiente.
--   - Parcial   : 0 < pago_monto < boleta_monto
--   - Pagado    : pago_monto >= boleta_monto

-- 1) Migrar las cuotas existentes en estado 'Vencido' a 'Pendiente'.
UPDATE `pago_cuotas` SET `estado` = 'Pendiente' WHERE `estado` = 'Vencido';

-- 2) Reducir el ENUM (sin 'Vencido').
ALTER TABLE `pago_cuotas`
  MODIFY COLUMN `estado` enum('Pendiente','Parcial','Pagado')
  COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pendiente';
