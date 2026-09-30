// ================================================================================================
// marks.js — marcas (observaciones) en el dispositivo, su cola de envío y el respaldo.
// ------------------------------------------------------------------------------------------------
// Una marca se guarda en `occurrences` con `status: pending` y deja una entrada en `outbox`; sync.js
// la sube. Nunca hay una marca sin coordenadas: la crea solo quien tiene una posición del GPS.
// Borrar es lógico: `deletedAt` + entrada `delete` en la cola (el servidor la borra para el equipo).
// Hay como mucho UNA entrada de cola por marca: la última intención (crear/editar o borrar) gana.
// ================================================================================================

import { state } from './state.js';
import { tx, getAll, byIndex, get, getMeta, setMeta } from './db.js';

export const MARK_ROLES = ['owner', 'custodian', 'editor'];
// Términos que viajan en el push (el resto de campos del registro local son del dispositivo).
const PUSH_FIELDS = ['occurrenceID', 'taxonID', 'eventDate', 'decimalLatitude', 'decimalLongitude',
  'coordinateUncertaintyInMeters', 'basisOfRecord', 'individualCount', 'lifeStage', 'occurrenceRemarks',
  'mature_count', 'iucn_assessment', 'clientUpdatedAt'];
export const BACKUP_FORMAT = 'biodex-backup';

