-- Sitios: entidad dueña real de la web/cuenta del cliente final.
-- Un sitio pertenece a UN cliente (sea agencia o directo).
-- Los proyectos opcionalmente se asocian a un sitio.
-- Los accesos (WP/FTP/etc) cuelgan del sitio, no del proyecto,
-- para que distintos proyectos sobre el mismo sitio compartan credenciales.

CREATE TABLE IF NOT EXISTS sitios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(150) NOT NULL,
  descripcion VARCHAR(500) DEFAULT NULL,
  url_principal VARCHAR(500) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  KEY idx_cliente (cliente_id),
  KEY idx_deleted (deleted_at),
  CONSTRAINT fk_sitios_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agregar sitio_id a proyectos (nullable; se completa abajo para datos existentes).
-- NOTA: si la columna ya existe el runner ignora error 1060.
ALTER TABLE proyectos
  ADD COLUMN sitio_id INT UNSIGNED DEFAULT NULL AFTER cliente_id;
ALTER TABLE proyectos
  ADD KEY idx_sitio (sitio_id);
ALTER TABLE proyectos
  ADD CONSTRAINT fk_proyectos_sitio FOREIGN KEY (sitio_id) REFERENCES sitios(id) ON DELETE SET NULL;

-- Migración de datos: para cada proyecto existente sin sitio,
-- crea un sitio con el mismo nombre del proyecto bajo su cliente,
-- y vincula el proyecto a ese sitio.
INSERT INTO sitios (cliente_id, nombre, created_at)
SELECT p.cliente_id, p.nombre, COALESCE(p.created_at, NOW())
FROM proyectos p
WHERE p.sitio_id IS NULL AND p.deleted_at IS NULL AND p.cliente_id IS NOT NULL;

UPDATE proyectos p
JOIN sitios s ON s.cliente_id = p.cliente_id AND s.nombre COLLATE utf8mb4_general_ci = p.nombre AND s.deleted_at IS NULL
SET p.sitio_id = s.id
WHERE p.sitio_id IS NULL AND p.deleted_at IS NULL;
