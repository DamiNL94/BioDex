// ================================================================================================
// iucn-engine.js — motor de evaluación UICN (criterios A–E) de una especie en el ámbito de un catálogo.
// ------------------------------------------------------------------------------------------------
// JS puro, sin DOM: lo importa el navegador (/shared/iucn-engine.js, servido por nginx y precacheado
// por el SW) y Node (shared/iucn-engine.test.js). NINGÚN umbral está escrito aquí: todos salen de
// `criteria` (shared/iucn-criteria.json, servido en /shared/iucn-criteria.json).
//
//   evaluateIucn(inputs, criteria) → {category, baseCategory, criteriaString, determining, byCriterion,
//                                     warnings, message, basis, regional}
//
// BioDex PROPONE una categoría con los datos del investigador y explica por qué; la decisión es suya.
// Reglas (docs/iucn.md):
//   · Categoría = la más alta (CR > EN > VU) que cumpla CUALQUIER criterio. La cadena lista, en notación
//     UICN, todos los criterios cumplidos EN ESA categoría: «EN B1ab(iii)+2ab(iii)», «VU A2bc; D2».
//   · B exige al menos 2 subcriterios (a, b, c) cumplidos con el umbral de la MISMA categoría.
//   · Sin criterio cumplido: DD si el investigador lo marca, NT o LC por juicio experto, o nada.
//   · Ajuste regional opcional: un escalón arriba o abajo con justificación obligatoria.
//
// inputs (todo opcional; null, undefined o '' = sin dato):
//   generationLength      años por generación (A, C1, E)
//   A  {skip, reductionPct, subcriterion: A1|A2|A3|A4, basis: ['a'..'e']}
//      o (F11h, series temporales) {items: [{subcriterion, reductionPct, basis}, …]}: varios subcriterios a la vez
//      (A2 + A3 + A4…); cumple una categoría si la cumple alguno y la notación los junta («A2b+3b+4b»).
//   B  {skip, eoo, aoo (km²), severelyFragmented, locations, decline: ['i'..'v'], fluctuation: ['i'..'iv']}
//   C  {skip, mature, c1DeclinePct, c1Years, continuingDecline, maxPerSubpop, pctInOneSubpop, extremeFluctuation,
//       c1AnnualRate (F11h: tasa anual r de la serie; la disminución de C1 se calcula en la ventana de CADA categoría)}
//   D  {skip, mature, plausibleThreat, aoo, locations}   (D2: aoo y localidades de B si aquí faltan)
//   E  {skip, probabilityPct, horizonYears}
//   dataDeficient, expertCategory: NT|LC, regional {direction: down|up, justification}
// ================================================================================================

const isMissing = (v) => v === null || v === undefined || v === '';

/** Número de un campo: {value} si vale, {missing} si falta, {invalid} si no es un número ≥ 0 (y ≤ max). */
function num(v, { max = Infinity, integer = false } = {}) {
  if (isMissing(v)) return { missing: true };
  const n = typeof v === 'number' ? v : Number(String(v).replace(',', '.'));
  if (!Number.isFinite(n) || n < 0 || n > max || (integer && !Number.isInteger(n))) return { invalid: true };
  return { value: n };
}

/** Número con signo (la tasa anual r puede ser negativa): {value} · {missing} · {invalid}. */
function signedNum(v) {
  if (isMissing(v)) return { missing: true };
  const n = typeof v === 'number' ? v : Number(String(v).replace(',', '.'));
  return Number.isFinite(n) ? { value: n } : { invalid: true };
}

const fmt = (n) => (Number.isInteger(n) ? String(n) : String(Math.round(n * 100) / 100).replace('.', ','));

/** Lista de códigos permitidos, sin repetir y en el orden oficial. */
const pick = (list, allowed) => allowed.filter((x) => Array.isArray(list) && list.includes(x));

/** «b(i,iii)» a partir de los ordinales marcados. */
const withItems = (letter, items) => `${letter}(${items.join(',')})`;

/**
 * Ventana en años de un umbral {years, generations?, maxYears?}: el mayor de años y generaciones, con
 * el tope. Sin duración de generación, solo cuentan los años.
 */
function windowYears(t, gen) {
  let w = t.years;
  if (t.generations && gen) w = Math.max(w, t.generations * gen);
  if (t.maxYears) w = Math.min(w, t.maxYears);
  return w;
}

function emptyLevels(cats) {
  const out = {};
  for (const c of cats) out[c] = { met: false, notation: null, reasons: [] };
  return out;
}

