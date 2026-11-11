// ================================================================================================
// iucn-series.js — series temporales de recuentos por sitio para los criterios A y C1 de la UICN. JS puro.
// ------------------------------------------------------------------------------------------------
// Lo usa el dispositivo (gráfico de la ficha y cálculo provisional) y lo repite en PHP backend/Lib/series.php (el
// definitivo). Mismos casos: shared/iucn-cases.json › series (node shared/iucn-engine.test.js · bin/test-f11h.php),
// generados con bin/series-reference.py. Métodos: docs/iucn.md › «Series temporales».
//
//   seriesFromVisits({visits, occurrences, taxonID})  → observaciones {site, siteName, year, visitID, mature}: una por
//                                                      visita CON SITIO en la que se contó la especie (maduros sumados;
//                                                      una ausencia cuenta 0)
//   analyzeSeries(obs, {generationLength, criteria}) → índice anual, tasa r con IC 95 %, reducción en la ventana de
//                                                      A, parte proyectada, fluctuación y sugerencias
//   seriesEngineInputs(series, params)                → {A, c1AnnualRate} para engineInputs()
//
// Modelo (como TRIM o un GLM de Poisson con efecto sitio), ajustado por IRLS:
//   · Datos: maduros por sitio y año; con varias visitas a un sitio en un año, el MÁXIMO. Solo sitios con dos o más años
//     y algún recuento > 0 (un sitio siempre a cero no informa de la tendencia).
//   · Índice: log μ_sy = α_s + γ_y (factor año). Índice del año y = Σ_s exp(α_s + γ_y): maduros esperados en el conjunto
//     de sitios. Un año sin ninguna visita no tiene índice (hueco); un año con todos los recuentos a cero, índice 0.
//   · Tendencia: log μ_sy = α_s + r·(y − ȳ). r es la tasa anual instantánea (cambio anual = e^r − 1).
//   · Cuasi-Poisson: φ = χ² de Pearson / (n − p), con φ ≥ 1 (no se estrecha el intervalo por infradispersión);
//     EE(r) = √(φ · [(XᵀWX)⁻¹]_rr); IC 95 % = r ± 1,96 · EE.
//   · Reducción en T años: 1 − e^(r·T), con T = máx(10 años, 3 generaciones) y tope de 100 (criterio A). Con menos de
//     3 años con datos: «no evaluable». Si la serie cubre menos que T, el resto de la ventana es PROYECTADO.
// ================================================================================================

export const Z95 = 1.959963984540054;
export const MIN_YEARS = 3;               // por debajo: «no evaluable»
export const FLUCT_RATIO = 10;            // fluctuación extrema: máx/mín del índice > 10 (Directrices UICN)
const IRLS_MAX_ITER = 100;
const IRLS_TOL = 1e-10;
const A_PERIOD = { minYears: 10, generations: 3, maxYears: 100 };

const round = (v, d) => { const f = 10 ** d; return Math.round(v * f) / f; };
const yearOf = (iso) => { const t = Date.parse(iso); return Number.isFinite(t) ? new Date(t).getUTCFullYear() : null; };

/**
 * Visitas + marcas → observaciones de una especie. Solo visitas con sitio, no borradas. Por visita: suma de
 * mature_count de las presencias de la especie (las ausencias suman 0). Si alguna presencia no tiene maduros, la visita
 * no cuenta (no se sabe cuántos había) y se anota en `skipped`.
 * visits: [{visitID, siteID, eventStart, deletedAt}] · sites: Map|objeto siteID → {name} · occurrences: marcas DwC con visitID.
 */
