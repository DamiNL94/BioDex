# EIDOS — validación de la API (F1)

Cliente: `backend/Lib/eidos.php`. Evidencia reproducible: `docker compose exec php php bin/eidos-spike.php`,
que regenera `docs/eidos-samples/<especie>.json` (≤ 3 filas por vista) y `docs/eidos-samples/_evidencias.json`.
Medido el 2026-09-29 desde el contenedor `php` (Docker Desktop, Windows).

## Resultado de los 9 taxones

| Nombre pedido | Estado | taxonid | Aceptado en EIDOS | Nombre común | UICN España/Mundial | Legal vig./nac. | Desc. | Fotos | ms |
|---|---|---|---|---|---|---|---|---|---|
| Artemisia granatensis | ok | 9918 | Artemisia granatensis Boiss. | Manzanilla de la sierra | CR / EN | 10 / 3 | 413 | 1 | ~1650 |
| Atropa baetica | ok | 2723 | Atropa baetica Willk. | Belladona de Andalucia | EN / – | 11 / 3 | 568 | 0 | ~1750 |
| Parnassius apollo | ok | 10740 | Parnassius apollo (Linnaeus, 1758) | Apolo | LC / LC | 16 / 2 | 721 | 9 | ~1700 |
| Lynx pardinus | ok | 11943 | Lynx pardinus (Temminck, 1825) | Lince | CR / VU | 21 / 4 | 893 | 142 | ~1780 |
| Aquila adalberti | ok | 11014 | Aquila adalberti Brehm, CL, 1861 | Águila imperial ibérica | EN / VU | 17 / 2 | 914 | 27 | ~1760 |
| Turdus merula | ok | 11726 | Turdus merula Linnaeus, 1758 (no la subsp. cabrerae, 11725) | Mirlo común | NE / LC | 9 / 0 | 432 | 8 | ~1680 |
| Capra pyrenaica | ok | 12055 | Capra pyrenaica H. R. Schinz, 1838 | Cabra montés | NT / LC | 5 / 1 | 822 | 31 | ~1790 |
| Rosmarinus officinalis | ok | 7647 | Rosmarinus officinalis L. | Romero Blanco | – / – | 1 / 1 | 699 | 10 | ~1740 |
| Inventadus falsus | null | – | – | – | – | – | – | – | ~290 |

«UICN España» es la evaluación UICN vigente más reciente con `aplicaa = España`. Varias especies tienen además
`Península`, que es más reciente (p. ej. *Aquila adalberti*: Península EN 2021).

**Trampas de resolución** (`_evidencias.json → trampas_resolucion`):
- **Sinónimo invertido:** EIDOS mantiene *Rosmarinus officinalis* como ACEPTADO, y *Salvia rosmarinus Spenn.* es su
  sinónimo, al revés de lo que suponía el prompt de F1. Por eso `eidosResolveTaxon('Salvia rosmarinus')` devuelve `sinonimo` → 7647.
- **Renumeración:** *Capra pyrenaica victoriae* (y el resto de subespecies) son hoy SINÓNIMOS con el taxonid de la
  especie (12055). El resolvedor devuelve `sinonimo`, `rankMismatch: true`: se pidió una subespecie y se entrega la especie.
- **Subespecie:** el desempate por rango funciona: «Turdus merula» → 11726 (Species), no 11725 (subsp. cabrerae).
- **Trinomios incoherentes:** EIDOS escribe «Capra pyrenaica victoriae» sin marca, pero «Turdus merula subsp. cabrerae»
  con ella. Si un trinomio sin marca no da resultados, el resolvedor reintenta con «subsp.».
- **Filas huérfanas:** `v_taxonomia` tiene nombres con `taxonid = null` y `nametype = null`, como el duplicado
  «Lynx pardinus (Temminck, 1827)». Se descartan.

## Preguntas

