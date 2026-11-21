-- Borra los recordatorios viejos de la campana que ya no se crean así:
--  * "Recuerda: Haz backup…" semanal (ahora avisa solo si no hay respaldo en 7 días)
--  * "N pago(s) vencido(s)" sin enlace (ahora "N cuotas vencidas por cobrar", con
--    la misma regla del Panel y enlace a Pagos; se borra solo cuando ya no aplica)
-- Solo datos de avisos; no toca nada más.
DELETE FROM `notificaciones`
 WHERE mensaje LIKE 'Recuerda: Haz backup%'
    OR mensaje REGEXP '^[0-9]+ pagos? vencidos?$';
