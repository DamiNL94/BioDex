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

## F8b · Asistente de evaluación UICN

Automático (todo en verde al cerrar F8b): `node shared/iucn-engine.test.js` (74 comprobaciones: 53 casos de tabla con
cada umbral en su borde —EOO 99 → CR y 100 → EN en B1, AOO 9/10 en B2, 5 localidades → EN y 6 → VU, 10/11 en VU,
A1 90/89/49 %, A2 80/79/30/29 %, C 249/250 maduros, C1 con plazo en generaciones, C2a(i) 50/51, D 49/50/999/1000,
D2 AOO 19/20, E 50/49 % y 20 % a 20/21 años—; el caso de la Artemisia → `EN B1ab(iii)+2ab(iii)` y su variante de 1
localidad → `CR B1ab(iii)`; B con un solo subcriterio → no cumple; A3 con (a) rechazado; D2 solo VU; cadenas
combinadas `VU A2bc; D2` y `EN A2c; B1ab(iii)+2ab(iii); C2a(i)`; EOO < AOO; DD, NT por juicio experto; ajuste regional
con y sin justificación y en los extremos; datos no válidos; y que con otros umbrales en el JSON sale otro resultado).
`docker compose exec php php bin/test-f8b.php` (57: push, idempotencia sin consumir `sync_seq`, `stale`, autoría,
lector 403, no miembro, 10 rechazos de validación, pull con autor y detalle, tombstone; referencia: solo owner —custodio,
editor autor, lector y no miembro 403 sin registro—, borrador 422, 404, una por especie con clave única, se conserva si
el autor edita, deja de serlo si la pasa a borrador o la borra, quitarla con `null`, siempre en `access_log` y en el
Registro con el nombre de la especie; lotes mixtos). Siguen en verde `test-f8a` (68), `test-f7` (35), `test-f6` (89),
`test-f5` (58), `test-f4` (31), `node frontend/tests/utm.test.mjs` y `check-sw.php` (`VERSION v8`, 62 ficheros).

Recorrido en Edge headless por CDP con dos perfiles: A (propietaria, escritorio 1280 px) y B (editor, móvil 390 px),
tema claro. A crea por API el catálogo con la *Artemisia granatensis* y el lince y sube 2 marcas de la Artemisia con
maduros (6 + 3 individuos, 4 + 2 maduros, el mismo día). Red cortada con `Network.emulateNetworkConditions`.
47 comprobaciones; consola sin excepciones de JS. Capturas en `docs/design/f8b-*.png`.

| # | Punto | Resultado | Captura |
|---|---|---|---|
| 1 | Asistente sin red | OK. SW `v8` con `/shared/iucn-engine.js`, `/shared/iucn-criteria.json` y el asistente precacheados; IndexedDB v4 con `assessments`. Sin red, la ficha muestra «Evaluación BioDex» después de las evaluaciones de EIDOS y aparte, con «Evaluar (UICN)» | `f8b-1a-ficha-evaluar.png` |
| 2 | Pasos, umbrales y resultado parcial | OK. Aviso fijo arriba; en cada paso la tabla CR/EN/VU de ese criterio. A3 deshabilita la base (a). «Sin datos para este criterio» salta A, C, D y E (el paso queda con filete discontinuo) | `f8b-2a-paso-A.png`, `f8b-2b-paso-B.png` |
| 3 | Artemisia: EOO 80, AOO 12, 3 localidades, b(iii) | OK. Solo con la EOO: «No alcanza umbrales». Con todo: **`EN · B1ab(iii)+2ab(iii)`**; CR «No: (a) 3 localidades > 1 ✗ … 1 de 2 subcriterios en el umbral de CR ✗». Resultado: **EN** en grande, «En peligro», `EN B1ab(iii)+2ab(iii)`, «La determina: B»; NT/LC/DD ocultos | `f8b-2c-paso-B-en-vivo.png`, `f8b-2e-resultado.png` |
| 4 | Datos sugeridos | OK. Paso C: «Suma de maduros: 6» y «Máx. en un día: 9» con el aviso de doble conteo; al usarla rellena 6 y dice «Origen: suma de maduros de las marcas del catálogo»; se puede cambiar. Paso B: «Calcular desde las marcas · Disponible en F9» | `f8b-2d-paso-C-sugerencia.png` |
| 5 | Ajuste regional | OK. Rebajar sin justificación: no se aplica y avisa (sigue EN). Con justificación: «Antes del ajuste regional: EN → después: VU». Sin ajuste vuelve a EN | `f8b-2f-ajuste-regional.png` |
| 6 | Guardar final sin red | OK. Ficha: «Tú · Final · Pendiente». IndexedDB: `assessmentStatus final`, `status pending`, `category EN`, `inputs.B` {eoo 80, locations 3}, `inputs.C.skip`; en la cola como `assessment` | `f8b-2g-ficha-pendiente.png` |
| 7 | Sincronizar y verla en el otro perfil | OK. Con red, B la sube sola: `synced` con `serverSeq`, cola vacía. A la recibe en el pull: «Bruno Editor · EN B1ab(iii)+2ab(iii) · Final», sin Editar ni Borrar; el detalle trae el resultado por criterio y la justificación | `f8b-3a-owner-ve-evaluacion.png` |
| 8 | Referencia con la owner | OK. «Marcar como referencia» → «Evaluación de referencia del catálogo · Marcada por Ana Propietaria», con «Quitar referencia». Servidor: `is_reference 1`, `reference_set_by` A; `access_log` `set_reference` «9918: EN B1ab(iii)+2ab(iii) [id] de user:B (antes: ninguna)». B, tras «Sincronizar ahora», la ve como referencia y sin los botones del owner | `f8b-3b-referencia-owner.png`, `f8b-4a-referencia-en-B.png`, `f8b-4b-referencia-oscuro.png` |

