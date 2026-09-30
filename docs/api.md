# API

Solo JSON bajo `/api` (única excepción: el ZIP de `POST /api/catalogs/{id}/export`, F10a). Errores: `{"error": "mensaje"}` con el código HTTP correspondiente; un 500 no capturado dice siempre un mensaje llano (en `APP_ENV=dev`, el detalle aparte en `detail`, F10b).
Sesión por cookie (`biodex_session` + `biodex_remember`, `HttpOnly`, `SameSite=Lax`).

## Salud
- `GET /api/health` → `{ok, db}`

## Autenticación (`Api/auth.php`)
| Método y ruta | Cuerpo | Respuesta |
|---|---|---|
| `POST /api/auth/register` | `{email, password (≥ 10), name?}` | 201 `{user, verificationSent}` · 400 · 409 email ya registrado. Deja la sesión abierta y envía el correo de verificación |
| `POST /api/auth/login` | `{email, password}` | 200 `{user}` · 401 · 429 tras 5 fallos por email o IP en 15 min (`Retry-After`) |
| `POST /api/auth/logout` | — | 200 `{ok}`. Cierra solo este dispositivo |
| `GET /api/auth/me` | — | 200 `{user}` · 401 |
| `GET /api/auth/verify?token=` | — | 200 `{ok, user}` · 400 enlace no válido, usado o caducado (48 h) |
| `PATCH /api/auth/me` | `{name?, orcid?}` | 200 `{user}` · 400. ORCID `0000-0000-0000-000X` con dígito de control ISO 7064 11,2 (admite la URL de orcid.org); `null` o `""` lo borra |

`user` = `{id, email, name, orcid, emailVerified}`.

## Especies (`Api/species.php`) — requieren sesión
| Método · ruta | Rol mínimo | Qué hace |
|---|---|---|
| `GET /api/species?q=&group=&origin=&scope=&legal=&category=&limit=20&offset=0` | sesión | Buscador. Con `q` (≥ 2): EIDOS en vivo sin acentos + especies de usuario; `group`/`origin`/`scope` (T·M·MT) se filtran en EIDOS. `legal` (`rd139`,`habitats`,`aves`,`rd630`,`ue1143`) y `category` (códigos UICN, lista con comas) sobre la caché, entre los 100 primeros candidatos; como mucho 5 estados legales pedidos por petición, el resto con `detailPending: true`. Sin `q`: solo la caché. → `{total, truncated, items:[tarjeta]}` (ver abajo) |
| `GET /api/species/filters` | sesión | Opciones de los filtros: `shared/eidos-filters.json` (`groups`, `origins`, `scopes`, `legal`, `iucn`, `art17`, cada una `{value, label}`) + `sensitivityLevels [{level, name, approxKm, gridDeg, display, displayText}]` (F7: `gridDeg` para que el mapa dibuje la celda de una marca generalizada) de `shared/sensitivity.json`. `Cache-Control: private, max-age=86400`. El JSON lo genera a mano `bin/build-filters.php` |
| `GET /api/species/{taxonID}` | sesión | Ficha completa; la trae de EIDOS si falta o tiene > 30 días. Añade clasificación, sinónimos, `iucnAssessments`, `legalStatus {national, other, andalucia}`, descripción, `images` (con `attribution`), `suggestedSensitivity {level, reason}`. 404 si no existe |
| `POST /api/species` | sesión | `{scientificName (sin autor), scientificNameAuthorship, taxonRank (Species·Subspecies·Variety·Form), vernacularName?, taxonID?}` → 201 `{species}` con `taxonID biodex:<uuid>` y clasificación del género en EIDOS o, si no, GBIF (`classification.source`). `taxonID` opcional = `biodex:<uuid v4>` propuesto por el móvil (400 si no tiene esa forma): si ya existe con ese nombre → 200 `{species}` (idempotente); con otro nombre → 422. 409 `{error, taxonID}` si el nombre ya está en la caché o en EIDOS |

UICN principal: la vigente más reciente del ámbito más específico: Península > España > Mundial.