/** Resultado de un criterio: la categoría más alta cumplida y su notación. */
function finish(res, cats) {
  const best = cats.find((c) => res.levels[c].met) || null;
  res.category = best;
  res.notation = best ? res.levels[best].notation : null;
  if (res.status !== 'skipped' && res.status !== 'incomplete') res.status = best ? 'met' : 'not_met';
  return res;
}

function skipped(cats, why = 'Sin datos para este criterio.') {
  return { status: 'skipped', category: null, notation: null, levels: emptyLevels(cats), notes: [why] };
}

// --- A · Reducción del tamaño poblacional ---------------------------------------------------------
function evalA(inp, ctx) {
  const { cats } = ctx;
  if (!inp || inp.skip) return skipped(cats, inp && inp.reason ? inp.reason : undefined);
  if (!Array.isArray(inp.items)) return evalAItem(inp, ctx);
  // F11h: varios subcriterios (pasado, proyección, ventana mixta) con la misma serie.
  const parts = inp.items.map((it) => evalAItem(it, ctx));
  if (!parts.length) return skipped(cats);
  const res = { status: 'evaluated', levels: emptyLevels(cats), notes: [], items: parts.map((p, i) => ({
    subcriterion: inp.items[i].subcriterion, status: p.status, category: p.category, notation: p.notation, periodYears: p.periodYears })) };
  for (const p of parts) for (const n of p.notes) if (!res.notes.includes(n)) res.notes.push(n);
  res.periodYears = Math.max(...parts.map((p) => p.periodYears || 0));
  if (parts.every((p) => p.status === 'incomplete' || p.status === 'skipped')) res.status = 'incomplete';
  for (const c of cats) {
    const met = parts.filter((p) => p.levels[c].met);
    for (const p of parts) res.levels[c].reasons.push(...p.levels[c].reasons);
    if (!met.length) continue;
    res.levels[c].met = true;
    res.levels[c].notation = met.map((p, i) => (i === 0 ? p.levels[c].notation : p.levels[c].notation.replace(/^A/, ''))).join('+');
  }
  return finish(res, cats);
}

function evalAItem(inp, ctx) {
  const { cats, criteria: crit, warnings, gen } = ctx;
  const A = crit.criteria.A;
  if (!inp || inp.skip) return skipped(cats);
  const res = { status: 'evaluated', levels: emptyLevels(cats), notes: [] };
  const pct = num(inp.reductionPct, { max: 100 });
  const sub = inp.subcriterion;
  if (pct.missing && !sub) return skipped(cats);
  if (pct.invalid) { warnings.push('A: la reducción debe ser un porcentaje entre 0 y 100.'); res.status = 'incomplete'; }
  if (!A.subcriteria[sub]) { res.notes.push('Falta el subcriterio (A1, A2, A3 o A4).'); res.status = 'incomplete'; }
  let basis = pick(inp.basis, Object.keys(A.basis));
  const banned = (A.basisNotAllowed && A.basisNotAllowed[sub]) || [];
  const rejected = basis.filter((b) => banned.includes(b));
  if (rejected.length) {
    warnings.push(`A: ${sub} no admite la base (${rejected.join(', ')}); no se tiene en cuenta.`);
    basis = basis.filter((b) => !banned.includes(b));
  }
  if (!basis.length) { res.notes.push('Falta al menos una base (a)–(e) válida.'); res.status = 'incomplete'; }
  if (pct.missing) { res.notes.push('Falta el porcentaje de reducción.'); res.status = 'incomplete'; }

  // Periodo: el más largo de 10 años y 3 generaciones (A3 y A4: como mucho 100 años).
  const p = A.period;
  let period = p.minYears;
  if (gen) period = Math.max(period, p.generations * gen);
  if ((sub === 'A3' || sub === 'A4') && p.maxYears) period = Math.min(period, p.maxYears);
  res.periodYears = period;
  res.notes.push(`Periodo de referencia: ${fmt(period)} años${gen ? '' : ' (sin duración de generación, solo los 10 años)'}.`);
  if (res.status === 'incomplete') return finish(res, cats);

  const thr = sub === 'A1' ? A.thresholdsPct.A1 : A.thresholdsPct.A2_A3_A4;
  for (const c of cats) {
    const ok = pct.value >= thr[c];
    res.levels[c].met = ok;
    res.levels[c].reasons.push(`Reducción ${fmt(pct.value)} % ${ok ? '≥' : '<'} ${thr[c]} % (${sub})`);
    if (ok) res.levels[c].notation = `${sub}${basis.join('')}`;
  }
  return finish(res, cats);
}

