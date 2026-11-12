// ================================================================================================
// iucn-quantifiers.js — cuantificadores para los criterios B, C y D de la UICN. JS puro, sin DOM.
// ------------------------------------------------------------------------------------------------
// Lo usa el dispositivo (cálculo PROVISIONAL sin red, /shared/iucn-quantifiers.js) y lo repite en PHP
// backend/Lib/iucn.php (el DEFINITIVO, con las coordenadas exactas). Los dos pasan los mismos casos:
// shared/iucn-cases.json (node shared/iucn-engine.test.js · bin/test-f11g.php). Métodos y fórmulas:
// docs/iucn.md › «Cuantificadores».
//
//   prepareRecords(records, project)            → puntos {id, x, y, lat, lon, t, day, mature, area}
//   matureEstimate(points, opts)                → individuos maduros: mínimo observado, mejor y máximo
//   subpopulations(points, separationKm, opts)  → subpoblaciones por enlace simple + C2a(i) y C2a(ii)
//   fragmentation(subpops, params)              → fragmentación grave (B a) o «no evaluable»
//   proposeLocations(subpops, impacts)          → localidades definidas por amenaza (propuesta)
//   quantify(records, opts)                     → todo junto, como lo pinta la pestaña Evaluación UICN
// Calidad de cada dato y requisitos mínimos (censo, parcelas, completitud): iucn-sufficiency.js.
//
// Distancias en metros sobre ETRS89 / UTM 30N (la proyección se INYECTA: toUtm() de js/geo/utm.js en el
// navegador). Fechas en UTC: «día de campaña» = fecha UTC de eventDate.
// ================================================================================================

import { seriesEngineInputs } from './iucn-series.js';
import { SUFFICIENCY, normCdf, normInv, samplingCompleteness, rangeQuality, seriesQuality } from './iucn-sufficiency.js';

export const DEDUP_CELL_M = 50;              // celda de deduplicación de maduros
export const LAST_YEAR_MS = 365 * 86400000;   // ventana: el último año de datos
export const AOO_CELL_M = 2000;               // celdas de la AOO (UICN: 2 × 2 km)
export const BOOTSTRAP_REPS = 1000;
export const BOOTSTRAP_SEED = 20261107;
const MINSTD_M = 2147483647;                  // generador de Park–Miller: exacto en JS (< 2^53) y en PHP (enteros)
const MINSTD_A = 48271;
export const INTENSITY_RANK = { high: 3, medium: 2, low: 1 };
const KM2_PER_M2 = 1e-6;

const isNum = (v) => typeof v === 'number' && Number.isFinite(v);
const toNum = (v) => (v === null || v === undefined || v === '' ? null : (Number.isFinite(Number(v)) ? Number(v) : null));
const round = (v, d = 0) => { const f = 10 ** d; return Math.round(v * f) / f; };
const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

/** Generador reproducible (MINSTD): misma semilla → misma secuencia en JS y en PHP. → () => [0, 1). */
export function minstd(seed) {
  let s = (((Math.floor(seed) - 1) % (MINSTD_M - 1)) + (MINSTD_M - 1)) % (MINSTD_M - 1) + 1;   // en [1, M − 1]
  return () => { s = (s * MINSTD_A) % MINSTD_M; return (s - 1) / (MINSTD_M - 1); };
}

/** Fecha ISO → {t (ms UTC), day (AAAA-MM-DD UTC)} o null. */
function when(iso) {
  if (!iso) return null;
  const d = new Date(iso);
  const t = d.getTime();
  return Number.isFinite(t) ? { t, day: d.toISOString().slice(0, 10) } : null;
}

/**
 * Registros (marcas DwC del catálogo) → puntos de PRESENCIA con coordenadas. Se quedan fuera las ausencias,
 * las borradas (deletedAt) y las de precisión peor que la celda de la AOO (como en EOO/AOO). Un registro con
 * `x`/`y` ya proyectados (casos de prueba) no necesita `project`.
 */
