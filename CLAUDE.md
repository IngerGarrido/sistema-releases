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

## Trabajo reciente
- **EMPEZAR POR `docs/RETOMAR.md`**: trabajo a medio terminar (sin commit) y
  próximos pasos al 2026-11-20.
- **`docs/REGISTRO-2026-11-20.md`**: todo lo hecho al cierre (auditoría
  full-stack aplicada, papelera automática, estado Reemplazada). **`docs/FLUJO.md`**:
  orden para ingresar un trabajo nuevo.
- **PRÓXIMO TRABAJO: `docs/PLAN-ediciones-y-modulos.md`** (2026-11-21, sin empezar):
  integración con Claude para todos → ediciones con módulos privados → registro de
  instalaciones.
- **`docs/PLAN-flujo-proyecto.md`** (completo, 2026-11-20): el proyecto como
  centro del flujo — etapa de entrega en cada proyecto, "Falta proyecto" tras
  aprobar, ficha del cliente y Panel con Entregas, propuesta/acta en Documentos
  y garantía con aviso. Tiene el registro de qué se hizo y dónde; leerlo antes
  de tocar Entregas, Proyectos o Documentos.
- **`docs/PLAN-simplificar.md`** (completo, 2026-11-20): números que cuadran y
  sistema más automático. Reglas que NO romper:
  - **Saldos, cobrado y "vencida" solo desde `backend/lib/saldos.php`** (nunca
    otra consulta propia). Total del pago = suma de cuotas; cliente del pago =
    cliente del proyecto.
  - **Todo lo automático va en `backend/lib/procesos.php` → `procesosDiarios()`**
    (cron `backend/procesos_diarios.php`, respaldo en Panel y Entregas).
  - "Deshacer" restaura el mismo registro (`useRestaurar`), nunca crea uno nuevo.
  - **No usar Sistema → Actualizar en esta carpeta** (está bloqueado): pisa el
    trabajo sin commitear. Migraciones locales: `php backend/scripts/migrate.php`
    o aplicar una sola con `runMigrationFile()`.
  - Probar procesos con `--hoy=` corre TODOS los procesos con esa fecha
    (expira cotizaciones, etc.): hacerlo solo con datos de prueba `ZZ`.
- Piezas comunes del frontend (usarlas, no copiar): `filas(respuesta)` y
  `SIN_FILAS` (helpers), `cotizacionesPorAtender(d)`, `useAtajoNuevo()` /
  `pedirNuevo()` (Ctrl+N y "+ Nuevo"), avisos globales con el evento `toast`,
  colores de estado vía tokens `--tone-*`.

## Integración con Claude Code (2026-11-21)
- Claude sube propuestas y actas solo: `POST /api/integracion/documentos` con llave
  `igs_…` (Configuración → Integraciones). Código: `backend/api/integracion.php`,
  `backend/lib/integracion.php`; spec en web-inger-nueva
  `propuestas/documentos/INSTRUCCIONES-integracion-claude.md`.
- **Panel e integración guardan con la misma función** (`dsGuardarPropuesta` /
  `dsGuardarActa` en `lib/doc_subido.php`): no duplicar esa lógica en otro lado.
- Marca de origen `propuesta_origen` / `acta_origen` ('manual' | 'claude'); en el panel: «Subida automática · fecha» / «Subida manual · fecha».
- Enlace directo del panel: `#/cotizaciones/N` y `#/entregas/N` (App.jsx).

## Visitas de los links públicos (2026-11-21)
- «Vista» = el cliente abrió el link. Todo pasa por `backend/lib/visitas.php` → `visRegistrar()`:
  no cuentan robots (sin Accept-Language, vistas previas de chats, lectores de correo) ni
  visitas desde una conexión que usó el panel en los últimos 30 días (`ips_panel`, se marca en
  `requireAuth()`; IP solo como hash). Cada visita que cuenta queda en `doc_visitas` con el
  dispositivo; las listas reciben `visitas: {veces, ultima, dispositivo}` (`visResumen()`).
- Limitación conocida: una visita de Inger desde otra conexión (celular con datos) sí cuenta;
  el dispositivo y la hora en el chip permiten reconocerla.

## Proyecto hermano
`/Applications/MAMP/htdocs/control` es una copia de este sistema que Inger está
adaptando **para agencias**: sus módulos se piensan de otra forma. Lo retomará más
adelante. No portar cambios entre ambos sin preguntarle (aclarado el 2026-11-21).