// --- B · Distribución geográfica -------------------------------------------------------------------
function evalB(inp, ctx) {
  const { cats, criteria: crit, warnings } = ctx;
  const B = crit.criteria.B;
  if (!inp || inp.skip) return skipped(cats);
  const res = { status: 'evaluated', levels: emptyLevels(cats), notes: [] };
  let eoo = num(inp.eoo);
  const aoo = num(inp.aoo);
  const loc = num(inp.locations, { integer: true });
  if (eoo.invalid) warnings.push('B: la EOO debe ser un número de km² ≥ 0.');
  if (aoo.invalid) warnings.push('B: la AOO debe ser un número de km² ≥ 0.');
  if (loc.invalid) warnings.push('B: el número de localidades debe ser un entero ≥ 0.');
  if (loc.value === 0) warnings.push('B: 0 localidades no tiene sentido para un taxón presente; revisa el dato.');
  const hasEoo = eoo.value !== undefined;
  const hasAoo = aoo.value !== undefined;
  if (!hasEoo && !hasAoo) {
    if (eoo.invalid || aoo.invalid) { res.status = 'incomplete'; return finish(res, cats); }
    return skipped(cats, 'Sin EOO ni AOO: B no se evalúa.');
  }
  if (hasEoo && hasAoo && eoo.value < aoo.value) {
    warnings.push(`B: la EOO (${fmt(eoo.value)} km²) es menor que la AOO (${fmt(aoo.value)} km²); se toma EOO = AOO.`);
    eoo = { value: aoo.value };
  }
  res.eoo = hasEoo ? eoo.value : null;
  res.aoo = hasAoo ? aoo.value : null;

  const decline = pick(inp.decline, B.conditions.b.items);
  const fluct = pick(inp.fluctuation, B.conditions.c.items);
  const fragmented = inp.severelyFragmented === true;
  const need = B.requireAtLeast;

  for (const c of cats) {
    const lv = res.levels[c];
    // Subcriterios con el umbral de ESTA categoría: (a) depende de ella; (b) y (c) no.
    const subs = [];
    const maxLoc = B.conditions.a.maxLocations[c];
    const aByLoc = loc.value !== undefined && loc.value <= maxLoc;
    if (fragmented || aByLoc) subs.push('a');
    lv.reasons.push(`(a) ${fragmented ? 'severamente fragmentada ✓' : loc.value !== undefined
      ? `${loc.value} localidades ${aByLoc ? '≤' : '>'} ${maxLoc} ${aByLoc ? '✓' : '✗'}` : 'sin fragmentación ni localidades ✗'}`);
    if (decline.length) subs.push(withItems('b', decline));
    lv.reasons.push(`(b) ${decline.length ? `disminución continua ${withItems('', decline)} ✓` : 'sin disminución continua ✗'}`);
    if (fluct.length) subs.push(withItems('c', fluct));
    lv.reasons.push(`(c) ${fluct.length ? `fluctuaciones extremas ${withItems('', fluct)} ✓` : 'sin fluctuaciones extremas ✗'}`);
    const enough = subs.length >= need;
    lv.reasons.push(`${subs.length} de ${need} subcriterios en el umbral de ${c}${enough ? ' ✓' : ' ✗'}`);

    const parts = [];
    if (hasEoo) {
      const t = B.thresholdsKm2.B1_EOO[c];
      const inRange = eoo.value < t;
      lv.reasons.push(`B1: EOO ${fmt(eoo.value)} km² ${inRange ? '<' : '≥'} ${t} ${inRange ? '✓' : '✗'}`);
      if (inRange && enough) parts.push(`1${subs.join('')}`);
    }
    if (hasAoo) {
      const t = B.thresholdsKm2.B2_AOO[c];
      const inRange = aoo.value < t;
      lv.reasons.push(`B2: AOO ${fmt(aoo.value)} km² ${inRange ? '<' : '≥'} ${t} ${inRange ? '✓' : '✗'}`);
      if (inRange && enough) parts.push(`2${subs.join('')}`);
    }
    if (parts.length) {
      lv.met = true;
      lv.notation = `B${parts.join('+')}`;
    }
  }
  return finish(res, cats);
}