**Tarjeta** (resultados, especies de un catálogo): `{taxonID, source, scientificName, canonicalName, scientificNameAuthorship, vernacularName, taxonomicGroup, family, taxonRank, iucnPrimary:{code,scope,year}, iucnAssessments:[{code,scope,year}] (la principal primero), descriptionExcerpt (≤ ~320 car.), thumbnail, eidosUrl}`. Las imágenes (`thumbnail`, `images[].url`) salen siempre como `/img/fototeca/...` (proxy de nginx a la Fototeca del MITECO; las de otro origen se omiten). El nombre se pinta con `canonicalName` en cursiva y el autor en redonda. Foto y descripción llegan con la búsqueda (dos peticiones por lotes a EIDOS) y la ficha las completa.

## Catálogos (`Api/catalogs.php`) — requieren sesión
Rol mínimo según `CATALOG_PERMISSIONS` (`Lib/authz.php`); sin rol o catálogo borrado → 403.

| Método · ruta | Rol mínimo | Qué hace |
|---|---|---|
| `GET /api/catalogs` | sesión | Mis catálogos y los compartidos: `{catalogs:[{id, name, description, ownerId, role, contentSeq, speciesCount, …}]}`. `contentSeq` sube al añadir o quitar especies, cambiar niveles o editar el catálogo |
| `POST /api/catalogs` | sesión | `{name, description?, taxonIDs?: []}` → 201 `{catalog}` con `speciesCount`; me inserta como `owner` y da de alta las especies con su nivel sugerido, todo en una transacción (las fichas se traen de EIDOS antes). Repetidos fuera; ≤ 200 por alta (400). Si alguna no existe: 404 `{error, missing:[…]}` y no se crea nada |
| `GET /api/catalogs/{id}` | viewer | Datos, mi rol, especies (tarjeta) con `sensitivity {level, suggestedLevel, reason, confirmed, setBy, setAt}` (`setBy` = nombre de quien lo fijó a mano, null si es el sugerido) y `summary {total, byTaxonomicGroup, byIucnCategory}` (`none` = sin evaluación) |
| `GET /api/catalogs/{id}/tree` | viewer | Árbol reino › filo › clase › orden › familia › `species`; cada nodo `{rank, id, name, count}` con `id` de EIDOS (o `name:<nombre>` si no lo hay) |
| `GET /api/catalogs/{id}/offline` | viewer | Paquete para el campo: `{catalog, role, contentSeq, species:[ficha de /api/species/{id} sin extracto ni nivel sugerido + sensitivity]}`. Sale de la caché, sin llamar a EIDOS |
| `PATCH /api/catalogs/{id}` | owner | `{name?, description?}` |
| `DELETE /api/catalogs/{id}` | owner | Borrado lógico |
| `POST /api/catalogs/{id}/species` | editor | `{taxonID}` → 201 (200 si ya estaba). Crea `species_sensitivity` con el nivel sugerido; no pisa un nivel ya fijado |
| `DELETE /api/catalogs/{id}/species/{taxonID}` | owner | Borrado lógico |
| `PUT /api/catalogs/{id}/species/{taxonID}/sensitivity` | owner | `{level 1–4, reason}`; `reason` obligatorio (422) si el nivel es mayor (menos protección) que el sugerido. Registra `set_level` en `access_log`. Si el nivel cambia, sube la `visibility_epoch` de los miembros sin acceso a exactas (`docs/sync.md`) |
| `PUT /api/catalogs/{id}/species/{taxonID}/reference-assessment` | owner | F8b. `{assessmentID}` marca como «Evaluación de referencia del catálogo» una evaluación UICN **final** de esa especie en el catálogo (de cualquier autor); `{assessmentID: null}` la quita. Una por especie (la anterior deja de serlo). → `{taxonID, reference, changed}`. Borrador → 422; inexistente, borrada o de otra especie → 404. Las filas que cambian toman `server_seq` nuevo (el pull la lleva a todos). Registra `set_reference` en `access_log` |
| `GET /api/catalogs/{id}/species/{taxonID}/metrics?scope=team·me&cell=2·1` | viewer | F9. EOO y AOO **definitivos**, con las coordenadas exactas (`docs/iucn.md` › «EOO y AOO en BioDex»): `{taxonID, scope, cellSizeKm, eooKm2, eooAdjusted, aooKm2, aooCells, aooRange {min, max}, nRecords, nExcluded, excluded {absent, imprecise, noCoordinates}, warnings, computedAt, cached, sourceSeq, geometry}`. `geometry.access`: `full` (owner, custodio, nivel 4 o `scope=me`: `hull` [[lat, lon]] y `cells` [[i, j]] en EPSG:25830 con `cellSizeKm`), `generalized` (nivel 2–3: `gridDeg` y `generalizedCells` [[lat, lon] esquina SO]) o `none` (nivel 1). Guarda en `species_metrics` y solo recalcula si hay marcas nuevas, editadas o borradas. Casco de exactas ajenas de una especie de nivel < 4 → `view_exact` en `access_log`. `scope`/`cell` no válidos → 400; especie fuera del catálogo → 404 |

