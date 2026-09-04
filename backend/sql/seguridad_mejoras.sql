-- ============================================================
-- MEJORAS DE SEGURIDAD - TABLAS ADICIONALES
-- Ejecutar este SQL para habilitar todas las funciones de seguridad
-- ============================================================

-- Tabla para rate limiting de login (intentos fallidos)
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,              -- IPv4 o IPv6
    email VARCHAR(255) NOT NULL,          -- Email intentado
    exitoso TINYINT(1) DEFAULT 0,         -- 1 = exitoso, 0 = fallido
    intento_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_intento (ip, intento_en),
    INDEX idx_intento_en (intento_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de auditoría para operaciones críticas
CREATE TABLE IF NOT EXISTS auditoria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,              -- Quién hizo la acción
    accion VARCHAR(50) NOT NULL,          -- CREATE, UPDATE, DELETE, LOGIN, LOGOUT
    tabla_afectada VARCHAR(50) NOT NULL,  -- Tabla afectada
    registro_id INT NULL,                 -- ID del registro afectado (opcional)
    detalle TEXT,                         -- Descripción detallada
    ip_address VARCHAR(45) NOT NULL,      -- IP del usuario
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_usuario_fecha (usuario_id, created_at),
    INDEX idx_accion (accion),
    INDEX idx_tabla (tabla_afectada),
    INDEX idx_fecha (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verificar que la tabla sesiones existe (requerida para la nueva auth)
-- Si ya existe con otra estructura, ajustar según sea necesario
CREATE TABLE IF NOT EXISTS sesiones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,    -- Token de sesión
    expira_en DATETIME NOT NULL,            -- Fecha de expiración
    ultima_actividad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_usuario (usuario_id),
    INDEX idx_expira (expira_en),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- LIMPIEZA AUTOMÁTICA (opcional - ejecutar periódicamente)
-- ============================================================

-- Limpiar intentos de login antiguos (> 24 horas)
-- DELETE FROM login_attempts WHERE intento_en < DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- Limpiar auditoría antigua (> 1 año)
-- DELETE FROM auditoria WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);

-- Limpiar sesiones expiradas
-- DELETE FROM sesiones WHERE expira_en < NOW();

-- ============================================================
-- FIN
-- ============================================================