// --- C · Población pequeña y en disminución ------------------------------------------------------
function evalC(inp, ctx) {
  const { cats, criteria: crit, warnings, gen } = ctx;
  const C = crit.criteria.C;
  if (!inp || inp.skip) return skipped(cats);
  const res = { status: 'evaluated', levels: emptyLevels(cats), notes: [] };
  const mature = num(inp.mature, { integer: true });
  if (mature.missing) return skipped(cats, 'Sin nº de individuos maduros: C no se evalúa.');
  if (mature.invalid) { warnings.push('C: los individuos maduros deben ser un entero ≥ 0.'); res.status = 'incomplete'; return finish(res, cats); }

  const c1Pct = num(inp.c1DeclinePct, { max: 100 });
  const c1Years = num(inp.c1Years);
  const rate = signedNum(inp.c1AnnualRate);
  if (rate.invalid) warnings.push('C1: la tasa anual debe ser un número.');
  if (c1Pct.invalid) warnings.push('C1: la disminución debe ser un porcentaje entre 0 y 100.');
  if (c1Years.invalid) warnings.push('C1: el plazo debe ser un número de años ≥ 0.');
  if (c1Pct.value !== undefined && c1Years.value === undefined && !c1Years.invalid) res.notes.push('C1: falta el plazo (años) de la disminución.');
  const maxSub = num(inp.maxPerSubpop, { integer: true });
  const pctOne = num(inp.pctInOneSubpop, { max: 100 });
  if (maxSub.invalid) warnings.push('C2a(i): el máximo por subpoblación debe ser un entero ≥ 0.');
  if (pctOne.invalid) warnings.push('C2a(ii): el porcentaje debe estar entre 0 y 100.');
  const decline = inp.continuingDecline === true;
  const fluct = inp.extremeFluctuation === true;

  for (const c of cats) {
    const lv = res.levels[c];
    const t = C.matureIndividualsLessThan[c];
    const small = mature.value < t;
    lv.reasons.push(`Maduros ${mature.value} ${small ? '<' : '≥'} ${t} ${small ? '✓' : '✗'}`);

    // C1: disminución continua de al menos pct % en su plazo (años o generaciones, el mayor).
    let c1 = false;
    if (rate.value !== undefined) {
      // F11h: con la tasa de la serie, la disminución en la ventana de esta categoría: 1 − e^(r·W).
      const th = C.C1.thresholds[c];
      const w = windowYears(th, gen);
      const pct = Math.max(0, Math.round(100 * (1 - Math.exp(rate.value * w)) * 100) / 100);
      c1 = pct >= th.pct;
      const r4 = String(Math.round(rate.value * 10000) / 10000).replace('.', ',');
      lv.reasons.push(`C1: tasa ${r4}/año → ${fmt(pct)} % en ${fmt(w)} años; exige ≥ ${th.pct} % ${c1 ? '✓' : '✗'}`);
    } else if (c1Pct.value !== undefined && c1Years.value !== undefined) {
      const th = C.C1.thresholds[c];
      const w = windowYears(th, gen);
      c1 = c1Pct.value >= th.pct && c1Years.value <= w;
      lv.reasons.push(`C1: ${fmt(c1Pct.value)} % en ${fmt(c1Years.value)} años; exige ≥ ${th.pct} % en ≤ ${fmt(w)} años ${c1 ? '✓' : '✗'}`);
    }

    // C2: disminución continua y al menos una de a(i), a(ii), b.
    const c2 = [];
    const aItems = [];
    if (maxSub.value !== undefined) {
      const th = C.C2.a_i_maxMaturePerSubpop[c];
      if (maxSub.value <= th) aItems.push('i');
      lv.reasons.push(`C2a(i): máx. ${maxSub.value} maduros por subpoblación ${maxSub.value <= th ? '≤' : '>'} ${th}`);
    }
    if (pctOne.value !== undefined) {
      const th = C.C2.a_ii_minPctInOneSubpop[c];
      if (pctOne.value >= th) aItems.push('ii');
      lv.reasons.push(`C2a(ii): ${fmt(pctOne.value)} % en una subpoblación ${pctOne.value >= th ? '≥' : '<'} ${th} %`);
    }
    if (aItems.length) c2.push(withItems('a', aItems));
    if (fluct) c2.push('b');
    const c2ok = decline && c2.length > 0;
    if (c2.length || decline) {
      lv.reasons.push(`C2: ${decline ? 'disminución continua ✓' : 'sin disminución continua ✗'}; ${c2.length ? c2.join('') : 'ni a(i), a(ii) ni b'} ${c2ok ? '✓' : '✗'}`);
    }

    if (small && (c1 || c2ok)) {
      lv.met = true;
      const parts = [];
      if (c1) parts.push('1');
      if (c2ok) parts.push(`2${c2.join('')}`);
      lv.notation = `C${parts.join('+')}`;
    }
  }
  if (!decline && c1Pct.value === undefined && rate.value === undefined && !res.notes.length) res.notes.push('C exige C1 o C2 (con disminución continua).');
  return finish(res, cats);
}

