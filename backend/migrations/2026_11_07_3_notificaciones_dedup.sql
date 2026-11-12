-- Limpieza única de notificaciones duplicadas.
--
-- Antes de la deduplicación de notificaciones.php (jul-2026) los recordatorios
-- diarios ("Hay una actualización…", "1 pago vencido", "Recuerda: Haz backup…")
-- se acumulaban sin leer: la campana mostraba 20+ avisos repetidos.
-- Se deja solo la más reciente de cada (usuario, tipo, mensaje) no leída.

DELETE n1 FROM `notificaciones` n1
JOIN `notificaciones` n2
  ON n1.leida = 0 AND n2.leida = 0
 AND n1.tipo = n2.tipo
 AND n1.mensaje = n2.mensaje
 AND (n1.usuario_id <=> n2.usuario_id)
 AND n1.id < n2.id;
