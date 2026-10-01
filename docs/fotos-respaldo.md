# Fotos de respaldo: iNaturalist y siluetas (F11b)

EIDOS no trae foto de muchas especies (de las 171 en caché el 2026-10-01, 141 sin ninguna de la Fototeca). Antes la
tarjeta y la ficha enseñaban un hueco. Ahora la miniatura sigue este orden:

1. **Foto de EIDOS** (Fototeca del MITECO, `/img/fototeca/…`). Prioridad y crédito sin cambios.
2. **Foto por defecto de iNaturalist aceptada** (`species_photos.status = 'ok'`, `/img/inat/…`).
3. **Silueta del grupo** (`frontend/img/silhouettes.svg`).

(F12a pondrá delante la portada del catálogo.) Regla de oro: **mejor sin foto que con la de otra especie.**
Son fotos **ilustrativas**, no evidencia: no entran en ninguna exportación (DwC-A, Plinian Core, hojas).

## Datos (`species_photos`, migración 012)
Una fila por especie, global (la comparten todos los catálogos). Solo especies de EIDOS sin foto de la Fototeca; las de
usuario (`biodex:`) no se buscan (su nombre puede no existir en iNat) y van directas a la silueta. Columnas en
`docs/db.md`. Estados:

| `status` | Significa | Se muestra |
|---|---|---|
| `pending` | Por consultar (recién dada de alta en un catálogo, o la consulta falló) | No |
| `ok` | Coincidencia exacta con licencia admitida, o sinónimo aceptado en la revisión | **Sí** |
| `review` | Coincidencia por sinónimo: la decide owner o custodio | No |
| `rejected` | Rechazada en la revisión: **no se vuelve a proponer** | No |
| `none` | Sin foto aceptable (sin coincidencia, ambigua, sin foto, licencia o URL no admitidas) | No |

## Licencias
Solo `cc0`, `cc-by` y `cc-by-nc` (`INAT_LICENSES` en `backend/Lib/inat.php`). `license_code: null` en iNat es «all
rights reserved» → `none`. Tampoco se admiten `cc-by-sa`, `cc-by-nd`, `cc-by-nc-sa`, `cc-by-nc-nd` (las «SA» obligarían
a licenciar igual lo que las contenga; las «ND» no admiten recortes). La atribución se guarda **tal cual** la da iNat
(«(c) faluke, some rights reserved (CC BY-NC), uploaded by faluke») y se enseña bajo la foto de la ficha:

