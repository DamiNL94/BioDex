# Modo búsqueda y esfuerzo de muestreo de las visitas (F11j, 1.10.0)

BioDex mide la completitud del inventario espacial con Chao2 sobre celdas de 2 km (`docs/iucn.md`,
`bibliografia/suficiencia-de-datos.md`). El estimador supone que cada ocasión de muestreo es una búsqueda
**independiente** y de **esfuerzo comparable** (Chao 1987). Hasta la 1.9.0 una ocasión era «un día con alguna marca
de la especie», y eso fallaba por dos sitios:

1. **Detecciones no independientes.** El mapa enseñaba las marcas anteriores: se podía ir derecho a una planta ya
   geolocalizada. Esa redetección convierte un único (Q1) en un duplicado (Q2) y sube la completitud sin decir nada
   de la detectabilidad.
2. **Esfuerzo sin registrar.** Una visita de 5 minutos pesaba como una de 3 horas, y una celda sin marcas no
   distinguía «busqué y no está» de «nunca fui».

Desde la 1.10.0 una visita puede hacerse en **modo búsqueda** y con un **protocolo de esfuerzo**; solo esas son
ocasiones de Chao2.

## Protocolos de búsqueda

Son independientes del protocolo de **conteo** (`protocol`: censo de especies objetivo, conteo en parcela, transecto
con banda, búsqueda por tiempo), que dice cómo se cuenta. El de búsqueda (`effortProtocol`) dice cómo se buscó.

| Protocolo | Esfuerzo objetivo | Unidad | Qué se mide |
|---|---|---|---|
| **Tiempo fijo** (`timed`; *timed meander search*, Goff et al. 1982) | minutos de búsqueda activa | min | tiempo entre inicio y fin, sin las pausas |
| **Transecto** (`transect`) | longitud recorrida | m | distancia del recorrido GPS filtrado |
| **Libre** (`free`) | — | — | nada: no es ocasión de Chao2 |

Al empezar la visita («Empezar visita» › *Búsqueda y esfuerzo*) se elige el protocolo y se fija el objetivo (por
defecto 30 min o 1000 m). Lo propuesto sale, por este orden, de la última visita al **sitio** (`sites.effort_protocol`,
`effort_target`: se sincroniza), de la última visita del **catálogo** en este dispositivo (meta
`effortDefault:<catalogId>`) o del protocolo de conteo (búsqueda por tiempo → tiempo fijo; transecto → transecto; lo
demás → libre). La barra de la visita enseña el progreso («12 de 30 min», «340 de 1000 m») y avisa al alcanzarlo.

## Modo búsqueda

Con un protocolo que no sea «libre», la visita arranca en modo búsqueda por defecto (se puede desmarcar al empezar).
Mientras dura:

- Se ocultan **todas las marcas anteriores de las especies objetivo** de la visita, de cualquier usuario y fecha. Si la
  visita no tiene especies objetivo se busca todo, y se oculta todo lo anterior del catálogo. Se ven las marcas de la
  visita en curso, los sitios, la cuadrícula y la posición propia.
- La regla es **una sola**, en `frontend/js/blind.js` (`hiddenByBlind`, `blindFilter`, `blindHidesTaxon`), y la
  consultan todos los caminos por los que una marca previa podía aparecer:

| Camino | Qué se cierra | Dónde |
|---|---|---|
| Mapa | puntos, clústeres, recuento, filtro de especies y de observadores, leyenda y lista lateral | `views/map.js › loadMarks` |
| Mapa · EOO/AOO y subpoblaciones | casco, celdas ocupadas, centros de subpoblación | `metrics.js › speciesMetrics`, `assessment.js › speciesAssessment` (lanzan el aviso) |
| Ficha · Registros | «Mis marcas», marcas del equipo y minimapa | `marks.js › marksFor` |
| Ficha · Distribución | EOO, AOO y su mapa | `speciesMetrics` |
| Ficha · Evaluación UICN | subpoblaciones, distancias, comparación con GBIF | `speciesAssessment` |
| Asistente UICN | resumen de marcas y métricas | `marksFor`, `speciesMetrics` |
| Hoja de marca y buscador | el grupo «Cerca de aquí (< 200 m)» | `components/species-picker.js` |
| Hoja de impacto | especies propuestas por tener marcas dentro del radio | `views/impact-sheet.js` |
| Exportación del catálogo | el ZIP lleva coordenadas | `views/catalog-export.js` (bloqueada con aviso) |

  La serie temporal (recuentos por sitio y año) no lleva posiciones y sigue visible. Los **sitios** se ven siempre: una
  visita de repetición vuelve a un sitio conocido a propósito.
