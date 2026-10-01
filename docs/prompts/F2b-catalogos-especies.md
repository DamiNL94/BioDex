# F2b · Endpoints de especies y catálogos

Requiere F2a hecha. Lee `CLAUDE.md`, `docs/db.md`, `docs/eidos.md` y la cabecera (firmas) de
`backend/Lib/eidos.php`, `Lib/authz.php` y `Lib/sensitivity.php`. No leas NaturDex. No toques `frontend/`.

## Decisiones ya tomadas
- **Categoría UICN principal:** la vigente más reciente del ámbito más específico disponible:
  **Península > España > Mundial**. Se guardan todas en `iucn_assessments`; las tarjetas mostrarán todas.
- **Fotos:** se muestran citando «Fototeca MITECO (vía EIDOS)»; guarda la atribución en `images`.
- **Acentos:** la caché usa colación `_ai_`. Para la búsqueda en vivo en EIDOS, prueba a sustituir las
  vocales de la consulta por el comodín `_` de LIKE (p. ej. `aguila` → `_g__l_`) y filtra luego en PHP
  comparando sin acentos. Si no funciona, busca en la caché y en EIDOS con la consulta tal cual y une resultados.
  Documenta cuál quedó en `docs/eidos.md`.

## 1. Especies — `Api/species.php`
- `GET /api/species?q=&group=&origin=&scope=&legal=&category=&limit=20&offset=0`
  - Con `q`: `eidosSearch` en vivo (grupo, origen y ámbito se filtran en EIDOS) + upsert ligero en `species`.
  - `legal` (`rd139`, `habitats`, `aves`, `rd630`, `ue1143`) y `category` (códigos) se filtran sobre la caché:
    se aplican a los resultados ya cacheados con detalle; si a un resultado le falta detalle, se pide (máx. 5 por
    petición, el resto se marca `detailPending: true`).
  - Respuesta: `{total, items:[{taxonID, scientificName, vernacularName, taxonomicGroup, taxonRank,
    iucnPrimary:{code,scope,year}, thumbnail, eidosUrl}]}`. Requiere sesión.
- `GET /api/species/{taxonID}` — ficha completa. Si falta detalle o `detail_fetched_at` > 30 días, lo trae de
  EIDOS (conservación, legal nacional + Andalucía, nombre común, descripción, imágenes, sinónimos) y lo guarda.
- `POST /api/species` — especie nueva (`source='user'`, taxonID `biodex:<uuid>`). Entrada: `scientificName`,
  `scientificNameAuthorship`, `taxonRank`, `vernacularName?`. Clasificación automática: busca el género en EIDOS
  (`v_taxonomia`, rango Genus) y copia reino…familia; si no está, GBIF `https://api.gbif.org/v1/species/match?name=`.
  Si ya existe en EIDOS o en la caché → 409 con el taxonID existente. Guarda `taxonRemarks` = «Taxón no presente en EIDOS».

## 2. Catálogos — `Api/catalogs.php`
Todas las rutas comprueban el rol con `requireCatalogRole` (matriz de F2a).
- `GET /api/catalogs` — los míos y los compartidos, con mi rol y nº de especies.
- `POST /api/catalogs` — crea y me inserta como `owner` en `catalog_members` (transacción).
- `GET /api/catalogs/{id}` — datos, mi rol, especies (con su nivel de sensibilidad) y resumen:
  nº de especies por `taxonomicGroup` y por `iucnPrimary.code`.
- `GET /api/catalogs/{id}/tree` — árbol reino › filo › clase › orden › familia › especies, por ids estables.
- `PATCH /api/catalogs/{id}` · `DELETE /api/catalogs/{id}` (borrado lógico) — solo owner.
- `POST /api/catalogs/{id}/species` `{taxonID}` — owner/custodian/editor. Crea la fila de
  `species_sensitivity` con `suggestSensitivityLevel` (level = suggested). Idempotente.
- `DELETE /api/catalogs/{id}/species/{taxonID}` — solo owner, borrado lógico.
- `PUT /api/catalogs/{id}/species/{taxonID}/sensitivity` `{level, reason}` — solo owner; `reason`
  obligatorio si baja el nivel respecto al sugerido; registra `set_level` en `access_log`.
- Miembros e invitaciones NO (F6).

## 3. Pruebas — `backend/bin/test-f2b.php`
Contra `http://nginx/api` con dos usuarios (A y B) y cookies separadas:
- Buscar «lince», «Aquila» con `group=Aves`, «aguila» sin acento (debe encontrar *Aquila adalberti*),
  `category=CR`, `legal=rd139`.
- Ficha de *Artemisia granatensis*: trae descripción, UICN principal y nivel sugerido 2.
- Crear *Inventadus falsus* → 201 con clasificación; repetir → 409; crear «Lynx pardinus» → 409.
- A crea catálogo, añade 3 especies; B no lo ve (403 en GET); con B insertado como `viewer` por SQL:
  GET 200, añadir especie 403, cambiar nivel 403. A baja un nivel sin `reason` → 422.
- Árbol y resumen correctos para esas 3 especies.

## Documentación
Rellena `docs/api.md`: tabla método · ruta · rol mínimo · qué hace (≤ 60 líneas).

## Criterio de hecho
`test-f2a.php` y `test-f2b.php` en verde. Commits `F2b:`. Resume en 8 líneas, incluida la solución de acentos.
