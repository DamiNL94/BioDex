# Mapa (F7)

Código: `frontend/js/views/map.js` (vista), `js/map/layers.js` (capas y cuentas de teselas),
`js/map/marks-layer.js` (marcas), `js/map/zones.js` (descarga de zonas), `js/map/zone-ui.js` (editor y
lista de zonas), `js/geo/utm.js` (UTM). Proxy en `docker/nginx/biodex-app.conf` + `default.conf`.

## Capas del IGN

Verificadas con `GetCapabilities` (WMTS 1.0.0) el 30/09/2026:

| Clave | Servicio | Capa | Formatos que ofrece | Usamos | Zoom (GoogleMapsCompatible) | Máximo en BioDex |
|---|---|---|---|---|---|---|
| `base` | `https://www.ign.es/wmts/ign-base` | `IGNBaseTodo` | `image/jpeg`, `image/png` | JPEG | 0–20 | **20** |
| `pnoa` | `https://www.ign.es/wmts/pnoa-ma` | `OI.OrthoimageCoverage` | `image/jpeg`, `image/png` | JPEG | 0–20 | **19** |
| `mtn` | `https://www.ign.es/wmts/mapa-raster` | `MTN` | `image/jpeg`, `image/png` | JPEG | 0–20 | **17** |

- Las tres anuncian 0–20, pero no todo es imagen nativa: el **MTN** es el 1:25.000 y desde z18 el IGN solo
  lo remuestrea (comprobado a ojo en la Alhambra: z18 es la z17 ampliada); el **PNOA** tiene 25 cm/píxel,
  que es z19. IGN Base es vectorial rasterizado y se ve nítido hasta z20.
- JPEG en las tres: IGN Base en PNG pesa unas 6 veces más (90 KB frente a 15 KB en z12).
- Leaflet usa `maxNativeZoom` = máximo de la capa y sigue ampliando hasta z20 sin pedir más teselas.
- Atribución visible: «© Instituto Geográfico Nacional · IGN Base / PNOA / MTN · CC BY 4.0» (la de la capa activa).

## Proxy `/tiles/`

`GET /tiles/<clave>/<z>/<x>/<y>.jpg` → `GetTile` KVP hacia el IGN con servicio, capa, formato y zoom máximo
**fijos por clave** (`map` en `default.conf`). Del cliente solo se usan z/x/y y solo si son números.

| Petición | Respuesta |
|---|---|
| tesela válida | 200 `image/jpeg`, `Cache-Control: public, max-age=2592000`, `X-Cache-Status` (MISS → HIT) |
| zoom por encima del máximo de la capa, clave desconocida, extensión que no es `.jpg` | 404 (sin llamar al IGN) |
| fila/columna fuera de la rejilla (el IGN contesta 400 XML) | 404 |
| coordenadas no numéricas, parámetros (`?…`), `%`-codificados, `..` o ruta de más | 400 |
| IGN caído (5xx) | 502 |

Caché de nginx `tiles` de 2 GB, 30 días sin uso; `proxy_cache_lock` para no pedir dos veces la misma.
Cabeceras nuestras (CSP, `nosniff`…) y ninguna del IGN/CloudFront. Pruebas: `bin/test-f7.php`.

## Tamaño medio de tesela (estimación de zonas)

Medido el 30/09/2026 con 12 teselas al azar por zoom dentro de la provincia de Granada (KB por tesela):

| Capa | z12 | z14 | z16 | z17 |
|---|---|---|---|---|
| IGN Base | 12,3 | 10,2 | 5,1 | 3,9 |
| PNOA | 14,8 | 14,1 | 17,6 | — |
| MTN | 21,7 | 19,7 | 14,7 | 9,9 |

Entre zooms medidos se interpola; por encima del último vale el último (`layers.js`, `sampleKB`). En zonas
urbanas la estimación se queda corta: la de la Alhambra (z13–16, base + PNOA) estimó 1,2 MB y ocupó 1,6 MB.

## Vista Mapa

- **Inicio**: la provincia de Granada (36,7–38,0 N; 4,3–2,2 O); después, la última vista y capa
  (`localStorage` `biodex.mapView`).
- **Posición**: `watchPosition` (alta precisión) solo con la pestaña Mapa montada y visible; se para al
  cambiar de pestaña o al ocultarse la página. Punto azul + círculo de precisión, «Centrar en mí» (z ≥ 16)
  y «Seguir» (se suelta al arrastrar el mapa). Pie: lat/lon con 6 decimales, **UTM 30N ETRS89
  (EPSG:25830)** y ±precisión.
- **UTM** (`geo/utm.js`): Mercator Transversa sobre GRS80 con las series de Krüger hasta n⁶ (Karney 2011),
  sin dependencias; siempre en el huso 30 (como EPSG:25830, también fuera de él). Pruebas:
  `node frontend/tests/utm.test.mjs`, 10 puntos de control contra PROJ (`cs2cs EPSG:4258 EPSG:25830`) con
  error < 1 cm, más ida y vuelta < 1 mm.
