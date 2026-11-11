# Evaluación UICN (F8b, F11g, F11h)

Motor: `shared/iucn-engine.js` (`evaluateIucn(inputs, criteria)`, JS puro). Umbrales: `shared/iucn-criteria.json`, única
fuente; en el código no hay ninguno. nginx sirve **solo** esos dos ficheros en `/shared/` (sin copia en `frontend/`: la
misma fuente la leen el navegador y Node) y el SW los precachea. Pruebas: `node shared/iucn-engine.test.js`.
Asistente: `frontend/js/views/iucn-wizard.js`; en la ficha, «Evaluación BioDex» (`components/species-assessments.js`).

**BioDex propone y explica; decide el investigador.** La evaluación es de la especie en el ámbito del catálogo, no de
una marca. Aviso fijo: «Propuesta orientativa. Una evaluación formal requiere las Directrices de uso de las Categorías y
Criterios de la Lista Roja de la UICN.» No sustituye a las evaluaciones oficiales de EIDOS: se muestra aparte.

## Cómo se aplica cada criterio
- **A** · reducción (%) ≥ umbral de su subcriterio: A1 90/70/50; A2–A4 80/50/30. Hace falta al menos una base
  (a)–(e); A3 no admite (a) (se descarta con aviso). Periodo = el mayor de 10 años y 3 generaciones (A3/A4: ≤ 100 años);
  se muestra, y se supone que la reducción introducida es sobre ese periodo.
- **B** · B1 (EOO) y/o B2 (AOO) por debajo del umbral (< 100/5 000/20 000 y < 10/500/2 000 km²) **y** al menos 2 de
  (a), (b), (c) **con el umbral de la misma categoría**: (a) fragmentación severa (vale en cualquiera) o localidades
  ≤ 1/5/10; (b) disminución continua en (i)–(v); (c) fluctuaciones extremas en (i)–(iv). (b) y (c) no dependen de la
  categoría. Si EOO < AOO, EOO = AOO (con aviso). Ejemplo: EOO 80, AOO 12, 3 localidades, b(iii) → **EN
  B1ab(iii)+2ab(iii)**, no CR: con 3 localidades (a) solo se cumple en EN; con 1, **CR B1ab(iii)**.
- **C** · maduros < 250/2 500/10 000 y C1 (disminución ≥ 25/20/10 % en un plazo ≤ el mayor de 3/5/10 años y 1/2/3
  generaciones, tope 100) o C2 (disminución continua y a(i) ≤ 50/250/1 000 maduros por subpoblación, a(ii) ≥ 90/95/100 %
  en una subpoblación o (b) fluctuaciones extremas).
- **D** · maduros < 50/250/1 000 (`D` en CR y EN, `D1` en VU). **D2 solo VU**: amenaza plausible y AOO < 20 km² o
  ≤ 5 localidades (si faltan, se toman las de B).
- **E** · probabilidad ≥ 50/20/10 % con un horizonte ≤ el mayor de 10 años/3 gen., 20 años/5 gen. (tope 100) y 100
  años. Una probabilidad a un horizonte más corto cuenta también para uno más largo.

## Resultado
- Categoría = la más alta (CR > EN > VU) que cumple **cualquier** criterio. La cadena lista, en notación UICN, todos
  los criterios cumplidos en esa categoría: `EN A2c; B1ab(iii)+2ab(iii); C2a(i)`, `VU A2bc; D2`. Lo cumplido solo en
  categorías más bajas no entra en la cadena (se ve en el detalle por criterio).
- «La determina»: los criterios de la cadena. En cada paso, el resultado parcial y el porqué por CR/EN/VU.
- Sin criterio cumplido: «No alcanza umbrales de amenaza: valorar NT o LC (juicio experto)», con NT, LC o **DD**
  explícitos. Con un criterio cumplido, DD y NT/LC se ignoran con aviso.
- **Ajuste regional** (Directrices regionales): un escalón abajo o arriba en CR·EN·VU·NT·LC, con justificación
  obligatoria; se ve la categoría antes y después y la cadena es la de antes del ajuste.
- Guardar como **borrador** o **final** (una final necesita categoría). Solo el owner marca una final como
  «Evaluación de referencia del catálogo» (una por especie; `access_log` `set_reference`). Sync: `docs/sync.md`.

