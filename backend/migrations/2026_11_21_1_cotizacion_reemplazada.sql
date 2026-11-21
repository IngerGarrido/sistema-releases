-- Estado 'Reemplazada' para cotizaciones: al crear una versión nueva (-v2, -v3…),
-- las versiones anteriores que seguían Pendiente/Enviada dejan de contar como
-- "por atender" y no se vencen solas. La versión aprobada nunca se toca.
ALTER TABLE `cotizaciones`
  MODIFY COLUMN `estado` ENUM('Pendiente','Enviada','Aceptada','Rechazada','Expirada','Reemplazada')
  COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pendiente';