| # | Pregunta | Respuesta | Evidencia |
|---|---|---|---|
| 1 | **Clasificación** | Todo sale de `v_taxonomia`, en la fila del nombre aceptado: `kingdom`, `phylum`, `class`, `ordertaxonomic` (orden) y `family`. Cada nivel trae además su id (`kingdomid`, `phylumid`, `classid`, `ordertaxonomicid`, `familyid`, `genusid`), así que el árbol se puede montar por ids estables. `taxonomicgroup` es la agrupación divulgativa (13 valores + null). | `q1_clasificacion`: lince → Animalia › Chordata › Mammalia › Carnivora › Felidae. Valores de grupo en `catalogo/v_taxonomic_group_api`. |
| 2 | **Búsqueda** | `ilike` funciona sobre `v_taxonomia.name` y sobre `v_nombrescomunes.nombre_comun`; `like` distingue mayúsculas. **`ilike` NO ignora acentos**: «aguila imperial» y «águila imperial» dan resultados distintos. `name` incluye el autor, así que «romero» encuentra *Agrostis … Romero García*. `limit`/`offset` funcionan, y el total llega en `Content-Range: 0-2/18` con `Prefer: count=exact`. Latencia: ~300 ms por consulta; `eidosSearch` (4 consultas + pausa) ~1,5 s de media. | `q2_busqueda` (`comun_sin_acento` ≠ `comun_con_acento`, `paginacion_offset` total 18), `q2_eidosSearch` (media 1532 ms). |
| 3 | **Filtros: servidor o caché** | **En servidor, combinables con el nombre:** grupo `taxonomicgroup=eq.Aves`; origen `origen=eq.Especie nativa` (valores: `Especie nativa`, `Especie no nativa`, `Desconocido`); ámbito `areacode=eq.T` (T terrestre, M marino, MT ambos; no hay C). **Marco legal:** cada norma se filtra por separado en `v_estadolegal?norma=ilike.*139/2011*&idvigente=eq.1&select=idtaxon`, pero NO se combina con `v_taxonomia`: el embedding da HTTP 300 («more than one relationship»). La vista `catalogo/v_listapatronespecie_normas` sí combina norma + nombre + grupo en una sola consulta (`Normativa=ilike.*139/2011*&ScientificName=ilike.*aquila*`, columnas con espacios entrecomilladas: `"Grupo taxonómico"=eq.Aves`), pero solo cubre la Lista Patrón. **Categoría:** `v_estadoconservacion?categoriaconservacion=like.CR *&vigencia=eq.Vigente` filtra filas, no «la categoría vigente más reciente», porque hay varias vigentes por taxón. **Conclusión:** grupo, origen y ámbito en servidor; norma y categoría sobre nuestra caché (F4). | `q3_filtros`: Aves 2834; Aves+aquila 14; nativa 52003; T/M/MT 75242/21688/78; RD 139/2011 1052 filas; Hábitats (92/43) 670; Aves (2009/147) 415; RD 630/2013 972; Reg. UE 1143/2014 37; `embedding_vistas` error; `patron_normas_combinado` 3. `q3_categorias`: totales por código. |
| 4 | **Imágenes** | `v_imagenes`: `id_taxon` (TEXTO), `idnombre_acept`, `nombre_aceptado`, `id_foto`, `es_prioridad`, `descripcion`, `ruta_foto`. La URL llega **sin esquema** (`www.miteco.gob.es/content/dam/fototeca/…jpg`); es pública por https y por http (200, `image/jpeg`). **No trae autor ni licencia**, así que la tarjeta citará «Fototeca MITECO» (condiciones de reutilización: sin resolver). Cobertura muy desigual: 0 a 142 fotos por especie. | `q4_imagenes`. |
| 5 | **Descripción** | Atributo «Descripción de la especie» de `v_atributos_pliniancore` (`valor_atributo`). **Texto plano**, sin HTML ni saltos de línea, de 400 a 900 caracteres en los 8 taxones. | `q5_descripcion`. |
| 6 | **Ficha web** | `https://iepnb.gob.es/areas-tematicas/especies-silvestres/eidos/{idtaxon}`. El slug que usa la web (`/11943/lynx-pardinus-temminck-1825`) es opcional. HEAD → 200. Ojo: un idtaxon inexistente **también da 200** (página vacía de ~37 KB), así que un HEAD no sirve para validar. | `q6_ficha`: con id real, 114 KB y 58 menciones de «pardinus»; con 999999999, 37 KB y 0 menciones. |
| 7 | **CORS** | Sí: `Access-Control-Allow-Origin: *` y expone `Content-Range`. Aun así, por convención (docs/convenciones.md), EIDOS solo se llama desde el backend. | `q7_cors`. |
| 8 | **Literales de sensibilidad** | `estadolegal` (RD 139/2011): **`CEEA: En peligro de extinción`**, **`CEEA: Vulnerable`**, `LESRPE: En régimen de protección especial`. Las normas autonómicas usan literales propios (`En Peligro de Extinción`, `Vulnerable`…), así que hay que filtrar además por norma o `ccaa`. `categoriaconservacion`: `LC (Preocupación menor)`, `NT (Casi amenazado)`, `VU (Vulnerable)`, `EN (En peligro)`, `CR (En peligro crítico)`, `RE (Extinto regionalmente)`, `DD (Datos insuficientes)`, `NE (No evaluado)`; Art. 17: `FV (Favorable)`, `U1 (Desfavorable-inadecuado)`, `U2 (Desfavorable-malo)`, `XX (Desconocido)`. **Literales sucios:** `EW ("Extinto en estado silvestre)` (comilla suelta) y `EX (RE) (Extinto a nivel Regional)\r\n` (autoridad «Otras»). Las reglas deben usar el **código** (`eidosCategoryCode()`: primer token), nunca el literal. | `q8_literales_legales`, `q8_literales_lesrpe`, `q8_literales_uicn`; catálogo completo de literales en `catalogo/v_idestadolegal_api` (293 distintos). |
| 9 | **`rpc/obtenertaxonespornombre`** | No aporta nada: devuelve las mismas columnas que `v_taxonomia` con otros nombres (`origin`, `order`, `cites_code`…) e incluye las filas huérfanas. **No se usa.** Las vistas permiten `select`, filtros y paginación. | `q9_rpc`: 2 filas para «Lynx pardinus», una con `taxonid: null`. |
| 10 | **Especie inexistente** | `eidosResolveTaxon` → `null`; con un idtaxon inexistente, `eidosConservation` → `[]`, `eidosLegal` → cuatro listas vacías, `eidosCommonName`/`eidosDescription` → `null`, `eidosImages` → `[]`, `eidosSearch` → `{total:0, items:[]}`. Ninguna lanza excepción. Solo lanza `EidosException` un error de programación (columna errónea). | `q10_inexistente`. |

