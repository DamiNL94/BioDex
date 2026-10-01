# F8b · Asistente de evaluación UICN

Requiere F8a hecha. Lee `CLAUDE.md`, `shared/iucn-criteria.json`, `docs/sync.md` y las firmas de
`frontend/js/{marks,sync,db}.js` y `views/species-detail.js`. El PDF de la tabla UICN está en la raíz: **no lo
abras**, `shared/iucn-criteria.json` ya contiene sus umbrales.

## Principio
La evaluación es **de la especie en el ámbito del catálogo**, no de una marca. BioDex **propone** una categoría
a partir de los datos que introduce el investigador y explica por qué; la decisión es suya. Aviso fijo en la
pantalla: «Propuesta orientativa. Una evaluación formal requiere las Directrices de uso de las Categorías y
Criterios de la Lista Roja de la UICN.»

## 1. Motor (`shared/iucn-engine.js`, JS puro sin DOM, importable desde el navegador)
`evaluateIucn(inputs, criteria) → {category, criteriaString, byCriterion, warnings}`
- Lee umbrales de `shared/iucn-criteria.json` (sírvelo al frontend: cópialo en `frontend/data/` con un
  script o sírvelo por nginx; decide y documéntalo). **Nada de umbrales escritos en el código.**
- A: reducción (%) + subcriterio A1/A2/A3/A4 + bases (a)–(e) (A3 no admite (a)); periodo = máx(10 años, 3 generaciones).
- B: B1 (EOO) y/o B2 (AOO) + al menos 2 de (evaluados **con el umbral de cada categoría**, CR/EN/VU): (a) fragmentación severa o nº de localidades ≤ umbral,
  (b)(i–v) disminución continua, (c)(i–iv) fluctuaciones extremas. Si EOO < AOO, EOO = AOO (con aviso).
- C: individuos maduros + C1 (disminución % en su plazo) o C2 (a)(i) máx. por subpoblación, (a)(ii) % en una
  subpoblación, (b) fluctuaciones extremas.
- D: individuos maduros; D2 solo VU (AOO < 20 km² o localidades ≤ 5 con amenaza plausible).
- E: probabilidad de extinción (%) y horizonte.
- Categoría = la más alta que cumpla **cualquier** criterio. Cadena en notación UICN, p. ej.
  `EN B1ab(iii)+2ab(iii)` o `VU A2bc; D2`. Sin ningún criterio cumplido: «No alcanza umbrales de amenaza:
  valorar NT o LC (juicio experto)»; y opción explícita **DD** si faltan datos.
- **Ajuste regional** opcional (Directrices regionales de la UICN): bajar o subir un escalón con justificación
  obligatoria; se muestra la categoría antes y después del ajuste.
- **Pruebas** en `shared/iucn-engine.test.js` (con Node si está instalado; si no, una página de pruebas que se
  ejecute por CDP): ≥ 25 casos de tabla que cubran cada umbral en su borde (p. ej. EOO 99 → CR y 100 → EN en
  B1; 5 localidades → EN y 6 → VU en B(a)), A3 con (a) rechazado, B con un solo subcriterio → no cumple,
  D2 solo VU, cadenas de notación combinadas.

## 2. Datos y sincronización
Tabla `species_assessments`: `assessmentID` (UUID), `catalog_id`, `taxonID`, `user_id`, `inputs` (JSON),
`result` (JSON: categoría, cadena, detalle), `regional_adjustment`, `justification`, `status`
(`draft` · `final`), `client_updated_at`, `deleted_at`, `server_seq`. Tipo de entidad `assessments` en el push
y el pull de F8a (mismas reglas; solo el autor edita). Solo el **owner** puede marcar una como
«Evaluación de referencia del catálogo» (una por especie; queda en `access_log`).

## 3. Interfaz (`js/views/iucn-wizard.js`)
- Botón «Evaluar (UICN)» en la ficha de especie; funciona **sin red**.
- Asistente por pasos A → B → C → D → E, cada uno saltable («Sin datos para este criterio»). En cada paso,
  la tabla de umbrales CR/EN/VU de ese criterio visible y el resultado parcial en vivo.
- **Datos que BioDex puede sugerir** (siempre editables, con su origen indicado):
  - B: EOO y AOO desde las marcas del catálogo cuando exista F9 (deja el hueco preparado: «Disponible en F9»).
  - C/D: suma de maduros registrados y máximo en un día (del resumen de F8a), con el aviso de doble conteo.
- Pantalla final: categoría propuesta en grande, cadena de criterios, qué criterio la determina, avisos,
  justificación (texto), ajuste regional, guardar como borrador o final.
- En la ficha: la evaluación de referencia (si hay) y las del equipo, con autor y fecha. La categoría
  propuesta **no sustituye** a las oficiales de EIDOS: se muestra aparte, rotulada «Evaluación BioDex».
- `docs/iucn.md`: cómo se aplica cada criterio y las limitaciones (≤ 60 líneas).

## 4. Comprobación
Pruebas del motor en verde; `backend/bin/test-f8b.php` (sync de evaluaciones, autoría, referencia solo owner).
Recorrido CDP: evaluar *Artemisia granatensis* sin red con EOO = 80 km², AOO = 12 km², 3 localidades y
disminución continua (iii). Resultado esperado: **`EN B1ab(iii)+2ab(iii)`**, no CR: aunque la EOO está por
debajo de 100 km², con 3 localidades el subcriterio (a) solo se cumple en el umbral de EN (≤ 5; CR exige 1),
y B exige 2 subcriterios **en el umbral de la misma categoría**. Este caso tiene que estar también en las pruebas
del motor, junto con su variante de 1 localidad → `CR B1ab(iii)`. Guardar, sincronizar, verla en el otro perfil
y marcarla como referencia con el owner.
`VERSION`, `APP_VERSION` `0.8.1 · F8b`. Resultados en `docs/prompts/F8-checklist.md`. Commits `F8b:`. Resume en 8 líneas.
