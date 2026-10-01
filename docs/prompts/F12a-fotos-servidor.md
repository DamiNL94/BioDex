# F12a · Fotos: almacén, API, sincronización y exportación (servidor)

Fase de después del congreso (19-11). Requiere F11. Lee `CLAUDE.md`, `docs/db.md`, `docs/sync.md`, `docs/api.md`,
`docs/railway.md`, las firmas de `backend/Lib/{sync,sensitivity,authz,export,audit}.php`, `backend/Api/sync.php` y
`docker/nginx/biodex-app.conf` (solo el bloque `/img/fototeca/` como modelo). No toques `frontend/` (es F12b).

## Qué se construye
El científico de campo puede adjuntar material gráfico a su catálogo, sin red, y el equipo lo recibe al sincronizar:
- **Fotos de una marca** (el individuo observado; `occurrenceID`).
- **Fotos de la especie** en el catálogo, sin marca: rasgo diagnóstico, hábitat, rastro (huellas, excrementos,
  egagrópilas, nidos), confusión con otra especie, otro. Campo `kind`:
  `individual` · `trait` · `habitat` · `sign` · `lookalike` · `other` (etiquetas en castellano en la UI).
- **Portada de la especie en el catálogo**: el owner o un custodio elige una foto del catálogo como portada de la
  tarjeta. Es **del catálogo**, no global: otros catálogos no la ven. Prioridad de la miniatura de la tarjeta:
  portada del catálogo > primera foto de EIDOS > foto de iNaturalist aceptada (F11b) > silueta del grupo (F11b). Es el caso típico de especies sin foto en EIDOS y de las
  especies de usuario (`biodex:`), que nunca la tienen.

## 1. Almacén (volumen persistente)
El disco del contenedor de Railway se borra en cada despliegue: las fotos van a un **volumen de Railway** montado
en `/data`. Variable `MEDIA_DIR` (por defecto `/data/media`). En local, bind mount `./media-data:/data/media` en los
servicios `php` y `nginx` de `docker-compose.yml`; `media-data/` a `.gitignore`, `.dockerignore` y a «No tocar» de
`CLAUDE.md`. `docker/railway/entrypoint.sh`: `mkdir -p` y `chown www-data` del directorio (el volumen llega como
root). Ruta en disco: `MEDIA_DIR/<catalogId>/<mediaID>/{full,thumb}.jpg`, nunca a partir de un nombre del cliente.
`docs/railway.md`: paso «+ Volume → mount path /data» y la limitación de copias de seguridad del volumen.

## 2. Datos (migración `014_media.sql`; la 012 es de F11b y la 013 de F11d)
Tabla `media`: `mediaID` CHAR(36) PK (UUID v4 del cliente), `catalog_id`, `taxonID`, `occurrenceID` NULL (→
`occurrences`, RESTRICT), `user_id` (autor), `kind`, `caption` (≤ 500), `license` (`CC-BY-4.0` · `CC0-1.0` ·
`CC-BY-NC-4.0` · `all-rights-reserved`), `created` (hora de la foto, UTC), `width`, `height`, `bytes`, `sha256`,
`client_updated_at` DATETIME(3), `device_id`, `deleted_at`, `server_seq` único (del mismo `sync_seq`),
`removed_by` NULL (owner que la retira). Índices por (`catalog_id`, `taxonID`) y `occurrenceID`.
`catalog_species.cover_media_id` NULL (→ `media`); cambiarla sube `catalogs.content_seq`.
`users.default_media_license` (por defecto `CC-BY-4.0`), editable en Ajustes. Cuota: `MEDIA_QUOTA_MB` por
catálogo (por defecto 1024) y `MEDIA_MAX_PER_OCCURRENCE` (6). Actualiza `docs/db.md`.

## 3. Subida y procesado (`Api/media.php`, `Lib/media.php`)
- `POST /api/media` multipart: `file` + metadatos (`mediaID`, `catalogId`, `taxonID`, `occurrenceID?`, `kind`,
  `caption?`, `license?`, `created`, `clientUpdatedAt`, `deviceId`). Permiso `mark`. **Idempotente por `mediaID`**:
  repetir la subida con el mismo `sha256` → 200 `unchanged`; con otro contenido → 409. La marca, si la hay, tiene
  que existir y ser del mismo catálogo y especie (el móvil sube fotos **después** del push).
- Validación: ≤ 12 MB, tipo real por `getimagesize()` (JPEG, PNG, WebP; no se fía de la extensión ni del
  `Content-Type`), ≤ 40 MP. Se **recodifica siempre con GD** a JPEG (lado mayor ≤ 2048 px, calidad 82) y miniatura
  (lado mayor 400 px). Recodificar descarta **todo el EXIF, incluida la posición GPS**, y neutraliza ficheros
  políglotas. Orientación EXIF aplicada antes de descartarla (`exif_read_data` si la hay). Se escribe a un temporal
  y se renombra (atómico).