export function prepareRecords(records, project = null) {
  const out = [];
  for (const r of records || []) {
    if (!r || r.occurrenceStatus === 'absent' || r.deletedAt) continue;
    const u = toNum(r.coordinateUncertaintyInMeters);
    if (u !== null && u > AOO_CELL_M) continue;
    let x = toNum(r.x);
    let y = toNum(r.y);
    const lat = toNum(r.decimalLatitude);
    const lon = toNum(r.decimalLongitude);
    if (x === null || y === null) {
      if (lat === null || lon === null || typeof project !== 'function') continue;
      const p = project(lat, lon);
      x = p.easting;
      y = p.northing;
    }
    const w = when(r.eventDate);
    const mature = Number.isInteger(r.mature_count) && r.mature_count >= 0 ? r.mature_count : null;
    const area = toNum(r.sampleSizeValue);
    const author = r.authorId ?? r.user_id ?? r.userId ?? null;
    out.push({ id: r.occurrenceID || String(out.length), x, y, lat, lon, t: w ? w.t : null, day: w ? w.day : null,
      mature, area: area !== null && area > 0 ? area : null, author: author === null ? null : String(author) });
  }
  return out;
}

/** Recuentos que cuentan para los maduros: con mature_count y fecha, en el último año de datos. */
function countedPoints(points) {
  const withCount = points.filter((p) => p.mature !== null && p.t !== null);
  if (!withCount.length) return { list: [], from: null, to: null };
  const to = Math.max(...withCount.map((p) => p.t));
  const from = to - LAST_YEAR_MS;
  return { list: withCount.filter((p) => p.t >= from), from, to };
}

const later = (a, b) => (a.t !== b.t ? a.t > b.t : a.id > b.id);   // a es más reciente que b

/**
 * El recuento más reciente por clave (celda de 50 m + día, o solo celda). Un RECUENTO son las marcas del
 * mismo autor a ≤ EVENT_WINDOW_MS de la más reciente: el conteo por casillas guarda una marca por
 * categoría en el mismo punto, y sus maduros se SUMAN. → un punto por clave: el de la marca más reciente,
 * con `mature` sumado y la superficie de la primera que la tenga.
 */
export const EVENT_WINDOW_MS = 60000;
function latestBy(list, keyOf) {
  const groups = new Map();
  for (const p of list) {
    const k = keyOf(p);
    if (!groups.has(k)) groups.set(k, []);
    groups.get(k).push(p);
  }
  const out = [];
  for (const g of groups.values()) {
    let last = g[0];
    for (const p of g) if (later(p, last)) last = p;
    const members = g.filter((p) => p === last || (p.author === last.author && last.t - p.t <= EVENT_WINDOW_MS))
      .sort((a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));
    const withArea = members.find((p) => p.area !== null);
    out.push({ ...last, mature: members.reduce((s, p) => s + p.mature, 0), area: withArea ? withArea.area : null, members: members.length });
  }
  return out.sort((a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));
}
const cellKey = (p, size) => `${Math.floor(p.x / size)},${Math.floor(p.y / size)}`;

/** Pares de marcas a menos de 50 m en días distintos (posible doble conteo). Rejilla para no comparar todo. */
function doubleCountPairs(list) {
  const grid = new Map();
  for (const p of list) {
    const k = cellKey(p, DEDUP_CELL_M);
    if (!grid.has(k)) grid.set(k, []);
    grid.get(k).push(p);
  }
  let pairs = 0;
  for (const p of list) {
    const i = Math.floor(p.x / DEDUP_CELL_M);
    const j = Math.floor(p.y / DEDUP_CELL_M);
    for (let di = -1; di <= 1; di++) {
      for (let dj = -1; dj <= 1; dj++) {
        for (const q of grid.get(`${i + di},${j + dj}`) || []) {
          if (q.id <= p.id || q.day === p.day) continue;
          if (dist(p, q) < DEDUP_CELL_M) pairs++;
        }
      }
    }
  }
  return pairs;
}

/**
 * Media y su intervalo por bootstrap BCa (corregido de sesgo y acelerado; DiCiccio y Efron 1996), al nivel del rango
 * plausible (90 %, Directrices v16 §3.2). El de percentiles se queda corto con pocas muestras (Hesterberg 2015).
 * Semilla fija: mismo resultado en JS y en PHP. Sesgo z0 = Φ⁻¹(proporción de medias remuestreadas < media, con la
 * mitad de los empates); aceleración a por navaja (jackknife).
 */
