-- ============================================================
-- ÍNDICES ADICIONALES - Sprint 2 (Altos)
-- ============================================================
-- Ejecutar manualmente. MySQL/MariaDB no soportan
-- "CREATE INDEX IF NOT EXISTS" directamente — si un índice ya
-- existe, simplemente ignorar el error de duplicado.
--
-- Índices ya existentes (NO recrear, verificados):
--   clientes.deleted_at, proyectos.deleted_at, cotizaciones.deleted_at,
--   pagos.deleted_at, servicios.deleted_at (soft_deletes.sql)
--   sesiones.token, sesiones.usuario_id, sesiones.expira_en (seguridad_mejoras.sql)
--   auditoria.created_at, auditoria.accion, auditoria.tabla_afectada
--   email_queue.status_scheduled, email_queue.priority
--   cotizaciones.plantilla_id
--
-- Índices nuevos: foreign keys frecuentes que no estaban indexadas.
-- ============================================================

-- proyectos: filtra por cliente, ordena por fecha
ALTER TABLE proyectos ADD INDEX idx_cliente_id (cliente_id);
ALTER TABLE proyectos ADD INDEX idx_servicio_id (servicio_id);
ALTER TABLE proyectos ADD INDEX idx_created_at (created_at);

-- cotizaciones: filtra por cliente y proyecto
ALTER TABLE cotizaciones ADD INDEX idx_cliente_id (cliente_id);
ALTER TABLE cotizaciones ADD INDEX idx_proyecto_id (proyecto_id);

-- cotizacion_items: lookups por cotización
ALTER TABLE cotizacion_items ADD INDEX idx_cotizacion_id (cotizacion_id);

-- pagos: joins con clientes y proyectos
ALTER TABLE pagos ADD INDEX idx_cliente_id (cliente_id);
ALTER TABLE pagos ADD INDEX idx_proyecto_id (proyecto_id);

-- pago_cuotas: agrupar por pago (corrige N+1)
ALTER TABLE pago_cuotas ADD INDEX idx_pago_id (pago_id);
ALTER TABLE pago_cuotas ADD INDEX idx_estado (estado);

-- clientes: búsquedas y orden frecuente
ALTER TABLE clientes ADD INDEX idx_email (email);

-- ============================================================
-- FIN
-- ============================================================
