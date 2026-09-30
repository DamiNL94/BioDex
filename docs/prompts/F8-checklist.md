# F8 · Checklist

## F8a · Marcas completas y notas de especie

Automático (todo en verde al cerrar F8a): `docker compose exec php php bin/test-f8a.php` (68 comprobaciones:
marca con todos los términos y marca mínima; `lifeStage`/`sex`/`occurrenceStatus`/`georeferenceProtocol` fuera de
vocabulario → `invalid_vocabulary` y los 10 + 4 valores válidos aceptados; `mature_count > individualCount` y sin
`individualCount` → `invalid_mature_count`; ausencia con individuos o con maduros → `invalid_absence`; punto del mapa
sin precisión → `missing_field`; `iucn_assessment` ignorado y la columna fuera; notas: push, idempotencia sin consumir
`sync_seq`, edición más reciente/antigua (`stale` con la nota del servidor), autoría (`forbidden` a otro editor, 403 al
lector), vacía / 4001 caracteres / fuera del catálogo, pull con autor y fecha, tombstone sin texto y borrado lógico,
edición que deshace el borrado, paginación mixta de marcas y notas con un solo cursor, no miembro y expulsado → 403
`not_member` sin notas, lote de 201 elementos entre tipos → 400). Siguen en verde `test-f4` (31), `test-f5` (58),
`test-f6` (89), `test-f7` (35), `node frontend/tests/utm.test.mjs` y `check-sw.php` (`VERSION v7`, 58 ficheros).

Recorrido en Edge headless por CDP con dos perfiles: A (propietaria, escritorio 1280 px) y B (editor, móvil 390 px).
A crea por API el catálogo con el lince (nivel 2) y la *Artemisia granatensis* (nivel 4) e invita a B. Posición con
`Emulation.setGeolocationOverride` en la Alhambra (37,1761, −3,5881, ±9 m); red cortada con
`Network.emulateNetworkConditions`. 60 comprobaciones, consola sin excepciones de JS. Capturas en `docs/design/f8a-*.png`.

| # | Punto | Resultado | Captura |
|---|---|---|---|
| 1 | Marca completa sin red (2 individuos, 1 maduro, hábitat) | OK. Desde la ficha, la hoja abre con «37.176100, −3.588100 · ±9 m». «+» → 2; «Individuo maduro» pregunta «¿Cuántos maduros?» con 2 y se deja en 1; el estado vital pasa solo a Adulto (valores de fauna); Hembra, hábitat y observaciones. Todos los objetivos ≥ 48 px y «Guardar» fijo abajo. IndexedDB: `individualCount 2`, `mature_count 1`, `adult`, `female`, `present`, `GPS del dispositivo`, `pending` | `f8a-1a-hoja-vacia.png`, `f8a-1b-hoja-completa.png`, `f8a-1c-hoja-completa-abajo.png` |
| 2 | Marca de ausencia | OK. El hábitat recuerda el último de la sesión; «Ausente» oculta individuos, estado vital y sexo y lo explica. Se guarda con `individualCount 0`, sin maduros ni estado vital. Resumen: 1 marca · 1 ausencia · máx. 2 en un día · 1 maduro | `f8a-2a-hoja-ausencia.png`, `f8a-2b-ficha-mis-marcas.png` |
| 3 | Marca en un punto del mapa, 150 m, borde discontinuo | OK. Clic derecho (lo que Leaflet recibe al mantener pulsado) → marcador provisional y «Marcar en este punto» con el punto y su UTM. Sin precisión no guarda y la pide; con 150 m y «Visto con prismáticos…» queda `Punto marcado en el mapa`, ±150 m, en otra posición que el GPS. El marcador mide 24 px con borde discontinuo de 3 px; leyenda «Punto marcado en el mapa» y «Ausencia» | `f8a-3a-hoja-punto-mapa.png`, `f8a-3b-mapa-punto-discontinuo.png`, `f8a-3c-punto-discontinuo-detalle.png` |
| 4 | Nota de especie sin red → en el otro perfil tras sincronizar | OK. B escribe la nota sin red: `pending`, en la cola como `note`. Con red: `synced` con `serverSeq`, en el servidor con autor «Bruno Editor». A sincroniza y la ve en la ficha con autor y fecha, sin Editar/Borrar; A añade una suya, la edita y B la recibe editada. Orden cronológico (la de B, después «Tú») | `f8a-4a-nota-sin-red.png`, `f8a-4b-notas-otro-perfil.png`, `f8a-7b-notas-movil-oscuro.png` |
| 5 | Editar una marca y ver `accepted` al sincronizar | OK. «Editar» abre la hoja con los datos guardados (2, maduro 1, Hembra, hábitat) y la posición fija («no se cambian»). 3 individuos → `pending` con `clientUpdatedAt` más reciente, misma posición y `eventDate`. «Sincronizar ahora»: el push responde `{status: "accepted", serverSeq}` mayor que el anterior, y la marca queda `synced` | `f8a-5a-editar-marca.png`, `f8a-5b-editada-sincronizada.png` |
| 6 | Resumen de la especie correcto | OK. En A (propietaria), con las 3 marcas de B: **2 marcas, 1 ausencia, máx. 5 en un día** (3 + 2 del mismo día), **1 maduro**, primera y última hoy, y «Suma de registros, puede contar varias veces a los mismos individuos». «Marcas del equipo» muestra la ausencia, «3 individuos · 1 maduro · Adulto · Hembra», el hábitat y el punto del mapa | `f8a-6a-resumen-especie.png` |
| 7 | Flora y tema oscuro | OK. «Marcar aquí» desde la LISTA en la Artemisia: Plántula · Vegetativo · En flor · En fruto · Adulto · Indeterminado, sin sexo. Desde el selector del mapa el lince sigue siendo fauna | `f8a-7a-hoja-flora-oscuro.png` |