export function seriesFromVisits({ visits = [], occurrences = [], taxonID, sites = null }) {
  const byVisit = new Map();
  for (const v of visits) if (v && v.siteID && !v.deletedAt) byVisit.set(v.visitID, v);
  const acc = new Map();
  for (const o of occurrences) {
    if (!o || o.deletedAt || o.taxonID !== taxonID || !byVisit.has(o.visitID)) continue;
    const a = acc.get(o.visitID) || { mature: 0, unknown: false };
    if (o.occurrenceStatus !== 'absent') {
      if (Number.isInteger(o.mature_count) && o.mature_count >= 0) a.mature += o.mature_count;
      else a.unknown = true;
    }
    acc.set(o.visitID, a);
  }
  const nameOf = (id) => { const s = sites instanceof Map ? sites.get(id) : sites ? sites[id] : null; return s ? s.name : null; };
  const obs = [];
  let skipped = 0;
  for (const [visitID, a] of acc) {
    const v = byVisit.get(visitID);
    const year = yearOf(v.eventStart);
    if (year === null) continue;
    if (a.unknown) { skipped++; continue; }
    obs.push({ site: v.siteID, siteName: nameOf(v.siteID), year, visitID, mature: a.mature });
  }
  obs.sort((x, y) => (x.site < y.site ? -1 : x.site > y.site ? 1 : x.year - y.year || (x.visitID < y.visitID ? -1 : 1)));
  return { obs, skipped };
}

// --- Álgebra mínima ------------------------------------------------------------------------------------------------

/** Inversa de una matriz simétrica definida positiva (Cholesky). null si no lo es. */
function invertSpd(A) {
  const n = A.length;
  const L = A.map(() => new Array(n).fill(0));
  for (let i = 0; i < n; i++) {
    for (let j = 0; j <= i; j++) {
      let s = A[i][j];
      for (let k = 0; k < j; k++) s -= L[i][k] * L[j][k];
      if (i === j) {
        if (!(s > 1e-300)) return null;
        L[i][i] = Math.sqrt(s);
      } else {
        L[i][j] = s / L[j][j];
      }
    }
  }
  // L⁻¹ (triangular inferior) y después (L⁻¹)ᵀ L⁻¹.
  const Li = L.map(() => new Array(n).fill(0));
  for (let i = 0; i < n; i++) {
    Li[i][i] = 1 / L[i][i];
    for (let j = 0; j < i; j++) {
      let s = 0;
      for (let k = j; k < i; k++) s -= L[i][k] * Li[k][j];
      Li[i][j] = s / L[i][i];
    }
  }
  const inv = L.map(() => new Array(n).fill(0));
  for (let i = 0; i < n; i++) {
    for (let j = 0; j <= i; j++) {
      let s = 0;
      for (let k = i; k < n; k++) s += Li[k][i] * Li[k][j];
      inv[i][j] = s;
      inv[j][i] = s;
    }
  }
  return inv;
}

/**
 * GLM de Poisson con enlace log por IRLS. X: filas de covariables; y ≥ 0. → {beta, cov (sin escalar), mu, deviance,
 * pearson, iterations, converged} o null si la matriz es singular.
 */
export function poissonIrls(X, y) {
  const n = y.length;
  const p = X[0].length;
  let mu = y.map((v) => v + 0.5);
  let eta = mu.map(Math.log);
  let beta = new Array(p).fill(0);
  let dev = Infinity;
  let cov = null;
  let iterations = 0;
  let converged = false;
  for (let it = 1; it <= IRLS_MAX_ITER; it++) {
    iterations = it;
    // Normal equations: (XᵀWX) β = XᵀWz, W = μ, z = η + (y − μ)/μ.
    const A = Array.from({ length: p }, () => new Array(p).fill(0));
    const b = new Array(p).fill(0);
    for (let i = 0; i < n; i++) {
      const w = mu[i];
      const z = eta[i] + (y[i] - mu[i]) / mu[i];
      const xi = X[i];
      for (let j = 0; j < p; j++) {
        if (xi[j] === 0) continue;
        const wx = w * xi[j];
        b[j] += wx * z;
        for (let k = 0; k <= j; k++) if (xi[k] !== 0) A[j][k] += wx * xi[k];
      }
    }
    for (let j = 0; j < p; j++) for (let k = 0; k < j; k++) A[k][j] = A[j][k];
    cov = invertSpd(A);
    if (!cov) return null;
    beta = cov.map((row) => row.reduce((s, v, k) => s + v * b[k], 0));
    eta = X.map((xi) => xi.reduce((s, v, k) => s + v * beta[k], 0));
    mu = eta.map(Math.exp);
    let d = 0;
    for (let i = 0; i < n; i++) d += 2 * ((y[i] > 0 ? y[i] * Math.log(y[i] / mu[i]) : 0) - (y[i] - mu[i]));
    const done = Math.abs(d - dev) <= IRLS_TOL * (Math.abs(d) + 0.1);
    dev = d;
    if (done) { converged = true; break; }
  }
  // Covarianza en el punto final (W = μ ajustada).
  const A = Array.from({ length: p }, () => new Array(p).fill(0));
  for (let i = 0; i < n; i++) for (let j = 0; j < p; j++) for (let k = 0; k < p; k++) A[j][k] += mu[i] * X[i][j] * X[i][k];
  cov = invertSpd(A);
  if (!cov) return null;
  let pearson = 0;
  for (let i = 0; i < n; i++) pearson += (y[i] - mu[i]) ** 2 / mu[i];
  return { beta, cov, mu, deviance: Math.max(0, dev), pearson, iterations, converged };
}

