-- Integración con Claude Code: subida automática de propuestas y actas
-- (docs: INSTRUCCIONES-integracion-claude.md en web-inger-nueva/propuestas/documentos).
--   - api_llaves: llaves de API (solo se guarda el SHA-256, nunca la llave en claro).
--   - api_registro: actividad de la integración (nunca el contenido de los archivos).
--   - *_origen / *_subida_at: marca "Subida desde Claude" / "Subida manual" en el panel.
-- Solo CREA tablas y AGREGA columnas NULL: no toca datos existentes (las propuestas y
-- actas que ya estaban quedan como "manual", sin fecha).
-- Revertir: migrations/down/2026_11_21_3_integracion_claude.sql

CREATE TABLE IF NOT EXISTS `api_llaves` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(80) NOT NULL,
  `llave_hash` CHAR(64) NOT NULL,
  `prefijo` CHAR(8) NOT NULL,
  `permisos` TEXT NOT NULL,
  `creada_por` INT UNSIGNED DEFAULT NULL,
  `creada_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ultimo_uso_at` DATETIME DEFAULT NULL,
  `ultimo_uso_ip` VARCHAR(45) DEFAULT NULL,
  `revocada_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_llaves_hash` (`llave_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_registro` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `llave_id` INT UNSIGNED DEFAULT NULL,
  `endpoint` VARCHAR(60) NOT NULL,
  `numero` VARCHAR(30) DEFAULT NULL,
  `resultado` VARCHAR(40) NOT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_api_registro_fecha` (`fecha`),
  KEY `idx_api_registro_llave` (`llave_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `cotizaciones`
  ADD COLUMN `propuesta_origen` ENUM('manual','claude') DEFAULT NULL,
  ADD COLUMN `propuesta_subida_at` DATETIME DEFAULT NULL;

ALTER TABLE `entregas`
  ADD COLUMN `acta_origen` ENUM('manual','claude') DEFAULT NULL,
  ADD COLUMN `acta_subida_at` DATETIME DEFAULT NULL;

UPDATE `cotizaciones` SET `propuesta_origen` = 'manual' WHERE `propuesta_html_path` IS NOT NULL;
UPDATE `entregas` SET `acta_origen` = 'manual' WHERE `html_path` IS NOT NULL;
