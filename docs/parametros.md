# Parámetros de especie

Base para F11g (cuantificadores UICN de B, C y D) y F11h (visitas y criterio A): características de una especie
**en un catálogo** que piden los criterios y que BioDex no puede deducir de las marcas. Dos equipos pueden usar
valores distintos para la misma especie, por eso son del catálogo y no de la especie.

Código: `backend/Lib/parameters.php` (claves y reglas), `backend/Api/catalogs.php` (rutas), migración
`014_species_parameters.sql`, `frontend/js/components/species-parameters.js` (pestaña Parámetros de la ficha).
Pruebas: `bin/test-f11f.php` y `frontend/tests/e2e/f11f.spec.mjs`.

## Claves

| Clave | Unidad | Tipo | Para qué | Bloquea si falta |
|---|---|---|---|---|
| `generation_length` | años | número (0,1–500) | ventana de 10 años o 3 generaciones | A, C1 |
| `separation_distance` | km | número | qué registros son subpoblaciones distintas | B (subpoblaciones), C2a |
| `dispersal_distance` | km | número | fragmentación grave | B (fragmentación) |
| `mature_definition` | — | texto (≤ 2000) | qué cuenta como individuo maduro; en clonales, ramet o genet | C, D |
| `min_viable_subpop` | individuos maduros | entero | fragmentación: subpoblación demasiado pequeña para ser viable | B (fragmentación) |
| `life_form` | — | lista: `perennial` perenne · `annual` anual · `biennial` bianual · `monocarpic` monocárpica · `other` otra | orienta la generación y cómo contar | — |
| `occupied_habitat_km2` | km² | número | maduros estimados = densidad de las parcelas × superficie | C, D (maduros estimados) |
| `decline_b_i` … `decline_b_v`, `fluct_c_i` … `fluct_c_iv`, `plausible_threat` | — | hecho: `yes` · `no` · `unknown` | disminución continua (b), fluctuaciones extremas (c), amenaza plausible de D2 | B, C2, D2 |
| `census_complete` | — | hecho | los recuentos del último año cubren TODOS los maduros: el total es observado (si no, solo estimado con parcelas suficientes; `docs/iucn.md` › «Calidad mínima») | C, D |
| `regional_endemic`, `regional_immigration`, `regional_immigration_decrease`, `regional_sink` | — | hecho | ajuste regional, paso 2 de las Directrices v4.0 | ajuste regional |

Los hechos llevan además `justification` (≤ 4000). Se fijan en la pestaña Evaluación UICN, no en Parámetros. Su
calidad cuenta: un hecho de disminución continua (b) o el b(v) de C2 con calidad *sospechada* no llega al mínimo de la
tabla 3.1 (*inferida*) y el motor no lo tiene en cuenta; sin calidad indicada, sí.

Cada valor guarda `value`, `value_min` y `value_max` (intervalo opcional: los dos o ninguno, y `value` dentro;
un número admite solo el intervalo), `quality` (vocabulario de las Directrices UICN: `observed` observado ·
`estimated` estimado · `inferred` inferido · `suspected` sospechado · `projected` proyectado), `source` (texto libre
o referencia bibliográfica, ≤ 1000), `set_by` y `set_at`. Las comas decimales se aceptan («1,5»).

Añadir una clave es añadirla a `SPECIES_PARAMETERS` (servidor) y a `PARAMETERS` (cliente): la columna es
`VARCHAR`, no hace falta migración.

## Valor por defecto: distancia de separación
`shared/species-groups.json` › `default_separation_km` da un valor por grupo (el de la silueta, misma regla que la
foto de respaldo): **1 km** para plantas vasculares, helechos, gimnospermas, briófitos, hongos, líquenes, algas,
moluscos, arácnidos y otros invertebrados poco móviles; **sin valor** (`null`) para insectos, crustáceos y
vertebrados, cuya movilidad varía demasiado dentro del grupo. Mientras nadie lo confirme, la ficha lo muestra como
**«Por defecto del grupo»** (no cuenta como «sin rellenar», pero se ve que no está confirmado) y owner y custodio
tienen «Confirmar 1 km», que lo guarda como valor estimado con la fuente «Valor por defecto del grupo».

## Permisos y registro
- Leer: cualquier miembro (`view`). Editar o dejar sin valor: **owner y custodio** (`set_parameter` en
  `Lib/authz.php`); editor y lector, 403.
- Cada cambio va a `access_log` con la acción `set_parameter` y `«taxonID»: «clave» antes → después` (el Registro
  del catálogo lo enseña con el nombre de la especie). Repetir el mismo valor no cuenta como cambio.

## Sin conexión
Se editan **con red** (como el nivel de sensibilidad). Cada cambio toma `server_seq` del contador común y baja por el
pull de `/api/sync` (`parameters`, con los borrados como tombstone, `docs/sync.md`) al almacén `parameters` de
IndexedDB. El cliente guarda además la respuesta del PUT en el dispositivo, así que se ve sin red aunque aún no haya
pasado el pull. Sin red, la pestaña dice «Copia del dispositivo: se editan con red» y no ofrece «Cambiar».
El valor por defecto del grupo sale entonces de `/shared/species-groups.json`, precacheado por el SW.
