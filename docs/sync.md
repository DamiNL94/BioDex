# Sincronización

Código: `backend/Api/sync.php` (rutas) y `backend/Lib/sync.php` (validación, `server_seq`, salida). Pruebas:
`docker compose exec php php bin/test-f5.php`, `bin/test-f8a.php` (marcas completas y notas) y `bin/test-f8b.php`
(evaluaciones UICN y referencia).

## Tipos de entidad (F8a, F8b)
Push y pull llevan varios tipos, todos con las **mismas reglas**: id UUID v4 del cliente, `clientUpdatedAt` decide,
solo el autor edita o borra, `server_seq` del contador común tomado en la misma transacción y tombstones.
Se declaran en `SYNC_ENTITIES` (`Api/sync.php`); añadir uno es añadir una entrada (F8b añadió `assessments`).

| Tipo | Tabla | Id | Push: lista · borrados | Respuesta | Pull | Coordenadas |
|---|---|---|---|---|---|---|
| marcas | `occurrences` | `occurrenceID` | `occurrences` · `deletes` | `results` | `items` | sí: `applyVisibility()` |
| notas de especie | `species_notes` | `noteID` | `notes` · `noteDeletes` | `noteResults` | `notes` | no |
| evaluaciones UICN (F8b) | `species_assessments` | `assessmentID` | `assessments` · `assessmentDeletes` | `assessmentResults` | `assessments` | no |

Las notas y las evaluaciones no llevan coordenadas y no pasan por `applyVisibility()`, pero **solo las recibe quien
tiene permiso `view`** en el catálogo (el pull lo exige; un no miembro recibe 403 `not_member` y nada más). Subirlas
exige `mark`, como las marcas: el lector solo las lee.

## Principios
- **Nunca se pierde una marca**: cada elemento de un push vuelve con su estado; nada se descarta en silencio.
- **Idempotente**: repetir un lote da el mismo resultado, no duplica filas ni consume `server_seq`.
- **Seguridad**: toda coordenada que sale pasa por `applyVisibility()` (autor, owner y custodian ven la
  exacta; el resto, generalizada según `species_sensitivity.level`). La exacta en BD nunca se toca.
- **Autoría**: una marca solo la edita o borra su autor, sea cual sea su rol.

## Orden en el móvil
1. `POST /api/species` con las especies creadas sin conexión (`taxonID` = `biodex:<uuid v4>` propio). 201 o
   200 si ya existía con ese id; **409 `{taxonID}`** si el nombre ya existe: reasignar las marcas a ese id.
2. Si hace falta, `POST /api/catalogs/{id}/species` (la especie tiene que estar en el catálogo).
3. `POST /api/sync/push` en lotes de ≤ 200 (marcas, notas y sus borrados) hasta vaciar la cola.
4. `GET /api/sync/pull` desde el último `cursor` guardado hasta `hasMore: false`.
5. Si `catalogContentSeq` ≠ el `contentSeq` guardado → volver a bajar `GET /api/catalogs/{id}/offline`.

Push antes que pull: así el pull ya trae los `server_seq` de lo propio y no hay que reconciliar dos veces.

## Push
`{catalogId, deviceId, occurrences:[…], deletes:[{occurrenceID, clientUpdatedAt}], notes:[…],
noteDeletes:[{noteID, clientUpdatedAt}]}` (todas las listas opcionales; ≤ 200 elementos entre todas). Permiso
`mark` (owner/custodian/editor). Sin rol → 403 `code: not_member`; rol sin permiso → 403 `code: forbidden`.

Marca: `occurrenceID` (UUID v4 en minúsculas), `taxonID`, `eventDate` (ISO 8601 **con zona**; se guarda en
UTC), `decimalLatitude`, `decimalLongitude`, `coordinateUncertaintyInMeters` (entero ≥ 0), `clientUpdatedAt`
(ISO 8601 con zona; se guarda en UTC con milisegundos) y, opcionales (F8a, nombres DwC exactos):

