# Migraciones SQL

Scripts SQL de actualización de esquema que NO se incluyen en `backend/sql/` (instalación inicial).
Aplicar manualmente sobre una BD ya en producción.

## Cómo ejecutar

### Opción 1: phpMyAdmin / Adminer

1. Abrir phpMyAdmin (MAMP: http://localhost/phpMyAdmin)
2. Seleccionar la base de datos `sistema_local`
3. Pestaña **SQL** → pegar el contenido del archivo `.sql` → **Continuar**
4. Si MySQL reporta error de índice duplicado, ignorarlo y continuar con el resto.

### Opción 2: Línea de comandos (mysql client)

```bash
mysql -u root -p sistema_local < backend/migrations/add_indices.sql
```

En MAMP la ruta del cliente suele ser `/Applications/MAMP/Library/bin/mysql`:

```bash
/Applications/MAMP/Library/bin/mysql -u root -proot sistema_local \
    < /Applications/MAMP/htdocs/sistema-local/backend/migrations/add_indices.sql
```

## Migraciones disponibles

| Archivo | Sprint | Descripción |
|---|---|---|
| `add_indices.sql` | 2 (Altos) | Índices adicionales en foreign keys frecuentes para optimizar JOIN/WHERE en proyectos, cotizaciones, pagos, items y auditoría. |

## Notas

- MySQL/MariaDB **no soportan** `CREATE INDEX IF NOT EXISTS`. Si un índice ya existe, la sentencia falla con error 1061 (duplicate key name). Es seguro ignorarlo: significa que ya está aplicado.
- Antes de ejecutar en producción, hacer respaldo (Configuración → Sistema → Generar backup).
- Verificar índices aplicados:

  ```sql
  SHOW INDEX FROM proyectos;
  SHOW INDEX FROM cotizaciones;
  SHOW INDEX FROM pagos;
  ```
