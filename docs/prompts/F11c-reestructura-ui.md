# F11c · Reestructurar la interfaz: «estudio» en escritorio, «campo» en el móvil

> **Estado (2026-10-01): ejecutada** (en Cowork, sobre F11b commiteada). Diferencias con lo de abajo:
> - Ajustes ya no es un nivel de BioDexNav: con cuatro pestañas en el móvil es una sección más (el «atrás» de la raíz
>   pide confirmación para salir, como en las otras).
> - «Ver sus marcas en el mapa» desde la ficha (opcional) no se ha hecho.
> - Sensibilidad no necesita aviso de «cambios sin guardar» al cambiar de pestaña: cada fila se guarda por separado.
> - Capturas `f11c-*` pendientes (el navegador de prueba no guarda ficheros); el SW no se pudo probar en ese navegador.

Fase de ANTES del congreso (19-11). Requiere **F11b commiteada** (toca los mismos ficheros: `species.js`,
`species-detail.js`, `species-card.js`, `biodex.css`, `sw.js`). Solo frontend: **no se toca el backend ni la API**.
Lee `CLAUDE.md`, `frontend/index.html`, `frontend/js/app.js`, `frontend/js/nav.js` (cabecera entera: explica la pila de
«atrás»), `frontend/js/components/overlay.js`, `frontend/css/biodex.css` (índice de bloques al principio) y, de cada vista
que toques, solo su `mount()`/`open*()` y su plantilla. `docs/demo.md` al final, para ponerlo al día.

## Por qué
La app funciona, pero se lee pesada y no distingue los dos usos para los que existe:
- **Escritorio / tablet = estudio** (en casa): crear catálogos, especies y equipos; niveles, registro, evaluación UICN,
  exportar y revisar datos. Se espera un programa: mucha información a la vez, maestro-detalle, teclado.
- **Móvil = campo**: se llega con los deberes hechos (catálogo y zonas descargados). Mapa a pantalla completa,
  buscar una especie, «Marcar aquí» con una mano, saber si hay pendientes. Todo sigue disponible, pero la gestión pasa
  a segundo plano.

Problemas concretos (vistos en `docs/design/f10b-*.png`):
1. **Cabecera muy pesada.** Cartel verde con *eyebrow* + título en Zilla de 1,6 rem + filete de latón de 4 px. En el
   móvil el nombre del catálogo se corta («DEMO — Flo…») en cuanto aparece «2 pendientes». Todas las hojas repiten el
   mismo bloque verde: la app entera es verde y nada destaca.
2. **Catálogos mezcla tres trabajos** en una columna: elegir el activo, prepararlo para el campo y administrarlo
   (equipo, niveles, registro, exportar, editar, borrar). En escritorio: una tarjeta de 395 px y medio ancho vacío;
   todo lo demás apretado en un *aside* de 380 px. En el móvil el *aside* va debajo de la lista y la tapa.
3. **Dos «descargar» que no son lo mismo**: «Descargar para usar sin conexión» (llevar al campo) y «DESCARGAR DATOS»
   (exportar DwC). Se confunden.
4. **Mapa en el móvil**: capas + 4 botones en tres filas (~180 px) antes del mapa; «EOO / AOO» (análisis de despacho)
   ocupa sitio en el campo; leyenda y posición debajo roban más alto.
5. **No hay «volver»**: los paneles solo tienen «×» a la derecha. Los saltos entre pestañas (familia del árbol →
   Especies, «Ver en el mapa» desde la lista de marcas o la ficha) dejan al usuario sin camino de vuelta. El «atrás»
   del sistema sí funciona (BioDexNav), pero no se ve en pantalla.
6. **Botones que gritan**: `btn-secondary` en versalitas con borde de 2 px marrón en todas partes (FILTROS, VER COMO
   LISTA, ZONAS, UNIRME, NUEVO CATÁLOGO, DESCARGAR DATOS). Versalitas también en insignias, rótulos, pestañas y
   conexión: demasiadas voces a la vez.
