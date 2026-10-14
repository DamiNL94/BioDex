// ================================================================================================
// tally.js — conteo rápido de censo (F11e): categorías por reino y paso de los totales a marcas.
// ------------------------------------------------------------------------------------------------
// En el campo se cuenta tocando una casilla por individuo (macho, hembra, juvenil… o plántula, en
// flor…). Al guardar, cada categoría con algún individuo es UNA marca Darwin Core en el mismo punto y
// a la misma hora: una ocurrencia solo tiene un sexo y un estado vital, así que no se mezclan.
// Todas llevan en occurrenceRemarks el resumen del conteo entero, para poder reconstruir el grupo.
// Maduros (mature_count): los adultos en fauna y lo que está en flor o en fruto en flora, es decir, lo
// capaz de reproducirse (definición UICN de individuo maduro).
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
  ],
  Plantae: [
    { key: 'seedling', label: 'Plántula', lifeStage: 'seedling', sex: null, mature: false },
    { key: 'vegetative', label: 'Vegetativa', lifeStage: 'vegetative', sex: null, mature: false },
    { key: 'flowering', label: 'En flor', lifeStage: 'flowering', sex: null, mature: true },
    { key: 'fruiting', label: 'En fruto', lifeStage: 'fruiting', sex: null, mature: true },
    { key: 'unknown', label: 'Indeterminada', lifeStage: 'undetermined', sex: null, mature: false },
  ],
  other: [
    { key: 'adult', label: 'Adulto', lifeStage: 'adult', sex: null, mature: true },
    { key: 'juvenile', label: 'Juvenil', lifeStage: 'juvenile', sex: null, mature: false },
    { key: 'unknown', label: 'Indeterminado', lifeStage: 'undetermined', sex: null, mature: false },
  ],
};

export const tallyCategories = (kingdom) => TALLY_CATEGORIES[kingdom] || TALLY_CATEGORIES.other;

/** Conteo vacío: { counts: {key: n}, history: [key…] } (history sirve para «Deshacer»). */
export const emptyTally = () => ({ counts: {}, history: [] });

export function tallyAdd(tally, key, delta = 1) {
  const cur = tally.counts[key] || 0;
  const next = Math.max(0, Math.min(TALLY_MAX, cur + delta));
  if (next === cur) return false;
  tally.counts[key] = next;
  if (delta > 0) tally.history.push(key);
  else {
    const i = tally.history.lastIndexOf(key);
    if (i >= 0) tally.history.splice(i, 1);
  }
  return true;
}

/** Quita el último toque. Devuelve la clave deshecha o null. */
export function tallyUndo(tally) {
  const key = tally.history.pop();
  if (!key) return null;
  tally.counts[key] = Math.max(0, (tally.counts[key] || 0) - 1);
  return key;
}

export function tallyTotal(tally) {
  return Object.values(tally.counts).reduce((a, n) => a + (n || 0), 0);
}

/** «Conteo rápido (n = 10): Macho adulto 3 · Hembra adulta 5 · Juvenil o cría 2». */
export function tallySummary(categories, tally) {
  const parts = categories.filter((c) => tally.counts[c.key] > 0).map((c) => `${c.label} ${tally.counts[c.key]}`);
  return `Conteo rápido (n = ${tallyTotal(tally)}): ${parts.join(' · ')}`;
}

/**
 * Campos de marca (los que espera markFields) de cada categoría con individuos, en el orden de las
 * casillas. `remarks`: observaciones del usuario; se les añade el resumen del conteo.
 */
export function tallyToMarks(categories, tally, { habitat = '', remarks = '' } = {}) {
  const summary = tallySummary(categories, tally);
  const own = String(remarks || '').trim().slice(0, Math.max(0, REMARKS_MAX - summary.length - 1)).trim();
  const note = [own, summary].filter(Boolean).join('\n');
  return categories.filter((c) => tally.counts[c.key] > 0).map((c) => ({
    occurrenceStatus: 'present',
    individualCount: tally.counts[c.key],
    mature_count: c.mature ? tally.counts[c.key] : null,
    lifeStage: c.lifeStage,
    sex: c.sex,
    habitat,
    occurrenceRemarks: note,
  }));
}
