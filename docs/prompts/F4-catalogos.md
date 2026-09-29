# F4 · Buscador, tarjetas de especie y constructor de catálogos

Requiere F3 hecha. Lee `CLAUDE.md`, `docs/api.md` y las firmas exportadas de `frontend/js/*.js` y
`frontend/js/views/*.js` (no los cuerpos enteros salvo el fichero que vayas a tocar). No leas NaturDex.
Nada de IndexedDB (F5), mapa (F7) ni marcas (F8). El buscador es **solo con conexión**: es la parte que
se hace en el ordenador antes de salir al campo.

## Correcciones de F3 (primero)
- **Nombre científico:** en cursiva solo el nombre (género, epíteto e infraespecífico); el **autor en
  redonda** (convención botánica y zoológica). Hoy sale todo en cursiva («*Aquila adalberti Brehm, CL, 1861*»).
  Usa `canonicalName` + `scientificNameAuthorship`; si la API no los devuelve en listados, añádelos.
  Crea un helper único `sciName(sp)` en `util.js` y úsalo en todas las vistas.
- Subir `APP_VERSION` a `0.4.0 · F4`.

## 1. Backend (cambios pequeños)
- `backend/bin/build-filters.php` → genera `shared/eidos-filters.json` con los valores reales de EIDOS:
  grupos taxonómicos (`catalogo/v_taxonomic_group_api`), orígenes, ámbitos (T/M/MT con etiqueta),
  marcos legales (`rd139`, `habitats`, `aves`, `rd630`, `ue1143` con su etiqueta) y categorías UICN y
  Art. 17 con su etiqueta en castellano. Se ejecuta a mano; el JSON se versiona.
- `GET /api/species/filters` → devuelve ese JSON (sesión, `Cache-Control: max-age=86400`).
- `POST /api/catalogs` acepta además `taxonIDs: []` y lo crea todo en una transacción (el constructor
  guarda de una vez). Cada especie recibe su nivel sugerido, como en `POST /{id}/species`.
- Pruebas nuevas en `backend/bin/test-f4.php` y `docs/api.md` actualizado.

## 2. Buscador de especies (`js/views/search.js`)
Se abre desde el constructor de catálogos y desde «Añadir especies» en un catálogo existente.
- Campo de búsqueda: mínimo 2 caracteres, **espera 400 ms** tras teclear y **cancela la petición anterior**
  (`AbortController`): EIDOS tarda ~1,5 s y no deben pisarse resultados.
- Filtros, con las opciones de `/api/species/filters`: grupo, origen, ámbito, marco legal y categoría
  (selección múltiple). En escritorio, columna lateral; en móvil, hoja desplegable «Filtros (n)».
  Los filtros activos se ven como fichas en versalitas que se quitan con un toque.
- Resultados en **tarjetas** (ver 3), paginación con «Cargar más» (`limit` 20). Si la respuesta trae
  `truncated` o elementos con `detailPending`, avisa en una línea: «Algunos filtros legales se están
  completando; vuelve a buscar en unos segundos».
- Estados: cargando (esqueleto de 3 tarjetas), sin resultados (con la salida a «Crear especie», ver 5),
  error de red, y **sin conexión** (buscador deshabilitado con aviso de guarda: «El buscador necesita
  conexión. Prepara tus catálogos antes de salir al campo»).

## 3. Tarjeta de especie (componente `js/components/species-card.js`)
Como en EIDOS, con la piel ranger:
- Imagen (`thumbnail`), `loading="lazy"`, `alt` con el nombre, pie en versalitas «FOTOTECA MITECO · VÍA EIDOS».
  Sin imagen: marcador sobrio con el grupo taxonómico (no una imagen rota).
- `sciName(sp)`, nombre común, grupo.
- Distintivos UICN **de todos los ámbitos** con su año (`CR · ESPAÑA 2007`, `EN · MUNDIAL`), el principal primero.
- Descripción recortada a 3 líneas y botón **«Más contenido»** → ficha de EIDOS
  (`eidosUrl`, `target="_blank" rel="noopener noreferrer"`).
- Acción: **«Añadir»** / **«Añadida ✓»** (y «Quitar» solo si el rol es owner).
- Pulsar la tarjeta abre la **ficha** (ver 6).

## 4. Constructor de catálogos (`js/views/catalog-builder.js`)
- «Nuevo catálogo» abre el constructor: nombre, descripción, buscador a la izquierda y **lista de la
  selección** a la derecha (en móvil, pestañas «Buscar · Seleccionadas (n)»).
