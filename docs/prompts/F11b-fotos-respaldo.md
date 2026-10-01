# F11b · Especies sin foto en EIDOS: respaldo de iNaturalist y siluetas

Fase de ANTES del congreso (19-11). Requiere F11. Va antes de F12a/F12b (fotos de usuario, después del congreso).
Lee `CLAUDE.md`, `docs/db.md`, `docs/api.md` › especies, `docs/eidos.md` (qué taxonomía trae EIDOS), `docs/railway.md`,
las firmas de `backend/Lib/{eidos,species,api,authz,audit}.php` y `backend/Api/{species,catalogs}.php`,
`docker/nginx/biodex-app.conf` (solo el bloque `/img/fototeca/` como modelo y dónde se declara su `proxy_cache`),
`docker/nginx/security-headers.conf`, `docker/railway/entrypoint.sh`, `frontend/js/components/species-card.js`,
la vista de detalle de especie en `frontend/js/views/` y `frontend/sw.js`. No toques la exportación.

## Qué se construye
Hoy, si EIDOS no trae foto, la tarjeta y la ficha muestran un hueco. Orden nuevo de la miniatura:
**foto de EIDOS (Fototeca) > foto por defecto de iNaturalist aceptada > silueta del grupo**.
(Con F12a se añadirá delante la portada del catálogo.) Regla de oro: **mejor sin foto que con la de otra especie.**

## 1. Datos (migración `012_species_photos.sql`)
Tabla `species_photos`, una fila por especie (global, la comparten todos los catálogos): `taxonID` PK,
`source` ENUM(`inaturalist`) (dejar sitio para `commons`/`gbif`), `photo_id` (id numérico de la foto en iNat),
`photo_ext` (`jpg`/`jpeg`/`png`), `inat_taxon_id`, `matched_name`, `match_type` (`exact`/`synonym`),
`attribution` (texto tal cual de iNat), `license` (`cc0`/`cc-by`/`cc-by-nc`), `status`
(`pending`/`ok`/`review`/`rejected`/`none`), `checked_at`, `reviewed_by` NULL, `reviewed_at` NULL.
Solo especies EIDOS sin foto de Fototeca; las de usuario (`biodex:`) no se buscan (su nombre puede no existir) y
van directas a silueta. `docs/db.md` al día. F12a pasa a usar `013_media.sql`.

## 2. iNaturalist (`backend/Lib/inat.php`)
- **Cliente**: `GET https://api.inaturalist.org/v1/taxa?q=<nombre sin autor>&is_active=true&per_page=10`.
  `User-Agent: BioDex/<VERSION> (+APP_URL)`. Máximo **1 petición/s**; timeout corto; 429/5xx → reintento con espera
  creciente y, si sigue, la especie se queda `pending`. Solo desde el backend (como EIDOS).
- **Decisión (función pura, sin red)**: entra el nombre científico de EIDOS sin autor, sus sinónimos (si `docs/eidos.md`
  dice que los hay) y el JSON; sale `{status, ...}`:
  - `exact` → `ok`: resultado activo con `name` igual (sin mayúsculas, espacios normalizados) y rango compatible
    (especie↔especie, subespecie↔subespecie, variedad↔variedad).
  - `synonym` → `review`: `name` distinto pero `matched_term` igual al nombre de EIDOS o a un sinónimo suyo.
  - Cualquier otra cosa (aproximado, ambiguo, otro rango) → `none`.
  - Taxón válido pero sin `default_photo`, o licencia fuera de `['cc0','cc-by','cc-by-nc']` (constante), incluida
    `null` → `none`.
- `photo_id` y extensión se sacan de `default_photo.medium_url`. Acepta solo URLs del bucket
  `inaturalist-open-data.s3.amazonaws.com/photos/<n>/...` (ahí están las fotos con licencia abierta); otro host → `none`.

## 3. Cuándo se busca (`backend/bin/fetch-species-photos.php`)
- Al crear un catálogo o añadirle especies: insertar `pending` las que no tengan fila. Nunca se espera a iNat.
- El script procesa `pending` a 1 req/s. Opciones `--limit=N`, `--recheck-none-days=30` (iNat sube fotos nuevas),
  `--dry-run`. Al acabar, una línea de resumen (ok/review/none/errores) en el log.
- `docker/railway/entrypoint.sh`: tras migraciones y seed, lanzarlo **en segundo plano** (que no retrase nginx ni el
  healthcheck). En local, `docker compose exec php php bin/fetch-species-photos.php` en `docs/railway.md`/README.