export function bootstrapMean(values, { reps = BOOTSTRAP_REPS, seed = BOOTSTRAP_SEED, level = SUFFICIENCY.level } = {}) {
  const n = values.length;
  const mean = values.reduce((s, v) => s + v, 0) / n;
  if (n < 2) return { mean, low: mean, high: mean, reps: 0, level, method: 'none' };
  const rnd = minstd(seed);
  const means = [];
  for (let r = 0; r < reps; r++) {
    let s = 0;
    for (let k = 0; k < n; k++) s += values[Math.floor(rnd() * n)];
    means.push(s / n);
  }
  means.sort((a, b) => a - b);
  if (means[0] === means[reps - 1]) return { mean, low: mean, high: mean, reps, level, method: 'bca' };
  let less = 0;
  let ties = 0;
  for (const m of means) { if (m < mean) less++; else if (m === mean) ties++; }
  const prop = Math.min(1 - 0.5 / reps, Math.max(0.5 / reps, (less + ties / 2) / reps));
  const z0 = normInv(prop);
  const sum = values.reduce((s, v) => s + v, 0);
  const jack = values.map((v) => (sum - v) / (n - 1));
  const jMean = jack.reduce((s, v) => s + v, 0) / n;
  let num = 0;
  let den = 0;
  for (const j of jack) { const d = jMean - j; num += d * d * d; den += d * d; }
  const acc = den > 0 ? num / (6 * den ** 1.5) : 0;
  const zl = normInv((1 - level) / 2);
  const adj = (z) => normCdf(z0 + (z0 + z) / (1 - acc * (z0 + z)));
  const at = (q) => means[Math.min(reps - 1, Math.max(0, Math.floor(q * reps)))];
  return { mean, low: at(adj(zl)), high: at(adj(-zl)), reps, level, method: 'bca' };
}

/**
 * Individuos maduros de una especie en el catálogo.
 *   Mínimo (observado): suma de mature_count de la marca más reciente por celda de 50 m y día, en el último
 *     año de datos. Aviso de posible doble conteo: marcas a < 50 m en días distintos.
 *   Mejor y máximo (estimados): densidad media de las PARCELAS (marcas con superficie contada,
 *     sampleSizeValue en m²; la más reciente por celda de 50 m) × superficie de hábitat ocupado
 *     (`habitatKm2`), con IC 95 % por bootstrap. Sin superficie de hábitat, la AOO como cota superior
 *     (solo el máximo; el mejor sigue siendo el observado).
 *   Calidad del TOTAL (`totalQuality`, la que mira la tabla 3.1): observado con censo completo declarado
 *     (`censusComplete`); estimado con parcelas suficientes (≥ 10, o ≥ 15 si el CV de la densidad es > 1) y
 *     superficie de hábitat; si no, inferido (el mínimo contado es solo una cota inferior). iucn-sufficiency.js.
 * opts: {habitatKm2, aooKm2, reps, seed, censusComplete} → {min, best, max, quality, totalQuality, density, plotsRequired, …}
 */
