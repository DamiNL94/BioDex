# F3 · Esqueleto de la PWA

Requiere F2a y F2b hechas. Lee `CLAUDE.md` y `docs/api.md` (solo la sección de autenticación).
De NaturDex lee SOLO, y solo lo indicado:
- `../NaturDex/Frontend/Css/ranger.css` → bloques **A (tokens, l. 15–148)**, **D (tema oscuro, l. 608–668)** y
  **F (áreas táctiles, l. 680–740)**. Del bloque B (l. 149–462) solo lo que pida la lista de componentes de abajo.
  **No leas `styles.css`** (134 KB de componentes de NaturDex que BioDex no usa).
- `../NaturDex/Frontend/Js/api.js`, `../NaturDex/Frontend/Js/nav.js` y, de `../NaturDex/Frontend/sw.js`,
  la estrategia de caché (l. 73–210).
- Copia tal cual `../NaturDex/Frontend/Vendor/fonts/` (Zilla Slab + Public Sans, con sus licencias).

No toques `backend/` salvo lo indicado en «Servidor». Nada de mapa real (F7), IndexedDB (F5) ni marcas (F8).

## Dirección de diseño: la «ranger» de NaturDex, en versión profesional
La propuesta de NaturDex (fichero `NaturDex - Propuesta ranger (offline).html` en la raíz; **no lo abras**,
es un bundle de 588 KB; muévelo a `docs/design/` y añádelo a `.claudeignore`). Sus reglas, que aplican aquí:
- **Cabecera de cartel:** banda verde bosque (`--leaf-fill`) con filete de latón (`--brass`), rótulo en
  Zilla Slab. En BioDex muestra el **catálogo activo** y un indicador de conexión/sincronización.
- **Tipografía:** Zilla Slab para títulos, Public Sans para todo lo demás. Vendorizadas, nunca desde CDN.
- **Esquinas rectas** (2–4 px), **etiquetas en versalitas** para códigos, categorías y metadatos
  (`CR`, `CEEA · VULNERABLE`, `NIVEL 2`), **filete lateral de color** en filas de lista.
- **Avisos de guarda:** bandas marrón/ámbar para avisos (sin conexión, sesión caducada, especie sensible).
- **Modo oscuro como cartel retroiluminado** (bloque D), no blanco invertido. Claro/oscuro/sistema.
- **Sin gamificación:** nada de parches, medallas ni contadores de «vistas». Es una herramienta científica.

## Estructura (`frontend/`)
```
index.html  manifest.webmanifest  sw.js
css/biodex.css            ← tokens A+D+F + componentes propios; un solo fichero, ≤ 600 líneas
vendor/fonts/             ← copiado de NaturDex
img/icon.svg  img/icon-192.png  img/icon-512.png  img/icon-maskable-512.png
js/app.js                 ← arranque, enrutado por hash (#/mapa, #/catalogos, #/especies, #/ajustes)
js/api.js                 ← portado de NaturDex (timeouts, apiGetJson/apiPostJson, errores en castellano)
js/nav.js                 ← portado de NaturDex (pila de «atrás»); API en window.BioDexNav
js/auth.js                ← pantallas de acceso y lógica de sesión
js/state.js               ← estado en memoria + usuario cacheado + preferencias
js/util.js                ← plantilla html`` con escapado automático (anti-XSS) y utilidades mínimas
js/views/map.js  catalogs.js  species.js  settings.js
```
Módulos ES nativos, sin build. **Todo HTML dinámico pasa por `html```** (los nombres de especie y las
notas vendrán de usuarios y de EIDOS).

## Pantallas
1. **Acceso** (sin sesión): entrar / crear cuenta, con los errores de la API (401, 409, 429 con
   `Retry-After`). Tras registrarse, aviso «revisa tu correo» (Mailpit en local).
2. **App**, con cabecera de cartel y navegación:
   - **Móvil (< 900 px):** barra inferior con **Mapa · Catálogos · Especies**; engranaje de **Ajustes** en la cabecera.
   - **Escritorio:** las mismas tres secciones como pestañas bajo la cabecera; Ajustes a la derecha.
     El escritorio es donde se preparan los catálogos: aprovecha el ancho (no una columna de móvil estirada).
   - **Mapa:** marcador de posición «El mapa llega en F7».
   - **Catálogos:** lista real de `GET /api/catalogs` (nombre, mi rol en versalitas, nº de especies) y
     botón «Nuevo catálogo» que crea con `POST /api/catalogs` (solo nombre y descripción). Elegir uno lo
     marca como **catálogo activo** (se guarda en `state`, persiste en `localStorage`). Estado vacío con
     una frase que explique qué es un catálogo.
   - **Especies:** si no hay catálogo activo, invita a elegir uno; si lo hay, lista de sus especies
     (`GET /api/catalogs/{id}`) con nombre, categoría UICN principal y nivel de sensibilidad en versalitas.
     Sin buscador todavía (F4).
   - **Ajustes:** cuenta (nombre, email, «email sin verificar» si aplica), tema, versión de la app,
     estado de conexión y **Cerrar sesión**.

