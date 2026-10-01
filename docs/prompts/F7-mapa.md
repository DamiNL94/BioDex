# F7 · Mapa: capas del IGN, zonas sin conexión, GPS y marcas

Requiere F6 hecha. Lee `CLAUDE.md`, `docs/sync.md` (solo «Pull») y las firmas exportadas de
`frontend/js/{db,marks,offline,sync,state}.js`. De NaturDex, **solo** copia `Frontend/Vendor/leaflet/` y
`Frontend/Vendor/markercluster/` (con sus LICENSE) y lee `Frontend/Js/map/camara.js` si necesitas ideas de
encuadre. Nada de EOO/AOO (F9) ni ficha de marca completa (F8).

## 1. Teselas del IGN por nuestro servidor
- **Primero verifica** con `GetCapabilities` de cada servicio los nombres de capa, formato y zoom máximo,
  y apúntalos en `docs/mapa.md`. Punto de partida (PNOA confirmado en NaturDex):
  | Clave | Servicio | Capa | Formato |
  |---|---|---|---|
  | `base` | `https://www.ign.es/wmts/ign-base` | `IGNBaseTodo` | a verificar |
  | `pnoa` | `https://www.ign.es/wmts/pnoa-ma` | `OI.OrthoimageCoverage` | `image/jpeg` |
  | `mtn` | `https://www.ign.es/wmts/mapa-raster` | `MTN` | a verificar |
  Todas con `TileMatrixSet=GoogleMapsCompatible`.
- nginx: `location ~ ^/tiles/(base|pnoa|mtn)/(\d{1,2})/(\d+)/(\d+)\.(png|jpg)$` → petición `GetTile` KVP con capa
  y formato **fijos por clave** (nada de la URL del cliente pasa tal cual), `proxy_cache` 30 días (el bloque
  comentado desde F0), mismas cabeceras que el proxy de la Fototeca. Zoom fuera del máximo de la capa → 404.
- Atribución visible: «© Instituto Geográfico Nacional · PNOA / IGN Base / MTN» (CC BY 4.0).

## 2. Vista Mapa (`js/views/map.js` + `js/map/*.js` si crece)
- Leaflet vendorizado (a `SHELL_ASSETS`). Vista inicial: **provincia de Granada** (≈ 36.7–38.0 N, 4.3–2.2 O);
  después recuerda la última vista.
- Selector de capa **IGN Base · PNOA (ortofoto) · MTN** con la piel ranger; escala métrica.
- **Posición:** `watchPosition` solo mientras la pestaña Mapa está visible (batería); punto azul con círculo
  de precisión, botón «Centrar en mí» y modo seguir. Pie de mapa con lat/lon (6 decimales), **UTM 30N
  ETRS89 (EPSG:25830)** y ±precisión. UTM con una implementación propia de Mercator Transversa en
  `js/geo/utm.js` (sin dependencias; F9 la reutiliza) y **pruebas con puntos de control** en
  `backend/bin/` o `frontend/tests/` (p. ej. comparados con valores de un SIG, error < 1 m).
- **«Marcar aquí»** flotante: usa `createMark` de F5b con la posición GPS actual (si la precisión es peor que
  50 m, aviso y confirmación). Sin especie elegida, abre un selector de las especies del catálogo activo.

## 3. Marcas en el mapa
- Del catálogo activo, desde IndexedDB (funciona sin red). `markercluster` para agrupar.
- Propias: marcador sólido; del equipo: color por observador (paleta de 8 colores de `biodex.css`, con leyenda).
- **Generalizadas: se dibujan como la celda (rectángulo) de su generalización, nunca como un punto**, con
  el aviso «Ubicación generalizada (~10 km)». Nivel 1 retenido: no se dibujan.
- Pendientes de sincronizar con un distintivo. Toque → globo con especie (`sciName`), fecha, autor, ±m y
  «Abrir ficha».
- Filtros: especie, observador, mías/equipo, rango de fechas. Se actualiza al terminar cada sincronización.

## 4. Zonas para usar sin conexión
- **«Descargar esta zona»**: dibuja el encuadre actual como rectángulo editable, permite elegir capas y
  rango de zoom (por defecto 12–17 base/MTN, 12–16 PNOA, limitado al máximo de cada capa) y muestra **nº de
  teselas y MB estimados** (media medida por capa, anótala en `docs/mapa.md`) antes de empezar.
- Límites: **máx. 8.000 teselas por zona** (aviso para reducir área o zoom) y comprobación de cuota con
  `navigator.storage.estimate()`. Descarga con **4 peticiones en paralelo**, progreso, pausa, cancelar y
  reanudar (lo ya bajado no se repite). Nombre de la zona obligatorio.
- Guardado en Cache Storage `biodex-tiles-<zoneId>`; la lista de zonas (nombre, capas, zooms, límites,
  nº teselas, tamaño, fecha) en IndexedDB (almacén nuevo `zones`, sube la versión de la base).
- En el mapa, las zonas descargadas se ven como contorno discreto; en Ajustes › Almacenamiento, lista con
  tamaño y borrar.
- **SW:** `/tiles/` cache-first buscando en **todas** las cachés `biodex-tiles-*`; si no está, red con corte de
  5 s; sin red ni caché, tesela transparente (nunca un error visible). Las teselas vistas con red van a una
  caché `biodex-tiles-runtime` **con tope de 1.500 entradas** (borra las más antiguas). **Nunca** a la caché del armazón.
- Aviso en la ayuda de la zona: usar áreas razonables; el IGN es un servicio público.

## 5. Reglas
- Cada fichero nuevo a `SHELL_ASSETS`, `VERSION` y `APP_VERSION` (`0.7.0 · F7`); `check-sw.php` en verde.
- Accesible: controles con etiqueta, objetivos ≥ 44 px, la lista de marcas visibles también como lista
  (botón «Ver como lista») para quien no use el mapa.

## 6. Comprobación
- `backend/bin/test-f7.php`: tesela válida de cada capa → 200 con `image/*`; zoom fuera de rango, clave
  desconocida, coordenadas no numéricas o parámetros → 400/404; segunda petición → `X-Cache-Status: HIT`.
- Pruebas de `utm.js` en verde.
- Recorrido CDP (capturas en `docs/design/`), con `Emulation.setGeolocationOverride` en la Alhambra:
  1. Las tres capas se ven; atribución y escala presentes.
  2. Punto azul, «Centrar en mí» y coordenadas UTM coherentes.
  3. Descargar la zona de la Alhambra, z13–16, base + PNOA → estimación, progreso y fin.
  4. Red cortada: el mapa de esa zona se ve completo (0 teselas fallidas); fuera de la zona, transparente sin errores.
  5. «Marcar aquí» sin red → aparece con distintivo de pendiente; al volver la red, sincronizada.
  6. Perfil editor: una marca ajena de nivel 2 se ve como **rectángulo de ~10 km**, no como punto.
- **A mano (Damián):** app instalada en Edge, descargar una zona, `docker compose stop nginx`, mover el mapa por
  la zona y marcar.
Resultados en `docs/prompts/F7-checklist.md`. Commits `F7:`. Resume en 8 líneas.
