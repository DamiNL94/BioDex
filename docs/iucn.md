# Evaluación UICN (F8b)

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
- B: EOO y AOO desde las marcas del catálogo → **F9** (hueco preparado en el paso B).

## Limitaciones
- No sustituye a las Directrices de uso: no comprueba definiciones (localidad, subpoblación, fragmentación severa,
  fluctuación extrema), ni incertidumbre (rangos, mejor estimación), ni la calidad de los datos.
- Un solo valor por dato: sin intervalos ni actitud ante el riesgo; A admite un solo subcriterio por evaluación.
- C1 y E comparan el plazo introducido con la ventana de cada categoría; sin duración de generación solo cuentan años.
- El servidor guarda `inputs` y `result` tal como salen del dispositivo, sin recalcular (el motor es JS); valida la
  forma, los vocabularios y la coherencia mínima (final con categoría, ajuste con justificación).
- La AOO debe medirse en celdas de 2×2 km para compararla con B2 (`criteria.B.metrics`).
