// ================================================================================================
// Casos compartidos de EOO/AOO en JS:  node shared/geo-metrics.test.js
// Los mismos (shared/metrics-cases.json) los pasa backend/bin/test-f9.php con el port en PHP.
// ================================================================================================

import { readFileSync } from 'node:fs';
import { computeMetrics, metricsFromPoints } from './geo-metrics.js';
import { toUtm } from '../frontend/js/geo/utm.js';

const data = JSON.parse(readFileSync(new URL('./metrics-cases.json', import.meta.url), 'utf8'));
const TOL = data.toleranceKm2;
let failures = 0;
let count = 0;
const check = (label, ok, detail = '') => {
  count++;
  if (!ok) failures++;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${label}${ok || !detail ? '' : `  → ${detail}`}`);
};
const near = (a, b, tol = TOL) => (a === null || b === null ? a === b : Math.abs(a - b) <= tol);

/** Registros de un caso: los suyos o los de `records` (lat, lon) con precisión de 10 m. */
function recordsOf(c) {
  if (c.records) return c.records;
  return data.records[c.recordsRef].map(([lat, lon]) => ({ decimalLatitude: lat, decimalLongitude: lon,
    coordinateUncertaintyInMeters: 10, occurrenceStatus: 'present' }));
}

for (const c of data.cases) {
  const m = c.points
    ? metricsFromPoints(c.points.map(([x, y]) => ({ x, y })), c.cellKm)
    : computeMetrics(recordsOf(c), { cellKm: c.cellKm, project: toUtm });
  const e = c.expect;
  const bad = [];
  if ('eooKm2' in e && !near(m.eooKm2, e.eooKm2)) bad.push(`eoo ${m.eooKm2}`);
  if ('aooKm2' in e && !near(m.aooKm2, e.aooKm2)) bad.push(`aoo ${m.aooKm2}`);
  if ('aooCells' in e && m.aooCells !== e.aooCells) bad.push(`celdas ${m.aooCells}`);
  if ('eooAdjusted' in e && m.eooAdjusted !== e.eooAdjusted) bad.push(`igualada ${m.eooAdjusted}`);
  if ('hullVertices' in e && m.hull.length !== e.hullVertices) bad.push(`vértices ${m.hull.length}`);
  if ('cells' in e && JSON.stringify(m.cells) !== JSON.stringify(e.cells)) bad.push(`celdas ${JSON.stringify(m.cells)}`);
  if ('aooRangeMin' in e && !near(m.aooRange.min, e.aooRangeMin)) bad.push(`mín ${m.aooRange.min}`);
  if ('aooRangeMax' in e && !near(m.aooRange.max, e.aooRangeMax)) bad.push(`máx ${m.aooRange.max}`);
  if ('nRecords' in e && m.nRecords !== e.nRecords) bad.push(`registros ${m.nRecords}`);
  if ('nExcluded' in e && m.nExcluded !== e.nExcluded) bad.push(`excluidos ${m.nExcluded}`);
  if ('excluded' in e && JSON.stringify(m.excluded) !== JSON.stringify(e.excluded)) bad.push(`excluidos ${JSON.stringify(m.excluded)}`);
  if ('warningIncludes' in e && !m.warnings.some((w) => w.includes(e.warningIncludes))) bad.push(`avisos ${m.warnings.join(' | ')}`);
  if ('projected' in e) {
    const recs = recordsOf(c);
    for (const [i, x, y] of e.projected) {
      const u = toUtm(recs[i].decimalLatitude, recs[i].decimalLongitude);
      if (Math.abs(u.easting - x) > 0.01 || Math.abs(u.northing - y) > 0.01) bad.push(`punto ${i}: ${u.easting.toFixed(4)}, ${u.northing.toFixed(4)}`);
    }
  }
  check(c.name, !bad.length, bad.join(' · '));
}

let threw = false;
try { computeMetrics([], { cellKm: 5, project: toUtm }); } catch (e) { threw = e instanceof RangeError; }
check('Celda que no es 2 ni 1 km → RangeError', threw);
threw = false;
try { computeMetrics([]); } catch (e) { threw = true; }
check('Sin proyección → error', threw);
const empty = computeMetrics([], { project: toUtm });
check('Sin registros: ni EOO ni AOO, con aviso', empty.eooKm2 === null && empty.aooKm2 === null && empty.warnings.length === 1);

console.log(failures ? `\n${failures} fallo(s) de ${count}` : `\nTodo OK (${count} comprobaciones)`);
process.exit(failures ? 1 : 0);
