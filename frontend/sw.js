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
 * Fotos de especies (/img/fototeca/, F5; /img/inat/, F11b; /img/respaldo/, F11d): CACHE-FIRST en las cachés `biodex-img-<catalogId>`
 * que llena la app al descargar un catálogo; lo que no esté ahí va a la red y NO se guarda en la del armazón.
 * El sprite de siluetas (img/silhouettes.svg, F11b) va en SHELL_ASSETS: sin red, la silueta sale igual.
 *
 * Motor y umbrales UICN (/shared/, F8b) y EOO/AOO (F9): van en SHELL_ASSETS como el resto del armazón.
 *
 * Teselas del mapa (/tiles/, F7): CACHE-FIRST en todas las cachés `biodex-tiles-*` (las zonas descargadas
 * y la de uso, `biodex-tiles-runtime`). Si no está: red con corte de TILE_TIMEOUT_MS, y lo que llega se
 * guarda en la de uso, con tope de RUNTIME_TILES_MAX entradas (fuera las más antiguas). Sin red ni copia:
 * tesela transparente, nunca un error visible en el mapa. Nunca van a la caché del armazón.
 * Las peticiones de la descarga de zonas (cabecera X-BioDex-Zone) van directas a la red: allí un fallo
 * tiene que ser un fallo, no una tesela vacía.
 */
var VERSION = 'v18';
var CACHE = 'biodex-shell-' + VERSION;
var NET_TIMEOUT_MS = 3000;
var TILE_TIMEOUT_MS = 5000;
var TILE_CACHE_PREFIX = 'biodex-tiles-';
var RUNTIME_TILES = 'biodex-tiles-runtime';
var RUNTIME_TILES_MAX = 1500;
// PNG de 1×1 transparente (el navegador lo estira a 256×256)
var EMPTY_TILE = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