7. **Especies en el móvil**: cinco campos de filtro antes del primer resultado.
8. **Ajustes** guarda cosas de campo (sincronización, zonas, pendientes) junto a perfil y apariencia, sin orden.

## Reglas (no negociables)
- Mismas rutas hash (`#/mapa`, `#/catalogos`, `#/especies`, `#/ajustes`, `#/unirse/<código>`) y misma lógica de datos:
  esto es disposición y piel, no funcionalidad. Nada de lo que hoy se puede hacer desaparece en ningún tamaño.
- Toda vuelta atrás pasa por **BioDexNav**: el botón de la app, Escape y el «atrás» del sistema hacen lo mismo.
- CSP: sin estilos ni scripts en línea. Sin dependencias nuevas. Fichero JS nuevo → `SHELL_ASSETS` de `sw.js` y sube
  `VERSION`.
- Accesibilidad: objetivos de 44 px (48 px en lo que se usa de pie: marcar, guardar, pestañas del móvil); foco
  visible; `aria-label` en los botones de solo icono; contraste ≥ 4,5:1 en claro y oscuro (tokens del bloque D).
- Tema oscuro: cada token nuevo se redefine en `:root[data-theme="dark"]`.
- Seguridad y offline intactos: no cambies qué datos se piden ni cuándo.

## 1. Modo por tamaño
Dos modos de disposición, decididos solo por CSS + una clase en `<body>` que pone `app.js` con `matchMedia`:
- `is-field` (< 900 px): campo.
- `is-studio` (≥ 900 px): estudio. Entre 900 y 1199 px (tablet apaisada) la barra lateral se pliega a iconos.
Con `pointer:coarse` en modo estudio se mantienen los 44 px (tablet táctil). La clase se recalcula al cambiar de
tamaño; las vistas que cambian de estructura (Catálogos, Mapa) se vuelven a montar con `show(current)` solo si cruzan
el umbral, no en cada `resize`.
Ruta por defecto (sin hash): **mapa** en campo, **catálogos** en estudio.

## 2. Armazón
### Campo (móvil)
- **Barra superior compacta** (52 px + `safe-area`), fondo `--paper`, línea inferior de 1 px; el verde de relleno
  queda solo como franja de 3 px arriba (marca de la casa). Contenido: selector de catálogo (nombre en Public Sans 600,
  1 rem, con «▾»; abre la hoja «Cambiar de catálogo»), y a la derecha la pastilla «n pendientes» (si hay) y el punto
  de conexión. Sin *eyebrow*.
- **Barra inferior con 4 pestañas**: Mapa · Especies · Catálogo · Ajustes (el engranaje sale de la cabecera). Ojo:
  en singular, «Catálogo» = el activo.
- Las bandas de guarda (sin conexión, sesión, versión nueva) se quedan, más finas: una línea, 13 px, sin filete de 4 px.

### Estudio (escritorio / tablet)
- **Barra lateral fija** a la izquierda (232 px; 72 px solo iconos entre 900 y 1199 px, con `title` y `aria-label`):
  logotipo BioDex arriba, selector de catálogo activo, navegación vertical (Catálogos, Especies, Mapa; Ajustes abajo),
  y al pie el estado (conexión + pendientes + «Sincronizar ahora»).
- El contenido ocupa el resto, sin `max-width` de 1280 px en Catálogos, Especies y Mapa (sí en Ajustes, ~880 px).
- Sin cartel verde: el título de cada página es el `page-head` de la vista.

### Selector de catálogo (nuevo, `components/catalog-switcher.js`)
Un único componente que usan las dos cabeceras: lista de catálogos (de `localCatalogs()` sin red) con rol, nº de
especies y «Sin conexión ✓»; elegir uno llama a `setActiveCatalog`. Campo: hoja inferior (nivel de BioDexNav
`cambiar-catalogo`). Estudio: menú desplegable anclado al selector. Al final, enlace «Gestionar catálogos» → Catálogos.