- **Salir**: botón «Salir del modo búsqueda» en la barra de la visita, con confirmación que explica la consecuencia.
  La visita queda como **búsqueda no ciega** (`blind = false`) de forma **irreversible** y deja de contar para Chao2;
  sus marcas se guardan igual y cuentan para EOO y AOO. El servidor tampoco deja volver: si la visita guardada ya no
  era ciega, la que llega tampoco lo es (`Lib/visits.php › visitGuardBlind`).
- **Sin conexión y tras cerrar la app**: el estado vive en el registro de la visita en IndexedDB (`blind`, `pauses`,
  `effortProtocol`…). `state.visit` (memoria y `localStorage biodex.activeVisit`) es una copia para leerlo sin
  esperar; al arrancar, `checkActiveVisit()` la corrige con lo que diga IndexedDB.

**Qué no puede garantizar.** El modo búsqueda es **declarativo**: la app oculta las marcas en ese dispositivo, pero no
puede impedir que alguien las mire en otro, en un mapa en papel o de memoria. Vale como protocolo de campo honrado, no
como control.

## Registro del esfuerzo

- **Inicio, fin y tiempo activo.** «Pausar» / «Reanudar» en la barra (descansos, desplazamientos entre zonas): las
  pausas se guardan como `[inicio, fin]` y no cuentan como búsqueda ni graban recorrido.
- **Recorrido GPS** (`watchPosition` de alta precisión, en `components/visit-bar.js`). Cada fix pasa por
  `appendFix()` de `shared/track.js`, que es el **único sitio donde se configuran los umbrales** (`TRACK`; en PHP,
  las constantes `TRACK_*` de `Lib/track.php`, que deben coincidir):

| Umbral | Valor | Para qué |
|---|---|---|
| `maxAccuracyM` | 30 m | se descartan los fixes con peor precisión |
| `minStepM` | 10 m (o la precisión del fix, si es mayor) | por debajo es ruido en parado: no suma distancia, pero el tiempo cuenta como vigilado |
| `maxSpeedMs` | 12 m/s | por encima es un salto imposible a pie: se descarta |
| `gapS` | 120 s | sin fixes válidos más tiempo: **hueco** |
| `minCoverage` | 0,8 | parte del tiempo activo que debe estar vigilada para que el recorrido sea completo |
| `maxPoints` | 5000 | tope de puntos por visita |
| `sampleM` | 250 m | un tramo largo se muestrea cada tanto para no saltarse celdas |

  Un punto es `[lat, lon, t, precisión, tÚltimo]`: `tÚltimo` es el último fix recibido sin salir del paso mínimo, de
  modo que diez minutos de búsqueda quieto son tiempo vigilado y no un hueco.
- **Distancia**: suma de los tramos continuos del recorrido filtrado (haversine).
- **Celdas cubiertas**: celdas de 2 km (UTM 30N, las de la AOO) que pisa el recorrido. Son las celdas muestreadas de
  la visita, con o sin detección. Las de 1 km no se guardan: se calculan del recorrido cuando hagan falta.
- **Huecos**: un hueco no suma distancia, ni tiempo, ni celdas entre sus extremos, y el recorrido queda partido en
  tramos. Si el tiempo vigilado no llega al 80 % del tiempo activo, la visita queda con **recorrido incompleto**.
- **`blind`** y **objetivo alcanzado** (`targetMet`).

El móvil calcula todo esto para enseñarlo sin red, pero **el servidor lo recalcula** del recorrido que recibe al cerrar
la visita y no acepta esas cifras del cliente (`syncValidateVisit`, `Lib/track.php`). Mismos resultados en JS y PHP:
`shared/track-cases.json`.

### Limitaciones del GPS en una PWA