## Exportación (`Api/exports.php`, incluido desde `Api/catalogs.php`) — requieren sesión (F10a)
Se genera en el servidor (`Lib/export.php`, mapeo en `docs/dwc.md`). Cuerpo común: `{mode: public·full, dateFrom?, dateTo?
(AAAA-MM-DD, hora peninsular), taxonIDs?: [], observers?: [userId], includeAbsences? (true), license? (CC-BY-4.0·CC0-1.0·
CC-BY-NC-4.0), title?, description?}` (vacíos = los del catálogo). `full` con rol sin `export_full` → 403; opción no válida → 400.

| Método · ruta | Rol mínimo | Qué hace |
|---|---|---|
| `POST /api/catalogs/{id}/export/preview` | viewer (`full`: custodian) | → `{mode, role, canFull, defaults {title, description, license}, licenses [{value, label, url}], available {species [{taxonID, scientificName, canonicalName, vernacularName, count}], observers [{userId, name, count}], dateMin, dateMax}, summary {occurrences, presences, absences, species, observers, generalized, withheld, measurements}}`. Sin coordenadas; no registra nada |
| `POST /api/catalogs/{id}/export` | viewer (`full`: custodian) | → `application/zip` `BioDex_<catálogo>_<completa·publicar>_<AAAA-MM-DD>.zip` con `dwca/` (occurrence.txt, measurementorfact.txt, meta.xml, eml.xml), `hojas/` (XLSX y CSV) y `LEEME.txt`. `public`: cada coordenada por `applyPublicVisibility()` (generalizada según el nivel, sea quien sea quien exporta; sin notas libres en las generalizadas). `full`: exactas y una fila `export_full` en `access_log` por descarga |

## Equipo (`Api/teams.php`, incluido desde `Api/catalogs.php`, e `Api/invites.php`) — requieren sesión
Todo cambio de miembros, roles o invitaciones va a `access_log` en la misma transacción. Orden de roles:
`owner` › `custodian` › `editor` › `viewer`; subir es `grant_role` y bajar, `revoke_role`.

