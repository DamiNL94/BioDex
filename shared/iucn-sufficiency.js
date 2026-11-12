// ================================================================================================
// iucn-sufficiency.js — suficiencia de los datos para la evaluación UICN automática. JS puro, sin DOM.
// ------------------------------------------------------------------------------------------------
// Decide QUÉ CALIDAD tiene cada dato que BioDex calcula desde las marcas (observado · estimado · inferido…) y
// cuánto falta para que llegue al mínimo de la tabla 3.1 de las Directrices de la UICN (v16). El motor
// (iucn-engine.js) no da por cumplido un criterio con un dato de calidad inferior a la mínima.
// Port en PHP: backend/Lib/sufficiency.php. Casos compartidos: shared/sufficiency-cases.json
// (node shared/iucn-engine.test.js · bin/test-sufficiency.php). Bibliografía y reglas: bibliografia/suficiencia-de-datos.md.
//
//   normCdf(x), normInv(p)                      → normal estándar (BCa del bootstrap y potencia de las series)
//   samplingCompleteness(records, project)      → completitud del muestreo de la distribución (Chao2 sobre celdas
//                                                 de 2 km y días de muestreo) y ausencias confirmadas
//   rangeQuality(completeness)                  → calidad de EOO y AOO
//   seriesQuality(series)                       → calidad de la reducción (A) y de la tasa de C1
//   requirements(q, {series})                   → requisitos mínimos por grupo con su progreso (0–1), para las barras
//
// Reglas (cada umbral con su fuente; los marcados «BioDex» son convenciones de trabajo documentadas, no de la UICN):
//   · Rango plausible con intervalos del 90 % (Directrices v16 §3.2).
//   · Maduros OBSERVADOS: solo con censo completo declarado (hecho census_complete = sí; Iriondo 2011, AFA). ESTIMADOS:
//     densidad de ≥ 10 parcelas (≥ 15 si el coeficiente de variación de la densidad es > 1) × superficie de hábitat
//     ocupado (Elzinga et al. 1998, cap. 7). Lo demás es un mínimo: INFERIDO.
//   · AOO ESTIMADA: ≥ 5 días de muestreo y completitud Chao2 ≥ 90 % (BioDex; Colwell y Coddington 1994; la AOO por
//     recuento de celdas mide sobre todo el esfuerzo: Marsh et al. 2023). EOO ESTIMADA: además, con ≥ 20 celdas con
//     presencia basta (Syfert et al. 2014, citado por Marsh et al. 2023). Si no, INFERIDAS (cota inferior).
//   · Ausencia confirmada en una celda: ≥ 3 días de muestreo sin detectar la especie (MacKenzie y Royle 2005).
//   · Series: ≥ 3 años (ya en iucn-series.js); ≥ 5 grados de libertad para estimar la sobredispersión φ (BioDex) para
//     que la tasa sea ESTIMADA (si no, INFERIDA); potencia ≥ 0,8 para detectar la reducción del umbral de VU en la
//     ventana (Gerrodette 1987; White 2019): no decide la calidad, avisa (Connors et al. 2014).
// ================================================================================================

export const SUFFICIENCY = {
  level: 0.9,                 // nivel del rango plausible (IC 90 %)
  plotsBase: 10,              // parcelas para una densidad estimada
  plotsHighCv: 15,            // … si la densidad varía mucho
  cvHigh: 1,                  // CV de la densidad por encima del cual se piden plotsHighCv
  eooCells: 20,               // celdas con presencia para un EOO estable
  completenessPct: 90,        // completitud Chao2 para una AOO estimada
  minOccasions: 5,            // días de muestreo para que Chao2 signifique algo
  absenceOccasions: 3,        // días sin detección para una ausencia confirmada
  seriesYears: 3,             // = MIN_YEARS de iucn-series.js
  seriesDf: 5,                // grados de libertad para φ
  power: 0.8,                 // potencia objetivo
};
const AOO_CELL_M = 2000;

const round = (v, d = 0) => { const f = 10 ** d; return Math.round(v * f) / f; };
const toNum = (v) => (v === null || v === undefined || v === '' ? null : (Number.isFinite(Number(v)) ? Number(v) : null));