## 4. Proxy nginx `/img/inat/`
Mismo patrón que `/img/fototeca/`: solo `^/img/inat/[0-9]{1,12}/(square|medium|large)\.(jpe?g|png)$`, guarda previa
sobre `$request_uri` y `$uri` (lo demás → 400), `limit_except GET`, `proxy_pass` fijo a
`https://inaturalist-open-data.s3.amazonaws.com/photos/<id>/<tamaño>.<ext>`, cabeceras de origen ocultas, zona
`proxy_cache inat` 30 días, `Cache-Control` nuestro. Recuerda que en Railway el `resolver` se cambia por `sed` en el
`Dockerfile` (comprueba que cubre el bloque nuevo). La CSP **no cambia** (`img-src 'self' data:`). Al ser un host fijo
y un id numérico no hay SSRF; el bucket solo contiene fotos con licencia abierta.

## 5. Siluetas
- Sprite SVG **propio y original** (`frontend/img/silhouettes.svg`, `<symbol>` con `currentColor`): planta con flor,
  helecho, gimnosperma, briófito, hongo, liquen, alga, ave, mamífero, reptil, anfibio, pez, insecto, arácnido,
  molusco, crustáceo, otro invertebrado, genérica.
- Mapeo taxonomía EIDOS (reino/filo/clase/orden) → grupo en `shared/species-groups.json`, aplicado en
  `Lib/species.php`; la API devuelve `silhouette` en cada especie y el cliente solo pinta. Sin regla → genérica.
- `role="img"` + `aria-label="Sin foto · silueta de <grupo>"`. Nunca una imagen rota: si falla la carga, silueta.

## 6. API e interfaz
- Las respuestas de especie llevan, junto a `thumbnail`, `photoCredit` (`{text, license, url}`) y `silhouette`.
  Solo se expone la foto de iNat con `status=ok`.
- Crédito bajo la foto de la ficha: «Foto: {attribution} · CC0/CC BY/CC BY-NC · iNaturalist», enlace a
  `https://www.inaturalist.org/photos/<id>` (`target="_blank" rel="noopener noreferrer"`). El de la Fototeca no cambia.
- **Revisión** (owner/custodian del catálogo, como el resto de `authz.php`): filtro «Fotos por revisar» en la lista de
  especies del catálogo con las `review` de sus especies: nombre de EIDOS, nombre de iNat, foto y crédito.
  **Aceptar** → `ok`; **Rechazar** → `rejected` (no se vuelve a proponer). Endpoint `POST` en `Api/species.php`
  (CSRF y rol como los demás); se registra en `access_log` quién y cuándo. `docs/api.md` al día.

## 7. Sin conexión
`/img/inat/` con la misma estrategia que `/img/fototeca/` en `sw.js` (y en la precarga del catálogo si existe). El
sprite entra en la precarga de la app. Sube la versión (`VERSION`) y pasa `bin/check-sw.php`.

## 8. No se toca
Exportaciones (DwC-A, Plinian Core, hojas): estas fotos son ilustrativas, no evidencia. Prioridad y crédito de la
Fototeca. Fotos de usuario (F12a/F12b).

## 9. Pruebas (`backend/bin/test-f11b.php`, con `test-lib.php`)
- Decisión con fixtures JSON en `docs/inat-samples/` (sin red): exacta CC BY → ok; exacta licencia `null` → none;
  sinónimo por `matched_term` → review; otra especie del género → none; subespecie vs especie → none; mayúsculas y
  dobles espacios → ok; sin `default_photo` → none; URL fuera del bucket → none.
- Mapeo de grupos: un caso por grupo y uno sin regla.
- Proxy: `/img/inat/abc/medium.jpg`, `/img/inat/1/huge.jpg`, `/img/inat/1/medium.jpg?x=1`, `%`-codificados → 400.
- Revisión: editor/viewer → 403; custodian acepta → la especie sale con `thumbnail`; rechazada → `silhouette`.
- Manual: script sobre el catálogo demo; dame el resumen y 3–4 especies `review` para que las mire.

## 10. Entrega
`docs/fotos-respaldo.md` (prioridad, licencias, reglas de coincidencia, script, proxy, siluetas, revisión) y una línea en
`docs/limitaciones.md` (iNat cubre poco algunas especies; fotos ilustrativas). Si el guion de `docs/demo.md` gana con
enseñar la revisión, una línea. Commits pequeños con prefijo `F11b:`. Al acabar: qué has cambiado, decisiones
distintas a este prompt y por qué, resumen del script en la demo y pasos para comprobarlo en Railway tras el push.