// --- Análisis ---------------------------------------------------------------------------------------------------------

/** Ventana del criterio A en años: máx(10, 3 generaciones), con tope de 100. */
export function aWindowYears(generationLength, criteria = null) {
  const p = (criteria && criteria.criteria && criteria.criteria.A && criteria.criteria.A.period) || A_PERIOD;
  const g = Number(generationLength);
  let w = p.minYears;
  if (Number.isFinite(g) && g > 0) w = Math.max(w, p.generations * g);
  return Math.min(w, p.maxYears);
}

/** Reducción (%) en T años con tasa r; las subidas cuentan como 0. */
const reductionPct = (r, T) => Math.max(0, round(100 * (1 - Math.exp(r * T)), 2));

/**
 * Serie de una especie → análisis completo. obs: [{site, siteName?, year, mature}].
 * → {status: ok | not_evaluable | no_data, reason, sites, years, nSites, nYears, firstYear, lastYear, trend, window,
 *    reduction, fluctuation, suggestions}
 */
export function analyzeSeries(obs, { generationLength = null, criteria = null } = {}) {
  // Máximo por sitio y año.
  const cell = new Map();
  const names = new Map();
  for (const o of obs || []) {
    if (!o || !Number.isInteger(o.year) || !(Number(o.mature) >= 0)) continue;
    const k = `${o.site}|${o.year}`;
    const m = Number(o.mature);
    if (!cell.has(k) || m > cell.get(k).y) cell.set(k, { site: o.site, year: o.year, y: m });
    if (o.siteName && !names.has(o.site)) names.set(o.site, o.siteName);
  }
  const bySite = new Map();
  for (const c of cell.values()) {
    if (!bySite.has(c.site)) bySite.set(c.site, []);
    bySite.get(c.site).push(c);
  }
  const siteIds = [...bySite.keys()].sort();
  const sites = siteIds.map((id) => {
    const list = bySite.get(id).sort((a, b) => a.year - b.year);
    const nYears = list.length;
    const positive = list.some((c) => c.y > 0);
    const excluded = nYears < 2 ? 'menos de dos años' : !positive ? 'siempre a cero' : null;
    return { site: id, name: names.get(id) || null, counts: Object.fromEntries(list.map((c) => [c.year, c.y])), included: !excluded,
      excluded };
  });
  const used = sites.filter((s) => s.included);
  const T = aWindowYears(generationLength, criteria);
  const base = { sites, windowYears: T, generationLength: Number(generationLength) > 0 ? Number(generationLength) : null };
  if (!cell.size) return { ...base, status: 'no_data', reason: 'Sin visitas a sitios con recuentos de la especie.', years: [] };

  const allYears = [...new Set([...cell.values()].map((c) => c.year))].sort((a, b) => a - b);
  const modelYears = [...new Set(used.flatMap((s) => Object.keys(s.counts).map(Number)))].sort((a, b) => a - b);
  const span = (list) => (list.length ? { first: list[0], last: list[list.length - 1] } : { first: null, last: null });
  const yr = span(modelYears.length ? modelYears : allYears);
  const yearsOut = () => {
    const out = [];
    for (let y = span(allYears).first; y <= span(allYears).last; y++) {
      const counts = sites.filter((s) => s.counts[y] !== undefined);
      out.push({ year: y, visited: counts.length > 0, total: counts.length ? counts.reduce((a, s) => a + s.counts[y], 0) : null,
        sitesVisited: counts.length, index: null });
    }
    return out;
  };
  if (modelYears.length < MIN_YEARS || !used.length) {
    return { ...base, status: 'not_evaluable', years: yearsOut(), nSites: used.length, nYears: modelYears.length,
      firstYear: yr.first, lastYear: yr.last,
      reason: !used.length ? 'Ningún sitio tiene dos o más años con recuentos.'
        : `Solo ${modelYears.length} ${modelYears.length === 1 ? 'año' : 'años'} con datos: hacen falta al menos ${MIN_YEARS}.` };
  }

  // Filas del modelo: sitios incluidos × sus años.
  const sIdx = new Map(used.map((s, i) => [s.site, i]));
  const rows = [];
  for (const s of used) for (const [y, v] of Object.entries(s.counts)) rows.push({ s: sIdx.get(s.site), year: Number(y), y: v });
  rows.sort((a, b) => a.s - b.s || a.year - b.year);
  const nS = used.length;

  // Tendencia: α_s + r·(año − media de años del modelo).
  const tMean = modelYears.reduce((a, y) => a + y, 0) / modelYears.length;
  const Xt = rows.map((r) => { const x = new Array(nS + 1).fill(0); x[r.s] = 1; x[nS] = r.year - tMean; return x; });
  const fit = poissonIrls(Xt, rows.map((r) => r.y));
  if (!fit) return { ...base, status: 'not_evaluable', years: yearsOut(), reason: 'El ajuste no converge con estos datos.' };
  const df = rows.length - (nS + 1);
  const phiRaw = df > 0 ? fit.pearson / df : null;
  const phi = Math.max(1, phiRaw ?? 1);
  const r = fit.beta[nS];
  const se = Math.sqrt(phi * fit.cov[nS][nS]);
  const lo = r - Z95 * se;
  const hi = r + Z95 * se;

  // Índice: α_s + γ_y (año base: el primero con algún recuento > 0).
  const yearTotals = new Map(modelYears.map((y) => [y, rows.filter((x) => x.year === y).reduce((a, x) => a + x.y, 0)]));
  const fitYears = modelYears.filter((y) => yearTotals.get(y) > 0);
  const yIdx = new Map(fitYears.slice(1).map((y, i) => [y, nS + i]));
  const rowsI = rows.filter((x) => yearTotals.get(x.year) > 0);
  const Xi = rowsI.map((x) => { const v = new Array(nS + fitYears.length - 1).fill(0); v[x.s] = 1; if (yIdx.has(x.year)) v[yIdx.get(x.year)] = 1; return v; });
  const fitI = poissonIrls(Xi, rowsI.map((x) => x.y));
  const index = new Map();
  for (const y of modelYears) {
    if (yearTotals.get(y) === 0) { index.set(y, 0); continue; }
    if (!fitI) continue;
    const g = yIdx.has(y) ? fitI.beta[yIdx.get(y)] : 0;
    let s = 0;
    for (let i = 0; i < nS; i++) s += Math.exp(fitI.beta[i] + g);
    index.set(y, round(s, 4));
  }
  const years = yearsOut().map((x) => ({ ...x, index: index.has(x.year) ? index.get(x.year) : null }));

  // Ventana de A: los T años que acaban en el último año con datos. La parte anterior al primero es proyectada.
  // Años enteros: con una generación no entera (T = 13,5) la ventana empieza en el año anterior que la cubre.
  const from = Math.floor(yr.last - T);
  const observedYears = Math.min(yr.last - yr.first, T);
  const window = { years: T, from, to: yr.last, observedYears, observedPct: round((100 * observedYears) / T, 1),
    projected: yr.last - yr.first < T };

  // Fluctuación: máx/mín del índice anual dentro de la ventana (años con índice).
  const inWin = years.filter((x) => x.index !== null && x.year >= from && x.year <= yr.last).map((x) => x.index);
  const maxI = inWin.length ? Math.max(...inWin) : null;
  const minI = inWin.length ? Math.min(...inWin) : null;
  const ratio = maxI === null ? null : minI > 0 ? round(maxI / minI, 2) : (maxI > 0 ? null : 1);
  const extreme = maxI !== null && (minI === 0 ? maxI > 0 : maxI / minI > FLUCT_RATIO);
  const fluctuation = { ratio, zeroYear: minI === 0 && maxI > 0, extreme, nYears: inWin.length };

  const pctTxt = (v) => String(round(100 * (Math.exp(v) - 1), 1)).replace('.', ',');
  const suggestions = [];
  if (hi < 0) {
    suggestions.push({ key: 'decline_b_v', value: 'yes',
      reason: `El IC 95 % de la tasa anual queda por debajo de 0 (${pctTxt(lo)} % a ${pctTxt(hi)} % al año): declive continuo de maduros.` });
  }
  if (extreme) {
    suggestions.push({ key: 'fluct_c_iv', value: 'yes',
      reason: fluctuation.zeroYear ? 'El índice anual llega a 0 en la ventana: posible fluctuación extrema (compruébalo; no se aplica sola).'
        : `El índice anual varía ×${String(ratio).replace('.', ',')} (máx./mín.) en la ventana, más de ×${FLUCT_RATIO}: posible fluctuación extrema (no se aplica sola).` });
  }

  return {
    ...base,
    status: 'ok',
    reason: null,
    years,
    nSites: nS,
    nYears: modelYears.length,
    nObs: rows.length,
    firstYear: yr.first,
    lastYear: yr.last,
    trend: { r: round(r, 6), se: round(se, 6), lo: round(lo, 6), hi: round(hi, 6), phi: round(phi, 4), phiRaw: phiRaw === null ? null : round(phiRaw, 4),
      deviance: round(fit.deviance, 4), iterations: fit.iterations, converged: fit.converged,
      annualChangePct: round(100 * (Math.exp(r) - 1), 2), annualChangeLo: round(100 * (Math.exp(lo) - 1), 2),
      annualChangeHi: round(100 * (Math.exp(hi) - 1), 2) },
    window,
    reduction: { best: reductionPct(r, T), min: reductionPct(hi, T), max: reductionPct(lo, T) },
    fluctuation,
    suggestions,
  };
}

