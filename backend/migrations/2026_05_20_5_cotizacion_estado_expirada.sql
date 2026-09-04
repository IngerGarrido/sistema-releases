-- El formulario de cotizaciones ofrece 4 estados (Pendiente, Aceptada,
-- Rechazada, Expirada) pero la BD sólo permitía 3. Si el usuario elegía
-- "Expirada" el INSERT/UPDATE fallaba. Agregamos 'Expirada' al enum.
ALTER TABLE `cotizaciones`
  MODIFY COLUMN `estado` ENUM('Pendiente','Aceptada','Rechazada','Expirada')
  COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pendiente';