export function uuid() {
  if (crypto.randomUUID) return crypto.randomUUID();
  const b = crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/** ISO 8601 con la zona del dispositivo: 2026-09-30T18:40:12.345+02:00. */
export function isoNow(d = new Date()) {
  const p = (n, w = 2) => String(Math.abs(n)).padStart(w, '0');
  const off = -d.getTimezoneOffset();
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}:` +
    `${p(d.getSeconds())}.${p(d.getMilliseconds(), 3)}${off >= 0 ? '+' : '-'}${p(Math.floor(Math.abs(off) / 60))}:${p(Math.abs(off) % 60)}`;
}

export async function deviceId() {
  let id = await getMeta('deviceId');
  if (!id) { id = uuid(); await setMeta('deviceId', id); }
  return id;
}

export function pushPayload(occ) {
  const out = {};
  for (const k of PUSH_FIELDS) if (occ[k] !== undefined && occ[k] !== null) out[k] = occ[k];
  return out;
}

/** Sustituye la entrada de cola de `ref` (si la hay) por una nueva. Dentro de una transacción con outbox. */
async function enqueue(t, entry) {
  for (const old of await t.byIndex('outbox', 'ref', entry.ref)) await t.del('outbox', old.id);
  await t.add('outbox', { attempts: 0, lastError: null, ...entry });
}

/**
 * Marca nueva en la posición `pos` (GeolocationPosition). Devuelve la marca guardada.
 */
export async function createMark({ catalogId, taxonID, pos, note = '' }) {
  if (!pos || !pos.coords || !Number.isFinite(pos.coords.latitude)) throw new Error('Sin posición no se puede marcar.');
  const now = isoNow();
  const occ = {
    occurrenceID: uuid(), catalogId, taxonID,
    eventDate: now,
    decimalLatitude: pos.coords.latitude,
    decimalLongitude: pos.coords.longitude,
    coordinateUncertaintyInMeters: Math.max(0, Math.round(pos.coords.accuracy || 0)),
    basisOfRecord: 'HumanObservation',
    occurrenceRemarks: note.trim() || null,
    clientUpdatedAt: now,
    status: 'pending', reason: null, authorId: state.user.id, recordedBy: state.user.name || state.user.email,
    generalized: false, serverSeq: null, deletedAt: null,
  };
  await tx(['occurrences', 'outbox'], 'readwrite', async (t) => {
    await t.put('occurrences', occ);
    await enqueue(t, { kind: 'occurrence', ref: occ.occurrenceID, catalogId });
  });
  return occ;
}

/** Borrado lógico de una marca propia; se sincroniza. */
export async function deleteMark(occurrenceID) {
  await tx(['occurrences', 'outbox'], 'readwrite', async (t) => {
    const occ = await t.get('occurrences', occurrenceID);
    if (!occ || occ.authorId !== state.user.id) return;
    const now = isoNow();
    await t.put('occurrences', { ...occ, deletedAt: now, clientUpdatedAt: now, status: 'pending', reason: null });
    await enqueue(t, { kind: 'delete', ref: occurrenceID, catalogId: occ.catalogId });
  });
}

/** Marcas visibles de una especie en un catálogo, más recientes primero: {own, others}. */
export async function marksFor(catalogId, taxonID) {
  const all = (await byIndex('occurrences', 'taxonID', taxonID))
    .filter((o) => o.catalogId === catalogId && !o.deletedAt)
    .sort((a, b) => String(b.eventDate).localeCompare(String(a.eventDate)));
  const me = state.user && state.user.id;
  return { own: all.filter((o) => o.authorId === me), others: all.filter((o) => o.authorId !== me) };
}

// --- Cola -----------------------------------------------------------------------------------------
export const outboxEntries = () => getAll('outbox');

/** {pending: en cola sin rechazar, rejected, unsynced: todo lo que se perdería al borrar la base}. */
export async function queueCounts() {
  const all = await outboxEntries();
  const rejected = all.filter((e) => e.rejected).length;
  return { pending: all.length - rejected, rejected, unsynced: all.length };
}

/** Rechazadas con su marca, para Ajustes › Sincronización. */
export async function rejectedItems() {
  const entries = (await outboxEntries()).filter((e) => e.rejected);
  return Promise.all(entries.map(async (e) => ({
    entry: e,
    occurrence: e.kind === 'species' ? null : await get('occurrences', e.ref),
    species: e.kind === 'species' ? await get('species', e.ref) : null,
  })));
}

/** Vuelve a poner en cola una rechazada. */
export async function retryRejected(entryId) {
  await tx(['outbox', 'occurrences'], 'readwrite', async (t) => {
    const e = await t.get('outbox', entryId);
    if (!e) return;
    await t.put('outbox', { ...e, rejected: null, attempts: 0, lastError: null });
    const occ = e.kind === 'species' ? null : await t.get('occurrences', e.ref);
    if (occ) await t.put('occurrences', { ...occ, status: 'pending', reason: null });
  });
}

/** Descarta a mano una rechazada: la marca nueva se borra del dispositivo; un borrado se deshace. */
export async function discardRejected(entryId) {
  await tx(['outbox', 'occurrences', 'species', 'catalogSpecies'], 'readwrite', async (t) => {
    const e = await t.get('outbox', entryId);
    if (!e) return;
    await t.del('outbox', entryId);
    if (e.kind === 'species') {
      await t.del('species', e.ref);
      for (const r of await t.byIndex('catalogSpecies', 'taxonID', e.ref)) await t.del('catalogSpecies', [r.catalogId, e.ref]);
      return;
    }
    const occ = await t.get('occurrences', e.ref);
    if (!occ) return;
    if (e.kind === 'delete' && occ.serverSeq) await t.put('occurrences', { ...occ, deletedAt: null, status: 'synced', reason: null });
    else await t.del('occurrences', e.ref);
  });
}

// --- Especies creadas sin red ----------------------------------------------------------------------

/** Especie de usuario creada sin conexión, ya añadida (en local) al catálogo. Devuelve la ficha. */
export async function createLocalSpecies(body, catalogId) {
  const canonical = body.scientificName;
  const existing = (await getAll('species')).find((s) => (s.canonicalName || s.scientificName) === canonical);
  const sp = existing || {
    taxonID: 'biodex:' + uuid(), source: 'user', scientificName: `${canonical} ${body.scientificNameAuthorship}`.trim(),
    canonicalName: canonical, scientificNameAuthorship: body.scientificNameAuthorship, vernacularName: body.vernacularName,
    taxonRank: body.taxonRank, taxonomicGroup: null, family: null, iucnPrimary: null, iucnAssessments: [], images: [],
    thumbnail: null, classification: {}, pendingBody: body,
  };
  await tx(['species', 'catalogSpecies', 'outbox'], 'readwrite', async (t) => {
    if (!existing) await t.put('species', { ...sp, pendingBody: { ...body, taxonID: sp.taxonID } });
    const row = await t.get('catalogSpecies', [catalogId, sp.taxonID]);
    if (!row) await t.put('catalogSpecies', { catalogId, taxonID: sp.taxonID, sensitivity: { level: null }, localOnly: true });
    if (!existing || existing.pendingBody) await enqueue(t, { kind: 'species', ref: sp.taxonID, catalogId });
  });
  return sp;
}

// --- Respaldo -------------------------------------------------------------------------------------

/** JSON con las marcas sin sincronizar (DwC) y sus catálogos. `catalogId` limita a uno. */
export async function buildBackup(catalogId = null) {
  const entries = (await outboxEntries()).filter((e) => !catalogId || e.catalogId === catalogId);
  const occurrences = [];
  const species = [];
  const catalogIds = new Set();
  for (const e of entries) {
    catalogIds.add(e.catalogId);
    if (e.kind === 'species') {
      const sp = await get('species', e.ref);
      if (sp && sp.pendingBody) species.push({ catalogId: e.catalogId, ...sp.pendingBody });
      continue;
    }
    const occ = await get('occurrences', e.ref);
    if (!occ) continue;
    occurrences.push({ catalogId: occ.catalogId, action: e.kind === 'delete' ? 'delete' : 'upsert', ...pushPayload(occ),
      recordedBy: occ.recordedBy, deletedAt: occ.deletedAt || null });
  }
  const catalogs = [];
  for (const id of catalogIds) {
    const c = await get('catalogs', id);
    catalogs.push({ id, name: c ? c.name : null });
  }
  return {
    format: BACKUP_FORMAT, version: 1, exportedAt: isoNow(), userId: state.user.id, userEmail: state.user.email,
    catalogs, species, occurrences,
  };
}

/** Descarga el respaldo como fichero. Devuelve el número de marcas. */
export async function downloadBackup(catalogId = null) {
  const backup = await buildBackup(catalogId);
  const blob = new Blob([JSON.stringify(backup, null, 2)], { type: 'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `biodex-respaldo-${backup.exportedAt.slice(0, 16).replace(/[:T]/g, '-')}.json`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 10000);
  return backup.occurrences.length;
}

/**
 * Devuelve a la cola las marcas de un respaldo. Idempotente por occurrenceID: lo que ya está en el
 * dispositivo no se toca. → {added, skipped}. Lanza Error con texto para el usuario si no vale.
 */
export async function importBackup(backup) {
  if (!backup || backup.format !== BACKUP_FORMAT || !Array.isArray(backup.occurrences)) {
    throw new Error('Ese fichero no es un respaldo de BioDex.');
  }
  if (backup.userId !== state.user.id) throw new Error('Ese respaldo es de otra cuenta: las marcas llevan su autoría.');
  let added = 0;
  let skipped = 0;
  await tx(['occurrences', 'outbox', 'catalogs', 'species', 'catalogSpecies'], 'readwrite', async (t) => {
    for (const c of backup.catalogs || []) {
      if (!(await t.get('catalogs', c.id))) await t.put('catalogs', { id: c.id, name: c.name || 'Catálogo', role: 'editor' });
    }
    for (const s of backup.species || []) {
      if (await t.get('species', s.taxonID)) continue;
      const { catalogId, ...body } = s;
      await t.put('species', { taxonID: s.taxonID, source: 'user', scientificName: `${s.scientificName} ${s.scientificNameAuthorship || ''}`.trim(),
        canonicalName: s.scientificName, scientificNameAuthorship: s.scientificNameAuthorship, vernacularName: s.vernacularName || null,
        taxonRank: s.taxonRank, pendingBody: body, classification: {}, images: [] });
      await t.put('catalogSpecies', { catalogId, taxonID: s.taxonID, sensitivity: { level: null }, localOnly: true });
      await enqueue(t, { kind: 'species', ref: s.taxonID, catalogId });
    }
    for (const o of backup.occurrences) {
      if (!o || !o.occurrenceID || !o.catalogId || await t.get('occurrences', o.occurrenceID)) { skipped++; continue; }
      const { action, ...fields } = o;
      await t.put('occurrences', { ...fields, status: 'pending', reason: null, authorId: state.user.id,
        generalized: false, serverSeq: null, deletedAt: o.deletedAt || null });
      await enqueue(t, { kind: action === 'delete' ? 'delete' : 'occurrence', ref: o.occurrenceID, catalogId: o.catalogId });
      added++;
    }
  });
  return { added, skipped };
}
