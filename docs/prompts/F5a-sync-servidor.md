# F5a · Sincronización: servidor

Requiere F4 y F3b hechas. Lee `CLAUDE.md`, `docs/api.md`, `docs/db.md`, `backend/migrations/004_occurrences.sql`
y las firmas de `backend/Lib/sensitivity.php`, `Lib/authz.php` y `Lib/species.php`. No toques `frontend/`
salvo la CSP. No leas NaturDex.

## Principios (no negociables)
- **Nunca se pierde una marca.** El servidor acepta o rechaza cada una con un motivo; nunca descarta en silencio.
- **Idempotente:** reenviar el mismo lote da el mismo resultado y no crea duplicados ni consume `server_seq`.
- **Seguridad:** toda coordenada que sale del servidor pasa por `applyVisibility()`. Sin excepciones.
- **La marca solo la edita o borra su autor.**

## 1. Migración `007_sync.sql`
- `occurrences.client_updated_at DATETIME(3) NOT NULL` (hora de la última edición en el dispositivo, UTC)
  y `occurrences.device_id CHAR(36) NULL`.
- `catalogs.content_seq BIGINT` (sube al añadir/quitar especies o cambiar niveles: el móvil sabe si
  tiene que volver a descargar el catálogo).

## 2. `POST /api/sync/push`
Cuerpo: `{catalogId, deviceId, occurrences: [...], deletes: [{occurrenceID, clientUpdatedAt}]}`, máx. 200 elementos.
Cada observación con términos DwC exactos: `occurrenceID` (UUID v4), `taxonID`, `eventDate` (ISO 8601 con
zona; se guarda en UTC), `decimalLatitude`, `decimalLongitude`, `coordinateUncertaintyInMeters`,
`individualCount?`, `lifeStage?`, `occurrenceRemarks?` (≤ 4000), `mature_count?`, `iucn_assessment?`, `clientUpdatedAt`.
- Permiso `mark` en el catálogo (403 si no). El taxón debe estar en el catálogo.
- Validación por elemento (no por lote): UUID válido, coordenadas en rango, `eventDate` no más de 24 h
  en el futuro, enteros no negativos. Un elemento inválido se rechaza con motivo; los demás siguen.
- Existe y es de otro autor → `rejected: forbidden`. Existe, mismo autor y `clientUpdatedAt` igual →
  `unchanged` (sin tocar `server_seq`). Más reciente → actualiza. Más antiguo → `stale` (gana lo del servidor).
- Cada inserción o cambio toma `server_seq` de `sync_seq` **en la misma transacción**.
- Respuesta: `{results: [{occurrenceID, status: accepted|unchanged|stale|rejected, reason?, serverSeq?}]}`.

## 3. `GET /api/sync/pull?catalogId=&since=&limit=500`
- Permiso `view`. Devuelve observaciones con `server_seq > since` en orden, **incluidas las borradas**
  (`deleted: true`, sin coordenadas), cada una pasada por `applyVisibility()` con el nivel de su especie
  en ese catálogo, y con `generalized: true` + `dataGeneralizations` cuando corresponda.
- Respuesta: `{items, cursor, hasMore, catalogContentSeq, role}`.
- Si el usuario ya no es miembro → **403 `{error, code: "not_member"}`** (el móvil borrará sus datos de ese catálogo).
- Si owner o custodian reciben coordenadas exactas ajenas: **una** fila `view_exact` en `access_log` por
  petición, con el número de registros en `target`.

## 4. `GET /api/catalogs/{id}/offline`
Paquete para llevar al campo: catálogo, rol, `contentSeq`, y por especie la ficha resumida que hace falta
sin red (nombres, `sciName`, clasificación, UICN de todos los ámbitos, estado legal, descripción completa,
nivel de sensibilidad, URLs de imagen **del proxy** — ver 6). Permiso `view`.

## 5. Especies creadas sin conexión
`POST /api/species` acepta un `taxonID` propuesto por el cliente con forma `biodex:<uuid v4>`.
- Si ya existe con ese mismo id → 200 (idempotente).
- Si el nombre ya existe (EIDOS o caché) → 409 `{taxonID}` como ahora: el móvil reasignará sus marcas a ese id.

## 6. Proxy de imágenes (necesario para guardarlas en el móvil)
Las fotos de la Fototeca vienen de otro origen sin CORS: el navegador las guarda como respuestas
«opacas» que ocupan hasta ~7 MB de cuota cada una. Se sirven desde nuestro origen:
- nginx: `location /img/fototeca/` → `https://www.miteco.gob.es/content/dam/fototeca/` con `proxy_cache`
  (como el bloque de teselas previsto para F7). **Solo ese prefijo**, sin parámetros de URL libres.
- Las URLs de imagen que devuelve la API pasan a `/img/fototeca/...`. CSP: `img-src 'self' data:`.

## 7. Pruebas `backend/bin/test-f5.php`
- Push de 3 marcas → `accepted`; el mismo lote otra vez → `unchanged`, mismo `server_seq`, 3 filas.
- Editar una con `clientUpdatedAt` más nuevo → actualiza y sube `server_seq`; con uno viejo → `stale`.
- Usuario B (editor) intenta editar una de A → `forbidden`. Un viewer hace push → 403.
- Lote con 1 marca inválida (lat 95) y 2 buenas → 2 `accepted`, 1 `rejected` con motivo.
- Pull paginado con `limit=2` recorre todo sin huecos ni repetidos; un borrado llega como `deleted`.
- Especie de nivel 2: A (autor) y el owner ven exacta; B (editor) la ve en el centro de la celda de 0,1°
  con `generalized: true`. Nivel 4: exacta para todos.
- B expulsado (borra su fila de `catalog_members`) → pull 403 `not_member`.
- Especie `biodex:<uuid>` enviada dos veces → 201 y luego 200; «Lynx pardinus» con id propio → 409 con el id de EIDOS.
- Imagen por `/img/fototeca/...` → 200 `image/jpeg`; `/img/fototeca/../` o parámetros → 400/404.

## Documentación
`docs/sync.md` (≤ 80 líneas): protocolo, estados de resultado, orden push → pull, reglas de conflicto.
`docs/api.md` al día. Commits `F5a:`. Resume en 8 líneas.
