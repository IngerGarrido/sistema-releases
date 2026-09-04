/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accesos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sitio_id` int unsigned NOT NULL,
  `tipo` enum('wp','ftp','hosting','bd','email','dominio','panel','otro') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'otro',
  `etiqueta` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usuario` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_cipher` text COLLATE utf8mb4_unicode_ci,
  `notas` text COLLATE utf8mb4_unicode_ci,
  `creado_por` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sitio` (`sitio_id`),
  KEY `idx_tipo` (`tipo`),
  KEY `fk_accesos_user` (`creado_por`),
  CONSTRAINT `fk_accesos_sitio` FOREIGN KEY (`sitio_id`) REFERENCES `sitios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_accesos_user` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auditoria` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int NOT NULL,
  `accion` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tabla_afectada` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `registro_id` int DEFAULT NULL,
  `detalle` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usuario_fecha` (`usuario_id`,`created_at`),
  KEY `idx_accion` (`accion`),
  KEY `idx_tabla` (`tabla_afectada`),
  KEY `idx_fecha` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=488 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clientes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tipo` enum('Agencia','Directo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Directo',
  `nombre_agencia` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rut` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notas` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuracion` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `clave` varchar(60) COLLATE utf8mb4_general_ci NOT NULL,
  `valor` text COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clave` (`clave`)
) ENGINE=InnoDB AUTO_INCREMENT=1415 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cotizacion_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cotizacion_id` int unsigned NOT NULL,
  `descripcion` varchar(300) COLLATE utf8mb4_general_ci NOT NULL,
  `cantidad` decimal(10,2) NOT NULL DEFAULT '1.00',
  `unidad` varchar(50) COLLATE utf8mb4_general_ci DEFAULT 'Servicio',
  `servicio_id` int unsigned DEFAULT NULL,
  `precio_unitario` decimal(14,2) NOT NULL DEFAULT '0.00',
  `descuento` decimal(5,2) NOT NULL DEFAULT '0.00',
  `orden` int DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `cotizacion_id` (`cotizacion_id`),
  KEY `idx_cotizacion_id` (`cotizacion_id`),
  CONSTRAINT `cotizacion_items_ibfk_1` FOREIGN KEY (`cotizacion_id`) REFERENCES `cotizaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=97 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cotizaciones` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `numero` varchar(30) COLLATE utf8mb4_general_ci NOT NULL,
  `proyecto_id` int unsigned DEFAULT NULL,
  `cliente_id` int unsigned NOT NULL,
  `fecha` date NOT NULL,
  `fecha_validez` date DEFAULT NULL,
  `estado` enum('Pendiente','Aceptada','Rechazada','Expirada') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pendiente',
  `notas` text COLLATE utf8mb4_general_ci,
  `descuento_global` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `descuento_global_tipo` enum('porcentaje','fijo') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'porcentaje',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `plantilla_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero` (`numero`),
  KEY `proyecto_id` (`proyecto_id`),
  KEY `cliente_id` (`cliente_id`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_plantilla_id` (`plantilla_id`),
  KEY `idx_cliente_id` (`cliente_id`),
  KEY `idx_proyecto_id` (`proyecto_id`),
  CONSTRAINT `cotizaciones_ibfk_1` FOREIGN KEY (`proyecto_id`) REFERENCES `proyectos` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `cotizaciones_ibfk_2` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_queue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `to_email` varchar(255) NOT NULL,
  `to_name` varchar(255) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `body_html` text,
  `body_text` text,
  `from_email` varchar(255) DEFAULT 'noreply@sistema.local',
  `from_name` varchar(255) DEFAULT 'Sistema Local',
  `priority` int DEFAULT '5',
  `status` enum('pending','processing','sent','failed') DEFAULT 'pending',
  `attempts` int DEFAULT '0',
  `max_attempts` int DEFAULT '3',
  `error_message` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `scheduled_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `processed_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_scheduled` (`status`,`scheduled_at`),
  KEY `idx_priority` (`priority`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exitoso` tinyint(1) DEFAULT '0',
  `intento_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_intento` (`ip`,`intento_en`),
  KEY `idx_intento_en` (`intento_en`)
) ENGINE=InnoDB AUTO_INCREMENT=106 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migraciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `archivo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ejecutada_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `duracion_ms` int DEFAULT NULL,
  `checksum` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `archivo` (`archivo`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notificaciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int DEFAULT NULL,
  `tipo` varchar(40) NOT NULL DEFAULT 'info',
  `mensaje` varchar(500) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `leida` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_leida` (`usuario_id`,`leida`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pago_cuotas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pago_id` int NOT NULL,
  `numero_cuota` int NOT NULL DEFAULT '1',
  `boleta_num` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `boleta_fecha` date DEFAULT NULL,
  `boleta_monto` int DEFAULT '0',
  `pago_fecha` date DEFAULT NULL,
  `pago_monto` int DEFAULT '0',
  `estado` enum('Pendiente','Parcial','Pagado') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pendiente',
  `tipo_pago` enum('boleta','directo') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'boleta',
  PRIMARY KEY (`id`),
  KEY `fk_pago_relacion` (`pago_id`),
  KEY `idx_pago_id` (`pago_id`),
  KEY `idx_estado` (`estado`),
  CONSTRAINT `fk_pago_relacion` FOREIGN KEY (`pago_id`) REFERENCES `pagos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;

-- Abonos por fecha sobre cada cuota (cobros parciales con su propia fecha).
CREATE TABLE `pago_abonos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cuota_id` int NOT NULL,
  `monto` int NOT NULL DEFAULT '0',
  `fecha` date NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cuota_id` (`cuota_id`),
  KEY `idx_fecha` (`fecha`),
  CONSTRAINT `fk_abono_cuota` FOREIGN KEY (`cuota_id`) REFERENCES `pago_cuotas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pagos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tipo_pago` enum('boleta','directo') COLLATE utf8mb4_spanish_ci NOT NULL DEFAULT 'boleta',
  `proyecto_id` int DEFAULT NULL,
  `cliente_id` int NOT NULL,
  `descripcion` varchar(255) COLLATE utf8mb4_spanish_ci DEFAULT NULL,
  `monto_total` int NOT NULL DEFAULT '0',
  `fecha_creacion` datetime DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_cliente_id` (`cliente_id`),
  KEY `idx_proyecto_id` (`proyecto_id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int NOT NULL,
  `token` varchar(255) NOT NULL,
  `expira_en` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_token` (`token`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plantillas_terminos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nombre descriptivo de la plantilla (ej: Ecommerce, Web Corporativa)',
  `descripcion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Descripción breve de cuándo usar esta plantilla',
  `terminos_obligaciones` text COLLATE utf8mb4_unicode_ci COMMENT 'Obligaciones del cliente específicas de esta plantilla',
  `terminos_desarrollo` text COLLATE utf8mb4_unicode_ci COMMENT 'Proceso de desarrollo específico de esta plantilla',
  `terminos_condiciones` text COLLATE utf8mb4_unicode_ci COMMENT 'Términos y condiciones específicos de esta plantilla',
  `activa` tinyint(1) DEFAULT '1' COMMENT 'Si la plantilla está disponible para usar',
  `orden` int DEFAULT '0' COMMENT 'Orden de aparición en el selector',
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activa` (`activa`),
  KEY `idx_orden` (`orden`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
-- Relación many-to-many: un proyecto puede tener múltiples servicios.
-- El campo legacy `proyectos.servicio_id` se mantiene y se sincroniza con
-- el primer servicio del pivote.
CREATE TABLE `proyecto_servicios` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `proyecto_id` int unsigned NOT NULL,
  `servicio_id` int unsigned NOT NULL,
  `cantidad` int NOT NULL DEFAULT 1,
  `orden` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_proyecto_servicio` (`proyecto_id`, `servicio_id`),
  KEY `idx_proyecto` (`proyecto_id`),
  KEY `idx_servicio` (`servicio_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `proyectos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int unsigned NOT NULL,
  `sitio_id` int unsigned DEFAULT NULL,
  `nombre` varchar(200) COLLATE utf8mb4_general_ci NOT NULL,
  `descripcion` text COLLATE utf8mb4_general_ci,
  `tipo_proyecto` varchar(80) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `servicio_id` int unsigned DEFAULT NULL,
  `estado` enum('Cotizado','En curso','Terminado','Pausado','Cancelado') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Cotizado',
  `fecha_inicio` date DEFAULT NULL,
  `fecha_termino` date DEFAULT NULL,
  `presupuesto` decimal(14,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cliente_id` (`cliente_id`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_cliente_id` (`cliente_id`),
  KEY `idx_servicio_id` (`servicio_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_sitio` (`sitio_id`),
  CONSTRAINT `fk_proyectos_sitio` FOREIGN KEY (`sitio_id`) REFERENCES `sitios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `proyectos_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `servicios` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(200) NOT NULL,
  `descripcion` text,
  `precio` int NOT NULL DEFAULT '0',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sesiones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int NOT NULL,
  `token` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ultima_actividad` datetime DEFAULT NULL,
  `expira_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `usuario_id` (`usuario_id`)
) ENGINE=InnoDB AUTO_INCREMENT=179 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sitios` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int unsigned NOT NULL,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url_principal` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cliente` (`cliente_id`),
  KEY `idx_deleted` (`deleted_at`),
  CONSTRAINT `fk_sitios_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `rol` varchar(20) COLLATE utf8mb4_general_ci DEFAULT 'admin',
  `activo` tinyint(1) DEFAULT '1',
  `ultimo_acceso` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=106 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