## Datos que BioDex sugiere (siempre editables, con su origen)
- C y D: suma de maduros registrados y máximo de individuos en un día (resumen de F8a), con el aviso de doble conteo.
- B: EOO y AOO desde las marcas del catálogo («Calcular» en el paso B; abajo, «EOO y AOO en BioDex»), con su origen
  (servidor con fecha o provisional) y tamaño de celda; si se cambian a mano, el origen lo dice.


## Limitaciones
- No sustituye a las Directrices de uso: no comprueba definiciones (localidad, subpoblación, fragmentación severa,
  fluctuación extrema), ni incertidumbre (rangos, mejor estimación), ni la calidad de los datos.
- Un solo valor por dato en el asistente de F8b (desde F11g la pestaña Evaluación UICN admite intervalos, y desde F11h
  A admite varios subcriterios a la vez con `A.items`).
- C1 y E comparan el plazo introducido con la ventana de cada categoría; sin duración de generación solo cuentan años.
  Con series (F11h), C1 usa la tasa anual y la ventana de cada categoría.
- La serie supone una tasa constante (log-lineal): no detecta un cambio de tendencia dentro de la ventana; mírese el
  gráfico. El índice depende de que los sitios representen a la población.
- El servidor guarda `inputs` y `result` tal como salen del dispositivo, sin recalcular (el motor es JS); valida la
  forma, los vocabularios y la coherencia mínima (final con categoría, ajuste con justificación).
- La AOO debe medirse en celdas de 2×2 km para compararla con B2 (`criteria.B.metrics`).

## EOO y AOO en BioDex (F9)
Cálculo: `shared/geo-metrics.js` (dispositivo) y su port `backend/Lib/metrics.php` + `Lib/utm.php` (servidor), con los
mismos casos: `shared/metrics-cases.json` (`node shared/geo-metrics.test.js` · `bin/test-f9.php`, ±0,01 km²).
- **EOO**: área del polígono convexo mínimo de los registros (cadena monótona de Andrew + fórmula del lazo), en km².
  Con menos de 3 puntos no colineales no hay EOO. Si EOO < AOO → EOO = AOO, con aviso.
- **AOO**: celdas ocupadas × área de la celda. Celda de **2 × 2 km** por defecto; 1 km con aviso (B2 está calibrado
  para 2 km). Se guarda siempre el tamaño usado. Rejilla anclada al origen UTM: celda `floor(x / tamaño)`, la misma
  que la cuadrícula UTM nacional; informativo, mínimo y máximo desplazándola medio tamaño en x, en y y en ambos.
- Todo en **ETRS89 / UTM 30N (EPSG:25830)**; un registro fuera de 6° O–0° se proyecta igual, con aviso de deformación.
- **Qué cuenta**: presencias no borradas del catálogo, del equipo o solo propias; fuera las ausencias y las de
  precisión peor que la celda (se dice cuántas). El nº de **localidades no se calcula**: lo decide el investigador.
- **Definitivo = servidor** (`GET …/species/{taxonID}/metrics`, `docs/api.md`), con las coordenadas exactas. Se
  guarda en `species_metrics` y se recalcula al pedirlo si hay marcas nuevas, editadas o borradas (`server_seq`).
- **Geometría según confianza** (regla de `applyVisibility`): owner, custodio, nivel 4 o ámbito «propias» → casco y
  celdas; resto en nivel 2–3 → solo cifras y las celdas de 0,1° / 0,01° con registros; nivel 1 → solo cifras. El casco
  nunca sale hacia quien no ve exactas: sus vértices son registros reales. El casco de exactas ajenas de una especie
  sensible queda en `access_log` (`view_exact`, «EOO/AOO <taxón>: n»).
- **Provisional sin red**: el dispositivo calcula con sus marcas **exactas** (las generalizadas no cuentan), rotulado
  «Provisional · N registros exactos en este dispositivo · el definitivo lo calcula el servidor», junto al último
  definitivo guardado con su fecha.
- Dónde se ve: ficha › «Distribución», mapa › «EOO / AOO» (especie elegida en Filtros) y asistente › paso B.

**Limitaciones.** Reflejan los registros del catálogo, **no la distribución completa de la especie**: sin prospección
exhaustiva la EOO y la AOO salen por debajo de las reales. Un editor sin red en una especie sensible solo cuenta
sus marcas. El casco convexo incluye zonas no habitables: así lo define la UICN y BioDex no las recorta.
La rejilla anclada puede variar la AOO (de ahí el rango). Pendiente: contrastar la referencia con shapely/pyproj
(`backend/bin/metrics-reference.py`); hoy es PROJ (`cs2cs`) + envoltura de Jarvis.