### Cambios respecto a lo que sabíamos
- **Columna errónea:** hoy PostgREST responde **HTTP 400** («column v_imagenes.idtaxon does not exist»), no 200 con un
  objeto. `eidosRequest()` lanza en los dos casos: HTTP ≥ 400 o cuerpo que no sea lista.
- **Varias UICN «Vigente» por ámbito:** el lince tiene España EN-1992 y CR-2007, las dos «Vigente». Hay que
  quedarse con el `anio` mayor (ya lo hace `eidosConservation`).
- **Nombre común preferente discutible:** para *Rosmarinus officinalis* sale «Romero Blanco» (dato de EIDOS). En F2
  convendrá permitir que el catálogo lo corrija.
- **Normas de Castilla-La Mancha con ámbito «Nacional»:** confirmado en el lince (Ley 9/1999, `ccaa` relleno).

## Búsqueda sin acentos (F2b)

Probado el 2026-09-29 contra `v_nombrescomunes` (castellano):
- **Comodín `_` de LIKE** (`aguila` → `ilike.*_g__l_*`): funciona, pero con demasiado ruido: 546 nombres
  («Pata de gallo», «Aulaguilla»…). Con el tope de 200 candidatos por vía, *Aquila adalberti* podía quedar fuera.
- **Elegida: `imatch` con clases de acentos.** `eidosAccentPattern()` quita los acentos de la consulta y cambia cada
  vocal por su clase (`a` → `[aáàâä]`, `n` → `[nñ]`, `c` → `[cç]`); el resto de caracteres especiales de regex se
  descarta. «aguila» → `imatch.[aáàâä]g[uúùûü][iíìîï]l[aáàâä]`: 20 nombres, todos águilas y «helecho águila»; «aguila»
  y «águila» dan el mismo total. Coste: una consulta, ~250 ms, igual que `ilike`. Se usa en las dos vías
  (científico y común). El orden (primero lo que empieza por la consulta) compara con `eidosFold()`, sin acentos.
