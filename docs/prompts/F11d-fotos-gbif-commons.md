# F11d · Más fotos de respaldo: GBIF y Wikimedia Commons después de iNaturalist

Fase de ANTES del congreso (19-11). Requiere F11b y F11c. Va antes de F12a/F12b (fotos de usuario, después del congreso).
Lee `CLAUDE.md`, `docs/fotos-respaldo.md` (entero: esta fase lo amplía), `docs/db.md` › `species_photos`, `docs/api.md` ›
especies y revisión de fotos, `docs/railway.md`, `backend/migrations/012_species_photos.sql`, `backend/Lib/{inat,species,api,authz,audit}.php`,
`backend/bin/fetch-species-photos.php`, `backend/bin/test-f11b.php`, `backend/public/index.php` (cómo enruta), `docker/nginx/biodex-app.conf`
(bloques `/img/fototeca/`, `/img/inat/` y `/api/`), `docker/nginx/security-headers.conf`, `Dockerfile`, `docker/railway/entrypoint.sh`,
`frontend/js/components/species-card.js`, `frontend/js/views/{species-detail,species,catalog-team}.js`, `frontend/js/offline.js` y `frontend/sw.js`.
No toques la exportación.

## Qué se construye
Hoy, si iNaturalist no da una foto aceptable, la especie pasa directamente a la silueta (en la demo, muchas especies de flora
endémica). Orden nuevo de la miniatura:
**Fototeca (EIDOS) > iNaturalist > GBIF > Wikimedia Commons > silueta del grupo.**
Regla de oro, igual que en F11b: **mejor sin foto que con la de otra especie.** Siguen siendo fotos **ilustrativas**: no entran en
ninguna exportación.

## 1. Decisiones ya tomadas
- **Licencias por fuente** (constantes, una por fuente):
  - iNaturalist: sin cambios (`cc0`, `cc-by`, `cc-by-nc`).
  - GBIF: `cc0`, `cc-by`, `cc-by-nc` y `cc-by-sa`.
  - Commons: `cc0`, dominio público (`pd`), `cc-by` y `cc-by-sa`. Commons no admite NC ni ND.
  - **CC BY-SA se admite solo en GBIF y Commons, decidido por Damián.** La foto se muestra sin modificar, con autor, licencia y
    enlace, y nunca entra en las exportaciones. Explica el motivo en `docs/fotos-respaldo.md` › Licencias. Recortarla con CSS
    (`object-fit`) no es una obra derivada. ND sigue fuera porque no deja recortar.
- **GBIF y Commons se guardan en local.** El script descarga una sola vez la miniatura elegida y la guarda en MySQL. Se sirve
  desde nuestro origen, sin proxy hacia ellos, por dos motivos:
  1. Desde 2026 Wikimedia sirve las miniaturas en `thumb.wikimedia.org`, solo a anchos fijos
     (20, 40, 60, 120, 250, 330, 500, 960, 1280…). Además avisa de que las URL de miniatura no son una API pública. No se
     pueden construir ni filtrar con una regex de nginx como `/img/inat/`.
  2. La caché de imágenes de GBIF (`api.gbif.org/v1/image/cache/…`) puede tardar o fallar si la imagen no estaba ya cacheada.
  La copia local también hace que la demo no dependa de que respondan en ese momento. MySQL y no disco porque el disco del
  contenedor de Railway se borra en cada despliegue y el volumen es cosa de F12a. Son ~50–100 KB por especie. **iNat no cambia**
  (sigue por su proxy).
- **Una búsqueda en cascada por especie.** Las pendientes se consultan en iNat. Si la decisión es `none`, se pasa a GBIF, y si
  vuelve a ser `none`, a Commons. La primera fuente que da `ok` o `review` cierra la búsqueda.

## 2. Datos (migración `013_species_photos_sources.sql`)
F12a pasa a usar `014_media.sql`: cámbialo en `docs/prompts/F12a-fotos-servidor.md` y en F12b si lo cita.
- `species_photos`:
  - `source` → ENUM(`inaturalist`,`gbif`,`commons`).
  - `license` → ENUM(`cc0`,`pd`,`cc-by`,`cc-by-nc`,`cc-by-sa`), más `license_version` VARCHAR(8) NULL («4.0») para el crédito.
  - Columnas nuevas:
    - `source_ref` VARCHAR(64) NULL: usageKey de GBIF o QID de Wikidata.
    - `photo_ref` VARCHAR(255) NULL: clave de ocurrencia + md5 en GBIF, o nombre del fichero en Commons.
    - `photo_page_url` VARCHAR(512) NULL: página de la foto, para el enlace del crédito.
    - `rejected_sources` SET(`inaturalist`,`gbif`,`commons`) NOT NULL DEFAULT ''.
  - Renombrar `inat_taxon_id` a algo neutro es opcional. Si lo dejas, documéntalo.
