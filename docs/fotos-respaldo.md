# Fotos de respaldo: iNaturalist, GBIF, Wikimedia Commons y siluetas (F11b, F11d)

EIDOS no trae foto de muchas especies (de las 171 en caché el 2026-10-01, 141 sin ninguna de la Fototeca). Antes la
tarjeta y la ficha enseñaban un hueco. Ahora la miniatura sigue este orden:

1. **Foto de EIDOS** (Fototeca del MITECO, `/img/fototeca/…`). Prioridad y crédito sin cambios.
2. **Foto por defecto de iNaturalist aceptada** (`/img/inat/…`, proxy de nginx). F11b.
3. **Foto de una observación de GBIF aceptada** (copia local, `/img/respaldo/…`). F11d.
4. **Imagen (P18) del taxón en Wikidata, de Wikimedia Commons** (copia local, `/img/respaldo/…`). F11d.
5. **Silueta del grupo** (`frontend/img/silhouettes.svg`).

Las fuentes 2–4 se consultan **en cascada** y solo hay una foto de respaldo por especie (`species_photos.status = 'ok'`).
(F12a pondrá delante la portada del catálogo.) Regla de oro: **mejor sin foto que con la de otra especie.**
Son fotos **ilustrativas**, no evidencia: no entran en ninguna exportación (DwC-A, Plinian Core, hojas).

## Datos (`species_photos`, migraciones 012 y 013; `species_photo_files`, 013)
Una fila por especie, global (la comparten todos los catálogos). Solo especies de EIDOS sin foto de la Fototeca; las de
usuario (`biodex:`) no se buscan (su nombre puede no existir en las fuentes) y van directas a la silueta. Columnas en
`docs/db.md`. Estados:

| `status` | Significa | Se muestra |
|---|---|---|
| `pending` | Por consultar (recién dada de alta en un catálogo, una consulta falló o se rechazó una foto y quedan fuentes) | No |
| `ok` | Coincidencia exacta con licencia admitida, o sinónimo aceptado en la revisión | **Sí** |
| `review` | Coincidencia por sinónimo (iNat o GBIF): la decide owner o custodio | No |
| `none` | Ninguna fuente tiene foto aceptable (o todas se rechazaron en la revisión) | No |

`source` dice de qué fuente es la foto (`inaturalist`, `gbif`, `commons`; NULL sin foto) y `rejected_sources` qué
fuentes se rechazaron en la revisión. F11b tenía un estado `rejected`; la migración 013 lo quita: las `rejected`
pasaron a `pending` con `rejected_sources = 'inaturalist'` y las `none` a `pending`, para que el primer despliegue
busque ya en GBIF y Commons. `inat_taxon_id` conserva su nombre (solo lo usa iNat); el taxón de GBIF (usageKey) o
de Wikidata (QID) va en `source_ref`.

## Licencias
Una lista por fuente; cualquier otra licencia → la fuente da `none` y se pasa a la siguiente.

| Fuente | Admitidas | Constante |
|---|---|---|
| iNaturalist | `cc0`, `cc-by`, `cc-by-nc` | `INAT_LICENSES` (`Lib/inat.php`) |
| GBIF | `cc0`, `cc-by`, `cc-by-nc`, `cc-by-sa` | `GBIF_LICENSES` (`Lib/gbif.php`) |
| Wikimedia Commons | `cc0`, dominio público (`pd`), `cc-by`, `cc-by-sa` | `COMMONS_LICENSES` (`Lib/commons.php`) |

- **ND** (sin obras derivadas) no se admite en ninguna: no deja recortar.
- **CC BY-SA solo en GBIF y Commons** (decidido por Damián, F11d). La «SA» obliga a licenciar igual las **obras
  derivadas**; BioDex muestra la foto **sin modificar** (los bytes tal cual llegaron, sin reescalar ni recomprimir),
  con autor, licencia y enlace, y **nunca** la mete en una exportación. Recortarla al mostrarla con CSS
  (`object-fit`) no es una obra derivada. En iNat sigue fuera (F11b no la admitía y su foto por defecto rara vez es SA).
  En Commons, además, NC no existe: Commons no admite fotos NC ni ND.
