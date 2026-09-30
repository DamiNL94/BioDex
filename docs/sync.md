# Sincronización

Código: `backend/Api/sync.php` (rutas) y `backend/Lib/sync.php` (validación, `server_seq`, salida). Pruebas:
`docker compose exec php php bin/test-f5.php`.

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
3. `POST /api/sync/push` en lotes de ≤ 200 (marcas + borrados) hasta vaciar la cola.
4. `GET /api/sync/pull` desde el último `cursor` guardado hasta `hasMore: false`.
5. Si `catalogContentSeq` ≠ el `contentSeq` guardado → volver a bajar `GET /api/catalogs/{id}/offline`.

Push antes que pull: así el pull ya trae los `server_seq` de lo propio y no hay que reconciliar dos veces.

## Push
`{catalogId, deviceId, occurrences:[…], deletes:[{occurrenceID, clientUpdatedAt}]}`. Permiso `mark`
(owner/custodian/editor). Sin rol → 403 `code: not_member`; rol sin permiso → 403 `code: forbidden`.

Marca: `occurrenceID` (UUID v4 en minúsculas), `taxonID`, `eventDate` (ISO 8601 **con zona**; se guarda en
UTC), `decimalLatitude`, `decimalLongitude`, `coordinateUncertaintyInMeters` (entero ≥ 0), `individualCount?`,
`lifeStage?` (≤ 64), `occurrenceRemarks?` (≤ 4000), `mature_count?`, `iucn_assessment?` (objeto),
`clientUpdatedAt` (ISO 8601 con zona; se guarda en UTC con milisegundos). Los campos desconocidos se ignoran.

Respuesta `{results:[{occurrenceID, status, reason?, detail?, serverSeq?, occurrence?}]}`, primero las marcas y
luego los borrados, en el orden recibido.

| status | Cuándo | Qué hace el móvil |
|---|---|---|
| `accepted` | Nueva, o `clientUpdatedAt` más reciente que la del servidor | Marcar como sincronizada (`serverSeq`) |
| `unchanged` | Misma `clientUpdatedAt` (reenvío), o borrado de algo ya borrado | Igual que `accepted` |
| `stale` | `clientUpdatedAt` más antigua: gana el servidor | Sustituir la local por `occurrence` |
| `rejected` | Ver `reason` | Avisar al usuario y conservar la marca local |

`reason`: `invalid_item`, `invalid_uuid`, `invalid_taxon`, `invalid_date`, `future_date` (> 24 h en el
futuro), `missing_field`, `invalid_coordinates`, `invalid_integer`, `invalid_field`, `taxon_not_in_catalog`,
`forbidden` (la marca es de otro autor), `catalog_mismatch` (es de otro catálogo), `not_found` (borrado de algo
que el servidor nunca tuvo: se puede olvidar). `detail` explica el motivo en castellano.

## Reglas de conflicto
- Gana la `clientUpdatedAt` más reciente (última escritura gana). Con relojes muy desviados puede ganar
  la edición equivocada: por eso `eventDate`/`clientUpdatedAt` no admiten más de 24 h en el futuro.
- Una edición más reciente que un borrado lo deshace. A igualdad de hora, el borrado gana a la edición.
- Cada elemento va en su propia transacción y toma `server_seq` de `sync_seq` dentro de ella. El bloqueo de
  `sync_seq` dura hasta el COMMIT, así que los `server_seq` se confirman en orden: un pull nunca se salta uno.
- Si la BD falla a mitad de lote: 500 y el móvil repite el lote entero (lo ya aplicado vuelve `unchanged`).

## Pull
`GET /api/sync/pull?catalogId=&since=0&limit=500` (1–500). Permiso `view`. Devuelve
`{items, cursor, hasMore, catalogContentSeq, role}` con las marcas de `server_seq > since` en orden,
**incluidas las borradas** (`{occurrenceID, taxonID, userId, clientUpdatedAt, serverSeq, deleted: true}`, sin
coordenadas ni datos). El resto lleva los términos DwC, `recordedBy` (nombre del autor), `userId`,
`generalized` y, si es `true`, `dataGeneralizations` e `informationWithheld`. Nivel 1 generalizado: sin
coordenadas (`null`). Guardar `cursor` y seguir mientras `hasMore`.

- Sin rol (expulsado o catálogo borrado) → **403 `{error, code: "not_member"}`**: el móvil borra sus datos de
  ese catálogo.
- Owner/custodian que reciben coordenadas exactas ajenas de especies de nivel < 4: **una** fila `view_exact`
  en `access_log` por petición, con el número de registros en `target`.

## Paquete offline
`GET /api/catalogs/{id}/offline` (permiso `view`): `{catalog, role, contentSeq, species:[ficha]}`. Ficha =
la de `GET /api/species/{id}` sin extracto ni nivel sugerido, más `sensitivity` del catálogo. `contentSeq`
sube al añadir o quitar especies, cambiar niveles o editar el catálogo.

Imágenes: siempre `/img/fototeca/...` (proxy de nginx a la Fototeca del MITECO, con caché de 30 días): mismo
origen, se pueden guardar sin respuestas opacas. Solo esa forma de ruta y sin parámetros; lo demás → 400.