export function matureEstimate(points, { habitatKm2 = null, aooKm2 = null, reps = BOOTSTRAP_REPS, seed = BOOTSTRAP_SEED, censusComplete = false } = {}) {
  const warnings = [];
  const { list, from, to } = countedPoints(points);
  const kept = latestBy(list, (p) => `${cellKey(p, DEDUP_CELL_M)}|${p.day}`);
  const min = kept.reduce((s, p) => s + p.mature, 0);
  const pairs = doubleCountPairs(kept);
  if (pairs) warnings.push(`Posible doble conteo: ${pairs} ${pairs === 1 ? 'par' : 'pares'} de marcas a menos de ${DEDUP_CELL_M} m en días distintos.`);

  const plots = latestBy(list.filter((p) => p.area !== null), (p) => cellKey(p, DEDUP_CELL_M));
  const densities = plots.map((p) => p.mature / (p.area * KM2_PER_M2));   // maduros por km²
  const census = censusComplete === true;
  const out = { min: kept.length ? min : null, best: kept.length ? min : null, max: kept.length ? min : null,
    quality: { min: 'observed', best: census ? 'observed' : 'inferred', max: census ? 'observed' : 'inferred' },
    totalQuality: kept.length ? (census ? 'observed' : 'inferred') : null, method: census ? 'census' : 'observed',
    density: null, areaKm2: null, areaSource: null, nCounted: kept.length, nPlots: plots.length, plotsRequired: SUFFICIENCY.plotsBase,
    window: from === null ? null : { from: new Date(from).toISOString(), to: new Date(to).toISOString() },
    doubleCountPairs: pairs, kept, warnings };
  if (!kept.length) { warnings.push('Sin recuentos de individuos maduros en las marcas.'); return out; }
  if (!plots.length) return out;

  const b = bootstrapMean(densities, { reps, seed });
  // Coeficiente de variación de la densidad (desviación típica muestral / media): decide cuántas parcelas hacen falta.
  const n = densities.length;
  const sd = n >= 2 ? Math.sqrt(densities.reduce((s, v) => s + (v - b.mean) ** 2, 0) / (n - 1)) : null;
  const cv = sd !== null && b.mean > 0 ? round(sd / b.mean, 3) : null;
  out.plotsRequired = cv !== null && cv > SUFFICIENCY.cvHigh ? SUFFICIENCY.plotsHighCv : SUFFICIENCY.plotsBase;
  const plotsOk = plots.length >= out.plotsRequired;
  out.density = { mean: round(b.mean, 3), low: round(b.low, 3), high: round(b.high, 3), reps: b.reps, plots: plots.length,
    level: b.level, method: b.method, cv };
  if (plots.length < 2) warnings.push('Una sola parcela con superficie: sin intervalo de confianza.');
  if (census) return out;                       // con censo completo el total es el contado (observado)
  const est = plotsOk ? 'estimated' : 'inferred';
  const habitat = toNum(habitatKm2);
  const aoo = toNum(aooKm2);
  if (habitat !== null && habitat > 0) {
    out.areaKm2 = habitat;
    out.areaSource = 'habitat';
    out.method = 'density';
    out.best = Math.max(min, Math.round(b.mean * habitat));
    out.max = Math.max(out.best, Math.round(b.high * habitat));
    out.quality.best = est;
    out.quality.max = est;
    out.totalQuality = est;
  } else if (aoo !== null && aoo > 0) {
    out.areaKm2 = aoo;
    out.areaSource = 'aoo';
    out.method = 'density-aoo-bound';
    out.max = Math.max(min, Math.round(b.high * aoo));
    out.quality.max = est;
    warnings.push('Sin superficie de hábitat ocupado: la AOO se usa como cota superior (solo el máximo).');
  } else {
    warnings.push('Sin superficie de hábitat ocupado ni AOO: los maduros estimados no se calculan.');
  }
  return out;
}

/** Celdas 2 × 2 km (AOO) de unos puntos → Set "i,j". */
const aooCells = (pts) => new Set(pts.map((p) => cellKey(p, AOO_CELL_M)));

/** Casco convexo de puntos {x, y} (cadena monótona de Andrew, como geo-metrics). */
function hull(points) {
  const pts = [...points].sort((a, b) => a.x - b.x || a.y - b.y);
  if (pts.length < 3) return pts;
  const cross = (o, a, b) => (a.x - o.x) * (b.y - o.y) - (a.y - o.y) * (b.x - o.x);
  const lower = [];
  for (const p of pts) { while (lower.length >= 2 && cross(lower[lower.length - 2], lower[lower.length - 1], p) <= 0) lower.pop(); lower.push(p); }
  const upper = [];
  for (let i = pts.length - 1; i >= 0; i--) { const p = pts[i]; while (upper.length >= 2 && cross(upper[upper.length - 2], upper[upper.length - 1], p) <= 0) upper.pop(); upper.push(p); }
  lower.pop();
  upper.pop();
  return lower.concat(upper);
}

/**
 * Subpoblaciones por ENLACE SIMPLE: dos presencias a ≤ `separationKm` están en la misma (y, por transitividad,
 * todo lo que se encadene). Etiquetas S1, S2… de oeste a este (x mínima). Cada una: nº de registros, maduros
 * (mínimo observado; mejor y máximo repartiendo la estimación total por su parte de celdas de la AOO), celdas
 * de 2 km, casco, centroide y distancia a la vecina más cercana (borde a borde, km).
 * `mature` = salida de matureEstimate (con `kept`). → {subpops, c2a: {maxPerSubpop, pctInLargest}, warnings}
 */
