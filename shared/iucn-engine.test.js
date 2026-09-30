// ================================================================================================
// Pruebas del motor UICN (shared/iucn-engine.js):  node shared/iucn-engine.test.js
// Casos de tabla con cada umbral en su borde, leídos de shared/iucn-criteria.json (el mismo fichero
// que usa la app). Imprime OK/FAIL por caso y sale con código 1 si alguno falla.
// ================================================================================================

import { readFileSync } from 'node:fs';
import { evaluateIucn, NO_THREAT_MESSAGE } from './iucn-engine.js';

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

// [nombre, inputs, categoría esperada, cadena esperada]
const TABLE = [
  // --- B: el caso del recorrido y sus bordes -----------------------------------------------------
  ['Artemisia: EOO 80, AOO 12, 3 localidades, b(iii) → EN, no CR', B(80, 12, 3), 'EN', 'EN B1ab(iii)+2ab(iii)'],
  ['Artemisia con 1 localidad → CR B1ab(iii)', B(80, 12, 1), 'CR', 'CR B1ab(iii)'],
  ['B1: EOO 99 km² con 1 localidad → CR', B(99, null, 1), 'CR', 'CR B1ab(iii)'],
  ['B1: EOO 100 km² con 1 localidad → EN', B(100, null, 1), 'EN', 'EN B1ab(iii)'],
  ['B1: EOO 4999 → EN', B(4999, null, 5), 'EN', 'EN B1ab(iii)'],
  ['B1: EOO 5000 → VU', B(5000, null, 5), 'VU', 'VU B1ab(iii)'],
  ['B1: EOO 20000 → no cumple', B(20000, null, 5), null, null],
  ['B2: AOO 9 con 1 localidad → CR', B(null, 9, 1), 'CR', 'CR B2ab(iii)'],
  ['B2: AOO 10 con 1 localidad → EN', B(null, 10, 1), 'EN', 'EN B2ab(iii)'],
  ['B2: AOO 1999 → VU; 2000 no', B(null, 1999, 8), 'VU', 'VU B2ab(iii)'],
  ['B2: AOO 2000 → no cumple', B(null, 2000, 8), null, null],
  ['B(a): 5 localidades → EN', B(3000, null, 5), 'EN', 'EN B1ab(iii)'],
  ['B(a): 6 localidades → VU', B(3000, null, 6), 'VU', 'VU B1ab(iii)'],
  ['B(a): 10 localidades → VU; 11 → (a) no se cumple', B(3000, null, 10), 'VU', 'VU B1ab(iii)'],
  ['B(a): 11 localidades → solo (b) → no cumple', B(3000, null, 11), null, null],
  ['B: un solo subcriterio (b) sin localidades → no cumple', { B: { eoo: 50, aoo: 8, decline: ['iii'] } }, null, null],
  ['B: fragmentación severa vale como (a) en CR', { B: { eoo: 50, severelyFragmented: true, decline: ['i', 'iii'] } }, 'CR', 'CR B1ab(i,iii)'],
  ['B: (b) y (c) sin (a) → cumple en CR', { B: { eoo: 50, decline: ['v'], fluctuation: ['iv'] } }, 'CR', 'CR B1b(v)c(iv)'],
  ['B: EOO 5 < AOO 12 → EOO = 12 (y aviso) → CR B1', B(5, 12, 1), 'CR', 'CR B1ab(iii)'],

  // --- A ----------------------------------------------------------------------------------------
  ['A2: 80 % con b y c → CR', { A: { reductionPct: 80, subcriterion: 'A2', basis: ['b', 'c'] } }, 'CR', 'CR A2bc'],
  ['A2: 79 % → EN', { A: { reductionPct: 79, subcriterion: 'A2', basis: ['b', 'c'] } }, 'EN', 'EN A2bc'],
  ['A2: 30 % → VU; 29 % no', { A: { reductionPct: 30, subcriterion: 'A2', basis: ['c'] } }, 'VU', 'VU A2c'],
  ['A2: 29 % → no cumple', { A: { reductionPct: 29, subcriterion: 'A2', basis: ['c'] } }, null, null],
  ['A1: 90 % → CR; umbrales propios de A1', { A: { reductionPct: 90, subcriterion: 'A1', basis: ['a'] } }, 'CR', 'CR A1a'],
  ['A1: 89 % → EN', { A: { reductionPct: 89, subcriterion: 'A1', basis: ['a'] } }, 'EN', 'EN A1a'],
  ['A1: 49 % → no cumple (VU exige 50)', { A: { reductionPct: 49, subcriterion: 'A1', basis: ['a'] } }, null, null],
  ['A3 solo con (a) → rechazado, no cumple', { A: { reductionPct: 85, subcriterion: 'A3', basis: ['a'] } }, null, null],
  ['A3 con (a) y (b) → (a) fuera: CR A3b', { A: { reductionPct: 85, subcriterion: 'A3', basis: ['a', 'b'] } }, 'CR', 'CR A3b'],

  // --- C ----------------------------------------------------------------------------------------
  ['C1: 249 maduros, 25 % en 3 años → CR', { C: { mature: 249, c1DeclinePct: 25, c1Years: 3 } }, 'CR', 'CR C1'],
  ['C1: 250 maduros → EN (CR exige < 250)', { C: { mature: 250, c1DeclinePct: 25, c1Years: 3 } }, 'EN', 'EN C1'],
  ['C1: 24 % en 3 años → no CR; EN tampoco (20 % en 5 años: sí) → EN', { C: { mature: 200, c1DeclinePct: 24, c1Years: 3 } }, 'EN', 'EN C1'],
  ['C1: plazo con generaciones: 25 % en 12 años con generación de 12 → CR', { generationLength: 12, C: { mature: 100, c1DeclinePct: 25, c1Years: 12 } }, 'CR', 'CR C1'],
  ['C2a(i): máx. 50 por subpoblación y disminución → CR', { C: { mature: 200, continuingDecline: true, maxPerSubpop: 50 } }, 'CR', 'CR C2a(i)'],
  ['C2a(i): máx. 51 → EN', { C: { mature: 200, continuingDecline: true, maxPerSubpop: 51 } }, 'EN', 'EN C2a(i)'],
  ['C2 sin disminución continua → no cumple', { C: { mature: 200, maxPerSubpop: 10 } }, null, null],
  ['C2a(ii) 96 % y fluctuaciones con 2000 maduros → EN C2a(ii)b', { C: { mature: 2000, continuingDecline: true, pctInOneSubpop: 96, extremeFluctuation: true } }, 'EN', 'EN C2a(ii)b'],
  ['C1+2: combinación en la misma categoría', { C: { mature: 9000, c1DeclinePct: 10, c1Years: 10, continuingDecline: true, pctInOneSubpop: 100 } }, 'VU', 'VU C1+2a(ii)'],
  ['C: sin maduros → no se evalúa', { C: { c1DeclinePct: 50, c1Years: 3 } }, null, null],

  // --- D ----------------------------------------------------------------------------------------
  ['D: 49 maduros → CR D', { D: { mature: 49 } }, 'CR', 'CR D'],
  ['D: 50 maduros → EN D', { D: { mature: 50 } }, 'EN', 'EN D'],
  ['D: 999 maduros → VU D1', { D: { mature: 999 } }, 'VU', 'VU D1'],
  ['D: 1000 maduros → no cumple', { D: { mature: 1000 } }, null, null],
  ['D2 solo VU (AOO 19 y amenaza), aunque sea poco', { D: { plausibleThreat: true, aoo: 19 } }, 'VU', 'VU D2'],
  ['D2: AOO 20 y 6 localidades → no cumple', { D: { plausibleThreat: true, aoo: 20, locations: 6 } }, null, null],
  ['D2 sin amenaza plausible → no cumple', { D: { aoo: 4, locations: 1 } }, null, null],
  ['D1+2 en VU', { D: { mature: 800, plausibleThreat: true, locations: 5 } }, 'VU', 'VU D1+2'],

  // --- E ----------------------------------------------------------------------------------------
  ['E: 50 % en 10 años → CR', { E: { probabilityPct: 50, horizonYears: 10 } }, 'CR', 'CR E'],
  ['E: 49 % en 10 años → EN', { E: { probabilityPct: 49, horizonYears: 10 } }, 'EN', 'EN E'],
  ['E: 20 % en 20 años → EN; en 21 → VU', { E: { probabilityPct: 20, horizonYears: 21 } }, 'VU', 'VU E'],
  ['E: 9 % en 100 años → no cumple', { E: { probabilityPct: 9, horizonYears: 100 } }, null, null],

  // --- Cadenas combinadas -----------------------------------------------------------------------
  ['Combinada VU A2bc; D2', { A: { reductionPct: 35, subcriterion: 'A2', basis: ['b', 'c'] }, D: { plausibleThreat: true, locations: 3 } }, 'VU', 'VU A2bc; D2'],
  ['Combinada: solo figura lo de la categoría más alta', { A: { reductionPct: 35, subcriterion: 'A2', basis: ['b'] }, ...B(80, 12, 3), D: { mature: 40 } }, 'CR', 'CR D'],
  ['Combinada EN A2c; B1ab(iii)+2ab(iii); C2a(i)', { A: { reductionPct: 60, subcriterion: 'A2', basis: ['c'] }, ...B(80, 12, 3),
    C: { mature: 1500, continuingDecline: true, maxPerSubpop: 200 } }, 'EN', 'EN A2c; B1ab(iii)+2ab(iii); C2a(i)'],
];

for (const [name, inputs, cat, str] of TABLE) {
  const r = evaluateIucn(inputs, criteria);
  check(name, r.category === cat && r.criteriaString === str, `${r.category} · ${r.criteriaString}`);
}

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