## Cuantificadores e incertidumbre (F11g)
La pestaña **Evaluación UICN** de la página de especie (`components/species-iucn.js`) sustituye al asistente: calcula
las entradas de B, C y D a partir de las marcas, los parámetros (`docs/parametros.md`), los impactos y las localidades
confirmadas, y pasa el motor. El asistente de F8b solo queda para editar las evaluaciones antiguas.

Código: `shared/iucn-quantifiers.js` (dispositivo, PROVISIONAL con las marcas exactas que tiene) y su port
`backend/Lib/iucn.php` (servidor, DEFINITIVO con las coordenadas exactas: `GET …/species/{taxonID}/assessment`,
`Lib/assessment.php`). El motor también tiene port en PHP (`evaluateIucn`, `assessIucn`). Casos compartidos en
`shared/iucn-cases.json` (cada umbral y su borde, intervalos que cruzan categorías, DD, CR(PE), ajuste regional,
combinaciones; enlace simple con distancias límite, deduplicación, bootstrap con semilla fija, fragmentación y
localidades): `node shared/iucn-engine.test.js` y `bin/test-f11g.php` dan lo mismo.

### Datos inciertos
Cada dato numérico del motor es `{best, min, max}` y lleva su calidad (observado · estimado · inferido · sospechado
· proyectado) y su fuente. `assessIucn()` evalúa tres veces: con el **mejor valor** (la categoría propuesta), con el
extremo **menos** amenazante de cada intervalo y con el **más** amenazante; esos dos dan el **rango plausible**
(«VU–EN»), como piden las Guidelines para datos inciertos (actitud ante el riesgo neutra: la propuesta es la del
mejor valor). Si los extremos caen en categorías distintas, se avisa.
- **DD**: si ningún criterio tiene los datos mínimos para evaluarse (todos «sin datos» o «incompletos»), sale DD;
  NT o LC a juicio experto solo se aceptan cuando algún criterio se ha evaluado y no alcanza umbrales.
- **CR(PE)**: nunca lo propone BioDex; solo si el usuario marca «posiblemente extinta» y la categoría es CR.
- La categoría final es la más alta de los criterios cumplidos; la cadena se normaliza («EN B1ab(iii)+2ab(iii)»,
  «VU D2»).

### Individuos maduros (`matureEstimate`)
- **Mínimo (observado)**: suma de `mature_count` del recuento más reciente por **celda de 50 m y día de campaña**
  (fecha UTC), dentro del **último año de datos**. Un recuento son las marcas del mismo autor a ≤ 60 s de la más
  reciente (el conteo por casillas de F11e guarda una marca por categoría en el mismo punto): sus maduros se suman.
  Aviso de posible doble conteo: dos recuentos a menos de 50 m en días distintos.
- **Mejor y máximo (estimados)**: con **parcelas** (marcas con superficie contada, DwC `sampleSizeValue` en m²; la
  más reciente por celda de 50 m), densidad media D = media(maduros / superficie), en maduros por km², con IC 95 %
  por bootstrap (1000 remuestreos, semilla 20261107, generador MINSTD de Park–Miller idéntico en JS y PHP; percentiles
  2,5 y 97,5). Mejor = máx(mínimo, D × H); máximo = máx(mejor, D(97,5) × H), con H = `occupied_habitat_km2`. Sin H,
  la AOO como cota superior: solo el máximo (D(97,5) × AOO), y se dice.
- Por subpoblación: el mínimo es el suyo; mejor y máximo, la estimación total repartida por su parte de celdas de la AOO.

### Subpoblaciones (`subpopulations`)
Enlace simple sobre las presencias en UTM 30N: dos registros a **≤** `separation_distance` van en la misma, y por
transitividad todo lo encadenado (sin parámetro, el valor por defecto del grupo, con aviso). Etiquetas S1, S2… de
oeste a este. De cada una: registros, maduros (mínimo, mejor, máximo), celdas de 2 km, casco (solo a quien ve
exactas) y distancia a la vecina más cercana (de borde a borde, km). C2a(i) = máximo de maduros en una subpoblación;
C2a(ii) = % de maduros en la mayor (solo si todas tienen recuento). En el mapa de estudio, capa «Subpoblaciones»
(`map/subpops-layer.js`): un color por subpoblación; a quien no ve exactas, un círculo en el centro generalizado.