| Término | Valores |
|---|---|
| `occurrenceStatus` | `present` (por defecto) · `absent` — una ausencia en un punto prospectado también es dato |
| `individualCount`, `mature_count` | enteros ≥ 0; `mature_count ≤ individualCount` y exige `individualCount` |
| `lifeStage` | `adult` `juvenile` `subadult` `larva` `egg` `seedling` `flowering` `fruiting` `vegetative` `undetermined` |
| `sex` | `male` `female` `hermaphrodite` `undetermined` (vocabulario de GBIF) |
| `habitat` (≤ 500), `occurrenceRemarks` (≤ 4000), `georeferenceRemarks` (≤ 255) | texto |
| `georeferenceProtocol` | `GPS del dispositivo` (por defecto) · `Punto marcado en el mapa` (exige precisión ≥ 1 m) |

Una **ausencia** lleva `individualCount` 0 o nulo y ningún `mature_count`. Los campos desconocidos se ignoran
(también el antiguo `iucn_assessment`: la evaluación UICN es de la especie, F8b).

Nota: `noteID` (UUID v4), `taxonID` (del catálogo), `text` (1–4000 caracteres, no vacío), `clientUpdatedAt`.

Evaluación (F8b): `assessmentID` (UUID v4), `taxonID`, `inputs` y `result` (objetos de `shared/iucn-engine.js`, ≤ 32 KB
cada uno; el servidor no recalcula), `status` `draft` · `final` (una final exige `result.category`),
`regionalAdjustment` `down` · `up` (exige `justification`), `justification` (≤ 4000), `clientUpdatedAt`. En el
dispositivo borrador/final va en `assessmentStatus`, porque `status` es allí el estado de sincronización.
**Referencia del owner**: `PUT /api/catalogs/{id}/species/{taxonID}/reference-assessment` (`docs/api.md`) pone
`isReference` en la fila con `server_seq` nuevo, así que llega a todos por el pull; el push del autor no toca esas
columnas. Si el autor la borra o la pasa a borrador, deja de ser la referencia (queda en `access_log`).

Respuesta `{results:[{occurrenceID, status, reason?, detail?, serverSeq?, occurrence?}], noteResults:[{noteID,
status, reason?, detail?, serverSeq?, note?}], assessmentResults:[{assessmentID, …, assessment?}]}`: por tipo, primero las altas y ediciones y luego los borrados, en
el orden recibido. En un `stale`, la versión del servidor va en `occurrence`, `note` o `assessment`.

| status | Cuándo | Qué hace el móvil |
|---|---|---|
| `accepted` | Nueva, o `clientUpdatedAt` más reciente que la del servidor | Marcar como sincronizada (`serverSeq`) |
| `unchanged` | Misma `clientUpdatedAt` (reenvío), o borrado de algo ya borrado | Igual que `accepted` |
| `stale` | `clientUpdatedAt` más antigua: gana el servidor | Sustituir la local por `occurrence` |
| `rejected` | Ver `reason` | Avisar al usuario y conservar la marca local |

`reason`: `invalid_item`, `invalid_uuid`, `invalid_taxon`, `invalid_date`, `future_date` (> 24 h en el
futuro), `missing_field`, `invalid_coordinates`, `invalid_integer`, `invalid_field`, `invalid_vocabulary` (valor
fuera de su lista cerrada), `invalid_mature_count` (maduros > individuos, o sin individuos), `invalid_absence`
(ausencia con individuos o maduros), `taxon_not_in_catalog`, `forbidden` (es de otro autor), `catalog_mismatch`
(es de otro catálogo), `not_found` (borrado de algo que el servidor nunca tuvo: se puede olvidar). `detail`
explica el motivo en castellano.

## Reglas de conflicto
- Gana la `clientUpdatedAt` más reciente (última escritura gana). Con relojes muy desviados puede ganar
  la edición equivocada: por eso `eventDate`/`clientUpdatedAt` no admiten más de 24 h en el futuro.
