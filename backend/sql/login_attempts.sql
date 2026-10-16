-- Tabla para rate limiting de login
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    email VARCHAR(255) NOT NULL,
    exitoso TINYINT(1) DEFAULT 0,
    intento_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ip_intento (ip, intento_en),
    KEY idx_email_intento (email, intento_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
