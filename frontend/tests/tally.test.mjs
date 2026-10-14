// ================================================================================================
// Pruebas de js/tally.js (conteo rápido):  node frontend/tests/tally.test.mjs
// ================================================================================================
import assert from 'node:assert/strict';
import { tallyCategories, emptyTally, tallyAdd, tallyUndo, tallyTotal, tallySummary, tallyToMarks, TALLY_MAX }
  from '../js/tally.js';

const fauna = tallyCategories('Animalia');
const flora = tallyCategories('Plantae');
assert.equal(tallyCategories('Fungi'), tallyCategories(null), 'reino desconocido → «other»');
assert.ok(fauna.some((c) => c.sex === 'male') && !flora.some((c) => c.sex), 'sexo solo en fauna');

// Contar, descontar y deshacer
const t = emptyTally();
['male', 'male', 'female', 'juvenile', 'male'].forEach((k) => tallyAdd(t, k));
assert.equal(tallyTotal(t), 5);
assert.equal(tallyUndo(t), 'male');
assert.equal(t.counts.male, 2);
assert.equal(tallyAdd(t, 'larva', -1), false, 'no baja de 0');
tallyAdd(t, 'female', -1);
assert.equal(t.counts.female, 0);
assert.ok(!t.history.includes('female'), '− también saca el toque del historial');
assert.equal(tallyUndo(t), 'juvenile');
assert.equal(tallyUndo(t), 'male');
assert.equal(tallyUndo(t), 'male');
assert.equal(tallyUndo(t), null);
assert.equal(tallyTotal(t), 0);

// Tope
const big = emptyTally();
big.counts.adult = TALLY_MAX;
assert.equal(tallyAdd(big, 'adult'), false);

// Paso a marcas: una por categoría con individuos, maduros según la categoría
const c = emptyTally();
for (let i = 0; i < 3; i++) tallyAdd(c, 'male');
for (let i = 0; i < 5; i++) tallyAdd(c, 'female');
tallyAdd(c, 'juvenile'); tallyAdd(c, 'juvenile');
const marks = tallyToMarks(fauna, c, { habitat: 'Borreguil', remarks: '  En el canchal  ' });
assert.equal(marks.length, 3);
assert.deepEqual(marks.map((m) => [m.sex, m.lifeStage, m.individualCount, m.mature_count]),
  [['male', 'adult', 3, 3], ['female', 'adult', 5, 5], [null, 'juvenile', 2, null]]);
assert.equal(marks[0].occurrenceRemarks,
  'En el canchal\nConteo rápido (n = 10): Macho adulto 3 · Hembra adulta 5 · Juvenil o cría 2');
assert.ok(marks.every((m) => m.occurrenceStatus === 'present' && m.habitat === 'Borreguil'));

const p = emptyTally();
tallyAdd(p, 'flowering'); tallyAdd(p, 'seedling'); tallyAdd(p, 'seedling');
const pm = tallyToMarks(flora, p);
assert.deepEqual(pm.map((m) => [m.lifeStage, m.individualCount, m.mature_count]), [['seedling', 2, null], ['flowering', 1, 1]]);
assert.equal(pm[0].occurrenceRemarks, 'Conteo rápido (n = 3): Plántula 2 · En flor 1');
assert.equal(tallySummary(flora, p), pm[1].occurrenceRemarks);

console.log('tally.test.mjs: OK');