- Con la **pantalla apagada** o la app en **segundo plano**, la mayoría de los móviles detienen `watchPosition` en una
  PWA. No hay forma de evitarlo desde una web.
- BioDex pide un **Wake Lock** de pantalla (`navigator.wakeLock.request('screen')`) mientras graba, cuando el navegador
  lo ofrece, para que la pantalla no se apague sola; lo vuelve a pedir al regresar a la app. Puede denegarse (batería
  baja, ahorro de energía) o no existir: la barra dice siempre «Mantén la pantalla encendida y la app abierta».
- Lo que se pierda queda **a la vista**: la barra cuenta los huecos, el resumen de cierre dice qué porcentaje del
  tiempo tuvo GPS y si la visita contará o no, y la exportación lo marca («recorrido GPS incompleto»).
- Bajo arbolado denso o en barrancos la precisión puede pasar de 30 m durante minutos: esos fixes se descartan y, si
  dura, es un hueco. El umbral está en `TRACK.maxAccuracyM`.
- **Sin probar en un teléfono real**: la grabación se ha probado con recorridos sintéticos y con la geolocalización
  simulada de Playwright (un punto fijo). Falta una salida de campo.

## Ocasión válida y Chao2

Para una especie se **consideran** las visitas cerradas que la buscaban: es una de sus especies objetivo, o la visita
no tiene especies objetivo. De ellas es **ocasión válida** la que, por este orden:

| Condición | Si no, excluida por |
|---|---|
| tiene protocolo de búsqueda (no «libre») | protocolo libre |
| se hizo entera en modo búsqueda | no ciega |
| alcanzó el esfuerzo objetivo | esfuerzo insuficiente |
| tiene el recorrido completo y sus celdas cubiertas | recorrido incompleto |
| es del protocolo con más ocasiones válidas (a igualdad, tiempo fijo) | otro protocolo |
| su esfuerzo está entre 0,5× y 2× la mediana de ese protocolo (solo con ≥ 3 ocasiones) | esfuerzo fuera de rango |

Con las ocasiones válidas (`shared/iucn-sufficiency.js › samplingCompleteness`, port en `Lib/sufficiency.php`):

- **Celdas del cálculo**: las cubiertas por el recorrido de alguna ocasión válida (más la de cada presencia registrada
  en ella). La incidencia de una celda es en cuántas ocasiones válidas se detectó la especie.
- **Chao2** con corrección de sesgo: S = S_obs + ((T − 1)/T) · Q1(Q1 − 1) / (2(Q2 + 1)); completitud = S_obs / S.
- **Resultado negativo**: una celda cubierta sin detección. Con ≥ 3 ocasiones, **ausencia confirmada**. Las ausencias
  marcadas a mano ya no cuentan para esto.
- **Frontera prospectada**: de las celdas vecinas (8) a las ocupadas —por cualquier registro, también los sueltos—,
  qué porcentaje está cubierto. Sin esto, volver seis veces a las mismas tres celdas da Q1 = 0 y una completitud del
  100 % aunque nadie haya mirado alrededor (el «caso trampa» de las pruebas).
- **AOO estimada** si hay ≥ 5 ocasiones válidas, completitud ≥ 90 % **y** frontera ≥ 50 %. EOO estimada con eso o con
  ≥ 20 celdas con presencia (de cualquier registro). Si no, inferidas, y B2 no se puede cumplir (tabla 3.1).

Las marcas hechas fuera de una ocasión válida (sueltas, de visitas libres o no ciegas) **siguen contando para EOO y
AOO**; solo quedan fuera de la medida de completitud.

Los umbrales 0,5×–2×, 50 % de frontera y los del recorrido son **convenciones de BioDex**, no valores de la
bibliografía: están en `SUFFICIENCY` y `TRACK` para poder cambiarlos.

### Efecto sobre los datos anteriores

Las visitas anteriores a la 1.10.0 quedan con protocolo «libre» y `blind = false` (migración 017). Hasta que una
especie tenga visitas con protocolo no hay ninguna ocasión válida: su completitud queda sin valor y su **AOO pasa a
inferida**. Una especie que salía amenazada por B2 puede pasar a «Calidad insuficiente» hasta que se muestree con
protocolo. En la demo, *Rothmaleria granatensis* conserva **EN B1ab(iii)+2ab(iii)** porque el seed le añade seis
visitas válidas.

