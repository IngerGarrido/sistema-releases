-- Accesos cifrados (WP, FTP, hosting, BD, email, dominio, panel, otros)
-- asociados a un SITIO. El password se guarda cifrado con AES-256-GCM
-- (ver backend/crypto.php). La clave maestra vive en .env (APP_KEY).
CREATE TABLE IF NOT EXISTS accesos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sitio_id INT UNSIGNED NOT NULL,
  tipo ENUM('wp','ftp','hosting','bd','email','dominio','panel','otro') NOT NULL DEFAULT 'otro',
  etiqueta VARCHAR(120) NOT NULL,
  url VARCHAR(500) DEFAULT NULL,
  usuario VARCHAR(255) DEFAULT NULL,
  password_cipher TEXT DEFAULT NULL,
  notas TEXT DEFAULT NULL,
  creado_por INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  KEY idx_sitio (sitio_id),
  KEY idx_tipo (tipo),
  CONSTRAINT fk_accesos_sitio FOREIGN KEY (sitio_id) REFERENCES sitios(id) ON DELETE CASCADE,
  CONSTRAINT fk_accesos_user FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
