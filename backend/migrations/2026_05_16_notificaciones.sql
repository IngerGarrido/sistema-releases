CREATE TABLE IF NOT EXISTS notificaciones (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NULL,
    tipo        VARCHAR(40) NOT NULL DEFAULT 'info',
    mensaje     VARCHAR(500) NOT NULL,
    link        VARCHAR(255) NULL,
    leida       TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_leida (usuario_id, leida),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