export function subpopulations(points, separationKm, mature = null) {
  const sep = toNum(separationKm);
  if (sep === null || sep <= 0) return { subpops: [], c2a: null, warnings: ['Sin distancia de separación: no se calculan subpoblaciones.'], missing: ['separation_distance'] };
  const sepM = sep * 1000;
  const n = points.length;
  const parent = points.map((_, i) => i);
  const find = (i) => { while (parent[i] !== i) { parent[i] = parent[parent[i]]; i = parent[i]; } return i; };
  const grid = new Map();
  points.forEach((p, i) => { const k = cellKey(p, sepM); if (!grid.has(k)) grid.set(k, []); grid.get(k).push(i); });
  points.forEach((p, i) => {
    const ci = Math.floor(p.x / sepM);
    const cj = Math.floor(p.y / sepM);
    for (let di = -1; di <= 1; di++) {
      for (let dj = -1; dj <= 1; dj++) {
        for (const j of grid.get(`${ci + di},${cj + dj}`) || []) {
          if (j <= i || dist(p, points[j]) > sepM) continue;
          const a = find(i);
          const b = find(j);
          if (a !== b) parent[Math.max(a, b)] = Math.min(a, b);
        }
      }
    }
  });
  const groups = new Map();
  points.forEach((p, i) => { const r = find(i); if (!groups.has(r)) groups.set(r, []); groups.get(r).push(p); });
  const list = [...groups.values()].map((pts) => ({ pts, minX: Math.min(...pts.map((p) => p.x)), minY: Math.min(...pts.map((p) => p.y)) }))
    .sort((a, b) => a.minX - b.minX || a.minY - b.minY);

  const keptIds = new Set(((mature && mature.kept) || []).map((p) => p.id));
  const keptById = new Map(((mature && mature.kept) || []).map((p) => [p.id, p]));
  const allCells = aooCells(points);
  const subpops = list.map((g, k) => {
    const cells = aooCells(g.pts);
    const counted = g.pts.filter((p) => keptIds.has(p.id)).map((p) => keptById.get(p.id));
    const minM = counted.length ? counted.reduce((s, p) => s + p.mature, 0) : null;
    const share = allCells.size ? cells.size / allCells.size : 0;
    const est = (v) => (v === null || v === undefined || minM === null ? minM : Math.max(minM, Math.round(v * share)));
    const h = hull(g.pts);
    return {
      label: `S${k + 1}`,
      nRecords: g.pts.length,
      mature: { min: minM, best: mature && mature.method === 'density' ? est(mature.best) : minM,
        max: mature && mature.max !== null && mature.method !== 'observed' ? est(mature.max) : minM },
      cells: [...cells].map((s) => s.split(',').map(Number)).sort((a, b) => a[0] - b[0] || a[1] - b[1]),
      hull: h.map((p) => [p.lat, p.lon]),
      centroid: [g.pts.reduce((s, p) => s + (p.lat || 0), 0) / g.pts.length, g.pts.reduce((s, p) => s + (p.lon || 0), 0) / g.pts.length],
      points: g.pts,
      nearestKm: null,
      nearest: null,
    };
  });
  for (let a = 0; a < subpops.length; a++) {
    for (let b = a + 1; b < subpops.length; b++) {
      let d = Infinity;
      for (const p of subpops[a].points) for (const q of subpops[b].points) d = Math.min(d, dist(p, q));
      const km = round(d / 1000, 3);
      for (const [x, y] of [[a, b], [b, a]]) {
        if (subpops[x].nearestKm === null || km < subpops[x].nearestKm) { subpops[x].nearestKm = km; subpops[x].nearest = subpops[y].label; }
      }
    }
  }
  const warnings = [];
  const withCounts = subpops.filter((s) => s.mature.min !== null);
  if (withCounts.length < subpops.length) {
    warnings.push(`${subpops.length - withCounts.length} de ${subpops.length} subpoblaciones sin recuento de maduros.`);
  }
  let c2a = null;
  if (withCounts.length) {
    const sum = (key) => withCounts.reduce((s, x) => s + x.mature[key], 0);
    const largest = (key) => Math.max(...withCounts.map((x) => x.mature[key]));
    const pct = (key) => { const t = sum(key); return t > 0 ? round((largest(key) / t) * 100, 2) : null; };
    // El % de la mayor no crece con los maduros: el intervalo son el menor y el mayor de los tres valores.
    const spread = (best, others) => { const v = [best, ...others].filter((x) => x !== null); return { best, min: v.length ? Math.min(...v) : null, max: v.length ? Math.max(...v) : null }; };
    c2a = {
      maxPerSubpop: { best: largest('best'), min: largest('min'), max: largest('max') },
      pctInLargest: spread(pct('best'), [pct('min'), pct('max')]),
      complete: withCounts.length === subpops.length,
    };
  }
  return { subpops, c2a, separationKm: sep, warnings, missing: [] };
}

