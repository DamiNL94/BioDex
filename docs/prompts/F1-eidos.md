# F1 · Cliente EIDOS y validación de la API

Lee `CLAUDE.md` y, de NaturDex, SOLO `../NaturDex/Tools/lib/eidos.js` (258 líneas): es un cliente
de EIDOS ya depurado en producción y sus comentarios documentan las trampas de la API. No leas nada
más de NaturDex. No toques `frontend/`, no crees tablas (eso es F2).

## Objetivo
1. Portar ese cliente a PHP (`backend/Lib/eidos.php`), con las mismas trampas resueltas.
2. Responder con evidencia las preguntas abiertas de abajo, para diseñar la tabla `species`, el
   buscador con filtros (F4) y los niveles de sensibilidad (F6).

## Qué ya sabemos (no reinvestigar)
- API PostgREST pública, sin auth: `https://iepnb.gob.es/api/especie/` y `/api/catalogo/`.
- Vistas: `v_taxonomia` · `v_estadoconservacion` · `v_estadolegal` · `v_nombrescomunes` ·
  `v_atributos_pliniancore` (descripción en el atributo «Descripción de la especie») · `v_imagenes`.
- Trampas: `v_taxonomia` usa `taxonid`, las de detalle `idtaxon`, `v_imagenes` `id_taxon`; un
  filtro con columna errónea devuelve 200 con un OBJETO de error (normalizar siempre a array);
  nombres comunes en 4 lenguas (castellano o `null`, nunca otro idioma); desempate por rango entre
  filas ACEPTADAS; normas autonómicas con `ambito: "Nacional"` (nacional = ámbito Nacional Y `ccaa` vacío).
- Pausa de 150 ms entre peticiones; timeout de 10 s.

## Tarea
### 1. `backend/Lib/eidos.php`
Funciones puras (sin BD), con curl:
- `eidosRows(string $view, array $params, string $base='especie'): array`
- `eidosResolveTaxon(string $sci): ?array` — mismo criterio que `resolveTaxon()` de NaturDex.
- `eidosConservation(int $idtaxon): array` — UICN vigente por ámbito (`aplicaa`).
- `eidosLegal(int $idtaxon, ?string $ccaa='Andalucía'): array` — `vigentes`, `nacional`, `autonomico`.
- `eidosCommonName(int $idtaxon): ?string`
- `eidosDescription(int $idtaxon): ?string`
- `eidosImages(int $idtaxon): array` — URL + autor + licencia si existen.
- `eidosSearch(string $q, array $filters=[], int $limit=20, int $offset=0): array` — por nombre
  científico Y común; devuelve `taxonID`, nombre, nombre común, grupo, rango, categoría UICN más
  reciente. Aún sin caché.

### 2. Script de validación `backend/bin/eidos-spike.php`
Se ejecuta con `docker compose exec php php bin/eidos-spike.php`. Usa estas especies de prueba:
`Artemisia granatensis`, `Atropa baetica`, `Parnassius apollo`, `Lynx pardinus`, `Aquila adalberti`,
`Turdus merula` (trampa subespecie), `Capra pyrenaica` (trampa renumeración),
`Rosmarinus officinalis` (trampa sinónimo, hoy *Salvia rosmarinus*) e `Inventadus falsus` (no existe).
Guarda una muestra recortada (máx. 3 filas por vista) en `docs/eidos-samples/<especie>.json` y mide tiempos.

### 3. Preguntas que debe responder (en `docs/eidos.md`, tabla pregunta · respuesta · evidencia)
1. **Clasificación:** ¿qué vista/columnas dan reino, filo, clase, orden y familia? (árbol taxonómico y especies nuevas)
2. **Búsqueda:** ¿`like`/`ilike` sobre `name` y sobre `v_nombrescomunes.nombre_comun`? Latencia media,
   paginación (`limit`/`offset`) y total (`Prefer: count=exact`).
3. **Filtros EIDOS en servidor o en caché:** para grupo taxonómico (`taxonomicgroup`), origen (`origen`),
   ámbito marino/terrestre, marco legal (RD 139/2011, Directiva Hábitats, Directiva Aves, RD 630/2013,
   Reg. UE 1143/2014) y categoría (LC…CR, EX, RE, EW, DD, NE, FV, U1, U2, XX): ¿se puede filtrar con
   una consulta a la API o hay que filtrar sobre nuestra caché? Indica la consulta exacta que funciona.
4. **Imágenes:** columnas de `v_imagenes`, si la URL es pública y qué autoría/licencia trae (hay que citarla en la tarjeta).
5. **Descripción:** texto de «Descripción de la especie»: longitud típica, HTML o texto plano.
6. **Ficha web:** patrón de URL por `idtaxon` para el botón «más contenido» (compruébalo con un HEAD, espera 200).
7. **CORS:** `curl -I -H "Origin: http://localhost:8090"` a la API: ¿trae `Access-Control-Allow-Origin`?
8. **Sensibilidad:** literales exactos de `estadolegal` para CEEA «En peligro de extinción» y «Vulnerable»
   y de `categoriaconservacion` (para las reglas de `shared/sensitivity.json`).
9. **`rpc/obtenertaxonespornombre`:** ¿aporta algo que no den las vistas? Si no, no se usa.
10. **Especie inexistente:** qué devuelve cada función con `Inventadus falsus` (debe ser `null`/`[]`, sin excepción).

Cierra `docs/eidos.md` con **«Mapeo EIDOS → tabla species»**: cada columna prevista de `species`
(ver plan) con su vista y columna de origen, y las que EIDOS NO da.

## Criterio de hecho
- `docker compose exec php php bin/eidos-spike.php` termina sin errores y resume los 9 taxones.
- `docs/eidos.md` responde las 10 preguntas con evidencia; lo que no se pudo averiguar se marca «sin resolver».
- `docs/eidos-samples/` pesa menos de 200 KB en total.
- Commit: `F1: cliente EIDOS en PHP y validación de la API`.

Al terminar, resume en 10 líneas: respuestas clave, sorpresas y lo que cambia del plan.
