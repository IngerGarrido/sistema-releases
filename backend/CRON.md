# Cron jobs recomendados

Asumiendo `/var/www/sistema/backend` como ruta. Ajustar a la real.

## Email worker (cada minuto)

Procesa la cola `email_queue` en batches.

```
* * * * * /usr/bin/php /var/www/sistema/backend/email_worker.php >> /var/www/sistema/backend/logs/email_worker.log 2>&1
```

## Procesos automáticos (diario 08:00)

Un solo cron para todo lo que el sistema hace solo (`lib/procesos.php`):
mantención periódica de sitios y renovaciones de dominio/hosting con monto
(proyecto + cobro, 30 días antes del vencimiento), actas con plazo vencido →
"Recibida por plazo" (días hábiles de Chile), aviso de garantía por terminar,
proyectos con acta recibida → Terminado, cotizaciones sin respuesta con la
validez vencida → Expirada, y avisos de cuotas vencidas y de respaldo.
Si el hosting no tiene cron, igual corre al abrir el Panel o la lista de Entregas.

```
0 8 * * * /usr/bin/php /var/www/sistema/backend/procesos_diarios.php >> /var/www/sistema/backend/logs/procesos.log 2>&1
```

Para probar con otra fecha: `php backend/procesos_diarios.php --hoy=2026-12-01`.
El cron viejo `entregas_plazo.php` sigue funcionando (llama a este).
Feriados extra (elecciones, decretos): Configuración → Documentos.

## Cleanup datos viejos (semanal, domingo 03:00)

Limpia `email_queue` enviados >30d, fallidos >90d, `login_attempts` >30d y sesiones expiradas.

```
0 3 * * 0 /usr/bin/php /var/www/sistema/backend/cleanup_old_data.php >> /var/www/sistema/backend/logs/cleanup.log 2>&1
```

## Backup DB (diario 02:00)

Si existe `backend/api/backup.php` o un script de backup propio:

```
0 2 * * * /usr/bin/php /var/www/sistema/backend/scripts/backup_db.php >> /var/www/sistema/backend/logs/backup.log 2>&1
```

## Healthcheck (monitoring externo)

Endpoint público: `GET /backend/api/health.php` — devuelve 200 cuando todo OK, 503 si DB caída o disco <10%. Conectar UptimeRobot / similar.

## Notas

- En MAMP/macOS dev no es necesario configurar cron; se puede invocar manualmente.
- Asegurar que el usuario que corre el cron tiene permisos de escritura sobre `backend/logs/` y `backend/cache/`.