/** Φ(x), función de distribución normal estándar (Abramowitz y Stegun 26.2.17; error < 7,5·10⁻⁸). */
export function normCdf(x) {
  const t = 1 / (1 + 0.2316419 * Math.abs(x));
  const d = 0.3989422804014327 * Math.exp(-x * x / 2);
  const p = d * t * (0.319381530 + t * (-0.356563782 + t * (1.781477937 + t * (-1.821255978 + t * 1.330274429))));
  return x >= 0 ? 1 - p : p;
}

/** Φ⁻¹(p), cuantil de la normal estándar (algoritmo de Acklam; error relativo < 1,2·10⁻⁹). */
export function normInv(p) {
  if (p <= 0) return -Infinity;
  if (p >= 1) return Infinity;
  const a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
  const b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01];
  const c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
  const d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];
  const lo = 0.02425;
  if (p < lo) {
    const q = Math.sqrt(-2 * Math.log(p));
    return (((((c[0] * q + c[1]) * q + c[2]) * q + c[3]) * q + c[4]) * q + c[5]) / ((((d[0] * q + d[1]) * q + d[2]) * q + d[3]) * q + 1);
  }
  if (p > 1 - lo) {
    const q = Math.sqrt(-2 * Math.log(1 - p));
    return -(((((c[0] * q + c[1]) * q + c[2]) * q + c[3]) * q + c[4]) * q + c[5]) / ((((d[0] * q + d[1]) * q + d[2]) * q + d[3]) * q + 1);
  }
  const q = p - 0.5;
  const r = q * q;
  return (((((a[0] * r + a[1]) * r + a[2]) * r + a[3]) * r + a[4]) * r + a[5]) * q / (((((b[0] * r + b[1]) * r + b[2]) * r + b[3]) * r + b[4]) * r + 1);
}

/**
 * Completitud del muestreo de la distribución de una especie en el catálogo (presencias Y ausencias).
 * Ocasiones de muestreo = días UTC con algún registro de la especie (presente o ausente). Para cada celda de 2 km con
 * presencia, en cuántas ocasiones se detectó (incidencia). Chao2 con corrección de sesgo (Colwell y Coddington 1994):
 *   S = S_obs + ((T − 1) / T) · Q1 (Q1 − 1) / (2 (Q2 + 1)),  completitud = S_obs / S.
 * Mismo filtro que la AOO: fuera las borradas y las de precisión peor que la celda.
 * → {occasions, cellsPresent, q1, q2, chao2, completenessPct, absentCells, confirmedAbsentCells}
 */
export function samplingCompleteness(records, project = null) {
  const days = new Set();
  const presence = new Map();     // celda → Set(días)
  const absence = new Map();      // celda → Set(días)
  for (const r of records || []) {
    if (!r || r.deletedAt) continue;
    const u = toNum(r.coordinateUncertaintyInMeters);
    if (u !== null && u > AOO_CELL_M) continue;
    let x = toNum(r.x);
    let y = toNum(r.y);
    if (x === null || y === null) {
      const lat = toNum(r.decimalLatitude);
      const lon = toNum(r.decimalLongitude);
      if (lat === null || lon === null || typeof project !== 'function') continue;
      const p = project(lat, lon);
      x = p.easting;
      y = p.northing;
    }
    const t = Date.parse(r.eventDate);
    if (!Number.isFinite(t)) continue;
    const day = new Date(t).toISOString().slice(0, 10);
    days.add(day);
    const cell = `${Math.floor(x / AOO_CELL_M)},${Math.floor(y / AOO_CELL_M)}`;
    const target = r.occurrenceStatus === 'absent' ? absence : presence;
    if (!target.has(cell)) target.set(cell, new Set());
    target.get(cell).add(day);
  }
  const T = days.size;
  const sObs = presence.size;
  let q1 = 0;
  let q2 = 0;
  for (const set of presence.values()) { if (set.size === 1) q1++; else if (set.size === 2) q2++; }
  const chao2 = sObs && T ? sObs + ((T - 1) / T) * (q1 * (q1 - 1)) / (2 * (q2 + 1)) : null;
  let absentCells = 0;
  let confirmed = 0;
  for (const [cell, set] of absence) {
    if (presence.has(cell)) continue;
    absentCells++;
    if (set.size >= SUFFICIENCY.absenceOccasions) confirmed++;
  }
  return { occasions: T, cellsPresent: sObs, q1, q2, chao2: chao2 === null ? null : round(chao2, 3),
    completenessPct: chao2 ? round((100 * sObs) / chao2, 1) : null, absentCells, confirmedAbsentCells: confirmed };
}

