/**
 * Service Worker del Sistema
 *
 * Estrategia:
 *  - Assets versionados (/frontend/dist/assets/*.js|css con hash): cache-first,
 *    inmutables. Como el nombre cambia con cada release, el cliente recibe el
 *    archivo nuevo sin invalidar manualmente.
 *  - HTML (/, /index.php): network-first → fallback al último HTML cacheado.
 *    Garantiza que el usuario reciba el index.html nuevo cuando hay deploy.
 *  - API (/backend/api/*): NO se cachea. Siempre red directa.
 *
 * Versionado: cuando cambies la estrategia de cache, bumpea CACHE_VERSION
 * para invalidar el cache viejo.
 */
const CACHE_VERSION = 'v1'
const RUNTIME_CACHE = `sistema-runtime-${CACHE_VERSION}`
const HTML_CACHE    = `sistema-html-${CACHE_VERSION}`

self.addEventListener('install', (event) => {
  // Activar inmediatamente sin esperar al cierre de pestañas viejas
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  // Limpiar caches viejos al actualizar la versión
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys
        .filter(k => k !== RUNTIME_CACHE && k !== HTML_CACHE)
        .map(k => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  )
})

self.addEventListener('fetch', (event) => {
  const req = event.request
  if (req.method !== 'GET') return

  const url = new URL(req.url)

  // No cachear nada fuera de mismo origen (CDN de fuentes, etc.)
  if (url.origin !== self.location.origin) return

  // API → red directa, sin cache
  if (url.pathname.startsWith('/backend/')) return

  // Assets versionados (con hash en el nombre) → cache-first, inmutables
  const isHashedAsset = /\/assets\/.+\.[a-f0-9]{6,}\.(js|css|woff2?|svg|png|jpg|jpeg|gif)$/i.test(url.pathname)
                     || /\/frontend\/dist\/assets\//.test(url.pathname)

  if (isHashedAsset) {
    event.respondWith(
      caches.open(RUNTIME_CACHE).then(cache =>
        cache.match(req).then(hit => hit || fetch(req).then(res => {
          if (res.ok) cache.put(req, res.clone())
          return res
        }))
      )
    )
    return
  }

  // HTML y demás → network-first con fallback al cache
  if (req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html')) {
    event.respondWith(
      fetch(req).then(res => {
        if (res.ok) {
          const copy = res.clone()
          caches.open(HTML_CACHE).then(c => c.put(req, copy))
        }
        return res
      }).catch(() => caches.match(req).then(hit => hit || caches.match('/')))
    )
    return
  }
})