- La selección es un borrador local (en memoria + `localStorage` para no perderla al recargar) hasta
  pulsar **«Guardar en Mis catálogos»** → `POST /api/catalogs` con `taxonIDs`. Al guardar, pregunta si
  se marca como catálogo activo.
- Salir con cambios sin guardar pide confirmación (diálogo propio, nunca `confirm()`).
- En un catálogo **existente**, «Añadir especies» usa el mismo buscador, pero cada «Añadir» se guarda
  al momento (`POST /{id}/species`), según rol (editor o superior).

## 5. Crear especie nueva (`js/views/species-new.js`)
Accesible desde «Sin resultados» (con el texto buscado precargado) y desde el constructor.
- Campos: nombre científico (sin autor), autoría, rango (Species · Subspecies · Variety · Form), nombre
  común opcional. Validación básica: binomio o trinomio, primera letra del género en mayúscula.
- `POST /api/species`. Si 201: muestra la clasificación obtenida y **su fuente** («Clasificación del
  género en EIDOS» o «Clasificación de GBIF»), con un aviso de guarda: «Esta especie no está en EIDOS;
  revisa la clasificación antes de usarla». Ofrece añadirla a la selección.
- Si 409: «Ya existe: *nombre*» con botón para abrir su ficha. Nunca crear duplicados.

## 6. Ficha de especie (`js/views/species-detail.js`)
Panel lateral en escritorio y hoja a pantalla completa en móvil (registrada en `BioDexNav` para el «atrás»).
- Galería de imágenes con su atribución; descripción completa; clasificación reino › … › familia.
- Tabla de **evaluaciones UICN** (código, ámbito, año, criterios) y **estado legal** (nacional y Andalucía,
  en versalitas: `CEEA · VULNERABLE`, `LESRPE`, `DIR. HÁBITATS · ANEXO II`…).
- **Nivel de sensibilidad** dentro del catálogo activo: todos lo ven; solo el owner puede cambiarlo
  (selector 1–4 con la precisión que implica: «Nivel 2 · se mostrará a ~10 km a quien no sea de
  confianza»). Si baja respecto al sugerido, pide motivo (el backend responde 422 sin él). Muestra el
  motivo y el autor del último cambio si lo hay.
- Enlace «Ver en EIDOS».

## 7. Pestaña Especies y datos del catálogo
- **Especies** (catálogo activo): filtro local por texto, grupo, categoría y nivel; orden por nombre,
  categoría (de CR a LC) o grupo. Tocar abre la ficha.
- **Datos del catálogo** (el panel lateral que ya existe en Catálogos): añade el **árbol taxonómico**
  (`GET /{id}/tree`) plegable reino › filo › clase › orden › familia, con recuentos; tocar una familia
  filtra la pestaña Especies por ella.
- Owner: renombrar, editar descripción y borrar catálogo (diálogo propio con el nombre escrito para confirmar).

## Reglas
- Todo HTML dinámico con `html```; nombres y descripciones vienen de EIDOS y de usuarios.
- Cada fichero JS nuevo va a `SHELL_ASSETS` y sube `VERSION` en `sw.js`; `check-sw.php` en verde.
- Accesibilidad: resultados navegables con teclado, foco atrapado en diálogos y devuelto al cerrar,
  objetivos táctiles ≥ 44 px, `aria-live` en el recuento de resultados.
- Sin dependencias nuevas.

## Comprobación
- `test-f2a.php`, `test-f2b.php`, `test-f4.php` y `check-sw.php` en verde.
- Como en F3, recorrido automático con Edge headless por CDP y capturas en `docs/design/`:
  1. Buscar «aguila» con filtro Aves → aparece *Aquila adalberti* con autor en redonda.
  2. Filtro `category=CR` + `legal=rd139` → resultados coherentes.
  3. Constructor: 3 especies + guardar → aparece en Mis catálogos con 3 especies.
  4. Crear *Inventadus falsus* → clasificación con fuente; repetir → 409 con enlace a la existente.
  5. Ficha de *Artemisia granatensis*: UICN de todos los ámbitos, legal, nivel 2 sugerido; bajar a 4 sin motivo → error visible.
  6. Árbol del catálogo y filtro por familia.
  7. Sin conexión: buscador deshabilitado con el aviso.
- Lista manual en `docs/prompts/F4-checklist.md` con el resultado de cada punto.

## Criterio de hecho
Todo lo anterior en verde. Commits `F4:`. Resume en 8 líneas.