/** Calidad de EOO y AOO según la completitud. → {eoo, aoo, completenessOk} (null sin presencias). */
export function rangeQuality(c) {
  if (!c || !c.cellsPresent) return { eoo: null, aoo: null, completenessOk: false };
  const ok = c.occasions >= SUFFICIENCY.minOccasions && c.completenessPct !== null && c.completenessPct >= SUFFICIENCY.completenessPct;
  return { eoo: ok || c.cellsPresent >= SUFFICIENCY.eooCells ? 'estimated' : 'inferred', aoo: ok ? 'estimated' : 'inferred', completenessOk: ok };
}

/** Calidad de la reducción (A) y de la tasa de C1 según la serie. → {a, c1} (null sin serie evaluable). */
export function seriesQuality(s) {
  if (!s || s.status !== 'ok') return { a: null, c1: null };
  const dfOk = Number.isInteger(s.df) && s.df >= SUFFICIENCY.seriesDf;
  if (!dfOk) return { a: 'inferred', c1: 'inferred' };
  return { a: s.window && s.window.projected ? 'projected' : 'estimated', c1: 'estimated' };
}

const QLABEL = { observed: 'observado', estimated: 'estimado', projected: 'proyectado', inferred: 'inferido (cota o indicio)', suspected: 'sospechado' };
const clamp01 = (v) => Math.max(0, Math.min(1, v));

/** Un requisito: valor frente a objetivo → progreso 0–1 (para la barra) y si se cumple. */
function item(id, label, value, target, { unit = '', ref = '', hint = '', boolean = false } = {}) {
  const v = value === null || value === undefined ? 0 : value;
  const met = v >= target;
  return { id, label, value: value === undefined ? null : value, target, unit, boolean, met, progress: round(met ? 1 : clamp01(v / target), 3), ref, hint };
}

/**
 * Requisitos mínimos por grupo, con su progreso, a partir de los cuantificadores (`q` de quantify(): mature,
 * completeness) y de la serie (analyzeSeries). Cada grupo dice qué calidad alcanza su dato ahora.
 * → [{id, title, criteria, quality, items: [{id, label, value, target, unit, met, progress, ref, hint}], note}]
 */