- Tabla nueva `species_photo_files`:
  - `taxonID` PK y FK a `species_photos` ON DELETE CASCADE.
  - `mime` ENUM(`image/jpeg`,`image/png`,`image/webp`), `bytes` MEDIUMBLOB, `sha256` CHAR(64), `width`, `height`, `fetched_at`.
- **Revisión**: rechazar una foto ya no cierra la especie. Añade la fuente a `rejected_sources`, deja el estado en `pending` y la
  cascada sigue con las fuentes siguientes, saltándose las rechazadas. `none` solo cuando no queda ninguna.
- **En la propia migración**:
  - Las filas `rejected` de F11b pasan a `pending` con `rejected_sources = 'inaturalist'`.
  - **Las `none` de F11b pasan a `pending`**, para que el primer despliegue busque ya en GBIF y Commons y no espere a
    `--recheck-none-days`.
  - Quita `rejected` del ENUM de `status` si ya no se usa; si lo mantienes, explica para qué.
- `docs/db.md` al día.

## 3. GBIF (`backend/Lib/gbif.php`)
- **Cliente**: igual que `inat.php` (User-Agent, 1 petición/s por fuente, timeout corto, reintentos con espera creciente en 429/5xx;
  si siguen fallando, la especie queda `pending`). Solo desde el backend.
- **Taxón**: `GET https://api.gbif.org/v1/species/match?name=<nombre sin autor>&kingdom=<reino de EIDOS>&strict=true`.
  - GBIF tiene también un `/v2/species/match` con otra forma de respuesta. Usa la versión que funcione hoy y apúntalo.
  - `matchType` `EXACT` + `status` `ACCEPTED`, mismo rango que EIDOS y mismo reino → seguimos como `exact`.
  - `status` `SYNONYM` (o `DOUBTFUL`) → seguimos como `synonym` con `acceptedUsageKey`. Si sale foto, irá a `review`.
  - `FUZZY`, `HIGHERRANK`, `NONE`, otro rango u otro reino → `none`.
- **Foto**: `GET /v1/occurrence/search?taxonKey=<clave aceptada>&mediaType=StillImage&basisOfRecord=HUMAN_OBSERVATION&limit=50`,
  primero con `country=ES` y, si no hay candidata, sin país. Cada ocurrencia es candidata solo si cumple todo esto:
  - Su `speciesKey` (o `acceptedTaxonKey`, para subespecies y variedades) es la clave aceptada.
  - No tiene en `issues` `TAXON_MATCH_FUZZY` ni `TAXON_MATCH_HIGHERRANK`.
  - El elemento de `media` tiene `type` `StillImage`, `format` `image/jpeg` o `image/png`, y una licencia admitida **en la propia
    foto**. ¡Ojo! La licencia de la ocurrencia (`occurrence.license`) no vale para la foto. Ej. real: ocurrencia CC BY 4.0 con la
    foto «Copyright by the creator…». Se acepta una URL de Creative Commons o un código reconocible; cualquier texto libre → no.
  - Tiene autor (`creator` o `rightsHolder`).
  La primera candidata que cumpla todo gana. Las ocurrencias del dataset de iNaturalist **no se excluyen**: una foto de
  observación CC BY puede servir aunque la `default_photo` del taxón en iNat fuera «all rights reserved».
- **Descarga**: `https://api.gbif.org/v1/image/cache/500x/occurrence/<gbifID>/media/<md5 hex de media[].identifier>`
  (documentado en techdocs.gbif.org › Occurrence image API; usa una sola conexión). Si falla, se prueba la siguiente candidata.
- **Crédito**:
  - `attribution` = autor (`creator`, o si no `rightsHolder`).
  - `photo_page_url` = `https://www.gbif.org/occurrence/<gbifID>`.
  - Texto en la ficha: «Foto: {autor} · CC BY-SA 4.0 · GBIF».

