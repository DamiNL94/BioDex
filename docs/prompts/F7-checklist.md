# F7 · Checklist

Automático (todo en verde al cerrar F7): `docker compose exec php php bin/test-f7.php` (35 comprobaciones:
tesela válida de cada capa con `image/*` y `X-Cache-Status: HIT` a la segunda, zoom máximo de cada capa,
clave/extensión/fila desconocidas → 404, coordenadas no numéricas, parámetros, `%`-codificados y `..` → 400,
`gridDeg` en los niveles), `node frontend/tests/utm.test.mjs` (25: 10 puntos de control contra PROJ a menos de
1 cm, ida y vuelta < 1 mm), `test-f4.php`, `test-f5.php`, `test-f6.php` y `check-sw.php` (`VERSION v6`, 56
ficheros en `SHELL_ASSETS`).

Recorrido en Edge headless por CDP con dos perfiles: A (propietaria, escritorio 1280 px, claro) y B (editor,
móvil 390 px). Posición con `Emulation.setGeolocationOverride` en la Alhambra (37,1761, −3,5881); red cortada
con `Network.emulateNetworkConditions` **en la página y en el service worker** (la emulación es por objetivo:
sin la del SW, sus `fetch` siguen saliendo a la red). A crea por API el catálogo con el lince (nivel 2) y la
*Artemisia granatensis* (CR: nivel 2 sugerido; A la baja a 4 con motivo para tener también un punto exacto
ajeno). Consola sin excepciones de JS. Capturas en `docs/design/f7-*.png`.

| # | Punto | Resultado | Captura |
|---|---|---|---|
| 1 | Las tres capas se ven; atribución y escala | OK. IGN Base, PNOA y MTN cargan sus teselas de `/tiles/<clave>/…` (18/18); atribución «© Instituto Geográfico Nacional · <capa> · CC BY 4.0», escala métrica y el botón de la capa marcado. La vista y la capa se recuerdan | `f7-1a-capa-base.png`, `f7-1b-capa-pnoa.png`, `f7-1c-capa-mtn.png` |
| 1b | Vista inicial | OK. Sin vista guardada abre en la provincia de Granada; el mapa llena la pantalla sin scroll | `f7-2a-vista-inicial-granada.png` |
| 2 | Punto azul, «Centrar en mí» y UTM | OK. Punto azul con círculo de precisión; pie «37.176100, -3.588100 · UTM 30N ETRS89 X 447 794 · Y 4 114 570 · ±12 m» (PROJ: 447 793,85 / 4 114 569,91). «Centrar en mí» lleva a la Alhambra en z16; «Seguir» sigue la nueva posición; al salir de Mapa se llama a `clearWatch` | `f7-2b-posicion-utm.png` |
| 3 | Zona de la Alhambra, z13–16, base + PNOA | OK. Estimación «104 teselas · ~1,2 MB (Base 52 · PNOA 52)» antes de empezar; con IGN Base z6–20 pasa de 8.000 → aviso y botón desactivado. Progreso en la tira del mapa; «Pausar» la deja en pausa y al reanudar solo se piden las que faltaban. Fin: 104 teselas, 0 fallidas, 1,6 MB reales; contorno de la zona en el mapa y en la lista | `f7-3a-zona-estimacion.png`, `f7-3b-zona-limite.png`, `f7-3c-zona-progreso.png`, `f7-3d-zona-contorno.png`, `f7-3e-zonas-lista.png` |
| 4 | Red cortada | OK. Dentro de la zona: IGN Base 18/18 y PNOA 18/18 teselas reales, 0 fallidas; en z15, 15 reales y 3 transparentes en el borde. Fuera (Baza, nunca visto): 18/18 transparentes y 0 errores; el SW contesta 200 `image/png` con `X-BioDex-Tile: empty`. Ninguna tesela en la caché del armazón | `f7-4a-sin-red-zona-base.png`, `f7-4b-sin-red-zona-pnoa.png`, `f7-4c-sin-red-borde-zona.png`, `f7-4d-sin-red-fuera-de-zona.png` |
| 5 | «Marcar aquí» sin red → pendiente → sincronizada | OK. Selector con las especies del catálogo (del dispositivo) y la posición ±12 m; «Guardar» desactivado hasta elegir. La marca sale con el distintivo ámbar, «1 pendiente» en la cabecera y leyenda; globo con especie, fecha, «Tú», ±12 m, «Pendiente de sincronizar» y «Abrir ficha». Con ±85 m pide confirmación. Al volver la red: `synced` con `serverSeq` y sin distintivo | `f7-5a-marcar-selector.png`, `f7-5b-marca-pendiente-sin-red.png`, `f7-5c-precision-baja.png`, `f7-5d-sincronizadas.png` |
| 6 | Editor: marca ajena de nivel 2 como rectángulo | OK. B recibe el lince de A en (37,15, −3,55), sin la coordenada exacta, y el mapa dibuja la celda [37,1–37,2] × [−3,6–−3,5] (8,9 × 11,1 km), no un punto; la Artemisia (nivel 4) llega exacta y sale como punto del color de A. Leyenda «Ana Propietaria» y «Ubicación generalizada (celda)»; globo «Ubicación generalizada (~10 km)». Filtro de especie, «Ver como lista» con «Ver en el mapa» y «Abrir ficha». Todos los controles del mapa ≥ 44 px | `f7-6a-editor-celda-10km.png`, `f7-6b-editor-globo-generalizada.png`, `f7-6c-movil-filtros.png`, `f7-6d-movil-lista.png` |
| 7 | Ajustes › zonas y tema oscuro | OK. Zonas con capas, zooms, teselas, tamaño y fecha; «Borrar» con confirmación quita la zona y su caché | `f7-7a-ajustes-zonas.png`, `f7-7b-mapa-oscuro.png` |