### Fragmentación grave (`fragmentation`)
Subpoblación **pequeña** si sus maduros (mejor valor) < `min_viable_subpop`; **aislada** si su vecina más cercana está
a más de `dispersal_distance` (sin vecina: aislada). Grave si **más del 50 % de la AOO** (celdas de 2 km) está en
subpoblaciones pequeñas y aisladas; se muestra también el % de individuos. Sin alguno de los dos parámetros, «no
evaluable» y qué falta. Grave cuenta como (a) en B en cualquier categoría.

### Amenazas, impactos y localidades
- `shared/iucn-threats.json`: Threats Classification Scheme v3.3 (dos niveles, etiquetas en castellano), los botones
  rápidos del campo y los códigos que degradan el hábitat.
- **Impactos** (hoja «Impacto» del modo campo, sin red): amenaza, punto + radio o perímetro recorrido a pie,
  fecha, intensidad, alcance, temporalidad (pasado · en curso · futuro), nota y especies (por defecto, las del
  catálogo con marcas en el sitio). Se sincronizan como las marcas; su sitio sale con la regla de `applyVisibility()`
  y el nivel **más restrictivo** de sus especies.
- **Localidades definidas por amenaza** (`proposeLocations`): cada subpoblación va con el impacto en curso o futuro
  **más grave** que la toque (intensidad, luego alcance, luego en curso antes que futuro); las que comparten ese impacto
  forman una localidad. Las no afectadas, una cada una, «sin amenaza: localidad no aplicable». Es una **propuesta**:
  owner o custodio unen o separan, escriben la justificación y la confirman (`species_locations`, a `access_log`);
  solo el número confirmado entra en el motor.

### Declive continuo y fluctuaciones
b(i)–(v), c(i)–(iv) y la amenaza plausible de D2 son **hechos** sí · no · desconocido con calidad, fuente y
justificación (parámetros de tipo `fact`). BioDex **sugiere** b(iii) cuando hay impactos en curso sobre el hábitat de la
especie, pero la sugerencia nunca cuenta sola: alguien tiene que fijar el hecho. C2 usa b(v) como disminución continua y
c(iv) como fluctuación extrema. Desde F11h la serie temporal sugiere también b(v) y c(iv) (abajo).

## Series temporales: criterios A y C1 (F11h)
Código: `shared/iucn-series.js` (dispositivo: gráfico y provisional) y `backend/Lib/series.php` (definitivo, el mismo
cálculo). Casos: `shared/iucn-cases.json › series`, generados con `backend/bin/series-reference.py` (statsmodels 0.15 para
los casos con ruido; los de dos sitios sin ruido tienen solución analítica: r exacta, EE = 1/√Σ μ (t − t̄ₛ)²). Los
pasan `node shared/iucn-engine.test.js` y `bin/test-f11h.php`.

**Datos.** Solo visitas a un **sitio** (`docs/sync.md`): una marca suelta no mide reducción. Por visita, los maduros de
la especie (suma de `mature_count` de sus presencias; una ausencia, 0; si una presencia no tiene maduros, la visita no
cuenta). Con varias visitas a un sitio en un año, el **máximo**. Solo sitios con dos o más años y algún recuento > 0.
Año = el de `eventStart` en UTC.

**Modelo.** GLM de Poisson log-lineal con efecto sitio, ajustado por IRLS (como TRIM):
- índice anual: log μ = αₛ + γ_año; índice = Σₛ exp(αₛ + γ_año) (maduros esperados en el conjunto de sitios). Un año sin
  visitas es un **hueco** (sin índice); un año con todo a cero, índice 0;
- tendencia: log μ = αₛ + r·(año − media); cambio anual = eʳ − 1;
- cuasi-Poisson: φ = χ² de Pearson / (n − p), **con φ ≥ 1** (no se estrecha el IC por infradispersión);
  EE(r) = √(φ·[(XᵀWX)⁻¹]ᵣᵣ); IC 95 % = r ± 1,96·EE.