## Pestaña Evaluación UICN

- En «Requisitos mínimos de datos» › Distribución: barra **Esfuerzo estandarizado: ocasiones válidas** (frente a 5),
  la de completitud y la nueva de **frontera prospectada**.
- Bajo las barras, una línea «Chao2.» con las ocasiones usadas (protocolo y mediana), las excluidas por motivo y las
  celdas cubiertas.
- **Suficiencia espacial: diseño y métodos**: el diseño de muestreo, una tarjeta por método (Chao2, de referencia;
  ocupación de una temporada, «próximamente») y el registro de cambios.
- Clases nuevas, sin `style=""` (CSP estricta): `suff-detail`, `suff-methods`, `suff-method`, `suff-design`,
  `suff-history`; en la barra de visita, `vp-0`…`vp-10` para el ancho del progreso.

## Diseño de muestreo y método de referencia

Preparado para ofrecer más de un método (Chao2 hoy; modelos de ocupación de una temporada, MacKenzie et al. 2002, en
una fase posterior). Son dos parámetros de especie por catálogo (`docs/parametros.md`):

| Parámetro | Valores | Para qué |
|---|---|---|
| `sampling_design` | `exploratory` · `repeated` · `mixed` | cada visita se etiqueta como exploratoria o repetición de sitio (`visits.visit_kind`) |
| `sufficiency_method` | `chao2` (la ocupación aún no se admite) | método que usa el motor; sin fijar, Chao2 |

- **Quién**: los fijan owner y custodio (permiso `set_parameter`, el de los demás parámetros), con conexión, en la
  pestaña Parámetros. Editor y lector los ven —al empezar la visita y en la pestaña Evaluación— pero no los cambian.
- **En el campo**: «Empezar visita» › *Tipo de visita*. Con diseño exploratorio o de sitios repetidos el tipo viene
  dado; con mixto o sin definir se elige (por defecto, repetición si se visita un sitio que ya existe).
- **Trazabilidad**: cambiarlos es libre, pero cada cambio queda en `access_log` (`set_parameter`) con fecha, usuario,
  valor anterior, valor nuevo y el motivo (opcional). Se ve en la pestaña Evaluación UICN (con conexión) y en la hoja
  «Evaluación por especie» de la exportación.
- **Métodos lado a lado**: la app calcula los que los datos permiten. Hoy solo Chao2; la tarjeta de ocupación está
  deshabilitada. El modelo de datos ya recoge lo que hará falta: tipo de visita, celdas cubiertas con y sin detección.

## Exportación

Darwin Core, núcleo Event (`docs/dwc.md`): `samplingProtocol` con el protocolo de búsqueda, el objetivo y si fue ciega;
`samplingEffort` con los minutos de búsqueda activa y los metros; `sampleSizeValue`/`sampleSizeUnit`; `eventDate` como
intervalo; y `footprintWKT` con el recorrido (`LINESTRING`, o `MULTILINESTRING` si hay huecos o pausas).

**El recorrido se generaliza como las ubicaciones de las especies sensibles**: pasa por `exportEventLocation()` con
el nivel más restrictivo de la visita y de su sitio. Un recorrido es más fino que cualquier celda de generalización,
así que si el evento se generaliza **no sale**. Tampoco sale nunca por `/api/sync/pull`, y a quien no ve exacta la
visita las celdas cubiertas le llegan solo como recuento. Plinian Core no lleva visitas ni geometría.

## Pruebas

| Qué | Dónde |
|---|---|
| Filtrado del recorrido, distancia, huecos, pausas y celdas (ruido en parado, salto imposible, hueco) | `node shared/track.test.js`, `php backend/bin/test-track.php` (`shared/track-cases.json`) |
| Chao2 con ocasiones válidas, exclusiones, frontera y caso trampa, igual en JS y PHP | `node shared/iucn-engine.test.js`, `php backend/bin/test-sufficiency.php` |
| API, irreversibilidad, pull, permisos, historial y exportación | `bin/test-f11j.php` (Docker) |
| Modo búsqueda en el móvil sin red, por todos los caminos | `frontend/tests/e2e/f11j.spec.mjs` |