// --- D · Población muy pequeña o restringida -------------------------------------------------------
function evalD(inp, ctx, bInp) {
  const { cats, criteria: crit, warnings } = ctx;
  const D = crit.criteria.D;
  if (!inp || inp.skip) return skipped(cats);
  const res = { status: 'evaluated', levels: emptyLevels(cats), notes: [] };
  const mature = num(inp.mature, { integer: true });
  if (mature.invalid) warnings.push('D: los individuos maduros deben ser un entero ≥ 0.');

  // D2: AOO o localidades de este paso; si faltan, los de B.
  const b = bInp && !bInp.skip ? bInp : {};
  const fromB = [];
  let aoo = num(inp.aoo);
  if (aoo.missing && !isMissing(b.aoo)) { aoo = num(b.aoo); fromB.push('AOO'); }
  let loc = num(inp.locations, { integer: true });
  if (loc.missing && !isMissing(b.locations)) { loc = num(b.locations, { integer: true }); fromB.push('localidades'); }
  const threat = inp.plausibleThreat === true;
  if (mature.missing && !threat) return skipped(cats);
  if (fromB.length && threat) res.notes.push(`D2 usa ${fromB.join(' y ')} del paso B.`);

  const d2c = D.D2;
  const d2Aoo = aoo.value !== undefined && aoo.value < d2c.aooLessThanKm2;
  const d2Loc = loc.value !== undefined && loc.value <= d2c.maxLocations;
  const d2 = threat && (d2Aoo || d2Loc);

  for (const c of cats) {
    const lv = res.levels[c];
    let d1 = false;
    if (mature.value !== undefined) {
      const t = D.matureIndividualsLessThan[c];
      d1 = mature.value < t;
      lv.reasons.push(`Maduros ${mature.value} ${d1 ? '<' : '≥'} ${t} ${d1 ? '✓' : '✗'}`);
    }
    const d2Here = d2 && d2c.categories.includes(c);
    if (threat) {
      lv.reasons.push(d2c.categories.includes(c)
        ? `D2: ${aoo.value !== undefined ? `AOO ${fmt(aoo.value)} km² ${d2Aoo ? '<' : '≥'} ${d2c.aooLessThanKm2}` : 'sin AOO'} · ${loc.value !== undefined
          ? `${loc.value} localidades ${d2Loc ? '≤' : '>'} ${d2c.maxLocations}` : 'sin localidades'} · amenaza plausible ${d2Here ? '✓' : '✗'}`
        : `D2 solo sirve para ${d2c.categories.join(', ')}`);
    }
    if (d1 && d2Here) { lv.met = true; lv.notation = `${D.notation[c]}+2`; }
    else if (d1) { lv.met = true; lv.notation = D.notation[c]; }
    else if (d2Here) { lv.met = true; lv.notation = 'D2'; }
  }
  return finish(res, cats);
}

// --- E · Análisis cuantitativo --------------------------------------------------------------------
function evalE(inp, ctx) {
  const { cats, criteria: crit, warnings, gen } = ctx;
  const E = crit.criteria.E;
  if (!inp || inp.skip) return skipped(cats);
  const res = { status: 'evaluated', levels: emptyLevels(cats), notes: [] };
  const p = num(inp.probabilityPct, { max: 100 });
  const h = num(inp.horizonYears);
  if (p.missing && h.missing) return skipped(cats);
  if (p.invalid) warnings.push('E: la probabilidad debe ser un porcentaje entre 0 y 100.');
  if (h.invalid) warnings.push('E: el horizonte debe ser un número de años ≥ 0.');
  if (p.value === undefined || h.value === undefined) {
    res.status = 'incomplete';
    res.notes.push('E necesita la probabilidad de extinción y su horizonte en años.');
    return finish(res, cats);
  }
  for (const c of cats) {
    const th = E.thresholds[c];
    const w = windowYears(th, gen);
    // Una probabilidad a un horizonte más corto es al menos la misma a uno más largo.
    const ok = p.value >= th.pct && h.value <= w;
    res.levels[c].reasons.push(`${fmt(p.value)} % en ${fmt(h.value)} años; exige ≥ ${th.pct} % en ≤ ${fmt(w)} años ${ok ? '✓' : '✗'}`);
    if (ok) { res.levels[c].met = true; res.levels[c].notation = 'E'; }
  }
  return finish(res, cats);
}