- **«Marcar aquí»**: abre la hoja de marca (`views/mark-sheet.js`, F8a) con la última posición del GPS; la
  hoja sigue la suya mientras está abierta y guarda la del momento de pulsar «Guardar». Con precisión peor
  que 50 m pide confirmación. Selector de las especies del catálogo activo (del servidor o, sin red, del
  dispositivo), con la del filtro de especie ya elegida.
- **Marcar en un punto del mapa** (F8a, individuo visto a distancia): mantener pulsado en el mapa (clic
  derecho en escritorio; Leaflet lo recibe como `contextmenu`) → marcador provisional y la misma hoja, con
  `georeferenceProtocol: Punto marcado en el mapa`, **precisión estimada obligatoria** (m) y
  `georeferenceRemarks`. Editando una zona, la pulsación larga no marca.
- Marcadores (F8a): el punto marcado a mano lleva **borde discontinuo** (24 px, 3 px); una ausencia, marcador
  hueco con aspa. Los dos salen en la leyenda y en el globo.

## Capa «EOO / AOO» (F9)
Botón **«EOO / AOO»** de la barra: con una especie elegida en Filtros (si no la hay, abre Filtros), pinta lo que manda
`GET …/species/{taxonID}/metrics?scope=team&cell=2` (`map/metrics-layer.js`, sin interacción y por debajo de las
marcas): **polígono convexo mínimo** (EOO, latón discontinuo) y **celdas de 2 km** (AOO, azul; celdas UTM dibujadas con
sus 4 esquinas por `fromUtm`, porque en grados no son rectángulos) para owner, custodio o nivel 4; para el resto en
nivel 2–3, solo las **celdas de 0,1° / 0,01°** con registros; en nivel 1, nada (la nota lo dice). Nota con las cifras y
el origen (servidor con fecha o provisional sin red), leyenda y encuadre al activarla. Se recalcula tras cada
sincronización. Reglas y límites: `docs/iucn.md` › «EOO y AOO en BioDex».

## Marcas en el mapa

Del catálogo activo, desde IndexedDB (sin red también), repintadas al terminar cada sincronización.

- Propias: cuadrado verde sólido. Del equipo: círculo del color de su observador (`--obs-1…8` de
  `biodex.css`, por orden alfabético de nombre), con leyenda. Puntos agrupados con markercluster.
- **Generalizadas** (niveles 2 y 3 para quien no es de confianza): el servidor manda el **centro** de la
  celda (floor + medio paso); el mapa dibuja la **celda** (`floor(centro / paso) · paso` + paso) como
  rectángulo discontinuo, nunca un punto. El paso sale de `dataGeneralizations` del registro («0,1°») o del
  `gridDeg` del nivel. Nivel 1 retenido: sin coordenadas, no se dibuja (se cuenta aparte).
- Pendientes: distintivo ámbar. Globo: especie, fecha, autor, ±m, «Ubicación generalizada (~10 km)» si
  aplica y «Abrir ficha».
- Filtros: especie, observador, mías/equipo, desde/hasta. «Ver como lista» con «Ver en el mapa» y «Abrir ficha».

## Zonas para usar sin conexión

- «Descargar esta zona» (Mapa › Zonas): rectángulo editable por sus esquinas, capas y rango de zoom
  (12–17 base y MTN, 12–16 PNOA por defecto, limitado al máximo de cada capa), nombre obligatorio y
  estimación de teselas y MB antes de empezar.
- Límites: **8.000 teselas por zona** y cuota (`navigator.storage.estimate()`, con 20 % de margen).
- Descarga: 4 peticiones a la vez, progreso, pausa, cancelar (borra lo bajado) y reanudar (salta lo que
  ya está en la caché de la zona; lo que esté en otra caché de teselas se copia sin pedirlo). 8 fallos
  seguidos → pausa («la red no responde»). Las peticiones llevan `X-BioDex-Zone: 1` y el SW las deja pasar.
- Cache Storage `biodex-tiles-<zoneId>`; la ficha en IndexedDB (`zones`, base v2): `{id, name, bounds,
  layers, tileCount, estimatedBytes, doneCount, failedCount, bytes, status, error, createdAt, completedAt}`.
- En el mapa se ven como contorno discontinuo de latón. Ajustes › Datos en este dispositivo: lista con
  tamaño, reanudar y borrar. Cerrar sesión borra las cachés de las zonas.

## Service Worker

`/tiles/`: cache-first en **todas** las `biodex-tiles-*` (primero zonas, después la de uso); si no está, red
con corte de 5 s y la respuesta va a `biodex-tiles-runtime` (**tope de 1.500**, se borran las más antiguas
cada 50 nuevas). Sin red ni copia: PNG transparente de 1×1 con 200 (`X-BioDex-Tile: empty`), nunca un
error visible. Ninguna tesela va a la caché del armazón.