## Decisiones (F8b)
- **Umbrales servidos por nginx** desde `shared/` (`location` solo para `iucn-engine.js` e `iucn-criteria.json`; el
  resto de `/shared/` → 404), no copiados a `frontend/`: una sola fuente para el navegador y para Node. `check-sw.php`
  resuelve las entradas `shared/…` de `SHELL_ASSETS` y exige esas dos.
- `iucn-criteria.json` gana los umbrales que solo estaban en texto (C1, C2a(ii), D2, E, periodo de A, bases vetadas en
  A3, categorías de salida y ajuste regional); los campos de antes no cambian.
- La cadena lista los criterios cumplidos en la categoría propuesta; con ajuste regional, la de antes del ajuste.
- En el dispositivo borrador/final es `assessmentStatus` (`status` ya es el estado de sincronización que usa el código
  común de la cola); en la API es `status`.
- Referencia = columnas en la propia fila (`is_reference` + `reference_key` generada y única). Cambiarla sube el
  `server_seq` de las filas afectadas, así que viaja por el pull sin tocar el paquete offline. Solo finales; si el autor
  la borra o la pasa a borrador deja de serlo, y queda registrado. Si el autor la edita (sigue final), sigue siéndolo.
- El servidor no recalcula (el motor es JS): valida forma, tamaños, vocabularios, final con categoría y ajuste con
  justificación.
- Una sola justificación por evaluación (sirve también para el ajuste regional). Pull `syncv` 3; respaldo `version: 3`.

## Encontrado y corregido durante el recorrido
- En el resultado en vivo la notación (`Sí · B1ab(iii)+2ab(iii)`) se salía de su columna y pisaba los motivos: ahora
  va encima de ellos. «disminución continua(iii)» sin espacio en los motivos del motor.
- «Guardar como final» se partía en dos líneas a 390 px junto a «Guardar borrador»: ocupa su propia fila en el móvil.
- El filete de color de la categoría no salía en la tarjeta ni en el resultado (el `border-left` propio pisaba el de
  `.iucn-edge-*`); anillo de foco sobre el título del paso al cambiar de paso (el foco es para lectores de pantalla).
- Del propio recorrido, no de la app: la sonda de IndexedDB abría la base sin versión antes que la app (una base v1
  vacía); y la emulación «sin red» de CDP se cae al cerrar la sesión, así que la evaluación de la primera pasada se
  subió sola entre scripts (se borró la fila y se repitió el paso). En la segunda pasada, 1 FAIL del script por
  mayúsculas («No» frente a /no/): el texto capturado era el esperado.

## Pendiente de mirar a mano
- [ ] **(Damián)** Revisar con alguien que evalúe para la Lista Roja la redacción de los pasos, los textos de las bases
      y la notación de las cadenas combinadas contra ejemplos oficiales.
- [ ] Actualizar en un móvil real de v7 a v8 con datos (IndexedDB 3 → 4) y evaluar sin red de verdad.
- [ ] F9: rellenar EOO y AOO desde las marcas del catálogo en el paso B (hueco preparado).
- [ ] ¿Avisar si una evaluación de referencia se edita después de marcarla? Ahora sigue siendo la referencia.