// --- Evaluación completa ----------------------------------------------------------------------------

/** Etiqueta en castellano de una categoría (CR → «En peligro crítico»). */
export function categoryLabel(criteria, code) {
  return (criteria.outcomes && criteria.outcomes.labels[code]) || code || '';
}

export const NO_THREAT_MESSAGE = 'No alcanza umbrales de amenaza: valorar NT o LC (juicio experto)';

export function evaluateIucn(inputs, criteria) {
  if (!criteria || !criteria.criteria) throw new Error('Faltan los criterios UICN (shared/iucn-criteria.json).');
  const inp = inputs || {};
  const cats = criteria.categories;
  const warnings = [];
  const g = num(inp.generationLength);
  if (g.invalid) warnings.push('La duración de una generación debe ser un número de años ≥ 0.');
  const ctx = { cats, criteria, warnings, gen: g.value || null };

  const byCriterion = {
    A: evalA(inp.A, ctx),
    B: evalB(inp.B, ctx),
    C: evalC(inp.C, ctx),
    D: evalD(inp.D, ctx, inp.B),
    E: evalE(inp.E, ctx),
  };
  const keys = Object.keys(byCriterion);
  const threatened = cats.find((c) => keys.some((k) => byCriterion[k].levels[c].met)) || null;
  const outcomes = criteria.outcomes;

  let baseCategory = null;
  let criteriaString = null;
  let determining = [];
  let message = null;
  let basis = 'none';
  if (threatened) {
    determining = keys.filter((k) => byCriterion[k].levels[threatened].met);
    baseCategory = threatened;
    criteriaString = `${threatened} ${determining.map((k) => byCriterion[k].levels[threatened].notation).join('; ')}`;
    basis = 'criteria';
    if (inp.dataDeficient) warnings.push('Con un criterio de amenaza cumplido no procede DD: se propone la categoría del criterio.');
    if (inp.expertCategory) warnings.push(`Con un criterio de amenaza cumplido no procede ${inp.expertCategory} por juicio experto.`);
  } else if (inp.dataDeficient) {
    baseCategory = outcomes.dataDeficient;
    basis = 'dd';
    message = 'Datos insuficientes (DD), elegido por el investigador.';
  } else if (outcomes.expertJudgement.includes(inp.expertCategory)) {
    baseCategory = inp.expertCategory;
    basis = 'expert';
    message = `${NO_THREAT_MESSAGE}. Elegido: ${inp.expertCategory}.`;
  } else {
    message = NO_THREAT_MESSAGE;
  }

  // Ajuste regional: un escalón, con justificación. No se aplica a DD ni sin categoría.
  const regional = { direction: null, from: baseCategory, to: baseCategory, applied: false };
  const r = inp.regional || {};
  if (r.direction === 'down' || r.direction === 'up') {
    regional.direction = r.direction;
    const order = outcomes.order;
    const i = order.indexOf(baseCategory);
    const justification = typeof r.justification === 'string' ? r.justification.trim() : '';
    if (i < 0) warnings.push('El ajuste regional solo se aplica a CR, EN, VU, NT o LC.');
    else if (outcomes.regionalAdjustment.justificationRequired && !justification) warnings.push('El ajuste regional exige una justificación: no se ha aplicado.');
    else {
      const j = i + (r.direction === 'down' ? outcomes.regionalAdjustment.steps : -outcomes.regionalAdjustment.steps);
      if (j < 0 || j >= order.length) warnings.push(`No se puede ${r.direction === 'down' ? 'rebajar' : 'subir'} más allá de ${baseCategory}.`);
      else { regional.to = order[j]; regional.applied = true; }
    }
  }

  return {
    category: regional.to,
    baseCategory,
    criteriaString,
    determining,
    byCriterion,
    warnings,
    message,
    basis,
    regional,
  };
}