var SHELL_ASSETS = [
  // Armazón
  'index.html', 'manifest.webmanifest',

  // css/
  'css/biodex.css',

  // img/
  'img/icon.svg', 'img/icon-192.png', 'img/icon-512.png', 'img/icon-maskable-512.png',
  'img/silhouettes.svg',   // F11b: siluetas de las especies sin foto

  // vendor/fonts/ — nunca desde CDN: sin ellas precacheadas la app pierde su tipografía en el monte
  'vendor/fonts/fonts.css',
  'vendor/fonts/public-sans-normal-latin.woff2', 'vendor/fonts/public-sans-normal-latin-ext.woff2',
  'vendor/fonts/public-sans-italic-latin.woff2', 'vendor/fonts/public-sans-italic-latin-ext.woff2',
  'vendor/fonts/zilla-slab-500-latin.woff2', 'vendor/fonts/zilla-slab-500-latin-ext.woff2',
  'vendor/fonts/zilla-slab-600-latin.woff2', 'vendor/fonts/zilla-slab-600-latin-ext.woff2',
  'vendor/fonts/zilla-slab-700-latin.woff2', 'vendor/fonts/zilla-slab-700-latin-ext.woff2',

  // js/ (nav.js es script clásico; el resto, módulos ES)
  'js/nav.js', 'js/util.js', 'js/state.js', 'js/api.js', 'js/auth.js', 'js/app.js',
  'js/db.js', 'js/offline.js', 'js/marks.js', 'js/sync.js', 'js/metrics.js',
  'js/tally.js',   // F11e: conteo rápido

  // js/geo/ y js/map/ (F7)
  'js/geo/utm.js',
  'js/map/layers.js', 'js/map/zones.js', 'js/map/zone-ui.js', 'js/map/marks-layer.js', 'js/map/metrics-layer.js',

  // vendor/leaflet/ y vendor/markercluster/ (F7) — el mapa tiene que abrir sin red
  'vendor/leaflet/leaflet.js', 'vendor/leaflet/leaflet.css',
  'vendor/leaflet/images/layers.png', 'vendor/leaflet/images/layers-2x.png',
  'vendor/leaflet/images/marker-icon.png', 'vendor/leaflet/images/marker-icon-2x.png', 'vendor/leaflet/images/marker-shadow.png',
  'vendor/markercluster/leaflet.markercluster.js',
  'vendor/markercluster/MarkerCluster.css', 'vendor/markercluster/MarkerCluster.Default.css',
  'vendor/qrcode-generator/qrcode.js',   // F11e: QR de invitación

  // js/components/
  'js/components/overlay.js', 'js/components/species-card.js', 'js/components/marks.js', 'js/components/species-notes.js',
  'js/components/species-assessments.js', 'js/components/species-distribution.js',
  'js/components/catalog-switcher.js',   // F11c: cambiar de catálogo desde la cabecera
  'js/components/qr.js',   // F11e: QR de invitación

  // js/views/
  'js/views/map.js', 'js/views/catalogs.js', 'js/views/species.js', 'js/views/settings.js',
  'js/views/search.js', 'js/views/catalog-builder.js', 'js/views/species-new.js', 'js/views/species-detail.js',
  'js/views/catalog-team.js', 'js/views/join.js', 'js/views/mark-sheet.js', 'js/views/iucn-wizard.js',
  'js/views/catalog-export.js',

  // shared/ (F8b): motor UICN y sus umbrales, servidos por nginx en /shared/ — el asistente funciona sin red
  'shared/iucn-engine.js', 'shared/iucn-criteria.json',
  // shared/ (F9): EOO y AOO provisionales sin red
  'shared/geo-metrics.js'

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
      // Solo cachés del armazón de versiones anteriores. Las de imágenes (biodex-img-*) y las de
      // teselas (biodex-tiles-*) no se tocan.
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
  if (url.pathname.indexOf('/img/fototeca/') === 0 || url.pathname.indexOf('/img/inat/') === 0 ||
      url.pathname.indexOf('/img/respaldo/') === 0) {
    event.respondWith(caches.match(req, { ignoreVary: true }).then(function (cached) {
      return cached || fetch(req);
    }));
    return;
  }

  // Teselas del IGN (F7)
  if (url.pathname.indexOf('/tiles/') === 0) {
    if (req.headers.get('X-BioDex-Zone')) return;   // descarga de una zona: directa a la red
    event.respondWith(tileResponse(req, url, event));
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

function tileResponse(req, url, event) {
  var key = url.origin + url.pathname;   // sin parámetros: una tesela, una sola entrada
  return caches.keys().then(function (names) {
    var tileCaches = names.filter(function (n) { return n.indexOf(TILE_CACHE_PREFIX) === 0; });
    // Primero las zonas descargadas, después la de uso.
    tileCaches.sort(function (a, b) { return (a === RUNTIME_TILES) - (b === RUNTIME_TILES); });
    return firstMatch(tileCaches, key);
  }).then(function (cached) {
    if (cached) return cached;
    return withTimeout(fetch(req), TILE_TIMEOUT_MS).then(function (res) {
      var type = res.headers.get('Content-Type') || '';
      if (!res.ok || type.indexOf('image/') !== 0) return emptyTile();
      var copy = res.clone();
      event.waitUntil(caches.open(RUNTIME_TILES).then(function (cache) {
        return cache.put(key, copy).then(function () { return trimRuntime(cache); });
      }).catch(function () { /* cuota llena: no es crítico */ }));
      return res;
    }, emptyTile);
  }).catch(emptyTile);
}

function firstMatch(names, key) {
  if (!names.length) return Promise.resolve(null);
  return caches.open(names[0]).then(function (c) { return c.match(key); }).then(function (hit) {
    return hit || firstMatch(names.slice(1), key);
  });
}

function emptyTile() {
  var bin = atob(EMPTY_TILE);
  var bytes = new Uint8Array(bin.length);
  for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return new Response(bytes, { status: 200,
    headers: { 'Content-Type': 'image/png', 'Cache-Control': 'no-store', 'X-BioDex-Tile': 'empty' } });
}

// Tope de la caché de uso. keys() va en orden de inserción: se borran las primeras. Una pasada cada
// 50 teselas nuevas y nunca dos a la vez (recorrer 1.500 claves por tesela sería gastar batería).
var trimming = false;
var putsSinceTrim = 0;
function trimRuntime(cache) {
  if (trimming || ++putsSinceTrim < 50) return Promise.resolve();
  trimming = true;
  putsSinceTrim = 0;
  return cache.keys().then(function (keys) {
    var extra = Math.max(0, keys.length - RUNTIME_TILES_MAX);
    return Promise.all(keys.slice(0, extra).map(function (k) { return cache.delete(k); }));
  }).then(function () { trimming = false; }, function () { trimming = false; });
}

function withTimeout(promise, ms) {
  return new Promise(function (resolve, reject) {
    var timer = setTimeout(function () { reject(new Error('la red no responde')); }, ms);
    promise.then(function (r) { clearTimeout(timer); resolve(r); },
                 function (e) { clearTimeout(timer); reject(e); });
  });
}
