# sistema-local — CRM de la agencia

CRM propio para gestionar clientes, sitios, proyectos, cotizaciones y pagos de
la agencia (Chile, CLP). Este archivo lo lee cualquier sesión nueva de Claude
para retomar contexto. **Responder siempre en español.**

## Stack
- **Backend:** PHP 8 nativo + PDO + MySQL (MAMP). Sin framework. Endpoints en
  `backend/api/*.php`; helpers en `backend/config.php` (`cors()`, `getDB()`,
  `requireAuth()`, `errSafe()`, `rateLimit()`).
- **Frontend:** React 18 + Vite, TanStack Query, React Hook Form + Zod, Recharts,
  react-quill. Pantallas en `frontend/src/components/` (Dashboard, Clientes,
  ClienteDetalle, Sitios, Proyectos, Cotizaciones, Pagos, Reportes, Servicios…).
- **Tests:** Vitest (`cd frontend && npm test`), PHPUnit (`backend/vendor/bin/phpunit`).
- Detalle de instalación, endpoints y seguridad: ver `README.md`.

## Repos y despliegue
- Repo dev privado: `IngerGarrido/sistema` (origin).
- `bash release.sh` compila el front y **force-pushea** al repo público
  `IngerGarrido/sistema-releases`. En producción se actualiza desde
  **Sistema → Actualizar** y luego **Migraciones**.
- **Al terminar cada cambio verificado: commit + push + `bash release.sh`, sin
  preguntar** (pedido por la usuaria). Avisar si hay migraciones nuevas.

## Decisiones de negocio importantes
- **La plata vive solo en Pagos**: los proyectos no guardan montos propios; el
  cobro se arma desde Pagos (cobro pendiente automático al crear proyecto).
- Ítems identificables como **"Sitio – Proyecto · Cliente"** en listas, dashboard
  y ficha de cliente.
- Proyectos: vista agrupada por sitio (acordeón, cerrada al entrar), precio
  editable por servicio (override del catálogo) y **líneas libres** sin servicio.
- Pagos: lista en acordeón; abonos parciales cuentan por fecha de abono.
- **Retención SII 15,25 %** (bruto − neto sobre boletas emitidas).
- Colores de estado: verde = Terminado.

## Estilo UI
- Tarjetas KPI con `StatCard`, estilo "Glass". Inputs: foco solo con borde, sin
  `box-shadow`. Usar tokens de color/sombra existentes, no valores sueltos.

## Proyecto hermano
`/Applications/MAMP/htdocs/control` es una versión ampliada (portal de cliente,
finanzas, facturas, tablero). Algunos cambios se portan entre ambos.