- Una edición más reciente que un borrado lo deshace. A igualdad de hora, el borrado gana a la edición.
- Cada elemento va en su propia transacción y toma `server_seq` de `sync_seq` dentro de ella. El bloqueo de
  `sync_seq` dura hasta el COMMIT, así que los `server_seq` se confirman en orden: un pull nunca se salta uno.
- Si la BD falla a mitad de lote: 500 y el móvil repite el lote entero (lo ya aplicado vuelve `unchanged`).

## Pull
`GET /api/sync/pull?catalogId=&since=0&limit=500` (1–500). Permiso `view`. Devuelve
`{items, notes, assessments, cursor, hasMore, catalogContentSeq, role, visibilityEpoch}`. Una página son los `limit`
primeros `server_seq > since` **de todos los tipos** (el contador es común), repartidos en `items` (marcas), `notes` y
`assessments`, cada lista en orden; `cursor` es el último `server_seq` de la página y vale para todos. **Incluidas las borradas**
(`{occurrenceID | noteID, taxonID, userId, clientUpdatedAt, serverSeq, deleted: true}`, sin coordenadas, texto ni
datos). Una marca lleva los términos DwC (los de F8a incluidos), `recordedBy` (nombre del autor), `userId`,
`generalized` y, si es `true`, `dataGeneralizations` e `informationWithheld`; nivel 1 generalizado: sin
coordenadas (`null`). Una nota: `text`, `recordedBy`, `userId`, `clientUpdatedAt`. Guardar `cursor` y seguir
mientras `hasMore`.

Móvil de antes de F8a: su cursor pudo pasar por encima de notas que no guardaba. `sync.js` guarda la versión del
pull aplicada por catálogo (`meta syncv:<id>`: 2 en F8a, 3 en F8b con `assessments`) y, con una menor, vuelve a pedir
desde 0 una vez.

- Sin rol (expulsado o catálogo borrado) → **403 `{error, code: "not_member"}`**: el móvil borra sus datos de
  ese catálogo.
- Owner/custodian que reciben coordenadas exactas ajenas de especies de nivel < 4: **una** fila `view_exact`
  en `access_log` por petición, con el número de registros en `target`.

## Revocar confianza también en el móvil (F6)
El pull solo manda lo que cambió desde el cursor: si a alguien le quitan el custodio, las coordenadas exactas que
ya bajó se quedarían en su dispositivo. Para eso está `catalog_members.visibility_epoch` (`Lib/teams.php`):

- **Sube** cuando cambia lo que ese miembro puede ver de las marcas **ajenas**: gana o pierde custodio (acceso a
  exactas, `exactAccess.roles` de `shared/sensitivity.json`) o el owner cambia el nivel de una especie y él no
  es de confianza (sube la de todos los miembros sin exactas). Subir el nivel le quita detalle; bajarlo se lo da,
  y sin la época tampoco le llegaría. Editor ↔ lector y la transferencia de propiedad no la tocan.
- El pull devuelve `visibilityEpoch`, leída junto con el rol en la misma consulta: la página corresponde a ella.
- **Móvil** (`sync.js`, `pullAll`): si no coincide con la guardada en `meta` (`epoch:<catalogId>`; también si no
  hay ninguna, p. ej. datos de antes de F6), borra de IndexedDB **todas las marcas ajenas** de ese catálogo,
  pone el cursor a 0, guarda la época nueva y vuelve a pedir desde 0 (si la página ya venía desde 0, la aplica
  tal cual). Las propias no se tocan nunca, tampoco las de la cola. Si la época cambia más de 3 veces en una
  misma pasada, se deja para el reintento.
- Comprobado en el recorrido de F6 leyendo la base, no la pantalla: tras quitar el custodio no queda ninguna
  marca ajena exacta en `biodex-<userId>` (`docs/prompts/F6-checklist.md`).

Expulsar, salir o borrar el catálogo no usa la época: el pull da 403 `not_member` y el catálogo sale entero.

