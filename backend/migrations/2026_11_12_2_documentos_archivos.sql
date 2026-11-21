-- Documentos fase 2: subir archivos (PDF, imágenes, Office…).
-- Un documento es un LINK (url) o un ARCHIVO (archivo_ruta); nunca ambos.
-- El archivo vive en backend/uploads/documentos/ con nombre aleatorio y sin
-- extensión, y solo se descarga vía documentos.php (con sesión).
ALTER TABLE documentos MODIFY url VARCHAR(1000) DEFAULT NULL;
ALTER TABLE documentos ADD COLUMN archivo_ruta VARCHAR(64) DEFAULT NULL AFTER url;
ALTER TABLE documentos ADD COLUMN archivo_nombre VARCHAR(255) DEFAULT NULL AFTER archivo_ruta;
ALTER TABLE documentos ADD COLUMN archivo_mime VARCHAR(120) DEFAULT NULL AFTER archivo_nombre;
ALTER TABLE documentos ADD COLUMN archivo_bytes INT UNSIGNED DEFAULT NULL AFTER archivo_mime;