/**
 * Fragmentación grave: una subpoblación es PEQUEÑA si sus maduros (mejor valor) < min_viable_subpop y
 * AISLADA si su vecina más cercana está a > dispersal_distance (sin vecina: aislada). Grave si MÁS DEL 50 %
 * de la AOO (celdas de 2 km) está en subpoblaciones pequeñas y aisladas. Sin parámetros → «no evaluable».
 */
export function fragmentation(subpops, { minViable = null, dispersalKm = null } = {}) {
  const missing = [];
  const mv = toNum(minViable);
  const dk = toNum(dispersalKm);
  if (mv === null) missing.push('min_viable_subpop');
  if (dk === null) missing.push('dispersal_distance');
  if (!subpops || !subpops.length) missing.push('subpopulations');
  if (missing.length) return { status: 'not_evaluable', severe: null, missing, pctAoo: null, pctIndividuals: null, bySubpop: [], warnings: [] };
  const warnings = [];
  const all = new Set();
  const bad = new Set();
  let total = 0;
  let badInd = 0;
  const bySubpop = subpops.map((s) => {
    const m = s.mature.best;
    const small = m === null ? null : m < mv;
    const isolated = s.nearestKm === null || s.nearestKm > dk;
    for (const c of s.cells) all.add(c.join(','));
    if (m !== null) total += m;
    if (small && isolated) { for (const c of s.cells) bad.add(c.join(',')); badInd += m; }
    return { label: s.label, small, isolated, mature: m, nearestKm: s.nearestKm };
  });
  if (bySubpop.some((s) => s.small === null)) warnings.push('Hay subpoblaciones sin recuento: no cuentan como pequeñas.');
  if (subpops.length === 1) warnings.push('Una sola subpoblación: se considera aislada (no tiene vecina).');
  const pctAoo = all.size ? round((bad.size / all.size) * 100, 2) : 0;
  const pctIndividuals = total > 0 ? round((badInd / total) * 100, 2) : null;
  return { status: 'evaluated', severe: pctAoo > 50, missing: [], pctAoo, pctIndividuals, bySubpop, warnings,
    minViable: mv, dispersalKm: dk };
}

/** ¿Toca el impacto algún punto de la subpoblación? Punto + radio, o dentro del polígono ([[lat, lon]…] proyectado). */
function impactHits(impact, pts) {
  if (impact.polygonXY && impact.polygonXY.length >= 3) {
    const poly = impact.polygonXY;
    const inside = (p) => {
      let c = false;
      for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
        const a = poly[i];
        const b = poly[j];
        if ((a.y > p.y) !== (b.y > p.y) && p.x < ((b.x - a.x) * (p.y - a.y)) / (b.y - a.y) + a.x) c = !c;
      }
      return c;
    };
    return pts.some(inside);
  }
  const r = toNum(impact.radiusM) || 0;
  return pts.some((p) => dist(p, impact) <= r);
}

/** Gravedad para elegir «el evento de amenaza más grave»: intensidad, luego alcance, luego en curso antes que futuro. */
function severityKey(i) {
  return [INTENSITY_RANK[i.intensity] || 0, toNum(i.scopePct) || 0, i.timing === 'ongoing' ? 1 : 0];
}
const moreSevere = (a, b) => { const ka = severityKey(a); const kb = severityKey(b); for (let k = 0; k < 3; k++) if (ka[k] !== kb[k]) return ka[k] > kb[k]; return a.impactID < b.impactID; };

/**
 * Localidades definidas por amenaza (propuesta; el usuario une, separa y justifica). Cada subpoblación va
 * con su impacto en curso o futuro MÁS GRAVE que la toque; las que comparten ese impacto forman una localidad.
 * Las no afectadas: una localidad cada una, «sin amenaza: localidad no aplicable» (Guidelines §4.11).
 * impacts: [{impactID, threatCode, intensity, scopePct, timing, x, y, radiusM, polygonXY?}] ya proyectados.
 */
export function proposeLocations(subpops, impacts) {
  const relevant = (impacts || []).filter((i) => i.timing === 'ongoing' || i.timing === 'future');
  const groups = new Map();
  const out = [];
  for (const s of subpops || []) {
    let worst = null;
    for (const i of relevant) if (impactHits(i, s.points) && (!worst || moreSevere(i, worst))) worst = i;
    if (!worst) { out.push({ label: null, subpops: [s.label], impactID: null, threatCode: null, noThreat: true }); continue; }
    if (!groups.has(worst.impactID)) {
      const g = { label: null, subpops: [], impactID: worst.impactID, threatCode: worst.threatCode, noThreat: false };
      groups.set(worst.impactID, g);
      out.push(g);
    }
    groups.get(worst.impactID).subpops.push(s.label);
  }
  out.forEach((g, k) => { g.label = `L${k + 1}`; });
  return { count: out.length, groups: out, threatened: out.filter((g) => !g.noThreat).length };
}