## Decisiones
- Zoom máximo por capa: IGN Base 20, PNOA 19 (25 cm/px), MTN 17 (1:25.000; el IGN da hasta 20 pero desde 18
  es la z17 ampliada). Leaflet amplía la última nativa hasta z20 (`maxNativeZoom`).
- JPEG en las tres capas (IGN Base en PNG pesa ~6 veces más).
- Las marcas propias son cuadradas y las del equipo redondas, además del color: se distinguen sin color.
- `gridDeg` sale ahora en `GET /species/filters`; el mapa usa antes el paso del propio registro
  (`dataGeneralizations`, «0,1°») por si el nivel cambió después.
- La descarga de zonas vive en `js/map/zones.js`, no en la vista: cambiar de pestaña no la corta.

## Encontrado y corregido durante el recorrido
- La regla global `svg{width:22px}` de los iconos encogía el SVG de Leaflet (punto azul y celdas invisibles):
  ahora `svg:not(.leaflet-zoom-animated)`.
- Leaflet encuadraba Granada antes de que el contenedor tuviese alto (salía media península): el alto se fija
  antes de crear el mapa.
- El aviso «no está descargado» no se quitaba al terminar la descarga del catálogo (faltaba escuchar `download`).
- «Quitar filtros» se quedaba desactivado tras elegir un filtro (el formulario no se repinta con el foco dentro).
- `listZones()` fallaba si se consultaba justo al arrancar una descarga.
- El globo quedaba bajo el zoom y los botones flotantes en el móvil: más estrecho y con margen de encuadre.

## Pendiente de mirar a mano
- [x] **(Damián)** App instalada en Edge, descargar una zona, `docker compose stop nginx`, mover el mapa por
      la zona y marcar; al volver (`docker compose start nginx`), que la marca se sincronice. **F10b:** hecho por CDP
      con nginx parado de verdad (zona de 28 teselas, 2 marcas, sincronizadas solas en 21–29 s); en ventana instalada, en la lista previa de `docs/demo.md`.
- [ ] GPS real en el móvil (HTTPS en :8443): precisión, «Seguir» caminando y consumo de batería.
- [ ] La estimación de MB usa medias provinciales: en ciudad se queda corta (Alhambra: 1,2 estimados, 1,6 reales).
- [ ] Descargas largas (miles de teselas) con el móvil bloqueado: el navegador puede congelar la pestaña; la zona
      queda «En pausa» y se reanuda sin repetir lo bajado.

> **F10b (2026-09-30):** los puntos que siguen abiertos están recogidos en `docs/limitaciones.md`.