const FACT = (params, k) => (params && params[k] ? params[k].value : null);
export const A_BASIS = ['a', 'b', 'c', 'd', 'e'];

/**
 * Entradas de A y C1 a partir de la serie y de los hechos (parámetros sí · no · desconocido):
 *   a_reversible, a_understood, a_ceased → A1 si los tres son «sí»; si no, A2 (pasado).
 *   a_future (¿se espera que la reducción continúe?) = sí → además A3 (proyección) y A4 (ventana centrada en el último año).
 *   a_basis_a … a_basis_e → bases (a)–(e) del criterio. A3 no admite (a).
 * → {A, c1AnnualRate}. Sin serie evaluable: A salta con el motivo y C1 sin dato.
 */
export function seriesEngineInputs(series, params = {}) {
  if (!series || series.status !== 'ok') {
    return { A: { skip: true, pending: 'series', reason: series ? series.reason : 'Sin series temporales.' }, c1AnnualRate: null };
  }
  const yes = (k) => FACT(params, k) === 'yes';
  const past = yes('a_reversible') && yes('a_understood') && yes('a_ceased') ? 'A1' : 'A2';
  const basis = A_BASIS.filter((b) => yes(`a_basis_${b}`));
  const red = { ...series.reduction };
  const items = [{ subcriterion: past, reductionPct: red, basis }];
  if (yes('a_future')) {
    items.push({ subcriterion: 'A3', reductionPct: red, basis: basis.filter((b) => b !== 'a') });
    items.push({ subcriterion: 'A4', reductionPct: red, basis });
  }
  return {
    A: { items, window: series.window },
    c1AnnualRate: { best: series.trend.r, min: series.trend.lo, max: series.trend.hi },
  };
}
