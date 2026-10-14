// ================================================================================================
// tally.js — conteo de una marca nueva (F11e): categorías por reino y paso de los totales a marcas.
// ------------------------------------------------------------------------------------------------
// Marcar es contar: se toca una casilla por individuo (macho, hembra, juvenil… o plántula, en flor…)
// o se escribe la cifra. Al guardar, cada categoría con algún individuo es UNA marca Darwin Core en el
// mismo punto y a la misma hora: una ocurrencia solo tiene un sexo y un estado vital, así que no se
// mezclan. Si salen varias, todas llevan en occurrenceRemarks el resumen del conteo entero, para poder
// reconstruir el grupo. (Sin contar nada, la hoja guarda 1 individuo sin detallar: views/mark-sheet.js.)
// Maduros (mature_count): adultos en fauna y lo que está en flor o en fruto en flora, es decir, lo
// capaz de reproducirse (definición UICN de individuo maduro). Se puede corregir editando la marca.
// `extra`: categorías poco frecuentes, detrás de «Más categorías».
// Sin imports: lo prueba node (frontend/tests/tally.test.mjs).
// ================================================================================================

export const TALLY_MAX = 100000;
const REMARKS_MAX = 4000;   // SYNC_REMARKS_MAX del servidor

export const TALLY_CATEGORIES = {
  Animalia: [
    { key: 'male', label: 'Macho adulto', lifeStage: 'adult', sex: 'male', mature: true },
    { key: 'female', label: 'Hembra adulta', lifeStage: 'adult', sex: 'female', mature: true },
    { key: 'adult', label: 'Adulto sin sexar', lifeStage: 'adult', sex: 'undetermined', mature: true },
    { key: 'juvenile', label: 'Juvenil o cría', lifeStage: 'juvenile', sex: null, mature: false },
    { key: 'larva', label: 'Larva', lifeStage: 'larva', sex: null, mature: false },
    { key: 'unknown', label: 'Indeterminado', lifeStage: 'undetermined', sex: null, mature: false },
    { key: 'subadult', label: 'Subadulto', lifeStage: 'subadult', sex: null, mature: false, extra: true },
    { key: 'egg', label: 'Huevo o puesta', lifeStage: 'egg', sex: null, mature: false, extra: true },
    { key: 'hermaphrodite', label: 'Hermafrodita', lifeStage: 'adult', sex: 'hermaphrodite', mature: true, extra: true },
  ],
  Plantae: [
    { key: 'seedling', label: 'Plántula', lifeStage: 'seedling', sex: null, mature: false },
    { key: 'vegetative', label: 'Vegetativa', lifeStage: 'vegetative', sex: null, mature: false },
    { key: 'flowering', label: 'En flor', lifeStage: 'flowering', sex: null, mature: true },
    { key: 'fruiting', label: 'En fruto', lifeStage: 'fruiting', sex: null, mature: true },
    { key: 'unknown', label: 'Indeterminada', lifeStage: 'undetermined', sex: null, mature: false },
    { key: 'adult', label: 'Adulta (sin fenología)', lifeStage: 'adult', sex: null, mature: true, extra: true },
  ],
  other: [
    { key: 'adult', label: 'Adulto', lifeStage: 'adult', sex: null, mature: true },
    { key: 'juvenile', label: 'Juvenil', lifeStage: 'juvenile', sex: null, mature: false },
    { key: 'unknown', label: 'Indeterminado', lifeStage: 'undetermined', sex: null, mature: false },
  ],
};

export const tallyCategories = (kingdom) => TALLY_CATEGORIES[kingdom] || TALLY_CATEGORIES.other;

/** Conteo vacío: { counts: {key: n}, history: [{key, prev}…] } (history sirve para «Deshacer»). */
export const emptyTally = () => ({ counts: {}, history: [] });

const clamp = (n) => Math.max(0, Math.min(TALLY_MAX, Math.round(Number(n) || 0)));

/** Fija la cifra de una casilla. Devuelve true si cambió (y lo apunta para «Deshacer»). */
export function tallySet(tally, key, n) {
  const prev = tally.counts[key] || 0;
  const next = clamp(n);
  if (next === prev) return false;
  tally.counts[key] = next;
  tally.history.push({ key, prev });
  return true;
}

/** Suma (o resta) `delta` individuos a una casilla. */
export function tallyAdd(tally, key, delta = 1) {
  return tallySet(tally, key, (tally.counts[key] || 0) + delta);
}

/** Deshace el último cambio (un toque, un «−» o una cifra escrita). Devuelve su clave o null. */
export function tallyUndo(tally) {
  const last = tally.history.pop();
  if (!last) return null;
  tally.counts[last.key] = last.prev;
  return last.key;
}

/** Se queda con las casillas de `keys` (cambio de especie a otro reino): lo demás, fuera. */
export function tallyKeep(tally, keys) {
  const keep = new Set(keys);
  const next = emptyTally();
  for (const k of keep) if (tally.counts[k]) next.counts[k] = tally.counts[k];
  next.history = tally.history.filter((h) => keep.has(h.key));
  return next;
}

export function tallyTotal(tally) {
  return Object.values(tally.counts).reduce((a, n) => a + (n || 0), 0);
}

/** «Conteo (n = 10): Macho adulto 3 · Hembra adulta 5 · Juvenil o cría 2». */
export function tallySummary(categories, tally) {
  const parts = categories.filter((c) => tally.counts[c.key] > 0).map((c) => `${c.label} ${tally.counts[c.key]}`);
  return `Conteo (n = ${tallyTotal(tally)}): ${parts.join(' · ')}`;
}

/**
 * Campos de marca (los que espera markFields) de cada categoría con individuos, en el orden de las
 * casillas. `remarks`: observaciones del usuario; si salen varias marcas se les añade el resumen.
 */
export function tallyToMarks(categories, tally, { habitat = '', remarks = '' } = {}) {
  const used = categories.filter((c) => tally.counts[c.key] > 0);
  const summary = used.length > 1 ? tallySummary(categories, tally) : '';
  const room = summary ? REMARKS_MAX - summary.length - 1 : REMARKS_MAX;
  const own = String(remarks || '').trim().slice(0, Math.max(0, room)).trim();
  const note = [own, summary].filter(Boolean).join('\n');
  return used.map((c) => ({
    occurrenceStatus: 'present',
    individualCount: tally.counts[c.key],
    mature_count: c.mature ? tally.counts[c.key] : null,
    lifeStage: c.lifeStage,
    sex: c.sex,
    habitat,
    occurrenceRemarks: note,
  }));
}
