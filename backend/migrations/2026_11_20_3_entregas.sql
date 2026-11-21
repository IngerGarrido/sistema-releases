-- Módulo Entregas (opcional): acta de entrega que Inger arma FUERA del sistema
-- (HTML + PDF) y el sistema guarda, muestra en /e/{token} y adjunta al correo.
-- Vinculada a un cliente y, si existen, al proyecto y a la cotización.
-- Solo CREA una tabla nueva: no toca datos existentes.
-- Revertir: migrations/down/2026_11_20_3_entregas.sql
--
-- Estados: borrador → enviada → recibida (el cliente confirmó) |
--          recibida_por_plazo (pasó el plazo sin observaciones) | con_observaciones
-- checklist: JSON con las casillas marcadas (se guarda como texto por compatibilidad).

CREATE TABLE IF NOT EXISTS `entregas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cliente_id` INT UNSIGNED NOT NULL,
  `proyecto_id` INT UNSIGNED DEFAULT NULL,
  `cotizacion_id` INT UNSIGNED DEFAULT NULL,
  `numero` VARCHAR(20) NOT NULL,
  `token` CHAR(43) NOT NULL,
  `dominio` VARCHAR(150) DEFAULT NULL,
  `fecha_entrega` DATE DEFAULT NULL,
  `plazo_dias_habiles` TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `html_path` VARCHAR(255) DEFAULT NULL,
  `pdf_path` VARCHAR(255) DEFAULT NULL,
  `hash` CHAR(64) DEFAULT NULL,
  `hojas` TINYINT UNSIGNED DEFAULT NULL,
  `checklist` TEXT DEFAULT NULL,
  `estado` ENUM('borrador','enviada','recibida','recibida_por_plazo','con_observaciones') NOT NULL DEFAULT 'borrador',
  `enviada_at` DATETIME DEFAULT NULL,
  `enviada_a` VARCHAR(255) DEFAULT NULL,
  `plazo_vence` DATE DEFAULT NULL,
  `vista_at` DATETIME DEFAULT NULL,
  `recibida_at` DATETIME DEFAULT NULL,
  `recibida_nombre` VARCHAR(150) DEFAULT NULL,
  `recibida_ip` VARCHAR(45) DEFAULT NULL,
  `recibida_user_agent` VARCHAR(255) DEFAULT NULL,
  `observaciones` TEXT DEFAULT NULL,
  `observacion_at` DATETIME DEFAULT NULL,
  `notas` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_entregas_token` (`token`),
  UNIQUE KEY `uq_entregas_numero` (`numero`),
  KEY `idx_entregas_cliente` (`cliente_id`),
  KEY `idx_entregas_proyecto` (`proyecto_id`),
  KEY `idx_entregas_estado` (`estado`, `plazo_vence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
