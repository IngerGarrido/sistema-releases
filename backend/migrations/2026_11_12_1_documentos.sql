-- Documentos y enlaces (Fase 1: solo links).
-- Cada documento pertenece a un CLIENTE y, opcionalmente, a un proyecto
-- (y por lo tanto a su sitio). Guarda links a artefactos de Claude, Figma,
-- Canva, Google Drive, etc. No se suben archivos al servidor.
CREATE TABLE IF NOT EXISTS documentos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  proyecto_id INT UNSIGNED DEFAULT NULL,
  sitio_id INT UNSIGNED DEFAULT NULL,
  tipo VARCHAR(20) NOT NULL DEFAULT 'enlace',
  titulo VARCHAR(200) NOT NULL,
  url VARCHAR(1000) NOT NULL,
  fecha DATE DEFAULT NULL,
  notas TEXT DEFAULT NULL,
  creado_por INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  KEY idx_doc_cliente (cliente_id),
  KEY idx_doc_proyecto (proyecto_id),
  KEY idx_doc_sitio (sitio_id),
  CONSTRAINT fk_documentos_cliente  FOREIGN KEY (cliente_id)  REFERENCES clientes(id)  ON DELETE CASCADE,
  CONSTRAINT fk_documentos_proyecto FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE SET NULL,
  CONSTRAINT fk_documentos_sitio    FOREIGN KEY (sitio_id)    REFERENCES sitios(id)    ON DELETE SET NULL,
  CONSTRAINT fk_documentos_user     FOREIGN KEY (creado_por)  REFERENCES usuarios(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