/** Criterio → texto corto de su resultado, para las listas («B: EN · B1ab(iii)+2ab(iii)»). */
export function criterionSummary(res) {
  if (res.status === 'skipped') return 'Sin datos';
  if (res.status === 'incomplete') return 'Datos incompletos';
  return res.category ? `${res.category} · ${res.notation}` : 'No alcanza umbrales';
}

// ================================================================================================
// F11g · Evaluación con incertidumbre (docs/iucn.md › «Cuantificadores e incertidumbre»).
// ------------------------------------------------------------------------------------------------
//   assessIucn(inputs, criteria) → evaluateIucn() con el MEJOR valor de cada dato + {displayCategory,
//                                  range, dataMinimum, possiblyExtinct, regionalV4}
// Un dato numérico puede llegar como número o como {best, min, max, openMax?} (calidad y fuente van aparte;
// `openMax`: el máximo no se conoce, y el escenario que lo pide queda sin dato). Se
// evalúa tres veces: con el mejor valor, con el extremo MENOS amenazante de cada intervalo y con el MÁS
// amenazante (rango plausible de las Guidelines). La categoría propuesta es la del mejor valor. Además:
//   · Ningún criterio evaluable (todos sin datos o incompletos) → DD («menos de los datos mínimos»).
//   · CR(PE) solo si el usuario lo decide (`possiblyExtinct: true`) y la categoría es CR; nunca solo.
//   · Ajuste regional v4.0 (paso 2) por preguntas: `regionalV4 {endemic, immigration, immigrationDecrease,
//     sink}` con yes · no · unknown → sin ajuste, rebajar o subir un escalón, con la justificación.
// Port en PHP: backend/Lib/iucn.php (mismos casos: shared/iucn-cases.json).
// ================================================================================================

// Dirección de la amenaza de cada dato con intervalo: «lower» = cuanto más bajo, más amenazada.
export const UNCERTAIN_FIELDS = {
  A: { reductionPct: 'higher' },
  B: { eoo: 'lower', aoo: 'lower', locations: 'lower' },
  C: { mature: 'lower', maxPerSubpop: 'lower', pctInOneSubpop: 'higher', c1DeclinePct: 'higher', c1AnnualRate: 'lower' },
  D: { mature: 'lower', aoo: 'lower', locations: 'lower' },
  E: { probabilityPct: 'higher' },
};
const CATEGORY_RANK = { CR: 0, EN: 1, VU: 2 };
const rankOf = (c) => (c in CATEGORY_RANK ? CATEGORY_RANK[c] : 3);
const isRange = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

/** Valor de un dato con intervalo para un escenario: best · low (menos amenaza) · high (más amenaza). */
function scenarioValue(v, dir, scenario) {
  if (!isRange(v)) return v;
  const best = isMissing(v.best) ? null : v.best;
  const lo = isMissing(v.min) ? best : v.min;
  const hi = isMissing(v.max) ? best : v.max;
  if (scenario === 'best') return best !== null ? best : (lo !== null ? lo : hi);
  const wantHigh = (scenario === 'low') === (dir === 'lower');   // menos amenaza con «lower» → el máximo
  // `openMax`: el máximo no se conoce (p. ej. maduros solo contados, sin parcelas): ese extremo queda sin dato.
  if (wantHigh && v.openMax === true) return null;
  return wantHigh ? hi : lo;
}

/** inputs con intervalos → inputs escalares de un escenario (lo demás, tal cual). */
export function scenarioInputs(inputs, scenario) {
  const out = { ...(inputs || {}) };
  for (const [k, fields] of Object.entries(UNCERTAIN_FIELDS)) {
    if (!out[k] || typeof out[k] !== 'object') continue;
    const step = { ...out[k] };
    for (const [f, dir] of Object.entries(fields)) if (f in step) step[f] = scenarioValue(step[f], dir, scenario);
    // F11h: A con varios subcriterios, cada uno con su reducción (con intervalo).
    if (k === 'A' && Array.isArray(step.items)) {
      step.items = step.items.map((it) => ('reductionPct' in it ? { ...it, reductionPct: scenarioValue(it.reductionPct, 'higher', scenario) } : it));
    }
    out[k] = step;
  }
  return out;
}

/**
 * Paso 2 de las Directrices regionales v4.0 (fig. 2) sobre la categoría preliminar del paso 1:
 * endémica del área → sin ajuste; sin inmigración significativa de propágulos (o no se sabe) → sin ajuste;
 * se espera que la inmigración disminuya (o no se sabe) → sin ajuste; si no, ¿la población regional es un
 * sumidero? sí → subir un escalón; no o no se sabe → rebajar un escalón (efecto rescate).
 * → {direction: up|down|null, path: [textos], justification}
 */