## 3. Volver atrás
### 3.1 Paneles (`overlay.js`)
- Nueva opción `back` (por defecto `auto`). Regla:
  - Campo, variantes `side` y `full` → botón **«←» a la izquierda** del título (patrón nativo), y se quita la «×».
  - Estudio, `side` → «×» a la derecha, como hoy. Estudio, `full` → «← Volver» a la izquierda con texto.
  - Cualquier panel abierto **encima de otro panel** → «←» con `aria-label="Volver a «<título del de abajo>»"` (el
    título se lee del `.sheet-title` del overlay anterior).
  - `dialog` → sin cambios.
  Las dos formas llaman al mismo `close()`; no hay caminos nuevos.
- Cabecera de panel más ligera: fondo `--paper`, título en `--ink`, línea inferior de 1 px. El verde de relleno solo en
  la hoja de marca (es la acción principal del campo) y como franja de 3 px.

### 3.2 Saltos entre pestañas
Nuevo en `app.js`: `go(route, { returnTo })`, con `returnTo = { label, run }`. Pinta, encima de la vista, un enlace
`.back-link` «← Volver a <label>» y abre un nivel de BioDexNav `retorno`, de modo que el «atrás» del sistema hace lo
mismo. Ir a otra pestaña a mano lo descarta (`Nav.close('retorno')` silencioso, igual que se hace hoy con Ajustes).
Usarlo en:
- Árbol taxonómico → Especies filtradas por familia: «← Volver a <catálogo>».
- «Ver en el mapa» desde la lista de marcas → Mapa: «← Volver a la lista de marcas» (la reabre).
- «Ver en el mapa» / «Marcas» desde la ficha de especie → Mapa: «← Volver a la ficha de <especie>» (la reabre).
- Lo que haya en el código que llame a `go()` desde dentro de un panel (búscalo: `grep -n "go('" frontend/js`).

### 3.3 Dentro de flujos largos
- Asistente UICN (`iucn-wizard.js`): pie fijo con «← Anterior» y «Siguiente →» (y «Ver resultado» en el último);
  los pasos A–E siguen siendo navegables directamente.
- Detalle de catálogo en estudio: migas «Catálogos / <nombre> / <pestaña>» en el `page-head`.

## 4. Catálogos
### Estudio: maestro-detalle a lo ancho
- Columna izquierda (300 px, desplazable): lista compacta de catálogos (nombre, rol, nº especies, «Activo»,
  «Sin conexión ✓»), y arriba «Nuevo catálogo» (primario) y «Tengo un código».
- Derecha: el catálogo elegido como **espacio de trabajo con pestañas horizontales**:
  **Resumen · Equipo · Sensibilidad** (owner) **· Registro** (owner) **· Exportar**.
  - Resumen en dos columnas: a la izquierda descripción, fila de cifras (especies, marcas, observadores, % evaluadas
    — solo las que ya traiga `summary`; no se piden datos nuevos), por categoría UICN y por grupo; a la derecha el árbol
    taxonómico. Acciones arriba: «Añadir especies» (primario), «Editar», menú «⋯» con «Borrar catálogo».
  - Sensibilidad, Registro y Exportar se pintan **dentro de la pestaña**, no en overlay. Para ello `openLevels`,
    `openAudit` y `openExport` se parten en `mountX(el, catalog, opts)` (pinta en un contenedor) + `openX()` (abre un
    overlay `full` y llama a `mountX` en su `body`). La lógica y el `isDirty`/confirmación de salir sin guardar se
    conservan: cambiar de pestaña con cambios sin guardar pregunta igual que cerrar el panel.
- Elegir un catálogo de la lista lo hace activo (como hoy) y lo muestra a la derecha.