- GD y exif son extensiones nuevas: añádelas (`libjpeg62-turbo-dev libpng-dev libwebp-dev`,
  `docker-php-ext-configure gd --with-jpeg --with-webp`) en `docker/php/Dockerfile` **y** en el `Dockerfile` raíz,
  justificado en el commit. `upload_max_filesize`/`post_max_size` 13M y `client_max_body_size 13m` solo en
  `location = /api/media`.
- Cuota superada → 413 `{code: "quota"}`; demasiadas en una marca → 422 `{code: "too_many"}`.

## 4. Servir las fotos sin saltarse la seguridad
`GET /api/media/{mediaID}/{full|thumb}`: PHP comprueba sesión, rol y visibilidad y responde con
`X-Accel-Redirect: /_media/<catalogId>/<mediaID>/<variante>.jpg` a una location `internal` de nginx (alias a
`MEDIA_DIR`). `Cache-Control: private, max-age=31536000, immutable` (el contenido de un `mediaID` no cambia).
Ni `/data` ni `/_media/` son accesibles desde fuera (prueba con path traversal y con `%2e%2e`).

**Visibilidad** (`mediaVisibleTo()` en `Lib/media.php`, reglas en `shared/sensitivity.json` › `media`): una foto
puede delatar el sitio por el paisaje.
- El autor, owner y custodian ven todas.
- Editor y lector: **no reciben fotos ajenas de especies de nivel 1 y 2** (ni metadatos ni fichero); de nivel 3 y 4, sí.
- Excepción: la **portada** la ve todo el catálogo. Elegir como portada una foto de una especie de nivel 1–2 exige
  confirmación en la UI y deja `set_cover` en `access_log`.
- Cambiar el nivel de una especie ya sube `visibility_epoch`: el pull desde 0 tiene que dejar fuera también las
  fotos que ya no le tocan (prueba explícita).

## 5. Sincronización
Nuevo tipo en `SYNC_ENTITIES`: `media` (push `media` · `mediaDeletes`, respuesta `mediaResults`, pull `media`). El
push **solo** edita `caption`, `kind`, `license` o borra (el fichero llega por `POST /api/media`; un push de un
`mediaID` sin fichero → `rejected` `reason: missing_file`). Mismas reglas: `clientUpdatedAt` decide, solo el autor
edita, tombstones. El pull trae metadatos + `thumbUrl`/`fullUrl` (`/api/media/...`), `recordedBy` y solo lo que
`mediaVisibleTo()` permite. **Retirar** (owner, moderación): `DELETE /api/catalogs/{id}/media/{mediaID}` →
`deleted_at` + `removed_by`, `server_seq` nuevo y `media_removed` en `access_log`; borra los ficheros a los 30 días
(`bin/purge-media.php`, lo lanza el entrypoint). **Portada**: `PUT /api/catalogs/{id}/species/{taxonID}/cover`
`{mediaID|null}`, permiso nuevo `set_cover` (owner, custodian). La tarjeta y el paquete offline llevan `cover`
`{mediaID, thumbUrl, fullUrl, recordedBy, license}`; `thumbnail` ya sale de la portada si la hay.
`docs/sync.md` y `docs/api.md` al día.

## 6. Exportación
- ZIP: carpeta `fotos/<mediaID>.jpg` + hoja «Fotos» (`mediaID`, `occurrenceID`, especie, tipo, autor, licencia,
  fecha, pie, fichero) en xlsx y CSV.
- «Completa»: todas las del catálogo. «Para publicar»: **ninguna** foto de especies de nivel 1–2 (sea quien sea quien
  exporta) y ninguna `all-rights-reserved`.
- DwC-A: **sin** extensión Multimedia por ahora: GBIF exige una URL pública por foto y las nuestras van con sesión.
  Apúntalo en `docs/dwc.md` › «Pendiente para publicar en GBIF» y en `docs/limitaciones.md`.

## 7. Comprobación
`backend/bin/test-f12a.php`: subida idempotente (mismo `sha256`) y 409 con otro contenido; JPEG con GPS en el EXIF
→ el `full.jpg` guardado **no tiene APP1/EXIF** (comprobar bytes); PNG con texto PHP incrustado → recodificado y
sin el texto; fichero que no es imagen → 422; > 12 MB → 413; lector no sube (403), no miembro no ve (403);
editor no recibe foto ajena de nivel 2 ni por pull ni por `GET`, sí la portada; custodio sí; cambio de nivel 3 → 2
la saca del pull de un editor tras la época; retirada por el owner registrada; exportación «para publicar» sin
fotos de nivel 1–2; `/_media/...` desde fuera → 404. Regresión: `test-f5`, `test-f8a`, `test-f10a` en verde.
Resultados en `docs/prompts/F12-checklist.md`. Commits `F12a:`. Resume en 8 líneas.
