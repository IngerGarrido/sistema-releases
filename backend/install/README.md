# Instalador del Sistema

Instalador web tipo asistente (similar al de WordPress) para configurar el sistema en cPanel u otro hosting compartido sin acceso SSH.

## Requisitos del servidor

- PHP **8.0 o superior**
- MySQL/MariaDB
- Extensiones PHP: `pdo_mysql`, `mbstring`, `json`, `openssl`, `fileinfo`
- Permisos de escritura en: `backend/`, `backend/uploads/`, `backend/logs/`, `backend/cache/`

## Pasos de instalación en cPanel

### 1. Subir archivos

Sube el proyecto completo a tu servidor. Estructura recomendada:

```
/public_html/sistema/        ← Frontend compilado (npm run build → dist/)
/public_html/sistema/api/    ← (alias) → /home/usuario/sistema-backend/
/home/usuario/sistema-backend/  ← carpeta backend completa (FUERA de public_html idealmente)
```

> **Recomendado:** mantén `backend/` **fuera** de `public_html` y expón solo `backend/api/` mediante un alias o symlink. Si no es posible, asegúrate de que el `.htaccess` proteja `config.php`, `.env` y `logs/`.

### 2. Crear base de datos

Desde cPanel → **MySQL® Databases**:
1. Crea una base de datos nueva (ej. `usuario_sistema`)
2. Crea un usuario MySQL y asígnale **todos los privilegios** sobre esa BD
3. Anota: host (normalmente `localhost`), puerto (3306), nombre BD, usuario, contraseña

### 3. Ajustar permisos

Vía Administrador de archivos o FTP:
```
backend/             → 755
backend/uploads/     → 755 (escribible)
backend/logs/        → 755 (escribible)
backend/cache/       → 755 (escribible)
```

### 4. Ejecutar el instalador

Abre en el navegador:
```
https://tu-dominio.com/api/install/
```
(o la ruta donde subiste `backend/install/`)

El asistente te guiará por 6 pasos:

1. **Bienvenida + verificación** de requisitos
2. **Conexión a BD** (con prueba antes de continuar)
3. **Importar schema** (crea las 16 tablas)
4. **Usuario administrador** (correo + contraseña — se guarda con bcrypt)
5. **Empresa + URL del frontend** (para CORS)
6. **Finalizar:** escribe `backend/.env` y crea `install.lock`

### 5. Asegurar el sistema tras instalar

Una vez termine la instalación:

- ✅ **Borra** la carpeta `backend/install/` completa (o al menos `index.php` y `schema.sql`)
- ✅ Verifica que `backend/.env` **no sea accesible** desde el navegador (debería estar protegido por `.htaccess`)
- ✅ Cambia los permisos de `.env` a `600` si tu hosting lo permite

### 6. Construir el frontend

En tu máquina local:
```bash
cd frontend
# Edita .env.production con la URL del backend:
# VITE_API_URL=https://tu-dominio.com/api
npm run build
```

Sube el contenido de `frontend/dist/` a `public_html/sistema/`.

Incluye un `.htaccess` para el SPA:
```apache
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteBase /sistema/
  RewriteRule ^index\.html$ - [L]
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule . /sistema/index.html [L]
</IfModule>
```

---

## Reinstalar / forzar instalación

El instalador crea `backend/install.lock` cuando termina. Si necesitas reinstalar:

```
https://tu-dominio.com/api/install/?action=force
```

⚠️ **Atención:** esto sobrescribirá `.env` y volverá a importar el schema (perderás datos).

## Solución de problemas

| Problema | Causa probable | Solución |
|---|---|---|
| "No se puede conectar a la BD" | Host/puerto/credenciales incorrectos | Revisa en cPanel → MySQL® Databases |
| "Schema no se importa" | Usuario sin permisos `CREATE` | Asegúrate de dar **todos los privilegios** |
| "Carpeta no escribible" | Permisos restrictivos | `chmod 755` en uploads/, logs/, cache/ |
| Página en blanco | PHP < 8.0 | Cambia versión de PHP en cPanel → MultiPHP Manager |
| CORS bloqueado en frontend | `CORS_ORIGIN` mal escrito | Edita `backend/.env` y ajusta `CORS_ORIGIN` |

## Migrar desde un sistema existente

Si ya tienes el sistema corriendo y quieres mover a otro servidor:

1. Exporta la BD desde el servidor antiguo (`mysqldump`)
2. Sigue los pasos 1–3 de instalación
3. **Omite el instalador** — en su lugar:
   - Importa tu dump manualmente vía phpMyAdmin
   - Copia tu `backend/.env` antiguo y ajusta las credenciales de BD nuevas
   - Sube `backend/uploads/` con todos los archivos
4. Crea manualmente `backend/install.lock` (vacío) para evitar que el instalador se ejecute por accidente
