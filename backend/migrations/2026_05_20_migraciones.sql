-- Tabla de registro de migraciones aplicadas.
-- El runner consulta esta tabla para no reaplicar lo ya ejecutado.
CREATE TABLE IF NOT EXISTS migraciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  archivo VARCHAR(255) NOT NULL UNIQUE,
  ejecutada_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  duracion_ms INT DEFAULT NULL,
  checksum CHAR(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