## Sesión y días sin red (clave del proyecto)
- Al entrar, se guarda el `user` en `localStorage` (`biodex.user`).
- Arranque: **sin red** y con usuario guardado → entra directo con banda de aviso «Sin conexión: trabajas
  en local». **Con red** → `GET /api/auth/me`; si 200, actualiza el usuario; si 401, pantalla de acceso
  con el aviso «Tu sesión ha caducado. Lo que tengas en este dispositivo se conserva.»
- `online`/`offline` del navegador actualizan el indicador de la cabecera sin recargar.
- Cerrar sesión: `POST /api/auth/logout` si hay red y borra `biodex.user`. (Los datos locales de F5
  tendrán su propia regla; deja un `TODO F5` donde irá.)

## Service worker
- Mismo patrón que NaturDex: `VERSION`, lista **`SHELL_ASSETS`** agrupada por carpeta, network-first en
  el mismo origen con caída a caché, `index.html` como respuesta de navegación sin red, limpieza de
  cachés viejas en `activate`.
- **`/api/` nunca se cachea en el SW** (los datos irán a IndexedDB en F5).
- Comentario al principio: «Un fichero nuevo del frontend va a SHELL_ASSETS y sube VERSION».

## Servidor
- `docker/nginx/default.conf`: cabeceras de seguridad para el frontend y la API:
  `Content-Security-Policy: default-src 'self'; img-src 'self' data: https://www.miteco.gob.es; connect-src 'self'; style-src 'self'; script-src 'self'; font-src 'self'; frame-ancestors 'none'`,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`. `sw.js` y `index.html` con `Cache-Control: no-cache`.
- **Probar en el móvil** (el GPS y el SW exigen contexto seguro). Implementa la opción A y documenta las dos
  en el `README.md`:
  - A. `mkcert`: servidor HTTPS en el puerto **8443** con certificados en `docker/nginx/certs/` (en `.gitignore`).
  - B. Sin certificados: depuración USB + `chrome://inspect` → *Port forwarding* 8090 → `localhost:8090` en el móvil.
- Monta `./frontend:/var/www/frontend:ro` también en el contenedor `php` para el script de comprobación.

## Iconos
`img/icon.svg` propio de BioDex (no el de NaturDex): silueta sencilla (hoja o huella + punto de
geolocalización) en `--leaf-fill` con filete `--brass`. Genera los PNG con un script PHP+GD
(`backend/bin/make-icons.php`) o dibújalos directamente con GD; el maskable con margen de seguridad del 20 %.
Manifest: `name` «BioDex — cuaderno de campo», `short_name` «BioDex», `lang` es, `display` standalone,
`theme_color` = `--leaf-fill`.

## Comprobaciones
- `backend/bin/check-sw.php`: lee `frontend/sw.js`, extrae `SHELL_ASSETS` y falla si algún fichero no
  existe o si algún `.js`/`.css` de `frontend/` no está en la lista (la lección de NaturDex).
- Manual, y déjalo como lista en `docs/prompts/F3-checklist.md`:
  1. Escritorio: crear cuenta → correo en Mailpit → crear catálogo → marcarlo activo → verlo en Especies.
  2. DevTools › Application: manifest sin errores, SW activo, «Offline» marcado → la app abre con la banda de aviso.
  3. Móvil (opción A o B): instalar en pantalla de inicio, modo avión, abrir → entra con el usuario guardado.
  4. Tema oscuro legible (contraste ≥ 4,5:1 en texto, ya resuelto en el bloque D de NaturDex).
  5. Consola sin errores de CSP.

## Criterio de hecho
`check-sw.php`, `test-f2a.php` y `test-f2b.php` en verde; los 5 puntos del checklist comprobados.
Commits `F3:`. Resume en 8 líneas y adjunta dos capturas (móvil y escritorio) en `docs/design/`.