### Campo: «¿Listo para el campo?»
La pestaña Catálogo del móvil muestra **el activo** y nada más arriba:
1. Tarjeta de estado: nombre, rol, nº especies y la preparación para el campo:
   - Sin descargar → botón primario de 48 px **«Llevar al campo»** + tamaño estimado.
   - Descargado → «✓ Listo sin conexión · <fecha>» y enlace «Actualizar copia».
   - Zonas de mapa descargadas: «n zonas» → abre Zonas en el mapa (con `returnTo`). Si 0: «Descarga una zona del mapa».
   - Pendientes de sincronizar, si hay.
2. «Cambiar de catálogo» (el mismo selector).
3. **Gestionar** (plegado, `<details>`): Añadir especies, Equipo, Sensibilidad, Registro, Exportar datos, Editar,
   Borrar. Cada uno abre su overlay `full` como hoy. Todo disponible; nada en primer plano.
Renombrar en toda la UI: «Descargar para usar sin conexión» → **«Llevar al campo»**; «Descargar datos» →
**«Exportar datos»** (y el título del panel «Exportar datos · <catálogo>»). Actualiza los textos de ayuda que los
citen y `docs/demo.md`.

## 5. Especies
- **Campo**: barra de búsqueda fija arriba + botón «Filtros (n)» que abre una hoja inferior con Grupo, Categoría,
  Nivel y Orden (reutiliza el patrón `.search-filters.is-open`). Fichas de filtro activas bajo la búsqueda.
  Filas compactas: nombre científico + común, insignia UICN principal, y a la derecha **«Marcar»** de 48 px.
- **Estudio**: la tabla de hoy, con cabecera y filtros fijos (`position:sticky`), la ficha en el panel lateral, y
  **↑/↓ con la ficha abierta pasan a la especie anterior/siguiente** de la lista filtrada (sin apilar niveles:
  `replaceTop`). «Añadir especies» y «Crear especie» en el `page-head`.

## 6. Mapa
- **Campo**: mapa a toda la altura entre las dos barras. Controles flotantes sobre el mapa:
  - Arriba a la izquierda, una fila de botones compactos de icono+texto: «Filtros (n)», «Lista», «Zonas».
  - Capas: un botón de icono (capas) que abre un menú pequeño con IGN Base / PNOA / MTN.
  - «EOO / AOO» no está en la barra del móvil: va dentro de la hoja de Filtros, en un apartado «Análisis».
  - Leyenda: botón «i» que la despliega; plegada por defecto.
  - Posición (lat/lon, UTM, precisión): una pastilla abajo a la izquierda con «±8 m»; tocarla despliega el resto.
  - «Marcar aquí» se queda como está (botón grande abajo a la derecha).
  `sizeWrap()` tiene en cuenta la nueva barra superior; el aviso de «servidor no disponible» va encima del mapa como
  banda fina, sin empujarlo.
- **Estudio**: barra de herramientas en una sola fila (capas a la izquierda; Filtros, Lista, Zonas, EOO/AOO a la
  derecha); la lista de marcas se acopla como panel a la derecha (360 px) en vez de tapar el mapa; leyenda en un
  recuadro flotante abajo a la izquierda que se puede plegar.

## 7. Ajustes
Orden nuevo de secciones: **Campo** (sincronización y pendientes, zonas descargadas, datos del dispositivo) ·
**Cuenta** (perfil, sesión) · **Apariencia** · **Aplicación**. En estudio, dos columnas; en campo, una y con
«Sincronizar ahora» arriba del todo.

## 8. Piel más ligera (`biodex.css`)
Se mantiene la identidad «ranger» (verde bosque, latón, Zilla Slab, esquinas rectas), pero con menos peso:
- **Botones**: `btn-primary` igual. `btn-secondary` pasa a borde de 1 px `--line-strong`, texto `--ink`, **sin
  versalitas**, peso 600, tamaño `--fs-sm`; hover con fondo `--surface-alt`. Nuevo `btn-quiet` (sin borde, para
  acciones terciarias de barra). `danger` igual, 1 px.
- **Versalitas solo** en: *eyebrows*, códigos UICN y rótulos de campo. Fuera de pestañas, insignias de rol y
  conexión (pasan a texto normal `--fs-xs` peso 600 en `--muted`).
