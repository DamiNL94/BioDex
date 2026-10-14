// ================================================================================================
// Pruebas de js/tally.js (conteo de una marca nueva):  node frontend/tests/tally.test.mjs
// ================================================================================================
import assert from 'node:assert/strict';
import { tallyCategories, emptyTally, tallyAdd, tallySet, tallyUndo, tallyKeep, tallyTotal, tallySummary,
  tallyToMarks, TALLY_MAX } from '../js/tally.js';

const fauna = tallyCategories('Animalia');
const flora = tallyCategories('Plantae');
assert.equal(tallyCategories('Fungi'), tallyCategories(null), 'reino desconocido → «other»');
assert.ok(fauna.some((c) => c.sex === 'male') && !flora.some((c) => c.sex), 'sexo solo en fauna');
// Todos los estados vitales de la hoja antigua siguen teniendo casilla
const stages = (cats) => new Set(cats.map((c) => c.lifeStage));
for (const s of ['adult', 'subadult', 'juvenile', 'larva', 'egg', 'undetermined']) assert.ok(stages(fauna).has(s), s);
for (const s of ['seedling', 'vegetative', 'flowering', 'fruiting', 'adult', 'undetermined']) assert.ok(stages(flora).has(s), s);
assert.ok(fauna.some((c) => c.sex === 'hermaphrodite'));

// Contar, descontar, escribir la cifra y deshacer
const t = emptyTally();
['male', 'male', 'female', 'juvenile', 'male'].forEach((k) => tallyAdd(t, k));
assert.equal(tallyTotal(t), 5);
assert.equal(tallyUndo(t), 'male');
assert.equal(t.counts.male, 2);
assert.equal(tallyAdd(t, 'larva', -1), false, 'no baja de 0');
assert.ok(tallySet(t, 'female', 40));
assert.equal(tallyTotal(t), 43);
assert.equal(tallySet(t, 'female', 40), false, 'la misma cifra no es un cambio');
tallyAdd(t, 'female', -1);
assert.equal(t.counts.female, 39);
assert.equal(tallyUndo(t), 'female'); assert.equal(t.counts.female, 40, 'deshace el −');
assert.equal(tallyUndo(t), 'female'); assert.equal(t.counts.female, 1, 'deshace la cifra escrita');
assert.equal(tallySet(t, 'adult', -5), false, 'negativo → 0, sin cambio');
assert.equal(tallySet(t, 'adult', 'abc'), false);
while (tallyUndo(t)) { /* hasta vaciar */ }
assert.equal(tallyTotal(t), 0);
assert.equal(tallyUndo(t), null);

// Tope
const big = emptyTally();
tallySet(big, 'adult', 10 ** 9);
assert.equal(big.counts.adult, TALLY_MAX);
assert.equal(tallyAdd(big, 'adult'), false);

// Cambio de reino: se conserva lo que tiene casilla en el nuevo
const k = emptyTally();
tallyAdd(k, 'adult'); tallyAdd(k, 'juvenile'); tallyAdd(k, 'unknown');
const kf = tallyKeep(k, flora.map((c) => c.key));
assert.deepEqual(kf.counts, { adult: 1, unknown: 1 });
assert.deepEqual(kf.history.map((h) => h.key), ['adult', 'unknown']);

// Paso a marcas: una por categoría con individuos, maduros según la categoría
const c = emptyTally();
tallySet(c, 'male', 3); tallySet(c, 'female', 5); tallySet(c, 'juvenile', 2);
const marks = tallyToMarks(fauna, c, { habitat: 'Borreguil', remarks: '  En el canchal  ' });
assert.deepEqual(marks.map((m) => [m.sex, m.lifeStage, m.individualCount, m.mature_count]),
  [['male', 'adult', 3, 3], ['female', 'adult', 5, 5], [null, 'juvenile', 2, null]]);
assert.equal(marks[0].occurrenceRemarks,
  'En el canchal\nConteo (n = 10): Macho adulto 3 · Hembra adulta 5 · Juvenil o cría 2');
assert.ok(marks.every((m) => m.occurrenceStatus === 'present' && m.habitat === 'Borreguil'));

// Una sola categoría: una marca, sin resumen (no hay grupo que reconstruir)
const one = emptyTally();
tallySet(one, 'flowering', 120);
const om = tallyToMarks(flora, one, { remarks: 'Ladera norte' });
assert.deepEqual(om.map((m) => [m.lifeStage, m.individualCount, m.mature_count, m.occurrenceRemarks]),
  [['flowering', 120, 120, 'Ladera norte']]);
assert.equal(tallyToMarks(flora, one)[0].occurrenceRemarks, '');

const p = emptyTally();
tallyAdd(p, 'flowering'); tallyAdd(p, 'seedling'); tallyAdd(p, 'seedling');
const pm = tallyToMarks(flora, p);
assert.deepEqual(pm.map((m) => [m.lifeStage, m.individualCount, m.mature_count]), [['seedling', 2, null], ['flowering', 1, 1]]);
assert.equal(pm[0].occurrenceRemarks, 'Conteo (n = 3): Plántula 2 · En flor 1');
assert.equal(tallySummary(flora, p), pm[1].occurrenceRemarks);

// Observaciones largas: el resumen cabe siempre en el límite del servidor
const long = tallyToMarks(fauna, c, { remarks: 'x'.repeat(5000) });
assert.ok(long[0].occurrenceRemarks.length <= 4000 && long[0].occurrenceRemarks.endsWith('Juvenil o cría 2'));

console.log('tally.test.mjs: OK');
