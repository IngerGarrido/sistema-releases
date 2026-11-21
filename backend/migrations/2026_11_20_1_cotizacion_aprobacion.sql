-- Aprobación en línea de cotizaciones + nuevo diseño del PDF.
--
-- 1) Estado nuevo 'Enviada' (se envió el link al cliente). Equivalencias:
--    Pendiente = borrador · Enviada = enviada · Aceptada = aprobada ·
--    Expirada = vencida · Rechazada = anulada.
-- 2) Condiciones comerciales de la pág. 1 (forma de pago y plazo).
-- 3) Copia de los textos de la plantilla en la propia cotización: si después
--    se edita la plantilla, las cotizaciones ya creadas no cambian.
-- 4) Datos del link público y del registro de aprobación.

ALTER TABLE `cotizaciones`
  MODIFY COLUMN `estado` ENUM('Pendiente','Enviada','Aceptada','Rechazada','Expirada')
    COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pendiente',
  ADD COLUMN `forma_pago` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN `plazo_estimado` VARCHAR(150) DEFAULT NULL,
  ADD COLUMN `terminos_obligaciones` TEXT DEFAULT NULL,
  ADD COLUMN `terminos_desarrollo` TEXT DEFAULT NULL,
  ADD COLUMN `terminos_condiciones` TEXT DEFAULT NULL,
  ADD COLUMN `token` CHAR(43) DEFAULT NULL,
  ADD COLUMN `enviada_at` DATETIME DEFAULT NULL,
  ADD COLUMN `vista_at` DATETIME DEFAULT NULL,
  ADD COLUMN `aprobada_at` DATETIME DEFAULT NULL,
  ADD COLUMN `aprobada_nombre` VARCHAR(150) DEFAULT NULL,
  ADD COLUMN `aprobada_rut` VARCHAR(12) DEFAULT NULL,
  ADD COLUMN `aprobada_ip` VARCHAR(45) DEFAULT NULL,
  ADD COLUMN `aprobada_user_agent` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN `aprobada_hash` CHAR(64) DEFAULT NULL,
  ADD COLUMN `codigo_verificacion` CHAR(9) DEFAULT NULL,
  ADD UNIQUE KEY `uq_cotizaciones_token` (`token`);

-- Copiar a las cotizaciones existentes los textos que hoy muestran
-- (de su plantilla activa, o los términos base de Configuración).
UPDATE `cotizaciones` c
LEFT JOIN `plantillas_terminos` p ON p.id = c.plantilla_id AND p.activa = 1
SET c.terminos_obligaciones = IF(p.id IS NOT NULL, p.terminos_obligaciones,
      (SELECT valor FROM `configuracion` WHERE clave = 'terminos_obligaciones' LIMIT 1)),
    c.terminos_desarrollo = IF(p.id IS NOT NULL, p.terminos_desarrollo,
      (SELECT valor FROM `configuracion` WHERE clave = 'terminos_desarrollo' LIMIT 1)),
    c.terminos_condiciones = IF(p.id IS NOT NULL, p.terminos_condiciones,
      (SELECT valor FROM `configuracion` WHERE clave = 'terminos_condiciones' LIMIT 1));