- **Filetes laterales de 4 px solo donde codifican algo**: color UICN en especies y rol en catálogos (bajan a 3 px).
  Se quitan de fichas de filtro, `new-catalog`, `builder-selection`, `zone-panel` y bandas.
- **Sin paneles dentro de paneles**: `.panel` pierde el fondo `--surface` cuando va dentro de otro contenedor con
  borde; las secciones se separan con espacio y una línea de 1 px.
- **Escala de espaciado** como tokens (`--sp-1`…`--sp-6`: 4, 8, 12, 16, 24, 32 px) y úsala en lo que toques.
- Tipografía: Zilla Slab para títulos de página, títulos de panel y nombre de especie en la ficha. Títulos de fila
  (`.row-title`) y tarjetas en Public Sans 600 (más densidad en listas).
- Sombras: solo en lo que flota (paneles, menús, botones del mapa).
- Quita reglas que queden muertas tras la reestructuración (busca cada clase antes de borrarla).

## 9. Commits
Pequeños, prefijo `F11c:` y en este orden (cada uno deja la app funcionando):
1. `F11c: tokens de espaciado y piel más ligera (botones, versalitas, filetes)`
2. `F11c: modo campo/estudio y armazón nuevo (barra superior, barra lateral, 4 pestañas)`
3. `F11c: selector de catálogo`
4. `F11c: volver atrás en paneles y saltos entre pestañas`
5. `F11c: Catálogos maestro-detalle en estudio y «Listo para el campo» en móvil`
6. `F11c: Especies (filtros en hoja, ↑/↓ en la ficha)`
7. `F11c: mapa a pantalla completa en el móvil y barra en una fila en escritorio`
8. `F11c: Ajustes reordenado`
9. `F11c: demo.md y capturas`

## 10. Comprobaciones
Capturas por CDP (Edge headless, como en F10b) en **390×844**, **1024×768** (tablet, barra plegada) y **1440×900**,
en claro y oscuro, con las cuentas de la demo. Guárdalas como `docs/design/f11c-*.png` y compáralas con las `f10b-*`.
- [ ] Sin errores en consola en ninguna vista; `node shared/*.test.js` en verde.
- [ ] Recorrido entero de `docs/demo.md` en las dos ventanas, con los textos nuevos («Llevar al campo», «Exportar
      datos»).
- [ ] Atrás: en cada panel, el botón de la app, Escape y el «atrás» del navegador cierran lo mismo; en la raíz,
      «pulsa atrás otra vez para salir». Panel sobre panel: «← Volver a «X»» con la X correcta.
- [ ] `returnTo`: familia → Especies → «← Volver» devuelve a Catálogos con el mismo catálogo y pestaña; ir a otra
      pestaña a mano quita el enlace y el «atrás» no lo resucita.
- [ ] Salir con cambios sin guardar (constructor, niveles, hoja de marca) sigue preguntando, también al cambiar de
      pestaña del catálogo en estudio.
- [ ] Cruzar 900 px con la app abierta (redimensionar) no pierde el catálogo activo ni deja paneles huérfanos.
- [ ] Móvil: el mapa ocupa toda la altura útil; «Marcar aquí», capas, filtros, lista y zonas se alcanzan con el pulgar;
      EOO/AOO accesible desde Filtros › Análisis.
- [ ] Sin red (`docker compose stop nginx`): la cabecera, el selector de catálogo (desde el dispositivo) y la
      pestaña Catálogo («✓ Listo sin conexión») funcionan.
- [ ] Teclado en estudio: Tab recorre la barra lateral y el contenido en orden; ↑/↓ en la ficha; foco visible.
- [ ] Contraste ≥ 4,5:1 en los textos nuevos, en claro y oscuro.
- [ ] `sw.js`: ficheros nuevos en `SHELL_ASSETS`, `VERSION` subida, la app abre sin red tras actualizar.
