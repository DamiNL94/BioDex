// ================================================================================================
// Pruebas del motor UICN (shared/iucn-engine.js):  node shared/iucn-engine.test.js
// Casos de tabla con cada umbral en su borde, leídos de shared/iucn-criteria.json (el mismo fichero
// que usa la app). Imprime OK/FAIL por caso y sale con código 1 si alguno falla.
// ================================================================================================

import { readFileSync } from 'node:fs';
import { evaluateIucn, assessIucn, NO_THREAT_MESSAGE } from './iucn-engine.js';
import { prepareRecords, matureEstimate, subpopulations, fragmentation, proposeLocations, minstd } from './iucn-quantifiers.js';
import { analyzeSeries, seriesFromVisits } from './iucn-series.js';

const criteria = JSON.parse(readFileSync(new URL('./iucn-criteria.json', import.meta.url), 'utf8'));

let failures = 0;
let count = 0;
const check = (label, ok, detail = '') => {
  count++;
  if (!ok) failures++;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${label}${ok || !detail ? '' : `  → ${detail}`}`);
};

// B con disminución continua del hábitat (b(iii)) y las localidades indicadas.
const B = (eoo, aoo, locations, extra = {}) => ({ B: { eoo, aoo, locations, decline: ['iii'], ...extra } });

// --- Casos compartidos con PHP (shared/iucn-cases.json; bin/test-f11g.php los pasa también) ----------
const CASES = JSON.parse(readFileSync(new URL('./iucn-cases.json', import.meta.url), 'utf8'));
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

for (const c of CASES.engine) {
  const r = c.fn === 'assess' ? assessIucn(c.inputs, criteria) : evaluateIucn(c.inputs, criteria);
  const e = c.expect;
  const bad = [];
  if ('category' in e && r.category !== e.category) bad.push(`categoría ${r.category}`);
  if ('criteriaString' in e && r.criteriaString !== e.criteriaString) bad.push(`cadena ${r.criteriaString}`);
  if ('baseCategory' in e && r.baseCategory !== e.baseCategory) bad.push(`base ${r.baseCategory}`);
  if ('displayCategory' in e && r.displayCategory !== e.displayCategory) bad.push(`mostrada ${r.displayCategory}`);
  if ('basis' in e && r.basis !== e.basis) bad.push(`base ${r.basis}`);
  if ('dataMinimum' in e && r.dataMinimum !== e.dataMinimum) bad.push(`datos mínimos ${r.dataMinimum}`);
  if ('rangeLow' in e && r.range.low !== e.rangeLow) bad.push(`rango bajo ${r.range.low}`);
  if ('rangeHigh' in e && r.range.high !== e.rangeHigh) bad.push(`rango alto ${r.range.high}`);
  if ('crosses' in e && r.range.crossesCategories !== e.crosses) bad.push('cruce');
  if ('regionalApplied' in e && r.regional.applied !== e.regionalApplied) bad.push(`ajuste ${r.regional.applied}`);
  if ('warningIncludes' in e && !r.warnings.some((w) => w.includes(e.warningIncludes))) bad.push(`avisos ${r.warnings.join(' | ')}`);
  check(c.name, !bad.length, bad.join('; '));
}

/** Ejecuta un caso de cuantificadores → lo comparable con `expect` (igual en bin/test-f11g.php). */
function runQuantifierCase(c) {
  const pts = prepareRecords(c.records);
  if (c.kind === 'mature') {
    const m = matureEstimate(pts, { habitatKm2: c.habitatKm2 ?? null, aooKm2: c.aooKm2 ?? null });
    return { min: m.min, best: m.best, max: m.max, nCounted: m.nCounted, doubleCountPairs: m.doubleCountPairs, method: m.method,
      areaSource: m.areaSource, density: m.density, warnings: m.warnings };
  }
  const mature = matureEstimate(pts, {});
  const sp = subpopulations(pts, c.separationKm, mature);
  if (c.kind === 'subpops') {
    return { count: sp.subpops.length, sizes: sp.subpops.map((s) => s.nRecords), nearestKm: sp.subpops.map((s) => s.nearestKm),
      labelsX: sp.subpops.map((s) => Math.min(...s.points.map((p) => p.x))) };
  }
  if (c.kind === 'fragmentation') {
    const f = fragmentation(sp.subpops, { minViable: c.minViable ?? null, dispersalKm: c.dispersalKm ?? null });
    return { status: f.status, severe: f.severe, pctAoo: f.pctAoo, pctIndividuals: f.pctIndividuals, missing: f.missing };
  }
  const l = proposeLocations(sp.subpops, c.impacts || []);
  return { count: l.count, threatened: l.threatened, groups: l.groups.map((g) => g.subpops) };
}

for (const c of CASES.quantifiers) {
  const got = runQuantifierCase(c);
  const bad = [];
  for (const [k, v] of Object.entries(c.expect)) {
    if (k === 'warningIncludes') { if (!got.warnings.some((w) => w.includes(v))) bad.push(`avisos ${got.warnings.join(' | ')}`); continue; }
    if (!same(got[k], v)) bad.push(`${k} ${JSON.stringify(got[k])}`);
  }
  check(c.name, !bad.length, bad.join('; '));
}

// --- Series temporales (casos de bin/series-reference.py: statsmodels o solución analítica) ---------
const near = (a, b, tol) => (a === null || b === null ? a === b : Math.abs(a - b) <= tol * Math.max(1, Math.abs(b)));
for (const c of CASES.series || []) {
  const s = analyzeSeries(c.obs, { generationLength: c.generationLength, criteria });
  const e = c.expect;
  const bad = [];
  if (s.status !== e.status) bad.push(`estado ${s.status}`);
  if (e.status === 'ok' && s.status === 'ok') {
    for (const k of ['r', 'se', 'lo', 'hi', 'phi']) if (!near(s.trend[k], e[k], 2e-6)) bad.push(`${k} ${s.trend[k]} ≠ ${e[k]}`);
    for (const k of ['best', 'min', 'max']) if (!near(s.reduction[k], e.reduction[k], 0.011)) bad.push(`reducción ${k} ${s.reduction[k]}`);
    for (const k of Object.keys(e.window)) if (s.window[k] !== e.window[k]) bad.push(`ventana ${k} ${s.window[k]}`);
    for (const [y, v] of Object.entries(e.index)) {
      const got = s.years.find((x) => x.year === Number(y));
      if (!got || !near(got.index, v, 1e-6)) bad.push(`índice ${y} ${got ? got.index : '—'} ≠ ${v}`);
    }
    if (s.fluctuation.extreme !== e.extreme) bad.push(`fluctuación ${s.fluctuation.extreme}`);
    if (s.suggestions.some((x) => x.key === 'decline_b_v') !== e.declineSuggested) bad.push('sugerencia de declive');
    if (s.nSites !== e.nSites || s.nYears !== e.nYears) bad.push(`sitios ${s.nSites} años ${s.nYears}`);
  }
  check(`Serie: ${c.name}`, !bad.length, bad.join('; '));
}
{
  // De visitas y marcas a observaciones: suma de maduros por visita, ausencia = 0, presencia sin maduros = no cuenta.
  const visits = [{ visitID: 'v1', siteID: 's1', eventStart: '2020-05-01T08:00:00Z' }, { visitID: 'v2', siteID: 's1', eventStart: '2021-05-01T08:00:00Z' },
    { visitID: 'v3', siteID: null, eventStart: '2021-05-02T08:00:00Z' }, { visitID: 'v4', siteID: 's1', eventStart: '2022-05-01T08:00:00Z' }];
  const occ = [{ visitID: 'v1', taxonID: 't', occurrenceStatus: 'present', mature_count: 3 }, { visitID: 'v1', taxonID: 't', occurrenceStatus: 'present', mature_count: 2 },
    { visitID: 'v2', taxonID: 't', occurrenceStatus: 'absent' }, { visitID: 'v3', taxonID: 't', occurrenceStatus: 'present', mature_count: 9 },
    { visitID: 'v4', taxonID: 't', occurrenceStatus: 'present', mature_count: null }, { visitID: 'v1', taxonID: 'otra', occurrenceStatus: 'present', mature_count: 7 }];
  const { obs, skipped } = seriesFromVisits({ visits, occurrences: occ, taxonID: 't' });
  check('Series: maduros por visita (suma, ausencia 0, sin sitio fuera, sin maduros no cuenta)',
    same(obs.map((o) => [o.visitID, o.year, o.mature]), [['v1', 2020, 5], ['v2', 2021, 0]]) && skipped === 1, JSON.stringify(obs));
}

// Bootstrap: misma semilla → mismo intervalo (la cifra exacta está en el caso y la comprueba también PHP).
const bootCase = CASES.quantifiers.find((c) => c.expect.density);
check('Bootstrap con semilla fija: dos ejecuciones dan lo mismo', bootCase
  && same(runQuantifierCase(bootCase).density, runQuantifierCase(bootCase).density));
check('MINSTD: primeros valores de la semilla 1', same([1, 2, 3].map(minstd(1)), [1, 2, 3].map(minstd(1))) && Math.abs(minstd(1)() - 48270 / 2147483646) < 1e-15);


// --- Detalle, avisos y salidas sin categoría de amenaza -------------------------------------------
let r = evaluateIucn(B(80, 12, 3), criteria);
check('Artemisia: B en CR no se cumple por (a) (3 > 1)', r.byCriterion.B.levels.CR.met === false
  && r.byCriterion.B.levels.CR.reasons.some((x) => x.includes('3 localidades > 1')), JSON.stringify(r.byCriterion.B.levels.CR.reasons));
check('Artemisia: la determina B', r.determining.join() === 'B', r.determining.join());

r = evaluateIucn(B(5, 12, 1), criteria);
check('EOO < AOO avisa', r.warnings.some((w) => w.includes('EOO = AOO')), r.warnings.join(' | '));

r = evaluateIucn({ A: { reductionPct: 85, subcriterion: 'A3', basis: ['a'] } }, criteria);
check('A3 con (a) avisa', r.warnings.some((w) => w.includes('A3 no admite')), r.warnings.join(' | '));
check('A3 solo con (a): datos incompletos', r.byCriterion.A.status === 'incomplete', r.byCriterion.A.status);

r = evaluateIucn({ A: { reductionPct: 50, subcriterion: 'A2', basis: ['b'] } }, criteria);
check('A: periodo sin generación = 10 años', r.byCriterion.A.periodYears === 10, String(r.byCriterion.A.periodYears));
r = evaluateIucn({ generationLength: 40, A: { reductionPct: 50, subcriterion: 'A3', basis: ['b'] } }, criteria);
check('A3: 3 generaciones de 40 años → tope de 100', r.byCriterion.A.periodYears === 100, String(r.byCriterion.A.periodYears));

r = evaluateIucn({}, criteria);
check('Sin datos: ninguna categoría y el mensaje de NT/LC', r.category === null && r.message === NO_THREAT_MESSAGE, `${r.category} ${r.message}`);
check('Sin datos: todos los criterios saltados', Object.values(r.byCriterion).every((c) => c.status === 'skipped'));
r = evaluateIucn({ dataDeficient: true }, criteria);
check('DD explícito', r.category === 'DD' && r.basis === 'dd', `${r.category} ${r.basis}`);
r = evaluateIucn({ dataDeficient: true, D: { mature: 30 } }, criteria);
check('DD con un criterio cumplido: se ignora y avisa', r.category === 'CR' && r.warnings.some((w) => w.includes('no procede DD')), r.category);
r = evaluateIucn({ expertCategory: 'NT' }, criteria);
check('NT por juicio experto', r.category === 'NT' && r.basis === 'expert', `${r.category} ${r.basis}`);

// --- Ajuste regional ------------------------------------------------------------------------------
r = evaluateIucn({ ...B(80, 12, 3), regional: { direction: 'down', justification: 'Inmigración desde Almería' } }, criteria);
check('Ajuste regional: EN → VU, antes y después', r.baseCategory === 'EN' && r.category === 'VU' && r.regional.applied
  && r.criteriaString === 'EN B1ab(iii)+2ab(iii)', `${r.baseCategory} → ${r.category}`);
r = evaluateIucn({ ...B(80, 12, 3), regional: { direction: 'up', justification: 'Sumidero' } }, criteria);
check('Ajuste regional: EN → CR', r.category === 'CR' && r.baseCategory === 'EN');
r = evaluateIucn({ ...B(80, 12, 3), regional: { direction: 'down', justification: '  ' } }, criteria);
check('Ajuste regional sin justificación: no se aplica', r.category === 'EN' && !r.regional.applied
  && r.warnings.some((w) => w.includes('justificación')), r.category);
r = evaluateIucn({ D: { mature: 10 }, regional: { direction: 'up', justification: 'x' } }, criteria);
check('Ajuste regional: no sube por encima de CR', r.category === 'CR' && !r.regional.applied);
r = evaluateIucn({ dataDeficient: true, regional: { direction: 'up', justification: 'x' } }, criteria);
check('Ajuste regional: no se aplica a DD', r.category === 'DD' && !r.regional.applied);

// --- Datos no válidos ------------------------------------------------------------------------------
r = evaluateIucn({ B: { eoo: -5, aoo: 'abc', decline: ['iii'], locations: 1 } }, criteria);
check('B con EOO y AOO no válidos: avisos y sin categoría', r.category === null && r.warnings.length >= 2, r.warnings.join(' | '));
r = evaluateIucn({ B: { eoo: '80', aoo: '12,5', locations: '1', decline: ['iii'] } }, criteria);
check('Números como texto (coma decimal) se aceptan', r.criteriaString === 'CR B1ab(iii)', r.criteriaString);
r = evaluateIucn({ E: { probabilityPct: 150, horizonYears: 10 } }, criteria);
check('E: probabilidad > 100 → aviso y sin categoría', r.category === null && r.warnings.some((w) => w.startsWith('E:')));

// Ningún umbral escrito en el motor: con otros umbrales, otro resultado.
const tweaked = JSON.parse(JSON.stringify(criteria));
tweaked.criteria.B.thresholdsKm2.B1_EOO.CR = 50;
tweaked.criteria.B.conditions.a.maxLocations.CR = 3;
r = evaluateIucn(B(80, 12, 3), tweaked);
check('Los umbrales salen del JSON (CR con EOO < 50 y ≤ 3 localidades → EN; con ≤ 3 y EOO 40 → CR)',
  r.category === 'EN' && evaluateIucn(B(40, 12, 3), tweaked).category === 'CR', r.criteriaString);

console.log(failures ? `\n${failures} fallo(s) de ${count}` : `\nTodo OK (${count} comprobaciones)`);
process.exit(failures ? 1 : 0);