export function requirements(q, { series = null } = {}) {
  const S = SUFFICIENCY;
  const m = (q && q.mature) || {};
  const c = (q && q.completeness) || null;
  const rq = rangeQuality(c);
  const groups = [];

  // Distribución (B y D2).
  groups.push({
    id: 'range', title: 'Distribución: EOO y AOO', criteria: ['B'],
    quality: { eoo: rq.eoo, aoo: rq.aoo },
    items: [
      item('occasions', 'Días de muestreo de la especie', c ? c.occasions : 0, S.minOccasions,
        { unit: 'días', ref: 'BioDex (Chao2: Colwell y Coddington 1994)', hint: 'Cada día con alguna marca de la especie, presente o ausente.' }),
      item('completeness', 'Completitud del muestreo (Chao2 sobre celdas de 2 km)', c ? c.completenessPct : 0, S.completenessPct,
        { unit: '%', ref: 'Colwell y Coddington 1994; Marsh et al. 2023', hint: 'Sube cuando las celdas ocupadas se vuelven a encontrar en días distintos y deja de aparecer ocupación nueva.' }),
      item('eooCells', 'Celdas de 2 km con presencia (EOO estable)', c ? c.cellsPresent : 0, S.eooCells,
        { unit: 'celdas', ref: 'Syfert et al. 2014 (vía Marsh et al. 2023)', hint: 'Con 20 o más, el EOO es estimado aunque falte completitud.' }),
    ],
    note: !c || !c.cellsPresent ? 'Sin presencias: no hay EOO ni AOO.'
      : `Calidad: EOO ${QLABEL[rq.eoo]}; AOO ${QLABEL[rq.aoo]}.${rq.aoo === 'inferred' ? ' Mientras la AOO sea inferida es solo una cota inferior: B2 no se puede cumplir (tabla 3.1 pide estimada).' : ''}${
        c.absentCells ? ` Ausencias: ${c.absentCells} ${c.absentCells === 1 ? 'celda' : 'celdas'} sin detección, ${c.confirmedAbsentCells} confirmadas (≥ ${S.absenceOccasions} días).` : ''}`,
  });

  // Individuos maduros (C y D): censo completo O parcelas suficientes con superficie de hábitat.
  const plotsTarget = m.plotsRequired || S.plotsBase;
  groups.push({
    id: 'mature', title: 'Individuos maduros', criteria: ['C', 'D'],
    quality: { mature: m.totalQuality || null },
    items: [
      item('census', 'Censo completo declarado (hecho «censo completo» = sí)', m.method === 'census' ? 1 : 0, 1,
        { boolean: true, ref: 'Iriondo 2011 (AFA)', hint: 'Si se han contado todos los individuos de la población, el total es observado. Es una vía; las parcelas son la otra.' }),
      item('plots', 'Parcelas con superficie contada', m.nPlots || 0, plotsTarget,
        { unit: 'parcelas', ref: 'Elzinga et al. 1998', hint: m.density && m.density.cv !== null && m.density.cv !== undefined
          ? `CV de la densidad ${String(m.density.cv).replace('.', ',')}: se piden ${plotsTarget} parcelas (${S.plotsHighCv} si el CV es > ${S.cvHigh}).` : `Se piden ${S.plotsBase} (${S.plotsHighCv} si la densidad varía mucho).` }),
      item('habitat', 'Superficie de hábitat ocupado (parámetro)', m.areaSource === 'habitat' ? 1 : 0, 1,
        { boolean: true, ref: 'Directrices v16 §4.3', hint: 'Densidad × superficie; la proporción ocupada debe salir del conocimiento del taxón.' }),
    ],
    note: m.min === null || m.min === undefined ? 'Sin recuentos de individuos maduros.'
      : `Calidad del total: ${QLABEL[m.totalQuality] || '—'}.${m.totalQuality === 'inferred' ? ' Es un mínimo: C y D no se pueden cumplir hasta que sea estimado u observado (tabla 3.1).' : ''}`,
  });

  // Serie temporal (A y C1).
  const s = series && series.status ? series : null;
  const sq = seriesQuality(s);
  const ok = s && s.status === 'ok';
  groups.push({
    id: 'series', title: 'Serie temporal: reducción y tasa', criteria: ['A', 'C1'],
    quality: { a: sq.a, c1: sq.c1 },
    items: [
      item('years', 'Años con recuentos en sitios', s ? (s.nYears || 0) : 0, S.seriesYears,
        { unit: 'años', ref: 'Directrices v16 §4.5.1', hint: 'La reducción se mide repitiendo el mismo muestreo en los mismos sitios.' }),
      item('df', 'Grados de libertad para la sobredispersión', ok ? s.df : 0, S.seriesDf,
        { unit: 'g. l.', ref: 'BioDex (Ver Hoef y Boveng 2007)', hint: 'Recuentos sitio-año menos parámetros del modelo. Más sitios o más años.' }),
      item('power', 'Potencia para detectar la reducción de VU en la ventana', ok && s.power ? round(s.power.power * 100, 1) : 0, S.power * 100,
        { unit: '%', ref: 'Gerrodette 1987; White 2019; Connors et al. 2014', hint: ok && s.power
          ? `Reducción mínima detectable: ${String(s.power.mdrPct).replace('.', ',')} % en ${String(s.window.years).replace('.', ',')} años (objetivo: ${s.power.targetPct} %).` : '' }),
    ],
    note: !ok ? (s ? s.reason : 'Sin series temporales.')
      : `Calidad: reducción (A) ${QLABEL[sq.a]}; tasa de C1 ${QLABEL[sq.c1]}.${s.power && s.power.power < S.power
        ? ' Con poca potencia, que A no se cumpla no demuestra que no haya reducción, y una reducción grande en una serie corta puede ser una falsa alarma.' : ''}`,
  });
  for (const g of groups) g.met = g.id === 'mature' ? (g.items[0].met || (g.items[1].met && g.items[2].met))
    : g.id === 'range' ? rq.completenessOk : g.items[0].met && g.items[1].met;
  return groups;
}
