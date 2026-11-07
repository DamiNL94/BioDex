# Interfaz: modo campo y modo estudio (F11c, F11f)

BioDex tiene dos disposiciones según el ancho (`app.js`, `body.is-field` < 900 px ≤ `body.is-studio`):

- **Campo** (móvil): barra superior fina con el selector de catálogo, la conexión y los pendientes; **barra de
  pestañas inferior fija** con Mapa · Especies · Catálogo · Ajustes (icono y texto, respetando la zona segura).
  Se entra en el Mapa.
- **Estudio** (escritorio y tableta apaisada): barra lateral con la marca, el selector, la navegación y
  «Sincronizar ahora». Se entra en Catálogos.

F11f nace de la salida de campo a Sierra Nevada (15–16 oct): botones pequeños, costaba encontrar la especie al
marcar y moverse entre pestañas era incómodo.

## Tamaños (F11f)
Tokens en `css/biodex.css` (bloque A):

| Token | Campo | Estudio | Para qué |
|---|---|---|---|
| `--tap-min` | 48 px | 44 px | mínimo de cualquier control |
| `--tap-main` | 56 px | 48 px | la acción principal: guardar, botón flotante, casillas del conteo |
| `--tap-gap` | 8 px | 8 px | separación mínima entre dos objetivos |

`--tap-big` queda como alias de `--tap-main`. En campo la letra base es de **17 px** (`html.is-field`: los `rem`
escalan con ella). En estudio se conservan los tamaños de antes (`body.is-studio`).

- **Conteo** (la acción más repetida): «+1» de 88 px, «−» y la cifra de 64 px, cifra a 2,25 rem. «Deshacer» y
  «Cancelar» van separados de lo que cuenta y guarda (`--tap-gap` × 2 y × 3).
- **Contraste**: AA en toda la app y **AAA (7:1) en la hoja de marcar**, que se lee a pleno sol. Dentro de
  `.mark-sheet` se redefinen los tonos secundarios:

  | Tono | Claro | Razón sobre papel | Oscuro | Razón sobre papel |
  |---|---|---|---|---|
  | `--muted` | #4A4A43 | 8,9:1 | #C3BBA5 | 9,4:1 |
  | `--ink-soft` | #3A3A33 | > 10:1 | #E0D8C3 | > 12:1 |
  | `--warn-ink` (sobre `--warn-bg`) | #6B4511 | 7,6:1 | #F0C27A | 9,3:1 |
  | `--danger` | #7E3516 | 8,7:1 (crema encima: 8,2:1) | #F0A68F | 9,0:1 |
  | `--leaf` / `--leaf-fill` | (sin cambio) 9,7:1 | | #9FD1B3 / #1F4D34 | 10,5:1 / crema 9,1:1 |

- **Auditoría**: la prueba de Playwright (`frontend/tests/e2e/f11f.spec.mjs`) recorre Mapa, Filtros, Especies,
  la hoja de marcar (buscador y conteo), las cuatro pestañas de la ficha, Catálogo, el selector y Ajustes en
  390×844 y falla si algún elemento interactivo visible mide menos de 48 × 48 px. Se mide la etiqueta entera de
  casillas, radios y selectores de fichero; se exceptúan los enlaces dentro de una frase (excepción «en línea» de
  WCAG 2.5.8: atribuciones del mapa y créditos de foto).

## Encontrar la especie (F11f)
Un solo buscador (`components/species-picker.js` sobre `js/species-search.js`, sin DOM y probado con node) en la
hoja de marcar y en la pestaña Especies del modo campo:

- Encuentra por **nombre común**, **nombre científico** (en cualquier parte o por el principio de cada palabra,
  en orden: «lag pal») y por la **abreviatura 3+3** de género y epíteto (`pinnev` → *Pinguicula nevadensis*;
  3+3+3 con subespecie, sin «subsp.»; a medio escribir desde 4 letras). Sin tildes ni mayúsculas.
- Funciona **sin conexión**: la lista sale del catálogo de IndexedDB, y los recientes y «cerca», de las marcas
  guardadas en el dispositivo.
- **Orden por defecto**: recientes de este usuario (sus marcas, la más reciente primero, como mucho 8) → especies
  con registros a menos de **500 m** de la posición actual (si hay GPS; en la pestaña Especies solo si el permiso ya
  estaba concedido, para no saltar el aviso del navegador) → el resto, alfabético por nombre científico. Cada
  tramo lleva su rótulo. Con texto, primero lo que mejor casa (abreviatura exacta → principio de palabra → en
  medio) y, a igualdad, el mismo orden.
- **Chips de grupo**: las siluetas de `shared/species-groups.json` presentes en el catálogo (con su número);
  un toque filtra.
- **Filas** de 64 px con miniatura o silueta, nombre común y científico (cursiva solo en el nombre). Con **8
  especies o menos**, rejilla de tarjetas.
- En la hoja de marcar, elegida la especie, el buscador se pliega en una línea con «Cambiar» para que el conteo
  quede a la vista. No abre el teclado al entrar: los recientes se tocan directamente.
- En la pestaña Especies (campo), «Filtros» queda para Categoría y Nivel; el grupo va en los chips y el orden
  es el de arriba. En estudio sigue la tabla con sus filtros.

## Navegación (F11f)
- **Botón flotante «Marcar»** (`components/fab.js`) en Mapa y Especies, abajo a la derecha por encima de la
  barra de pestañas (a la altura del pulgar). En Especies abre la hoja con el buscador; en Mapa, con la última
  posición del GPS como siempre. En estudio, el del Mapa sigue flotando sobre el mapa.
- **Cada pestaña vuelve como se dejó**: texto y grupo del buscador (y el texto del filtro en estudio), filtros
  y encuadre del mapa los guardan las vistas; el desplazamiento lo guarda `app.js` por sección y lo restaura
  cuando la vista ha terminado de pintar.
- **«Atrás»** (botón del sistema en Android y del navegador): fuera de la sección de inicio (Mapa en campo,
  Catálogos en estudio) hay un nivel `seccion` en `BioDexNav`. Así, «atrás» cierra primero la hoja o el modal
  abierto, después el «← Volver a…» si lo hay y después vuelve a la sección de inicio; solo allí pide «pulsa
  atrás otra vez para salir». Ir de una pestaña a otra no apila niveles. Escape cierra hojas, pero no cambia de
  sección. Todo con el historial (`#/seccion` y entradas de `nav.js`), sin tocar la CSP.

## Página de especie (F11f)
La ficha pasa a ser la página de la especie **en el catálogo**, con pestañas (patrón ARIA, flechas, Inicio y
Fin; la elegida se recuerda al pasar de ficha con ↑/↓):

| Pestaña | Qué tiene |
|---|---|
| Resumen | Foto, nombres, categorías UICN oficiales, estado legal, nivel de sensibilidad, notas, clasificación y descripción |
| Registros | Minimapa de las marcas (IGN Base; propias cuadradas, del equipo redondas), la lista con su resumen y EOO/AOO |
| Parámetros | Características de la especie en el catálogo (`docs/parametros.md`) |
| Evaluación UICN | El asistente actual sin cambios (F11g lo sustituye) |

Fuera del catálogo activo (abierta desde el buscador de EIDOS) solo hay Resumen. En estudio es una página a
pantalla completa con «← Volver».

## Funciones ocultas tras un flag
`js/flags.js`, por dispositivo (`localStorage biodex.flags`, p. ej. `{"impact": true}` desde la consola):

| Flag | Qué enseña | Lo rellena |
|---|---|---|
| `impact` | Segundo botón flotante «Impacto», junto a «Marcar» (desactivado) | F11g |
