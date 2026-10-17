# Guion de la demo (8 minutos)

Dos pantallas, una al lado de la otra:
- **Escritorio** (izquierda): Edge a pantalla completa, **Lucía Ortega (owner)**.
- **Móvil** (derecha): la app **instalada** en Edge, ventana estrecha (~400 × 850 px, o DevTools › modo dispositivo
  390 × 844), **Elena Ruiz (editora)**. Aquí van la red cortada y el GPS simulado.

Cuentas y datos: `README.md` › «Cuentas de demostración». Todo lo que se ve de marcas es **ficticio**.
El recorrido completo se ha pasado por CDP (Edge headless) con estas capturas en `docs/design/f10b-*.png`; son el
**plan B** de cada paso si algo falla en directo. Desde F11c la interfaz cambió (barra lateral en escritorio, cuatro pestañas en el
móvil, «Llevar al campo» y «Exportar datos»): las capturas `f10b-*` muestran la disposición anterior y
hay que rehacerlas antes del ensayo.

| Min | Pantalla | Qué se enseña | Qué se dice | Plan B |
|---|---|---|---|---|
| 0:00–1:00 | Escritorio: Catálogos | El catálogo «DEMO — Flora amenazada de Sierra Nevada»: 10 especies, resumen por categoría UICN | En el campo no hay cobertura; los datos acaban en libretas, hojas sueltas y fotos del móvil; y de algunas especies **no se puede** publicar dónde están (expolio). BioDex: catálogo compartido, marcas sin red y coordenadas protegidas por nivel | `f10b-1-catalogos` |
| 1:00–2:30 | Escritorio | «Nuevo catálogo» → Grupo *Plantas vasculares* + UICN *CR* → buscar «granatensis» → Añadir *Artemisia granatensis*; «Quitar todos», buscar «Atropa baetica» → Añadir. Cerrar y descartar. En DEMO: pestaña «Sensibilidad» | Las especies vienen de **EIDOS** (el inventario oficial del MITECO), con su categoría UICN y estado legal. Cada especie tiene un nivel 1–4 que **sugiere BioDex** desde EIDOS (CR → 2, ~10 km); la owner lo confirma o lo sube con motivo (la *Plantago*, a 3 por expolio) y queda en el registro | `f10b-2a-buscador-filtros`, `f10b-2b-constructor`, `f10b-2c-niveles` |
| 2:30–4:30 | Móvil | Catálogo › «Llevar al campo» → «✓ Listo sin conexión». «Descarga una zona del mapa» (o Mapa › Zonas) › «Descargar esta zona» (IGN Base, z11–15, ~30 teselas). **`docker compose stop nginx`** → cabecera «Servidor no disponible». Especies › *Artemisia* › «Marcar» (GPS simulado ±8 m): toca «En flor» 4 veces y escribe 2 en «Vegetativa» → «Guardar 6 individuos» (salen 2 marcas en el mismo punto; las 4 en flor cuentan como maduras). *Plantago nivalis* › «Marcar» › **Ausente** → Guardar. Mapa: «2 pendientes» y el mapa se ve | «Llevar al campo» baja el catálogo, las fichas y el mapa. Sin servidor la app abre igual; nunca se guarda una marca sin posición; la precisión va con la marca. Las **ausencias** también son dato | `f10b-3a-llevar-al-campo`, `f10b-3b-zona-estimacion`, `f10b-3c-marca-presencia`, `f10b-3d-ausencia`, `f10b-3e-mapa-sin-servidor` |
| 4:30–5:30 | Móvil | **`docker compose start nginx`** → sin tocar nada, en ≤ 30 s desaparece «2 pendientes». Mapa › Filtros › *Artemisia*: tocar una marca de Lucía → «Ubicación generalizada (~10 km)» | Al volver la cobertura se sincroniza sola. Elena es editora: **las marcas de otros de una especie de nivel 2 le llegan ya generalizadas desde el servidor**, las suyas exactas. La coordenada exacta nunca sale del servidor hacia quien no debe | `f10b-4a-editor-generalizada`. Si tarda: Ajustes › «Sincronizar ahora» |
| 5:30–7:00 | Escritorio | Mapa › Filtros › *Artemisia* › **EOO / AOO** (en el móvil: Filtros › Análisis): polígono y 3 celdas de 2 km (EOO 62,46 km², AOO 12 km²). Especies › *Artemisia*: evaluación de referencia. «Evaluar»: A sin datos → B «Calcular (celdas de 2 km)», 3 localidades, (b)(iii) → C, D, E sin datos → **EN B1ab(iii)+2ab(iii)** | EOO y AOO se calculan en el servidor con las coordenadas exactas, en UTM 30N, como pide la UICN. El asistente aplica los criterios paso a paso y explica cada umbral. Es **orientativo** y solo con los registros del catálogo | `f10b-5a-mapa-eoo-aoo`, `f10b-5b-ficha-referencia`, `f10b-5c-asistente-paso-B`, `f10b-5d-asistente-resultado` |
| 7:00–8:00 | Escritorio | Catálogos › pestaña «Exportar» › «Para publicar» (resumen: 62 observaciones, 52 generalizadas) → «Descargar ZIP». Abrir el ZIP: `dwca/`, `hojas/`, `LEEME.txt`; abrir `hojas/BioDex_….xlsx` en Excel | Sale un **Darwin Core Archive** listo para GBIF y las mismas tablas en Excel y CSV. «Para publicar» generaliza según el nivel aunque exporte la owner; la «completa» solo la tienen owner y custodio y queda registrada | `f10b-6a-descargar-datos`, `f10b-6b-descargado`. Si Excel falla: abrir el .xlsx con LibreOffice (`hojas/csv/` es para R o Python) |