| Método · ruta | Rol mínimo | Qué hace |
|---|---|---|
| `GET /api/catalogs/{id}/members` | viewer | `{role, members:[{userId, name, orcid, role, emailVerified, joinedAt, email?}]}`, el owner primero. `email` solo para quien gestiona el equipo (owner) |
| `PUT /api/catalogs/{id}/members/{userId}` | owner | `{role: custodian·editor·viewer}` → `{member, changed}`. 403 el propio rol o el del owner · 404 no es miembro · **422 custodio sin email verificado**. Ganar o perder custodio sube su `visibility_epoch` |
| `DELETE /api/catalogs/{id}/members/{userId}` | owner | Expulsa (`member_removed`); sus marcas se quedan. 403 a sí mismo o al owner |
| `POST /api/catalogs/{id}/invites` | owner | `{email?, role: editor·viewer, maxUses? (1–20, def. 1), days? (1–30, def. 7)}` → 201 `{invite, code, link, emailSent?}`. `code` = 128 bits en base32 (26 caracteres) y `link` = `APP_URL/#/unirse/<code>`, **solo en esta respuesta** (en la BD, su SHA-256). Con email: `maxUses` = 1 y se envía el enlace (Mailpit en local); 409 si ya es miembro. `invite_created` |
| `GET /api/catalogs/{id}/invites` | owner | Pendientes (ni revocadas, ni caducadas, ni agotadas), sin código: `{invites:[{id, email, role, maxUses, uses, expiresAt, createdAt, createdBy}]}` |
| `DELETE /api/catalogs/{id}/invites/{inviteId}` | owner | Revoca (`invite_revoked`); idempotente |
| `POST /api/catalogs/{id}/leave` | viewer | Salir (`member_left`). 409 si eres el owner: transfiere antes |
| `POST /api/catalogs/{id}/transfer` | owner | `{userId, password}`: la propiedad pasa a un **custodio** (422 si no lo es) y el owner anterior queda como custodio. Contraseña errónea → 403 (no 401: la sesión sigue) y cuenta para el límite del login (5 en 15 min → 429) |
| `GET /api/catalogs/{id}/audit?limit=50&before=` | owner | `{items:[{id, at, userId, userName, action, target, detail}], hasMore, before}`, lo más reciente primero. `detail` legible: nombres en vez de ids, roles en castellano, especie por su nombre. `limit` 1–200; `before` = el de la página anterior |
| `POST /api/invites/preview` | sesión | `{code}` (o el enlace entero) → `{catalog:{id, name, description, ownerName}, role, expiresAt, alreadyMember}` |
| `POST /api/invites/accept` | sesión | `{code}` → `{catalog, role, joined, alreadyMember}`. Invitación con email: solo si coincide con el de la cuenta (403). Caducada, revocada, agotada o catálogo borrado → 410. Ya miembro → 200 sin cambios (no gasta uso). `member_joined` |

Preview y accept: el código va en el cuerpo, nunca en la URL (no queda en los registros de nginx), y se normaliza
(mayúsculas, sin espacios ni guiones). Código que no existe → 404, y cuenta: **10 por usuario y hora → 429**
(`Retry-After: 3600`) en las dos rutas. Orden de comprobación: existe → ya miembro → email → revocada,
caducada, agotada.

## Sincronización (`Api/sync.php`) — requieren sesión
Protocolo, estados y reglas de conflicto: `docs/sync.md`. Sin rol en el catálogo → 403 `{error, code: "not_member"}`;
rol insuficiente → 403 `{error, code: "forbidden"}`.

| Método · ruta | Rol mínimo | Qué hace |
|---|---|---|
| `POST /api/sync/push` | editor | `{catalogId, deviceId, occurrences:[…], deletes:[{occurrenceID, clientUpdatedAt}], notes:[…], noteDeletes:[{noteID, clientUpdatedAt}], assessments:[…], assessmentDeletes:[{assessmentID, clientUpdatedAt}]}`, ≤ 200 elementos entre todos (400). → `{results:[{occurrenceID, status: accepted·unchanged·stale·rejected, reason?, detail?, serverSeq?, occurrence?}], noteResults:[{noteID, …, note?}], assessmentResults:[{assessmentID, …, assessment?}]}`. Validación por elemento (F8a: vocabularios cerrados, maduros ≤ individuos, ausencias sin individuos); idempotente |
| `GET /api/sync/pull?catalogId=&since=0&limit=500` | viewer | Marcas, notas de especie y evaluaciones UICN (F8b) con `server_seq > since` en orden (un solo cursor), incluidas las borradas (`deleted: true`, sin coordenadas, texto ni datos); las marcas pasan por `applyVisibility()` (`generalized`, `dataGeneralizations`). → `{items, notes, assessments, cursor, hasMore, catalogContentSeq, role, visibilityEpoch}` (F6: si cambia, el móvil rehace las marcas ajenas; `docs/sync.md`). Owner/custodian: una fila `view_exact` por petición si recibe exactas ajenas |

## Imágenes (nginx)
`GET /img/fototeca/<ruta>.<jpg·png·webp·gif>` → proxy a `https://www.miteco.gob.es/content/dam/fototeca/` con caché
de 30 días. Otra forma de ruta, `..` o parámetros → 400.