## 4. Wikimedia Commons (`backend/Lib/commons.php`)
- **Cliente**: con la política de User-Agent de Wikimedia, que exige contacto: `BioDex/<VERSION> (<APP_URL>; <CONTACT_EMAIL>)`.
  `CONTACT_EMAIL` es una variable de entorno nueva; si falta, solo URL. Documéntala en `docs/railway.md`. 1 petición/s y
  `maxlag=5`.
- **Taxón por Wikidata**: `GET https://www.wikidata.org/w/api.php?action=query&list=search&srsearch=haswbstatement:"P225=<nombre sin autor>"&format=json`.
  Luego `wbgetentities` de los QID encontrados (`props=claims`). Vale si se cumple todo esto:
  - Hay **un solo** elemento con P225 exactamente igual.
  - Su P105 (rango) es el de EIDOS: Q7432 especie, Q68947 subespecie, Q767728 variedad.
  - No es un elemento de sinónimo.
  - Tiene P18 (imagen).
  Todo lo demás → `none`. Aquí no hay `review`: solo coincidencia exacta.
- **Foto**: `GET https://commons.wikimedia.org/w/api.php?action=query&titles=File:<P18>&prop=imageinfo&iiprop=url|mime|size|extmetadata&iiurlwidth=500&format=json`.
  - Licencia sacada de `extmetadata.License` (`cc0`, `pd`, `cc-by-4.0`, `cc-by-sa-3.0`…) → código + versión. Cualquier otra → `none`.
  - Autor de `extmetadata.Artist`, sin HTML y con un máximo de 200 caracteres. Sin autor y licencia distinta de cc0/pd → `none`.
  - `mime` jpeg, png o webp.
  - **La miniatura se descarga de la `thumburl` que devuelve la API, nunca de una URL construida a mano.**
- **Crédito**:
  - `photo_page_url` = `descriptionurl`.
  - Texto en la ficha: «Foto: {autor} · CC BY-SA 4.0 · Wikimedia Commons».

## 5. Descarga y servicio de las copias
- **Descarga** (`Lib/species.php` o un `Lib/photo-files.php`, como veas):
  - Comprueba el tipo **por contenido** (`finfo`), no por la cabecera.
  - Máximo 1,5 MB, dimensiones razonables y `getimagesize` válido. Si no cumple, se descarta la candidata y se prueba la
    siguiente.
  - Sin reescalar ni recomprimir: se guardan los bytes tal cual llegan, para no tener que pensar si es una adaptación.
- **Servicio**: `GET /img/respaldo/<taxonID>/<16 primeros hex de sha256>.<jpg|png|webp>`.
  - Lo atiende un script PHP propio (`backend/public/respaldo.php` o similar; el router de `/api` no sirve, porque solo enruta
    `/api/<recurso>`).
  - nginx solo le pasa URLs que cumplan `^/img/respaldo/[0-9]{1,12}/[0-9a-f]{16}\.(jpg|png|webp)$`. Pon la misma guarda previa
    sobre `$request_uri` y `$uri` que `/img/inat/`; lo demás → 400. `limit_except GET`.
  - Si el hash no coincide con la foto actual → 404. Así, al cambiar de foto cambia la URL y la caché no sirve la vieja.
  - Respuesta con `Content-Type` correcto, `Cache-Control: public, max-age=31536000, immutable`, `ETag`, `nosniff` y las
    cabeceras de seguridad.
  - Solo se sirve con `status=ok`. Excepción: la miniatura de una `review`, para que la vean el propietario y el custodio en
    la revisión. Ahí sí con sesión y rol, como el endpoint de revisión.
  - La CSP **no cambia** (`img-src 'self' data:`).
- `speciesPhotoOut()`:
  - `thumbnail` = esa URL.
  - `photoCredit` = `{text, license, url, source}`, con `source` igual a `inaturalist`, `gbif` o `commons`.
  - `license` es la etiqueta completa: «CC BY-SA 4.0», «Dominio público», «CC0».

## 6. Script (`bin/fetch-species-photos.php`)
- La cascada del punto 1, saltando `rejected_sources`. Una línea de salida por especie que diga qué fuente decidió y por qué
  se descartaron las anteriores.
