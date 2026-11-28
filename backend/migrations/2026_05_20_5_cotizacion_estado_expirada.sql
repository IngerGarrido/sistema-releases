-- El formulario de cotizaciones ofrece 4 estados (Pendiente, Aceptada,
-- Rechazada, Expirada) pero la BD sólo permitía 3. Si el usuario elegía
-- "Expirada" el INSERT/UPDATE fallaba. Agregamos 'Expirada' al enum.
-- (2026-11-20) Incluye 'Enviada': si esta migración corría DESPUÉS de
-- 2026_11_20_1_cotizacion_aprobacion borraba ese estado (pasó en local).
ALTER TABLE `cotizaciones`
  MODIFY COLUMN `estado` ENUM('Pendiente','Enviada','Aceptada','Rechazada','Expirada')
  COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pendiente';