/** Impactos en curso sobre la especie que degradan el hábitat → sugerencia de b(iii) (nunca un hecho). */
export function suggestDecline(impacts, habitatCodes) {
  const codes = new Set(habitatCodes || []);
  const hits = (impacts || []).filter((i) => i.timing === 'ongoing' && codes.has(i.threatCode));
  return hits.length ? [{ key: 'decline_b_iii', value: 'yes', impacts: hits.map((i) => i.impactID),
    reason: `${hits.length} ${hits.length === 1 ? 'impacto en curso' : 'impactos en curso'} sobre el hábitat de la especie.` }] : [];
}

// --- Todo junto ------------------------------------------------------------------------------------

const DECLINE_KEYS = { i: 'decline_b_i', ii: 'decline_b_ii', iii: 'decline_b_iii', iv: 'decline_b_iv', v: 'decline_b_v' };
const FLUCT_KEYS = { i: 'fluct_c_i', ii: 'fluct_c_ii', iii: 'fluct_c_iii', iv: 'fluct_c_iv' };
const paramValue = (params, key) => (params && params[key] ? params[key].value : null);
const paramQuality = (params, key) => (params && params[key] && params[key].quality ? params[key].quality : null);

/**
 * Cuantificadores de una especie en el catálogo a partir de sus registros, parámetros e impactos.
 * opts: {project, params: {clave: {value…}}, defaults: {separation_distance}, impacts (con x/y ya proyectados),
 *        metrics: {eooKm2, aooKm2, aooRange}, habitatCodes, seed}
 * → {mature, subpops, fragmentation, locations (propuesta), suggestions, separation: {km, fromDefault}}
 */
export function quantify(records, opts = {}) {
  const points = prepareRecords(records, opts.project);
  const params = opts.params || {};
  const metrics = opts.metrics || {};
  const mature = matureEstimate(points, { habitatKm2: paramValue(params, 'occupied_habitat_km2'), aooKm2: metrics.aooKm2,
    seed: opts.seed, reps: opts.reps, censusComplete: paramValue(params, 'census_complete') === 'yes' });
  let sepKm = toNum(paramValue(params, 'separation_distance'));
  let fromDefault = false;
  if (sepKm === null && opts.defaults && opts.defaults.separation_distance) { sepKm = opts.defaults.separation_distance.value; fromDefault = true; }
  const sp = subpopulations(points, sepKm, mature);
  if (fromDefault) sp.warnings.unshift(`Distancia de separación por defecto del grupo (${sepKm} km): confírmala en Parámetros.`);
  const frag = fragmentation(sp.subpops, { minViable: paramValue(params, 'min_viable_subpop'), dispersalKm: paramValue(params, 'dispersal_distance') });
  const locations = proposeLocations(sp.subpops, opts.impacts || []);
  return { nPoints: points.length, mature, subpops: sp, fragmentation: frag, locations,
    completeness: samplingCompleteness(records, opts.project),
    suggestions: suggestDecline(opts.impacts || [], opts.habitatCodes), separation: { km: sepKm, fromDefault } };
}

/**
 * ¿Siguen valiendo las localidades confirmadas? Solo si las subpoblaciones son las mismas que cuando se
 * confirmaron: misma distancia de separación y cada subpoblación actual asignada exactamente a una localidad.
 * Si no (marcas nuevas que forman otra subpoblación, otra separación), no entran en el motor y se pide revisarlas.
 */
export function locationsCurrent(loc, q) {
  if (!loc) return false;
  const sp = (q && q.subpops) || {};
  const labels = (sp.subpops || []).map((s) => s.label);
  const sep = toNum(loc.separationKm);
  if (sep !== null && sp.separationKm !== null && sp.separationKm !== undefined && Math.abs(sep - sp.separationKm) > 1e-9) return false;
  const assigned = (loc.groups || []).flatMap((g) => g.subpops || []);
  if (!assigned.length) return true;   // confirmadas sin agrupación (solo el número): no hay con qué comparar
  return assigned.length === labels.length && labels.every((l) => assigned.includes(l));
}

