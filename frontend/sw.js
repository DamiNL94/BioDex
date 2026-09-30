/*
 * Service Worker de BioDex (estrategia portada de NaturDex).
 *
 * Un fichero nuevo del frontend va a SHELL_ASSETS y sube VERSION.
 * (backend/bin/check-sw.php falla si algún .js/.css de frontend/ no está en la lista: un módulo sin
 * precachear deja la app rota sin cobertura, y network-first no lo salva porque justo entonces no hay
 * red de la que tirar.)
 *
 * Estrategia (F3b): CACHE-FIRST con revalidación en segundo plano (stale-while-revalidate). Es una app
 * de campo: tiene que abrir al instante aunque la red esté caída o colgada. Lo que no está en caché va
 * a la red con corte de NET_TIMEOUT_MS. Las versiones nuevas llegan subiendo VERSION; la app avisa
 * «Hay una versión nueva · Recargar» cuando el SW nuevo toma el control.
 * (Antes era network-first sin límite: con el servidor colgado la navegación no llegaba nunca a la caché.)
 *
 * /api/ NUNCA pasa por aquí: los datos de trabajo (catálogos, especies, marcas) viven en IndexedDB
 * (F5), con su propia fecha y su propia regla de sincronización. Una respuesta de la API cacheada aquí
 * sería una segunda copia, sin edad conocida, que el navegador serviría como si fuese fresca.
 *
 * Fotos de especies (/img/fototeca/, F5): CACHE-FIRST en las cachés `biodex-img-<catalogId>` que llena
 * la app al descargar un catálogo; lo que no esté ahí va a la red y NO se guarda en la del armazón.
 */
var VERSION = 'v4';
var CACHE = 'biodex-shell-' + VERSION;
var NET_TIMEOUT_MS = 3000;

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
  'js/db.js', 'js/offline.js', 'js/marks.js', 'js/sync.js',

  // js/components/
  'js/components/overlay.js', 'js/components/species-card.js', 'js/components/marks.js',

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
      // Solo cachés del armazón de versiones anteriores. Las de imágenes (biodex-img-*) no se tocan.
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

  // Fotos de especies: las guardadas al descargar un catálogo (caches.match busca en todas las cachés).
  if (url.pathname.indexOf('/img/fototeca/') === 0) {
    event.respondWith(caches.match(req, { ignoreVary: true }).then(function (cached) {
      return cached || fetch(req);
    }));
    return;
  }

  // Abrir un fichero en una pestaña (/img/icon-512.png) no es una ruta del SPA: no recibe index.html.
  if (req.mode === 'navigate' && /\.[a-z0-9]+$/i.test(url.pathname) && !/\.html$/i.test(url.pathname)) return;

  // La revalidación del armazón (HTML/CSS/JS) salta la caché HTTP: sin esto el móvil podía seguir
  // guardando un app.js viejo en la caché del SW aunque el servidor ya tuviese el nuevo.
  var isShell = req.mode === 'navigate' || req.destination === 'document' ||
                req.destination === 'script' || req.destination === 'style';
  var netReq = isShell ? new Request(req, { cache: 'no-cache' }) : req;

  // Una navegación siempre recibe index.html (try_files del SPA): se guarda y se busca con ESE nombre
  // en vez de una entrada por cada URL visitada.
  var key = req.mode === 'navigate' ? 'index.html' : req;

  // Revalidación en paralelo: actualiza la caché para el próximo arranque.
  var network = fetch(netReq).then(function (res) {
    if (res && res.ok && res.status !== 206) {
      var isPage = (res.headers.get('Content-Type') || '').indexOf('text/html') === 0;
      if (req.mode === 'navigate' && !isPage) return res;   // abrir un .txt no pisa index.html
      var copy = res.clone();
      caches.open(CACHE).then(function (cache) { return cache.put(key, copy); })
        .catch(function () { /* cuota llena o respuesta no almacenable: no es crítico */ });
    }
    return res;
  });
  network.catch(function () { /* sin red: se queda lo cacheado */ });
  event.waitUntil(network.catch(function () {}));

  event.respondWith(
    caches.match(key).then(function (cached) {
      if (cached) return cached;
      // Sin copia: red, pero con corte (una conexión colgada no puede dejar la ventana en blanco).
      return withTimeout(network, NET_TIMEOUT_MS);
    })
  );
});

function withTimeout(promise, ms) {
  return new Promise(function (resolve, reject) {
    var timer = setTimeout(function () { reject(new Error('la red no responde')); }, ms);
    promise.then(function (r) { clearTimeout(timer); resolve(r); },
                 function (e) { clearTimeout(timer); reject(e); });
  });
}
