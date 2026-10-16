-- ============================================================
-- SOFT DELETES - Agregar columnas deleted_at a las tablas
-- ============================================================

-- Tabla clientes
ALTER TABLE clientes ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL;
ALTER TABLE clientes ADD INDEX idx_deleted_at (deleted_at);

-- Tabla proyectos  
ALTER TABLE proyectos ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL;
ALTER TABLE proyectos ADD INDEX idx_deleted_at (deleted_at);

-- Tabla cotizaciones
ALTER TABLE cotizaciones ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL;
ALTER TABLE cotizaciones ADD INDEX idx_deleted_at (deleted_at);

-- Tabla pagos
ALTER TABLE pagos ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL;
ALTER TABLE pagos ADD INDEX idx_deleted_at (deleted_at);

-- Tabla servicios
ALTER TABLE servicios ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL;
ALTER TABLE servicios ADD INDEX idx_deleted_at (deleted_at);

-- ============================================================
-- VISTAS para consultas sin eliminados (opcional, para facilitar)
-- ============================================================

-- Ejemplo: CREATE VIEW clientes_activos AS SELECT * FROM clientes WHERE deleted_at IS NULL;

-- ============================================================
-- FIN
-- ============================================================