/**
 * Entradas del motor (assessIucn) a partir de los cuantificadores, los parámetros (hechos sí/no), el nº de
 * localidades CONFIRMADO (la propuesta no cuenta hasta que alguien la guarda) y las decisiones del usuario
 * (expertCategory NT/LC, possiblyExtinct). A y C1 salen de la serie temporal (`series`, analyzeSeries() de
 * iucn-series.js) y de los hechos de A (seriesEngineInputs); E queda fuera de alcance.
 * Cada paso lleva la CALIDAD de sus datos (iucn-sufficiency.js y la calidad de los hechos) para la tabla 3.1.
 */
export function engineInputs(q, { params = {}, metrics = {}, locations = null, choices = {}, series = null } = {}) {
  const fromSeries = seriesEngineInputs(series, params);
  const yes = (k) => paramValue(params, k) === 'yes';
  const decline = Object.keys(DECLINE_KEYS).filter((r) => yes(DECLINE_KEYS[r]));
  const fluct = Object.keys(FLUCT_KEYS).filter((r) => yes(FLUCT_KEYS[r]));
  const m = q.mature || {};
  const rq = rangeQuality(q.completeness);
  const sq = seriesQuality(series);
  // Sin parcelas ni censo el total es solo un MÍNIMO: el máximo no se conoce (openMax) y el rango lo refleja.
  const open = m.method === 'observed';
  const matureRange = m.best === null || m.best === undefined ? null : { best: m.best, min: m.min, max: m.max, ...(open ? { openMax: true } : {}) };
  const c2aRaw = q.subpops && q.subpops.c2a && q.subpops.c2a.complete ? q.subpops.c2a : null;
  const c2a = c2aRaw && open ? { ...c2aRaw, maxPerSubpop: { ...c2aRaw.maxPerSubpop, openMax: true } } : c2aRaw;
  const aoo = metrics.aooKm2 === null || metrics.aooKm2 === undefined ? null
    : { best: metrics.aooKm2, min: metrics.aooRange ? metrics.aooRange.min : null, max: metrics.aooRange ? metrics.aooRange.max : null };
  const regionalKeys = ['regional_endemic', 'regional_immigration', 'regional_immigration_decrease', 'regional_sink'];
  const hasRegional = regionalKeys.some((k) => paramValue(params, k) !== null);
  return {
    engine: 2,
    generationLength: toNum(paramValue(params, 'generation_length')),
    A: fromSeries.A,
    B: { eoo: metrics.eooKm2 === undefined ? null : metrics.eooKm2, aoo, severelyFragmented: q.fragmentation && q.fragmentation.severe === true,
      locations: locations && Number.isInteger(locations.count) && locationsCurrent(locations, q) ? locations.count : null, decline, fluctuation: fluct,
      quality: { eoo: rq.eoo, aoo: rq.aoo, decline: Object.fromEntries(Object.entries(DECLINE_KEYS).map(([r, k]) => [r, paramQuality(params, k)])) } },
    C: { mature: matureRange, c1DeclinePct: null, c1Years: null, c1AnnualRate: fromSeries.c1AnnualRate, continuingDecline: yes('decline_b_v'),
      maxPerSubpop: c2a ? c2a.maxPerSubpop : null, pctInOneSubpop: c2a ? c2a.pctInLargest : null, extremeFluctuation: yes('fluct_c_iv'),
      quality: { mature: m.totalQuality || null, c1: sq.c1, continuingDecline: paramQuality(params, 'decline_b_v'), maxPerSubpop: m.totalQuality || null } },
    D: { mature: matureRange, plausibleThreat: yes('plausible_threat'), aoo: null, locations: null, quality: { mature: m.totalQuality || null } },
    E: { skip: true, pending: 'out_of_scope', reason: 'Fuera del alcance de BioDex: haría falta un análisis de viabilidad poblacional con series largas.' },
    expertCategory: choices.expertCategory === 'NT' || choices.expertCategory === 'LC' ? choices.expertCategory : null,
    possiblyExtinct: choices.possiblyExtinct === true,
    regionalV4: hasRegional ? { endemic: paramValue(params, 'regional_endemic'), immigration: paramValue(params, 'regional_immigration'),
      immigrationDecrease: paramValue(params, 'regional_immigration_decrease'), sink: paramValue(params, 'regional_sink') } : null,
  };
}
