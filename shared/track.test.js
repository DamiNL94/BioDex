// ================================================================================================
// Recorrido GPS de una visita en JS:  node shared/track.test.js
// Casos compartidos (shared/track-cases.json): aquí se filtran los fixes crudos con appendFix() y se comprueba el
// recorrido resultante, su análisis y el esfuerzo; backend/bin/test-track.php comprueba análisis y esfuerzo con el
// port en PHP. Además, las cifras clave se comprueban a mano (no solo contra el JSON generado).
// ================================================================================================

import { readFileSync } from 'node:fs';
import { TRACK, appendFix, analyzeTrack, visitEffort, trackSegments, trackDistance, pausedSeconds } from './track.js';
import { toUtm } from '../frontend/js/geo/utm.js';

const data = JSON.parse(readFileSync(new URL('./track-cases.json', import.meta.url), 'utf8'));
let failures = 0;
let count = 0;
const check = (label, ok, detail = '') => {
  count++;
  if (!ok) failures++;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${label}${ok || !detail ? '' : `  → ${detail}`}`);
};
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const byName = (s) => data.cases.find((c) => c.name.startsWith(s));

for (const c of data.cases) {
  const track = [];
  const reasons = {};
  for (const [lat, lon, t, acc] of c.fixes) { const r = appendFix(track, { lat, lon, t, acc }); reasons[r] = (reasons[r] || 0) + 1; }
  check(`Filtrado: ${c.name}`, same(track, c.track) && same(reasons, c.reasons), JSON.stringify(reasons));
  const a = analyzeTrack(c.track, c.pauses, toUtm);
  check(`Análisis: ${c.name}`, same(a, c.expect), JSON.stringify(a));
  check(`Tramos: ${c.name}`, trackSegments(c.track, c.pauses).length === c.segments);
  if (c.visit) check(`Esfuerzo: ${c.name}`, same(visitEffort(c.visit, a), c.expectEffort), JSON.stringify(visitEffort(c.visit, a)));
}

// --- A mano -------------------------------------------------------------------------------------------
check('Un grado de latitud son 111,195 km', Math.abs(trackDistance(37, -3, 38, -3) - 111195) < 1);
let c = byName('Recta de 1000 m');
check('Recta: 1000 m ± 1 m, sin huecos, dos celdas de 2 km', Math.abs(c.expect.distanceM - 1000) <= 1 && c.expect.gaps === 0 && c.expect.cells.length === 2);
c = byName('Parado 10 min');
check('Parado: el ruido no suma distancia y el tiempo cuenta como vigilado',
  c.expect.distanceM === 0 && c.expect.nPoints === 1 && c.expect.trackedSeconds === 600 && c.expectEffort.trackComplete === true);
c = byName('Fixes de 50 m');
check(`Precisión: fuera los fixes peores de ${TRACK.maxAccuracyM} m`, c.reasons.accuracy === 5 && Math.abs(c.expect.distanceM - 380) <= 1);
c = byName('Salto imposible');
check('Salto imposible descartado: 300 m, no 4300', c.reasons.jump === 1 && Math.abs(c.expect.distanceM - 300) <= 1);
c = byName('Hueco de 15 min');
check('Hueco: no suma los 600 m ni el tiempo, y la visita queda con el recorrido incompleto',
  c.expect.gaps === 1 && Math.abs(c.expect.distanceM - 400) <= 1 && c.expect.trackedSeconds === 200 && c.segments === 2 && c.expectEffort.trackComplete === false);
c = byName('La misma parada');
check('Pausa: ni hueco ni tiempo activo; recorrido completo',
  c.expect.gaps === 0 && c.expectEffort.activeSeconds === 210 && c.expectEffort.trackComplete === true && c.expectEffort.targetMet === true);
c = byName('5 km al este');
check('Celdas: 5 km en línea cruzan cuatro celdas contiguas de la misma fila y el objetivo de 6000 m no se alcanza',
  c.expect.cells.length === 4 && new Set(c.expect.cells.map((k) => k.split(',')[1])).size === 1 && c.expectEffort.targetMet === false);
c = byName('Visita de 40 min');
check('Cobertura 0,25: tiempo cumplido pero recorrido incompleto', c.expectEffort.coverage === 0.25 && c.expectEffort.targetMet && !c.expectEffort.trackComplete);

// Un tramo largo entre dos fixes (sin hueco) no se salta la celda intermedia.
{
  const a = analyzeTrack([[37.1, -3.5, 0, 5, 0], [37.1, -3.44, 100, 5, 100]], [], toUtm);   // ≈ 5,3 km en 100 s
  check('Tramo largo: se muestrea cada 250 m y no se salta celdas', a.cells.length >= 3, JSON.stringify(a.cells));
}
check('pausedSeconds recorta al intervalo y cierra una pausa abierta en el fin', pausedSeconds([[0, 50], [90, null]], 10, 100) === 50);
{
  const t = [];
  appendFix(t, { lat: 37.1, lon: -3.5, t: 100, acc: 5 });
  check('appendFix: un fix anterior al último no entra', appendFix(t, { lat: 37.2, lon: -3.5, t: 90, acc: 5 }) === 'time' && t.length === 1);
  check('appendFix: sin precisión no entra', appendFix(t, { lat: 37.2, lon: -3.5, t: 200 }) === 'accuracy');
  check('appendFix: tras un hueco no se juzga la velocidad', appendFix(t, { lat: 37.11, lon: -3.5, t: 100 + TRACK.gapS + 1, acc: 5 }) === 'added');
}
check('Libre: sin valor ni objetivo cumplido', same(visitEffort({ effortProtocol: 'free', effortTarget: null, start: 0, end: 600, pauses: [] }, null),
  { activeSeconds: 600, value: null, unit: null, targetMet: false, trackComplete: false, coverage: 0 }));

console.log(failures ? `\n${failures} fallo(s) de ${count}` : `\nTodo OK (${count} comprobaciones)`);
process.exit(failures ? 1 : 0);
