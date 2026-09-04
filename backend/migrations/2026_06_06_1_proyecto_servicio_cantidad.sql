-- Cantidad por servicio en un proyecto.
--
-- Antes: proyecto_servicios solo guardaba (proyecto_id, servicio_id) — cada
-- servicio contaba una vez y el presupuesto sumaba su precio unitario. No se
-- podía expresar "3 × pestaña adicional".
--
-- Ahora: cada fila lleva `cantidad`. El presupuesto = Σ (precio × cantidad).
-- La UNIQUE(proyecto_id, servicio_id) se mantiene: el mismo servicio no se
-- repite en filas; se ajusta su cantidad.

ALTER TABLE `proyecto_servicios`
  ADD COLUMN `cantidad` int NOT NULL DEFAULT 1 AFTER `servicio_id`;
