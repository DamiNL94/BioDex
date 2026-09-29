/*
 * Service Worker de BioDex (estrategia portada de NaturDex).
 *
 * Un fichero nuevo del frontend va a SHELL_ASSETS y sube VERSION.
 * (backend/bin/check-sw.php falla si algún .js/.css de frontend/ no está en la lista: un módulo sin
 * precachear deja la app rota sin cobertura, y network-first no lo salva porque justo entonces no hay
 * red de la que tirar.)
 *
 * Estrategia: NETWORK-FIRST en todo lo del mismo origen. Con red se sirve siempre la versión fresca;
 * la caché solo responde cuando NO hay conexión. Las navegaciones sin red reciben index.html.
 *
 * /api/ NUNCA pasa por aquí: los datos de trabajo (catálogos, especies, marcas) irán a IndexedDB en
 * F5, con su propia fecha y su propia regla de sincronización. Una respuesta de la API cacheada aquí
 * sería una segunda copia, sin edad conocida, que el navegador serviría como si fuese fresca.
 */
var VERSION = 'v2';
var CACHE = 'biodex-shell-' + VERSION;

var SHELL_ASSETS = [
  // Armazón
  'index.html', 'manifest.webmanifest',

  // css/
  'css/biodex.css',

  // img/
  'img/icon.svg', 'img/icon-192.png', 'img/icon-512.png', 'img/icon-maskable-512.png',

  // vendor/fonts/ — nunca desde CDN: sin ellas precacheadas la app pierde su tipografía en el monte
  'vendor/fonts/fonts.css',
  'vendor/fonts/public-sans-normal-latin.woff2', 'vendor/fonts/public-sans-normal-latin-ext.woff2',
  'vendor/fonts/public-sans-italic-latin.woff2', 'vendor/fonts/public-sans-italic-latin-ext.woff2',
  'vendor/fonts/zilla-slab-500-latin.woff2', 'vendor/fonts/zilla-slab-500-latin-ext.woff2',
  'vendor/fonts/zilla-slab-600-latin.woff2', 'vendor/fonts/zilla-slab-600-latin-ext.woff2',
  'vendor/fonts/zilla-slab-700-latin.woff2', 'vendor/fonts/zilla-slab-700-latin-ext.woff2',

  // js/ (nav.js es script clásico; el resto, módulos ES)
  'js/nav.js', 'js/util.js', 'js/state.js', 'js/api.js', 'js/auth.js', 'js/app.js',

  // js/components/
  'js/components/overlay.js', 'js/components/species-card.js',

  // js/views/
  'js/views/map.js', 'js/views/catalogs.js', 'js/views/species.js', 'js/views/settings.js',
  'js/views/search.js', 'js/views/catalog-builder.js', 'js/views/species-new.js', 'js/views/species-detail.js'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE).then(function (cache) {
      // cache: 'reload' salta la caché HTTP: se precachea lo que hay en el servidor, no una copia vieja.
      return Promise.all(SHELL_ASSETS.map(function (url) {
        return cache.add(new Request(url, { cache: 'reload' })).catch(function () { /* no crítico */ });
      }));
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (names) {
      // Solo cachés del armazón de versiones anteriores. Las de teselas (F6) tendrán otro prefijo.
      return Promise.all(names.map(function (name) {
        if (name.indexOf('biodex-shell-') === 0 && name !== CACHE) return caches.delete(name);
        return null;
      }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return;

  var url = new URL(req.url);
  // Otros orígenes (imágenes de EIDOS/MITECO): directos a la red, sin gestionar.
  if (url.origin !== self.location.origin) return;
  // La API nunca se cachea en el SW (ver la cabecera).
  if (url.pathname.indexOf('/api/') === 0) return;

  // El armazón (HTML/CSS/JS) se revalida siempre contra el servidor: sin esto la caché HTTP del móvil
  // podía seguir sirviendo un app.js viejo aunque el SW fuese network-first.
  var isShell = req.mode === 'navigate' || req.destination === 'document' ||
                req.destination === 'script' || req.destination === 'style';
  var netReq = isShell ? new Request(req, { cache: 'no-cache' }) : req;

  event.respondWith(
    fetch(netReq).then(function (res) {
      // Una navegación siempre recibe index.html (try_files del SPA): se guarda con ESE nombre, que
      // es el respaldo sin red, en vez de una entrada por cada URL visitada.
      if (res && res.ok && res.status !== 206) {
        var copy = res.clone();
        var isPage = (res.headers.get('Content-Type') || '').indexOf('text/html') === 0;
        if (req.mode === 'navigate' && !isPage) return res;   // abrir un .txt no pisa index.html
        var key = req.mode === 'navigate' ? 'index.html' : req;
        caches.open(CACHE).then(function (cache) { cache.put(key, copy); })
          .catch(function () { /* cuota llena o respuesta no almacenable: no es crítico */ });
      }
      return res;
    }).catch(function () {
      return caches.match(req).then(function (cached) {
        if (cached) return cached;
        if (req.mode === 'navigate') return caches.match('index.html');
        return Promise.reject(new Error('sin red y sin caché para ' + req.url));
      });
    })
  );
});
