-- Sitios: renovaciones (dominio / hosting) y mantención mensual automática.
--
-- Renovaciones: fecha en que vence el dominio y el hosting del sitio. Se
-- muestran en Sitios y en "Mi semana" del Panel cuando faltan ≤ 30 días.
--
-- Mantención mensual: si `mantencion_monto` > 0, el sistema crea solo cada mes
-- (a partir de `mantencion_dia`) un proyecto "Mantención <mes año>" en ese
-- sitio con su cobro pendiente en Pagos. `mantencion_ultimo_periodo` ('AAAA-MM')
-- guarda el último mes generado para no duplicar.

ALTER TABLE `sitios`
  ADD COLUMN `dominio_vence` date DEFAULT NULL AFTER `url_principal`;
ALTER TABLE `sitios`
  ADD COLUMN `hosting_vence` date DEFAULT NULL AFTER `dominio_vence`;
ALTER TABLE `sitios`
  ADD COLUMN `mantencion_monto` int unsigned DEFAULT NULL AFTER `hosting_vence`;
ALTER TABLE `sitios`
  ADD COLUMN `mantencion_dia` tinyint unsigned NOT NULL DEFAULT 1 AFTER `mantencion_monto`;
ALTER TABLE `sitios`
  ADD COLUMN `mantencion_ultimo_periodo` char(7) DEFAULT NULL AFTER `mantencion_dia`;