## Decisiones
- **Tipos de entidad** en `SYNC_ENTITIES` (`Api/sync.php`): cada uno con su lista, borrados y respuesta
  (`occurrences`/`deletes`/`results` como antes; `notes`/`noteDeletes`/`noteResults`). Un móvil de F7 sigue
  funcionando: sus campos no cambian de sitio.
- **Pull con un solo cursor**: una página son los `limit` primeros `server_seq` de todos los tipos, repartidos en
  `items` y `notes`. El móvil guarda `syncv:<catálogo>` y, si viene de antes de F8a, repide desde 0 una vez (su
  cursor pudo pasar por encima de notas que no guardaba).
- **Notas**: subirlas exige `mark` (como marcar); leerlas, `view`. La fecha que se muestra es la de la última
  edición (`clientUpdatedAt`); el orden, cronológico por instante (no por texto: las horas llegan con zonas distintas).
- **Ausencia** = `individualCount 0` (no nulo) y sin datos del individuo; el servidor admite también nulo.
- `mature_count` exige `individualCount`. «Individuo maduro» con 1 individuo guarda `mature_count 1` sin preguntar.
- En plantas «Individuo maduro» también pone `adult` si no hay otro estado elegido (está en su lista).
- `occurrences.iucn_assessment` se **quita** en 009 (nunca se rellenó desde la app); un `lifeStage` libre de antes
  pasaría a `occurrenceRemarks` («Estado vital: …»): no había ninguno.
- Editar una marca no toca la posición ni `eventDate` (la coordenada exacta nunca se modifica); para moverla,
  borrar y volver a marcar. La precisión de un punto del mapa tampoco se edita.
- Respaldo `version: 2` con `notes`; importa también los de la versión 1.

## Encontrado y corregido durante el recorrido
- Las tarjetas de la lista y del selector del mapa no traen la clasificación: la hoja mostraba los estados vitales
  genéricos para la Artemisia. Ahora el reino sale de la ficha guardada en el dispositivo (o de la API).
- Al cambiar de especie en el selector se quedaba el estado vital de la anterior (de fauna en una planta).
- La × de la hoja medía 44 px: 48 en la hoja de marca.
- Borde discontinuo poco visible a 18 px: el marcador del punto del mapa pasa a 24 px con borde de 3 px.
- Tema oscuro: el borde de «−»/«+» (corteza) no se veía; latón.

## Pendiente de mirar a mano
- [ ] **(Damián)** Mantener pulsado de verdad en un móvil Android y en iOS (Safari): Leaflet recibe `contextmenu`
      en Chrome; en iOS depende de `tap`/`tapHold` y puede necesitar un temporizador propio.
- [ ] Con guantes y al sol: tamaño de «−»/«+» y del conmutador Presente/Ausente.
- [ ] Varios dispositivos editando la misma nota a la vez (gana la última escritura, como en las marcas).