- **NC**: BioDex es un prototipo sin uso comercial; si eso cambiara, habría que sacar `cc-by-nc` de iNat y GBIF.
- `license_version` guarda la versión («4.0», «3.0-de» para una CC adaptada) para el crédito; iNat no la da.
- `license_code: null` en iNat es «all rights reserved» → `none`. En GBIF vale la licencia **de la foto**
  (`media[].license`), no la de la ocurrencia: ej. real (`foto-copyright.json`), ocurrencia CC BY 4.0 con la foto
  «Copyright by the creator…». Se acepta una URL de Creative Commons o un código reconocible («CC_BY_NC_4_0»,
  «CC BY-SA 4.0»); cualquier texto libre («Denis Bastianelli (cc-by-sa)») → no.

La atribución se enseña bajo la foto de la ficha. iNat la da hecha y se guarda **tal cual**; GBIF y Commons, solo el
autor (`creator` o `rightsHolder`; `extmetadata.Artist` sin HTML, ≤ 200 caracteres):

> Foto: (c) faluke, some rights reserved (CC BY-NC), uploaded by faluke · CC BY-NC · [iNaturalist](https://www.inaturalist.org/photos/109833820)
> Foto: Isidre blanc · CC BY-SA 4.0 · [Wikimedia Commons](https://commons.wikimedia.org/wiki/File:ACHILLEA_PYRENAICA_-_GENTO_-_IB-907_(Camamilla_de_muntanya).jpg)

En la tarjeta, por espacio, solo la fuente y la licencia: «iNaturalist · CC BY-NC», «GBIF · CC BY 4.0»,
«Commons · CC BY-SA 4.0». La API da `photoCredit {text, license, url, source}` con `license` ya como etiqueta
completa («CC BY-SA 4.0», «Dominio público», «CC0»).

## Reglas de coincidencia
Entran el nombre canónico de EIDOS (sin autor), su rango y su reino (y, en iNat, sus sinónimos de EIDOS).

### iNaturalist (`inatDecide()`, función pura; sin cambios en F11d)
Consulta: `GET https://api.inaturalist.org/v1/taxa?q=<nombre de EIDOS sin autor>&is_active=true&per_page=10`, sin marcas de
rango en `q` (`inatQueryName()`: con «subsp.» iNat no encuentra nada).
Entran también los sinónimos de EIDOS (`species.synonyms`, con autor: se quita con `inatStripAuthor()`). Sobre cada
resultado:

- Se descarta si no está activo, si su rango no es el de EIDOS (especie↔especie, subespecie↔subespecie,
  variedad↔variedad, forma↔forma) o si su `iconic_taxon_name` es de **otro reino** (homónimos entre plantas y animales).
- **`exact` → `ok`**: `name` igual al de EIDOS sin mayúsculas, con los espacios normalizados y sin marcas de rango
  (EIDOS escribe «Turdus merula subsp. cabrerae»; iNat, «Turdus merula cabrerae»).
- **`synonym` → `review`**: `name` distinto, pero `matched_term` igual al nombre de EIDOS o a uno de sus sinónimos.
  Ej. real: EIDOS «Lacerta lepida» → iNat «Timon lepidus».
- Más de un resultado exacto, o más de uno por sinónimo → `none` (ambiguo). Cualquier otra cosa → `none`.
- Con el taxón elegido, `none` si no tiene `default_photo`, si la licencia no está en la lista, si la foto está marcada
  (`flags`) o si `medium_url` no es del bucket `inaturalist-open-data.s3.amazonaws.com/photos/<n>/medium.<jpg|jpeg|png>`
  (ahí están solo las fotos con licencia abierta; las demás viven en `static.inaturalist.org`). De esa URL salen
  `photo_id` y la extensión.

Casos de prueba reales (recortados) en `docs/inat-samples/`; los comprueba `bin/test-f11b.php` sin red.

#### La trampa de las subespecies
EIDOS tiene las subespecies de *Capra pyrenaica* como **sinónimos** de la especie (`docs/eidos.md`). Al pedir «Capra
pyrenaica», iNat podría devolver «Capra pyrenaica victoriae», cuyo nombre sí es un sinónimo de EIDOS; pero es una
subespecie frente a una especie → `none`. Ver `subespecie-vs-especie.json`.

### GBIF (`Lib/gbif.php`; funciones puras `gbifMatchDecide()`, `gbifLicense()`, `gbifCandidates()`)
1. **Taxón**: `GET https://api.gbif.org/v1/species/match?name=<nombre sin autor>&kingdom=<reino de EIDOS>&strict=true`.
   Se usa la **v1**: el `/v2/species/match` tiene otra forma de respuesta (`usage`, `diagnostics`…) y la v1 sigue
   funcionando (comprobado el 2026-10-01).
   - `matchType` `EXACT` + `status` `ACCEPTED`, mismo rango que EIDOS y mismo reino → **`exact`**.
   - `status` `SYNONYM` (`HETEROTYPIC_`/`HOMOTYPIC_SYNONYM`) → **`synonym`** con `acceptedUsageKey`; `DOUBTFUL` →
     `synonym` con su propia clave. Si sale foto, va a `review`. El aceptado tiene que ser del mismo rango: para una
     especie de EIDOS, `acceptedUsageKey = speciesKey`; para una subespecie o variedad, distinto. Ej. real: EIDOS
     «Sideritis glacialis subsp. virens» es en GBIF sinónimo de la **especie** *Sideritis glacialis* → `none` (sus
     fotos podrían ser de otra subespecie).
   - `FUZZY` (aun con `strict=true`: «Timon lepida» → *Timon lepidus*), `HIGHERRANK`, `NONE`, otro rango, otro reino,
     `PROPARTE_SYNONYM` o `MISAPPLIED` → `none`.
2. **Foto**: `GET /v1/occurrence/search?taxonKey=<clave aceptada>&mediaType=StillImage&basisOfRecord=HUMAN_OBSERVATION&limit=50`,
   primero con `country=ES` y, **si no hay candidata**, sin país. Una ocurrencia es candidata (con su primer `media`
   que cumpla) solo si:
   - su `speciesKey` (especie) o `acceptedTaxonKey` (subespecie, variedad) es la clave aceptada;
   - no tiene en `issues` `TAXON_MATCH_FUZZY` ni `TAXON_MATCH_HIGHERRANK`;
   - el `media` es `StillImage`, `image/jpeg` o `image/png`, con licencia admitida **en la propia foto** y con autor.
   Las ocurrencias del dataset de iNaturalist **no se excluyen**: una foto de observación CC BY puede servir aunque la
   `default_photo` del taxón en iNat fuera «all rights reserved».
3. **Descarga**: `https://api.gbif.org/v1/image/cache/500x/occurrence/<gbifID>/media/<md5 hex de media[].identifier>`
   (techdocs.gbif.org › Occurrence image API), una conexión cada vez, como mucho 3 candidatas. Si una no se descarga o
   no pasa la validación, se prueba la siguiente. La caché de imágenes de GBIF tarda 5–30 s si la foto no estaba
   cacheada y a veces corta la primera petición (comprobado el 2026-10-01): timeout de 30 s y los reintentos de siempre.
   Si **todas** fallan por red, la especie se queda `pending`.
4. **Crédito**: `attribution` = autor; `photo_page_url` = `https://www.gbif.org/occurrence/<gbifID>`; `photo_ref` =
   `<gbifID>/<md5>`; en la revisión, enlace al taxón `https://www.gbif.org/species/<clave aceptada>`.

Casos reales (recortados) en `docs/gbif-samples/`: `exacta-cc-by`, `foto-copyright`, `foto-sin-autor` (a la primera
ocurrencia se le quitó el autor: en la muestra no salió ninguna foto CC sin él), `sinonimo`, `fuzzy`,
`subespecie-vs-especie` y `otro-reino` (respuesta real para «Morus alba» —morera— frente a un EIDOS que dijera
Animalia: *Morus* es también el género de los alcatraces).

### Wikimedia Commons (`Lib/commons.php`; funciones puras `commonsTaxonDecide()`, `commonsPhotoDecide()`)
Solo coincidencia exacta: aquí **no hay `review`**.
1. **Taxón por Wikidata**: `GET https://www.wikidata.org/w/api.php?action=query&list=search&srsearch=haswbstatement:"P225=<nombre>"`
   y `wbgetentities` (`props=claims`) de los QID. El nombre va como lo escribe Wikidata: los zoológicos sin marca de
   rango («Turdus merula cabrerae»), los botánicos con ella («Acer opalus subsp. granatense», como EIDOS). Vale si:
   - hay **un solo** elemento con P225 igual (dos → `none`; ej. real: *Agathis montana* es una conífera y una avispa);
   - su P105 (rango) es el de EIDOS: Q7432 especie, Q68947 subespecie, Q767728 variedad (ej. real: *Platanus ×
     hispanica* es en Wikidata notoespecie, Q1306176 → `none`);
   - es un taxón (P31 = Q16521) y no un sinónimo (P31 = Q1040689) ni una combinación no disponible (Q17487588);
   - tiene P18 (imagen; la preferente, o la primera no obsoleta).
2. **Foto**: `GET https://commons.wikimedia.org/w/api.php?action=query&titles=File:<P18>&prop=imageinfo&iiprop=url|mime|size|extmetadata&iiurlwidth=500`.
   - Licencia de `extmetadata.License` (`cc0`, `pd`, `cc-by-4.0`, `cc-by-sa-3.0`, `cc-by-sa-3.0-de`…) → código y
     versión. Cualquier otra → `none` (ej. real: un fichero solo GFDL no trae `License`).
   - Autor de `extmetadata.Artist` sin HTML y con un máximo de 200 caracteres. Sin autor y licencia distinta de
     cc0/pd → `none`.
   - `mime` jpeg, png o webp.
   - **La miniatura se descarga de la `thumburl` que devuelve la API** (hoy `https://thumb.wikimedia.org/…/500px-…`),
     nunca de una URL construida a mano: desde 2026 Wikimedia sirve las miniaturas solo a anchos fijos (20, 40, 60,
     120, 250, 330, 500, 960, 1280…) y avisa de que sus URL no son una API pública.
3. **Crédito**: `photo_page_url` = `descriptionurl`; `photo_ref` = nombre del fichero; `source_ref` = QID.
4. **Cliente**: `User-Agent: BioDex/<VERSION> (<APP_URL>; <CONTACT_EMAIL>)` (política de Wikimedia; sin
   `CONTACT_EMAIL`, solo la URL), `maxlag=5`. Wikidata contesta `maxlag` cuando su réplica va con retraso (pasó
   varias veces el 2026-10-01): se reintenta respetando `Retry-After` y, si sigue, la especie queda `pending`.

Casos reales (recortados) en `docs/commons-samples/`: `exacta-cc-by-sa`, `dos-elementos`, `rango-distinto`,
`sin-p18`, `solo-gfdl` y `artist-html`.

## La cascada (`Lib/photos.php`)
- `photoCascade()`: iNaturalist → GBIF → Commons, **saltando las de `rejected_sources`**. La primera que da `ok` o
  `review` **cierra** la búsqueda; `none` pasa a la siguiente; si todas dan `none` → `none`.
- **Un error de red** (tras los reintentos) deja la especie `pending` **sin pasar a la fuente siguiente**: si no, se
  mostraría la de Commons solo porque iNat estaba caído.
- `photoBreaker()`: tras 3 errores seguidos de una fuente, esa fuente se da por caída hasta el final de la pasada (sus
  especies quedan `pending` al momento, sin ~1 min de reintentos cada una).
- Todas las fuentes van a **1 petición/s por fuente** (`Lib/photo-files.php`), timeout corto y reintentos a los 2, 5
  y 15 s ante 429, 5xx, red o `maxlag`. Solo el backend llama a las fuentes (como a EIDOS).

## Copia local y su servicio (GBIF y Commons)
De iNat se sigue usando su proxy (`/img/inat/`). De GBIF y Commons el script descarga **una sola vez** la miniatura
elegida y la guarda en MySQL (`species_photo_files`):
- porque Wikimedia solo sirve miniaturas a anchos fijos y sus URL no son una API pública (no se pueden filtrar con
  una regex de nginx como `/img/inat/`), y la caché de imágenes de GBIF puede tardar o fallar si la foto no estaba
  cacheada. Con la copia, la demo tampoco depende de que respondan en ese momento;
- en MySQL y no en disco porque el disco del contenedor de Railway se borra en cada despliegue (el volumen es cosa de
  F12a). Son ~30–100 KB por especie.

**Descarga** (`photoDownload()`, `photoValidateBytes()`): tipo comprobado **por contenido** (`finfo`), no por la
cabecera; máximo 1,5 MB (se corta la descarga al pasar); `getimagesize` válido y del mismo tipo; lados entre 100 y
4000 px. Sin reescalar ni recomprimir: se guardan los bytes tal cual llegan. Si no cumple, se prueba la candidata
siguiente (GBIF) o la fuente da `none` (Commons).

**Servicio**: `GET /img/respaldo/<taxonID>/<16 primeros hex del sha256>.<jpg|png|webp>`, atendido por
`backend/public/respaldo.php` (el router de `/api` solo enruta `/api/<recurso>`).
- nginx (`docker/nginx/biodex-app.conf`) solo le pasa `^/img/respaldo/[0-9]{1,12}/[0-9a-f]{16}\.(jpg|png|webp)$`, con
  la misma guarda previa sobre `$request_uri` y `$uri` que `/img/inat/` (mayúsculas, `%`-codificados, `..` o
  parámetros → 400) y `limit_except GET` (POST → 403).
- Hash o extensión que no son los de la copia actual → 404: al cambiar de foto cambia la URL y una caché no sirve la
  vieja. Por eso la respuesta lleva `Cache-Control: public, max-age=31536000, immutable`, `ETag` (304 con
  `If-None-Match`), su `Content-Type`, `nosniff` y las cabeceras de seguridad de nginx. La CSP **no cambia**
  (`img-src 'self' data:`).
- Solo se sirve con `status = ok`. Excepción: la de una `review` (sinónimo de GBIF), que solo ven con sesión quienes
  son owner o custodio de un catálogo con la especie (como el endpoint de revisión), con `Cache-Control: private,
  no-store`; a los demás, 404.
- `speciesPhotoOut()` da `thumbnail` = esa URL y `photoCredit {text, license, url, source: gbif|commons}`.
- Sin red, en el móvil: las copias se guardan con el catálogo descargado como las de iNat (`offline.js`, `sw.js`).

## Cuándo se busca
- Al **crear un catálogo** o **añadirle especies** (`catalogAddSpecies()` → `speciesQueuePhoto()`): se inserta
  `pending` si no hay fila. La petición nunca espera a las fuentes.
- `backend/bin/fetch-species-photos.php` procesa la cola:

  ```
  docker compose exec php php bin/fetch-species-photos.php [--limit=N] [--recheck-none-days=30] [--source=inaturalist|gbif|commons] [--dry-run]
  ```

  - Antes de consultar pone en cola las especies de EIDOS de algún catálogo vigente sin fila (las de catálogos creados
    antes de F11b).
  - `--recheck-none-days=30` (por defecto): vuelve a mirar las `none` consultadas hace más de 30 días, porque las
    fuentes suben fotos nuevas. `0` = no volver a mirarlas. Las fuentes rechazadas no se vuelven a mirar nunca.
  - `--source=gbif` (o `inaturalist`, `commons`): prueba una sola fuente. Guarda lo que encuentre (`ok`/`review`), pero
    un `none` no se escribe: las otras fuentes no se han consultado.
  - `--dry-run`: consulta y decide, pero no escribe.
  - Una especie que ya tiene foto de la Fototeca sale de la cola. Una foto nueva `ok` sube `content_seq` de los
    catálogos que la tienen: el móvil vuelve a bajar el paquete offline.
  - Dos ejecuciones a la vez no se pisan (`GET_LOCK` de MySQL). Imprime una línea por especie con la fuente que decidió
    y por qué se descartaron las anteriores, y un resumen por fuente:

    ```
      ok      44408 Adactylotis gesticularia → GBIF: Adactylotis gesticularia · CC BY-NC 4.0 · 6179211731/2dcaf1c0… (iNat: licencia no admitida: todos los derechos reservados)
      none    5676 Laserpitium longiradium → sin foto (iNat: el taxón no tiene foto por defecto; GBIF: sin fotos con licencia admitida y autor; Commons: Q17133520: sin imagen (P18))
    BioDex fotos: iNat ok 0 · GBIF ok 1 · Commons ok 0 · review 0 · none 1 · errores 0 · con Fototeca 0 · 2 especies consultadas · 0 puestas en cola · 2 min
    ```
  - Las especies con error de red se reintentan **una vez** al final de la pasada, tras 60 s y con el cortocircuito a
    cero (en seco, no). Las que vuelvan a fallar quedan `pending` para la pasada siguiente.
- Peticiones por especie: iNat 1; GBIF 2–3 (match, ocurrencias en España y quizá en el mundo) + 1–3 descargas;
  Commons 3 + 1 descarga. Cada fuente va a 1/s, pero GBIF responde en 5–10 s y su caché de imágenes en 5–30 s.
  Medido el 2026-10-01 en local, en seco, con 40 especies de la caché sin foto de la Fototeca: **17 min (~25 s por
  especie)**; iNat ok 15 · GBIF ok 3 · Commons ok 1 · review 1 · none 5 · errores 15 (GBIF sin aceptar la conexión a
  ratos y Wikidata en `maxlag` casi toda la tarde). Con ~140 especies, cuenta **~1 h** en segundo plano. No satura
  nada: es una sola conexión a la vez y como mucho 1 petición/s por fuente.
- **Railway**: `docker/railway/entrypoint.sh` lo lanza en segundo plano tras las migraciones (y tras el seed de la demo
  si `SEED_DEMO=1`): no retrasa nginx ni el healthcheck. El resumen sale en los logs del despliegue.

## Siluetas
- `frontend/img/silhouettes.svg`: sprite **propio y original**, un `<symbol id="sil-<grupo>">` por grupo en 48 × 48 y
  `currentColor`: planta con flor, helecho, gimnosperma, briófito, hongo, liquen, alga, ave, mamífero, reptil, anfibio,
  pez, insecto, arácnido, molusco, crustáceo, otro invertebrado y genérica (una lupa).
- `shared/species-groups.json`: reglas por reino/filo/clase/orden; gana la primera que casa (las específicas antes:
  líquenes antes que hongos, helechos antes que «Tracheophyta»). Sin regla → genérica. Las aplica `speciesSilhouette()`
  en `Lib/species.php`, también a las especies de usuario (clasificación de EIDOS o de GBIF).
- La API devuelve `silhouette {id, label}` en **todas** las especies (también las que tienen foto): el cliente solo
  pinta. `role="img"` y `aria-label="Sin foto · silueta de <grupo>"`.
- **Nunca una imagen rota**: si la foto no carga (sin red y sin copia, una fuente o MITECO caídos), la tarjeta y la
  ficha la cambian por la silueta (`swapToSilhouette()` en `components/species-card.js`). Si fallan **todas** las
  fotos de la galería de la Fototeca, también la silueta (F11d; antes la cabecera se quedaba vacía).
- Sin red: el sprite va en la precarga de la app (`SHELL_ASSETS`) y las fotos de respaldo se guardan con el catálogo
  descargado, como las de la Fototeca (`offline.js`, `sw.js`).

## Revisión (owner y custodio)
- `GET /api/catalogs/{id}` lleva a owner y custodio `photoReviews`: las especies del catálogo con foto en `review`
  (nombre de EIDOS, fuente, nombre en la fuente con enlace a su taxón, foto y crédito). La de GBIF se ve desde
  `/img/respaldo/`, que en `review` solo se la sirve a ellos.
- En Especies, el filtro **«Fotos por revisar (n)»** cambia la tabla por esa lista con **Aceptar** (→ `ok`, se muestra
  a todos) y **Rechazar**.
- **Rechazar ya no cierra la especie** (F11d): la fuente pasa a `rejected_sources` (su foto no se vuelve a proponer),
  se borran los datos de la foto y su copia local, y la especie vuelve a `pending`; la siguiente pasada del script
  sigue con las fuentes siguientes, saltándose las rechazadas. `none` solo cuando no queda ninguna.
- `POST /api/species/{taxonID}/photo-review {catalogId, decision: accept|reject}`: rol owner o custodio en ese catálogo
  (`review_photos` en `authz.php`) y la especie en él; si no hay foto en `review` → 409. La decisión es **global**: vale
  para todos los catálogos con la especie (sube su `content_seq`). Queda en `access_log` (`photo_review`: quién,
  cuándo, qué foto de qué fuente y qué decisión); el registro del catálogo lo enseña como «Foto de respaldo».