> Foto: (c) faluke, some rights reserved (CC BY-NC), uploaded by faluke · CC BY-NC · [iNaturalist](https://www.inaturalist.org/photos/109833820)

En la tarjeta, por espacio, solo «iNaturalist · CC BY-NC». BioDex es un prototipo sin uso comercial; si eso cambiara,
habría que sacar `cc-by-nc` de la lista.

## Reglas de coincidencia (`inatDecide()`, función pura)
Consulta: `GET https://api.inaturalist.org/v1/taxa?q=<nombre de EIDOS sin autor>&is_active=true&per_page=10`, sin marcas de
rango en `q` (`inatQueryName()`: con «subsp.» iNat no encuentra nada).
Entran el nombre canónico de EIDOS, su rango, su reino y sus sinónimos de EIDOS (`species.synonyms`, con autor: se
quita con `inatStripAuthor()`). Sobre cada resultado:

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

### La trampa de las subespecies
EIDOS tiene las subespecies de *Capra pyrenaica* como **sinónimos** de la especie (`docs/eidos.md`). Al pedir «Capra
pyrenaica», iNat podría devolver «Capra pyrenaica victoriae», cuyo nombre sí es un sinónimo de EIDOS; pero es una
subespecie frente a una especie → `none`. Ver `subespecie-vs-especie.json`.

## Cuándo se busca
- Al **crear un catálogo** o **añadirle especies** (`catalogAddSpecies()` → `speciesQueuePhoto()`): se inserta
  `pending` si no hay fila. La petición nunca espera a iNat.
- `backend/bin/fetch-species-photos.php` procesa la cola a **1 petición/s** (timeout 8 s; 429, 5xx o red → reintentos
  a los 2, 5 y 15 s; si sigue fallando, la especie se queda `pending` para la próxima pasada):

  ```
  docker compose exec php php bin/fetch-species-photos.php [--limit=N] [--recheck-none-days=30] [--dry-run]
  ```

  - Antes de consultar pone en cola las especies de EIDOS de algún catálogo vigente sin fila (las de catálogos creados
    antes de F11b).
  - `--recheck-none-days=30` (por defecto): vuelve a mirar las `none` consultadas hace más de 30 días, porque iNat sube
    fotos nuevas. `0` = no volver a mirarlas. Las `rejected` no se vuelven a mirar nunca.
  - `--dry-run`: consulta y decide, pero no escribe.
  - Una especie que ya tiene foto de la Fototeca sale de la cola. Una foto nueva `ok` sube `content_seq` de los
    catálogos que la tienen: el móvil vuelve a bajar el paquete offline.
  - Dos ejecuciones a la vez no se pisan (`GET_LOCK` de MySQL). Al acabar imprime una línea de resumen:
    `BioDex fotos: 3 consultas a iNaturalist · ok 2 · review 0 · none 1 · errores 0 · con Fototeca 0 · 3 puestas en cola`.
- **Railway**: `docker/railway/entrypoint.sh` lo lanza en segundo plano tras las migraciones (y tras el seed de la demo
  si `SEED_DEMO=1`): no retrasa nginx ni el healthcheck. El resumen sale en los logs del despliegue.
- `User-Agent: BioDex/<VERSION> (+APP_URL)`. Solo el backend llama a iNat (como a EIDOS).

## Proxy `/img/inat/` (nginx)
Mismo patrón que `/img/fototeca/` (`docker/nginx/biodex-app.conf`): solo
`^/img/inat/[0-9]{1,12}/(square|medium|large)\.(jpe?g|png)$`, con guarda previa sobre `$request_uri` y `$uri`
(mayúsculas, `%`-codificados, `..` o parámetros → 400), `limit_except GET` (POST → 403) y `proxy_pass` fijo a
`https://inaturalist-open-data.s3.amazonaws.com/photos/<id>/<tamaño>.<ext>`. Host fijo e id numérico: no hay SSRF; el
bucket solo contiene fotos con licencia abierta. Cabeceras de S3 ocultas, caché `inat` de 30 días
(`docker/nginx/default.conf`) y `Cache-Control` nuestro; una foto inexistente → 404 sin el XML de Amazon. La CSP **no
cambia** (`img-src 'self' data:`). En Railway el `sed` del `Dockerfile` cambia también el `resolver` de este bloque (el
`grep` de comprobación falla si quedara alguno de Docker) y la imagen crea `/var/cache/nginx/inat`.

## Siluetas
- `frontend/img/silhouettes.svg`: sprite **propio y original**, un `<symbol id="sil-<grupo>">` por grupo en 48 × 48 y
  `currentColor`: planta con flor, helecho, gimnosperma, briófito, hongo, liquen, alga, ave, mamífero, reptil, anfibio,
  pez, insecto, arácnido, molusco, crustáceo, otro invertebrado y genérica (una lupa).
- `shared/species-groups.json`: reglas por reino/filo/clase/orden; gana la primera que casa (las específicas antes:
  líquenes antes que hongos, helechos antes que «Tracheophyta»). Sin regla → genérica. Las aplica `speciesSilhouette()`
  en `Lib/species.php`, también a las especies de usuario (clasificación de EIDOS o de GBIF).
- La API devuelve `silhouette {id, label}` en **todas** las especies (también las que tienen foto): el cliente solo
  pinta. `role="img"` y `aria-label="Sin foto · silueta de <grupo>"`.
- **Nunca una imagen rota**: si la foto no carga (sin red y sin copia, iNat o MITECO caídos), la tarjeta y la ficha la
  cambian por la silueta (`swapToSilhouette()` en `components/species-card.js`).
- Sin red: el sprite va en la precarga de la app (`SHELL_ASSETS`) y las fotos de iNat se guardan con el catálogo
  descargado, como las de la Fototeca (`offline.js`, `sw.js`).

## Revisión (owner y custodio)
- `GET /api/catalogs/{id}` lleva a owner y custodio `photoReviews`: las especies del catálogo con foto en `review`
  (nombre de EIDOS, nombre de iNat con enlace al taxón, foto del proxy y crédito).
- En Especies, el filtro **«Fotos por revisar (n)»** cambia la tabla por esa lista con **Aceptar** (→ `ok`, se muestra
  a todos) y **Rechazar** (→ `rejected`, no se vuelve a proponer).
- `POST /api/species/{taxonID}/photo-review {catalogId, decision: accept|reject}`: rol owner o custodio en ese catálogo
  (`review_photos` en `authz.php`) y la especie en él; si no hay foto en `review` → 409. La decisión es **global**: vale
  para todos los catálogos con la especie (sube su `content_seq`). Queda en `access_log` (`photo_review`: quién, cuándo,
  qué foto y qué decisión); el registro del catálogo lo enseña como «Foto de iNaturalist».