- Resumen final por fuente: «iNat ok 3 · GBIF ok 12 · Commons ok 20 · review 4 · none 40 · errores 0».
- Mismas opciones de antes, más `--source=inaturalist|gbif|commons` para probar una sola fuente.
- Un error de red en una fuente deja la especie `pending`. No se salta a la siguiente fuente: si no, se mostraría la de
  Commons cuando iNat solo estaba caído.
- `entrypoint.sh` no cambia: sigue en segundo plano. Comprueba que el primer despliegue con las `none` pasadas a `pending`
  no satura nada: ~141 especies × hasta ~7 peticiones a 1/s ≈ 15–20 min.

## 7. Interfaz
- `species-card.js`: crédito corto según la fuente: «iNaturalist · CC BY-NC», «GBIF · CC BY 4.0», «Commons · CC BY-SA 4.0».
- `species-detail.js`:
  - `photoBlock` pinta la foto de respaldo para **cualquier** `source` que no sea la Fototeca (hoy solo mira `inaturalist`).
    Crédito con enlace a `photoCredit.url` y nombre de la fuente.
  - **Arregla de paso** un fallo de la galería: si todas sus fotos fallan, hoy se borran una a una y la cabecera queda vacía.
    Cuando se quita la última, pon la silueta.
- `species.js` › «Fotos por revisar»: el texto deja de nombrar solo a iNaturalist («{Fuente} llama a esta especie con otro
  nombre…»), muestra la fuente y enlaza al taxón en esa fuente.
- `catalog-team.js`: `photo_review` → «Foto de respaldo».
- `offline.js` (`imageUrls`) y `sw.js`: añadir el prefijo `/img/respaldo/` con la misma estrategia que `/img/inat/`.
- Versión 1.4.0, SW v15 y `bin/check-sw.php`.

## 8. No se toca
Exportaciones, la prioridad y el crédito de la Fototeca, la decisión de iNat (`inatDecide()`), su proxy y las fotos de usuario.

## 9. Pruebas (`backend/bin/test-f11d.php`, con `test-lib.php`)
- Fixtures **grabados de respuestas reales** (recortados) en `docs/gbif-samples/` y `docs/commons-samples/`, sin red.
  Casos de GBIF:
  - Match exacto + ocurrencia en España CC BY con autor → ok.
  - Ocurrencia CC BY con la foto «copyright by the creator» → se salta.
  - Foto sin autor → se salta.
  - Match `FUZZY` → none.
  - `SYNONYM` → review.
  - Subespecie frente a especie → none.
  - Homónimo de otro reino → none.
  Casos de Commons:
  - P225 exacto con P18 CC BY-SA 4.0 → ok, con licencia y versión.
  - Dos elementos con el mismo P225 → none.
  - Rango distinto → none.
  - Sin P18 → none.
  - Licencia GFDL sola → none.
  - Artist con HTML → texto limpio.
- **Cascada** con fuentes falsas: iNat none → GBIF ok; iNat error → pending sin pasar a GBIF; iNat rechazada → se salta;
  todo none → none.
- **Migración**: `rejected` → `pending` + `rejected_sources`; `none` → `pending`.
- **Servicio**:
  - Hash incorrecto → 404.
  - Formas no válidas, `%`-codificados y `..` → 400.
  - POST → 403.
  - Una `review` sin sesión → 404 o 403.
  - `Content-Type` correcto y `nosniff`.
- **Revisión**: rechazar una de GBIF → `pending` con `gbif` en `rejected_sources`.
- `test-f11b` debe seguir pasando.
- **Manual**: script sobre el catálogo demo. Dame el resumen por fuente y 4–5 especies resueltas por GBIF o Commons
  para que las mire.

## 10. Entrega
- `docs/fotos-respaldo.md` ampliado: orden, licencias por fuente (con el motivo de BY-SA), reglas de coincidencia por fuente,
  copia local y su servicio, revisión con `rejected_sources`.
- `docs/api.md`, `docs/db.md`, `docs/railway.md` (`CONTACT_EMAIL`) y una línea en `docs/limitaciones.md`: las copias van en
  MySQL; si un autor retira su foto de Commons o GBIF, la nuestra sigue hasta la siguiente comprobación.
- README si cambia algo de uso.
- Commits pequeños con prefijo `F11d:`.
- Al acabar, dime:
  - qué has cambiado;
  - qué decisiones son distintas de este prompt y por qué;
  - el resumen del script con la demo;
  - cuántas especies del catálogo demo quedan con silueta;
  - los pasos para comprobarlo en Railway tras el push.