**Reducción (A).** Ventana T = máx(10 años, 3 generaciones), tope 100 (`generation_length`; sin ella, 10). Reducción =
1 − e^(r·T) con su IC (una subida cuenta como 0 %). Con menos de **3 años** con datos: «no evaluable». Si la serie
cubre menos que T, la parte no observada se marca **proyectada** y se dice qué fracción de la ventana está observada.
Subcriterios, por hechos (sí · no · desconocido con calidad y justificación, como los de B):
- `a_reversible`, `a_understood`, `a_ceased`: los tres «sí» → **A1**; si no, **A2** (pasado);
- `a_future` = sí (se espera que continúe) → además **A3** (proyección de la misma tasa; no admite (a)) y **A4** (ventana
  que abarca pasado y futuro). Con una tasa constante la reducción en cualquier ventana de T años es la misma, así que
  A3 y A4 dan la misma cifra que A2: cambian el umbral (A1) y la base admitida (A3);
- `a_basis_a` … `a_basis_e`: bases (a)–(e). Sin ninguna, A queda «datos incompletos».
El motor evalúa los subcriterios a la vez (`A.items`) y la notación los junta: «EN A2b+3b+4b».

**C1.** La tasa r con su IC entra como `C.c1AnnualRate`; para cada categoría el motor calcula la disminución en SU
ventana (3 años o 1 generación, 5 o 2, 10 o 3, tope 100): 1 − e^(r·W) ≥ 25/20/10 %, junto con los maduros de F11g.

**Sugerencias** (nunca se aplican solas): b(v) declive continuo cuando el IC de r queda entero por debajo de 0; c(iv)
fluctuación extrema cuando el índice anual varía más de ×10 (máx./mín.) dentro de la ventana o llega a 0.

**E** queda fuera de alcance: la tarjeta lo dice (haría falta un análisis de viabilidad poblacional con series largas).

### Ajuste regional (Directrices regionales v4.0, paso 2)
Cuatro preguntas (hechos `regional_*`): endémica del área → sin ajuste; si no, sin inmigración significativa de
propágulos (o no se sabe) → sin ajuste; si la hay y se espera que disminuya (o no se sabe) → sin ajuste; si no, ¿es la
población regional un sumidero? sí → **sube** un escalón; no o no se sabe → **rebaja** un escalón (efecto rescate). La
justificación sale del camino seguido. La cadena es la de antes del ajuste. Pendiente: cotejar el orden con la figura 2
de las Directrices regionales (`docs/limitaciones.md`).

### Comparación de fuentes (GBIF)
«Añadir registros de GBIF» (`?gbif=1`, `Lib/gbif-compare.php`): nombre de EIDOS → backbone de GBIF (misma regla que
las fotos, `gbifMatchDecide`), ocurrencias con coordenadas y sin problemas geoespaciales dentro del recuadro de las
marcas del catálogo (+0,25°), caché de 30 días en `gbif_occurrence_cache`. Se recalculan EOO, AOO, subpoblaciones y B
«solo BioDex» frente a «BioDex + GBIF», con los dos conjuntos en un mapa. **Nunca** se guardan como ocurrencias ni se
exportan. Una búsqueda de GBIF no tiene DOI (solo las descargas, que piden cuenta): se cita la búsqueda con su URL y fecha.

### Instantáneas
«Guardar evaluación» crea una evaluación (`species_assessments`, motor 2: `inputs.engine = 2`) con las entradas, el
resultado, un resumen de los cuantificadores, autor y fecha; sin red también. Al sincronizar, el servidor la
**recalcula** con el motor en PHP y guarda su resultado (`computedBy: server`). Cada una lleva su versión (v1, v2…) y
dos se comparan en una tabla. En la exportación: hojas «Evaluación por especie» y «Subpoblaciones» (`docs/dwc.md`).

## Referencias
- IUCN (2012). *IUCN Red List Categories and Criteria: Version 3.1*. Segunda edición. Gland y Cambridge: IUCN.
- IUCN Standards and Petitions Committee (2024). *Guidelines for Using the IUCN Red List Categories and Criteria.
  Version 16* (marzo de 2024). Confirmada en una copia de Artsdatabanken; falta verla en la biblioteca de la UICN.
- IUCN (2012). *Guidelines for Application of IUCN Red List Criteria at Regional and National Levels: Version 4.0*.
  Gland y Cambridge: IUCN (RL-2012-002).
- IUCN Threats Classification Scheme, versión 3.3 (sobre Salafsky et al. 2008, *Conservation Biology* 22: 897–911).
