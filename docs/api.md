# API

Solo JSON bajo `/api`. Errores: `{"error": "mensaje"}` con el código HTTP correspondiente.
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

`user` = `{id, email, name, orcid, emailVerified}`.

## Especies (`Api/species.php`) — requieren sesión
| Método · ruta | Rol mínimo | Qué hace |
|---|---|---|
| `GET /api/species?q=&group=&origin=&scope=&legal=&category=&limit=20&offset=0` | sesión | Buscador. Con `q` (≥ 2): EIDOS en vivo sin acentos + especies de usuario; `group`/`origin`/`scope` (T·M·MT) se filtran en EIDOS. `legal` (`rd139`,`habitats`,`aves`,`rd630`,`ue1143`) y `category` (códigos UICN, lista con comas) sobre la caché, entre los 100 primeros candidatos; como mucho 5 estados legales pedidos por petición, el resto con `detailPending: true`. Sin `q`: solo la caché. → `{total, truncated, items:[{taxonID, source, scientificName, vernacularName, taxonomicGroup, taxonRank, iucnPrimary:{code,scope,year}, thumbnail, eidosUrl}]}` |
| `GET /api/species/{taxonID}` | sesión | Ficha completa; la trae de EIDOS si falta o tiene > 30 días. Añade clasificación, sinónimos, `iucnAssessments`, `legalStatus {national, other, andalucia}`, descripción, `images` (con `attribution`), `suggestedSensitivity {level, reason}`. 404 si no existe |
| `POST /api/species` | sesión | `{scientificName (sin autor), scientificNameAuthorship, taxonRank (Species·Subspecies·Variety·Form), vernacularName?}` → 201 `{species}` con `taxonID biodex:<uuid>` y clasificación del género en EIDOS o, si no, GBIF (`classification.source`). 409 `{error, taxonID}` si ya está en la caché o en EIDOS |

UICN principal: la vigente más reciente del ámbito más específico: Península > España > Mundial.

## Catálogos (`Api/catalogs.php`) — requieren sesión
Rol mínimo según `CATALOG_PERMISSIONS` (`Lib/authz.php`); sin rol o catálogo borrado → 403.

| Método · ruta | Rol mínimo | Qué hace |
|---|---|---|
| `GET /api/catalogs` | sesión | Mis catálogos y los compartidos: `{catalogs:[{id, name, description, ownerId, role, speciesCount, …}]}` |
| `POST /api/catalogs` | sesión | `{name, description?}` → 201; me inserta como `owner` (transacción) |
| `GET /api/catalogs/{id}` | viewer | Datos, mi rol, especies con `sensitivity {level, suggestedLevel, reason, confirmed}` y `summary {total, byTaxonomicGroup, byIucnCategory}` (`none` = sin evaluación) |
| `GET /api/catalogs/{id}/tree` | viewer | Árbol reino › filo › clase › orden › familia › `species`; cada nodo `{rank, id, name, count}` con `id` de EIDOS (o `name:<nombre>` si no lo hay) |
| `PATCH /api/catalogs/{id}` | owner | `{name?, description?}` |
| `DELETE /api/catalogs/{id}` | owner | Borrado lógico |
| `POST /api/catalogs/{id}/species` | editor | `{taxonID}` → 201 (200 si ya estaba). Crea `species_sensitivity` con el nivel sugerido; no pisa un nivel ya fijado |
| `DELETE /api/catalogs/{id}/species/{taxonID}` | owner | Borrado lógico |
| `PUT /api/catalogs/{id}/species/{taxonID}/sensitivity` | owner | `{level 1–4, reason}`; `reason` obligatorio (422) si el nivel es mayor (menos protección) que el sugerido. Registra `set_level` en `access_log` |
