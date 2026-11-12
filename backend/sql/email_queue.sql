-- Tabla para cola de emails
CREATE TABLE IF NOT EXISTS email_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    to_email VARCHAR(255) NOT NULL,
    to_name VARCHAR(255) DEFAULT NULL,
    subject VARCHAR(255) NOT NULL,
    body_html TEXT,
    body_text TEXT,
    from_email VARCHAR(255) DEFAULT 'noreply@sistema.local',
    from_name VARCHAR(255) DEFAULT 'Sistema Local',
    priority INT DEFAULT 5, -- 1=urgente, 10=baja
    status ENUM('pending','processing','sent','failed') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    scheduled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME,
    sent_at DATETIME,
    INDEX idx_status_scheduled (status, scheduled_at),
    INDEX idx_priority (priority, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