## Paquete offline
`GET /api/catalogs/{id}/offline` (permiso `view`): `{catalog, role, contentSeq, species:[ficha]}`. Ficha =
la de `GET /api/species/{id}` sin extracto ni nivel sugerido, más `sensitivity` del catálogo. `contentSeq`
sube al añadir o quitar especies, cambiar niveles o editar el catálogo.

Imágenes: siempre `/img/fototeca/...` (proxy de nginx a la Fototeca del MITECO, con caché de 30 días): mismo
origen, se pueden guardar sin respuestas opacas. Solo esa forma de ruta y sin parámetros; lo demás → 400.

## En el dispositivo (F5b)
Código: `frontend/js/db.js` (IndexedDB), `offline.js` (descarga y lecturas locales), `marks.js` (marcas,
cola, respaldo) y `sync.js` (motor).

- **Una base por usuario**: `biodex-<userId>` con `catalogs`, `species`, `catalogSpecies`, `occurrences`,
  `outbox` y `meta` (cursor y época por catálogo, `deviceId`, última sincronización y, F6, lo último de
  Equipo, Invitaciones, Registro y Niveles para verlos sin red: `team:`, `invites:`, `audit:`, `levels:`). Cerrar sesión la borra, junto
  con las cachés `biodex-img-<catalogId>`, pero **solo** si no hay nada en la cola o el usuario lo decide
  (diálogo «Tienes n marcas sin sincronizar» → sincronizar · exportar respaldo · cancelar).
- **Descargar un catálogo** = paquete offline + pull completo + la primera foto de cada especie en Cache
  Storage (el SW la sirve cache-first). El activo se descarga solo al activarlo con red.
- **Cola** (`outbox`): una entrada por marca con la última intención (`occurrence` o `delete`) y una por
  especie creada sin red (`species`). Las rechazadas se quedan con su motivo hasta que el usuario las
  reintenta o descarta (Ajustes › Sincronización).
- **Motor**: al entrar, al volver «En línea», cada 5 min, tras guardar y con «Sincronizar ahora». Candado
  en memoria + Web Locks (`biodex-sync`) entre pestañas. Red caída → reintento a 30 s, 1, 2 y 5 min.
  401 → se conserva la cola y la pantalla de acceso dice «Inicia sesión para sincronizar (n pendientes)».
- **Pull**: lo que tenga un cambio local en cola no se pisa (lo decide el push). `visibilityEpoch` distinta →
  fuera las marcas ajenas y desde 0 (arriba). 403 `not_member` → el catálogo sale del dispositivo; si hay marcas propias sin subir queda como `revoked` y Ajustes ofrece
  exportarlas o descartarlas.
- **Respaldo**: JSON `{format: "biodex-backup", version: 1, userId, catalogs, species, occurrences}`; solo lo
  importa la misma cuenta y es idempotente por `occurrenceID`.
- **Zonas del mapa (F7)**: la base sube a la versión 2 con el almacén `zones`; sus teselas van a las cachés
  `biodex-tiles-<zoneId>`, que cerrar sesión también borra. Detalle en `docs/mapa.md`.
- **Notas (F8a)**: versión 3 con el almacén `notes` (índices `catalogId`, `taxonID`). Cola `note` / `noteDelete`,
  una entrada por nota como las marcas; el respaldo pasa a `version: 2` con `notes` (importa también los de la 1).
  Expulsión o catálogo borrado: sus notas salen del dispositivo con el resto.
- **Evaluaciones UICN (F8b)**: versión 4 con el almacén `assessments` (índices `catalogId`, `taxonID`). Cola
  `assessment` / `assessmentDelete`; el respaldo pasa a `version: 3` con `assessments` (importa también 1 y 2).
  Expulsión o catálogo borrado: salen con el resto. El asistente guarda sin red (`docs/iucn.md`).
- **Editar una marca** (F8a, «Mis marcas»): cambia los datos, nunca la posición ni `eventDate`; sube
  `clientUpdatedAt` y el push la acepta por ser la más reciente.