- La caché (`species`) no lo necesita: la colación `utf8mb4_0900_ai_ci` ya ignora acentos.

## Mapeo EIDOS → tabla `species`

Columnas previstas (propuesta para F2; nombres Darwin Core donde existen). Origen: vista.columna, siempre de la fila
aceptada salvo que se indique otra cosa.

| Columna `species` | Origen EIDOS | Nota |
|---|---|---|
| `taxonID` | `v_taxonomia.taxonid` | Como texto; `biodex:<uuid>` si `source='user'`. |
| `source` | — (EIDOS no lo da) | `'eidos'` \| `'user'`. |
| `scientificName` | `v_taxonomia.name` | Incluye el autor. |
| `scientificNameAuthorship` | `v_taxonomia.scientificnameauthorship` | |
| `canonicalName` | — (se deriva) | `genus` + `specificepithet` [+ marca + `infraspecificepithet`]: para buscar sin autor. |
| `taxonRank` | `v_taxonomia.taxonrank` | `Species`, `Subspecies`, `Variety`, `Genus`… |
| `kingdom` / `phylum` / `class` / `order` / `family` | `v_taxonomia.kingdom` / `phylum` / `class` / `ordertaxonomic` / `family` | Ids estables en `*id`. |
| `genus` / `specificEpithet` / `infraspecificEpithet` | `v_taxonomia.genus` / `specificepithet` / `infraspecificepithet` | |
| `taxonomicGroup` | `v_taxonomia.taxonomicgroup` | Filtro del buscador. |
| `establishmentMeans` (origen) | `v_taxonomia.origen` | Nativa / no nativa / desconocido. |
| `habitatScope` (terrestre/marino) | `v_taxonomia.areacode` | T / M / MT. |
| `vernacularName` | `v_nombrescomunes.nombre_comun` (castellano, `espreferente` primero) | null si no hay castellano. |
| `iucnCategory` / `iucnScope` / `iucnYear` / `iucnCriteria` | `v_estadoconservacion.categoriaconservacion` (código), `aplicaa`, `anio`, `criterios` | UICN vigente más reciente; prioridad de ámbito a decidir (España/Península frente a Mundial). |
| `habitatsDirectiveStatus` | `v_estadoconservacion` con autoridad «Art. 17 (Dir. Hábitats)» | FV/U1/U2/XX. |
| `legalStatus` (JSON) | `v_estadolegal` (vigentes: `estadolegal`, `norma`, `ambito`, `ccaa`, `observacionespoblacion`) | Nacional = ámbito Nacional y `ccaa` vacío. Base de `species_sensitivity`. |
| `description` | `v_atributos_pliniancore.valor_atributo` (atributo «Descripción de la especie») | Texto plano. |
| `images` (JSON) | `v_imagenes.ruta_foto` (+ `https://`), `id_foto`, `es_prioridad` | Sin autor ni licencia. |
| `eidosUrl` | — (se deriva) | `https://iepnb.gob.es/areas-tematicas/especies-silvestres/eidos/{taxonid}`. |
| `acceptedNameUsage` / sinónimos | `v_taxonomia` (filas del mismo `taxonid` con `nametype = Sinónimo`) | Para buscar por sinónimo (Salvia rosmarinus → 7647). |
| `fetchedAt` | — | Frescura de la caché (`species_cache`). |

**Lo que EIDOS NO da:** autor y licencia de las fotos; nivel de sensibilidad (se deriva en BioDex de `v_estadolegal` +
`shared/sensitivity.json`); código UICN normalizado (hay que parsear el literal); búsqueda insensible a acentos;
nombre canónico sin autor; ningún dato de las especies creadas por el usuario.

## Sin resolver
- Condiciones de reutilización (licencia) de las fotos de la Fototeca MITECO.
- ~~Qué ámbito UICN mostrar cuando España y Península discrepan~~ → decidido en F2b: Península > España > Mundial
  (`eidosPrimaryIucn()`); se guardan todas en `iucn_assessments`.
- Los géneros (`taxonrank = Genus`) de `v_taxonomia` son filas huérfanas (`taxonid` null), pero con clasificación
  válida: `eidosGenusClassification()` la usa para las especies de usuario.