export function regionalStep2(q) {
  const a = q || {};
  const path = [];
  const done = (direction, last) => { path.push(last); return { direction, path, justification: path.join(' ') }; };
  if (a.endemic === 'yes') return done(null, 'Endémica del área de evaluación: no hay ajuste regional.');
  if (a.immigration !== 'yes') {
    return done(null, a.immigration === 'no' ? 'No recibe inmigración significativa de propágulos: sin ajuste.'
      : 'No se sabe si recibe inmigración significativa de propágulos: sin ajuste.');
  }
  path.push('Recibe inmigración significativa de propágulos capaces de reproducirse en la región.');
  if (a.immigrationDecrease !== 'no') {
    return done(null, a.immigrationDecrease === 'yes' ? 'Se espera que la inmigración disminuya: sin ajuste.'
      : 'No se sabe si la inmigración disminuirá: sin ajuste.');
  }
  path.push('No se espera que la inmigración disminuya.');
  if (a.sink === 'yes') return done('up', 'La población regional es un sumidero: se sube un escalón.');
  return done('down', a.sink === 'no' ? 'La población regional no es un sumidero: se rebaja un escalón (efecto rescate).'
    : 'No se sabe si es un sumidero: se rebaja un escalón (efecto rescate).');
}

/** ¿Hay al menos un criterio evaluado (cumplido o no)? */
const anyEvaluated = (r) => Object.values(r.byCriterion).some((c) => c.status === 'met' || c.status === 'not_met');

export function assessIucn(inputs, criteria) {
  const inp = { ...(inputs || {}) };
  let regionalV4 = null;
  if (inp.regionalV4) {
    regionalV4 = regionalStep2(inp.regionalV4);
    inp.regional = regionalV4.direction ? { direction: regionalV4.direction, justification: regionalV4.justification } : { direction: null };
  }
  const best = evaluateIucn(scenarioInputs(inp, 'best'), criteria);
  const low = evaluateIucn(scenarioInputs(inp, 'low'), criteria);
  const high = evaluateIucn(scenarioInputs(inp, 'high'), criteria);

  const dataMinimum = anyEvaluated(best) || anyEvaluated(low) || anyEvaluated(high);
  if (!dataMinimum && best.basis !== 'criteria') {
    if (best.basis === 'expert') best.warnings.push(`Sin datos mínimos no procede ${best.baseCategory} por juicio experto: sale DD.`);
    best.baseCategory = criteria.outcomes.dataDeficient;
    best.category = best.baseCategory;
    best.basis = 'insufficient';
    best.message = 'Datos insuficientes (DD): ningún criterio tiene los datos mínimos para evaluarse.';
    best.regional = { ...best.regional, from: best.category, to: best.category, applied: false };
  }

  // CR(PE): solo por decisión del usuario y solo sobre CR.
  let possiblyExtinct = false;
  if (inp.possiblyExtinct === true) {
    if (best.category === 'CR') possiblyExtinct = true;
    else best.warnings.push('«Posiblemente extinta» (PE) solo se aplica a CR: no se tiene en cuenta.');
  }
  if (possiblyExtinct && best.criteriaString && best.baseCategory === 'CR') best.criteriaString = best.criteriaString.replace(/^CR /, 'CR(PE) ');

  const threatened = (r) => (r.baseCategory in CATEGORY_RANK ? r.baseCategory : null);
  const lowCat = threatened(low);
  const highCat = threatened(high);
  const range = { low: lowCat, lowString: lowCat ? low.criteriaString : null, high: highCat, highString: highCat ? high.criteriaString : null,
    crossesCategories: rankOf(lowCat) !== rankOf(highCat) };
  if (range.crossesCategories) {
    best.warnings.push(`Los datos inciertos cruzan categorías: de ${lowCat || 'no amenazada'} a ${highCat || 'no amenazada'}.`);
  }
  return { ...best, displayCategory: possiblyExtinct ? 'CR(PE)' : best.category, possiblyExtinct, range, dataMinimum, regionalV4 };
}

/** «VU–EN», «EN» o «no amenazada–VU»: el rango plausible, de menos a más amenaza. */
export function rangeLabel(range) {
  if (!range) return '';
  const a = range.low || 'no amenazada';
  const b = range.high || 'no amenazada';
  return a === b ? a : `${a}–${b}`;
}