Cierre (si sobra tiempo): limitaciones en `docs/limitaciones.md` (prototipo local, evaluación orientativa, pruebas en
móvil real pendientes).

## Plan B general
- **Capturas**: las de la tabla, en `docs/design/`, abiertas en una presentación o en el visor de fotos, en orden.
- **Vídeo de respaldo**: grabar el recorrido entero la víspera con la grabadora de pantalla de Windows (Recortes ›
  Grabar, `Win + Mayús + R`, o Xbox Game Bar, `Win + Alt + R`), las dos ventanas a la vista. Guardarlo en el portátil
  (`Vídeos\BioDex-demo.mp4`) y en un pendrive; no va al repositorio.
- **Si Docker no arranca**: el vídeo. **Si EIDOS no responde** (paso 2): el catálogo DEMO ya tiene las fichas en caché;
  saltar el buscador y enseñar `f10b-2a`.

## Lista previa al congreso
La víspera, con red:
- [ ] Portátil sin actualizaciones pendientes (Windows Update, Docker Desktop, Edge) y con las notificaciones silenciadas.
- [ ] `docker compose up -d` y http://localhost:8090/api/health → `{"ok":true,"db":true}`.
- [ ] `docker compose exec php php bin/reset-demo.php` y `bin/seed-demo.php` (imprime las cuentas); luego
      `bin/fetch-species-photos.php`: en el paso 2, *Atropa baetica* (sin foto en EIDOS) sale con la de iNaturalist y su
      crédito, y *Laserpitium longiradium* con la silueta (F11b; F11d: tampoco hay foto en GBIF ni en Commons).
- [ ] Todas las pruebas en verde (`README.md` › «Comprobaciones»).
- [ ] Escritorio: entrar como `owner@biodex.demo`, catálogo DEMO activo.
- [ ] Móvil: app instalada, entrar como `editor@biodex.demo`, catálogo DEMO activo. **No** descargarlo aún (se hace en
      directo); sí ensayar una vez el paso 3 entero y, después, `reset-demo` + `seed-demo` y volver a entrar.
- [ ] Caché de teselas llena: el ensayo deja en nginx las teselas de la zona; comprobar que la descarga de la zona
      tarda segundos.
- [ ] Geolocalización simulada preparada en la ventana del móvil: DevTools › Sensors › Location, 37.0806, −3.3996, y
      permiso de ubicación concedido a `localhost`.
- [ ] Terminal abierta en la carpeta del proyecto con `docker compose stop nginx` y `docker compose start nginx` a mano.
- [ ] Abrir una vez el XLSX exportado en Excel en este portátil (no se ha podido probar en la máquina de desarrollo).
- [ ] Vídeo de respaldo grabado y copiado al pendrive; capturas abiertas en orden.
- [ ] Batería cargada, cargador, adaptador HDMI; resolución del proyector probada con las dos ventanas.
