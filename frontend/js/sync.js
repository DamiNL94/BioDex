// ================================================================================================
// sync.js — motor de sincronización del dispositivo (protocolo: docs/sync.md).
// ------------------------------------------------------------------------------------------------
// Cuándo: al entrar en la app, al volver a «En línea», cada 5 min con red, tras guardar una marca y
// con «Sincronizar ahora». Nunca dos a la vez: candado en memoria y, entre pestañas, Web Locks.
// Orden de cada pasada:
//   1. especies creadas sin red (POST /species con su taxonID; 409 → se reasignan sus marcas) y su
//      alta en el catálogo;
//   2. push de marcas y borrados en lotes de 200, por catálogo;
//   3. pull de cada catálogo descargado hasta `hasMore: false`; si `catalogContentSeq` cambió, se
//      vuelve a bajar el paquete.
// Red caída a mitad → reintento con espera creciente (30 s, 1, 2, 5 min). 401 → la cola se conserva
// (la app pide entrar de nuevo). Pull 403 not_member → el catálogo se borra del dispositivo; si tenía
// marcas propias sin subir, antes se ofrecen para exportar (Ajustes).
// ================================================================================================

import { apiGetJson, apiPostJson } from './api.js';
import { state, subscribe, setSync, setDownload, setActiveCatalog } from './state.js';
import { tx, getAll, get, put, getMeta, setMeta, hasDb } from './db.js';
import { deviceId, pushPayload, queueCounts, outboxEntries } from './marks.js';
import { downloadCatalog, forgetCatalog, isDownloading } from './offline.js';

const PERIOD_MS = 5 * 60 * 1000;
const BACKOFF_S = [30, 60, 120, 300];
const PUSH_BATCH = 200;
const PULL_LIMIT = 500;

let running = null;          // promesa de la pasada en curso
let again = false;           // alguien pidió otra pasada mientras corría esta
let failures = 0;
let retryTimer = null;
let periodTimer = null;
let soonTimer = null;
let unsubscribe = null;
let wasOnline = false;

class Stop extends Error {
  constructor(kind, message) { super(message); this.kind = kind; }   // 'offline' · 'unauthorized'
}

const isNetwork = (e) => !e.aborted && (e.status === 0 || [502, 503, 504].includes(e.status));

function stopFor(e) {
  if (e.status === 401) return new Stop('unauthorized', 'Inicia sesión para sincronizar.');
  if (isNetwork(e)) return new Stop('offline', e.message);
  return null;
}

/** Recuento de la cola en el estado (cabecera, Ajustes). */
export async function refreshCounts() {
  if (!hasDb()) return;
  const counts = await queueCounts();
  const revoked = (await getAll('catalogs')).filter((c) => c.revoked).map((c) => ({ id: c.id, name: c.name }));
  setSync({ ...counts, revoked });
}

// --- 1. Especies creadas sin red ------------------------------------------------------------------

/** 409: la especie ya existe con otro taxonID → se reasignan sus marcas y su alta en catálogos. */
async function remapTaxon(oldId, newId) {
  await tx(['species', 'catalogSpecies', 'occurrences'], 'readwrite', async (t) => {
    for (const o of await t.byIndex('occurrences', 'taxonID', oldId)) await t.put('occurrences', { ...o, taxonID: newId });
    for (const r of await t.byIndex('catalogSpecies', 'taxonID', oldId)) {
      await t.del('catalogSpecies', [r.catalogId, oldId]);
      if (!(await t.get('catalogSpecies', [r.catalogId, newId]))) await t.put('catalogSpecies', { ...r, taxonID: newId });
    }
    await t.del('species', oldId);
  });
}

async function pushSpecies(entry) {
  const sp = await get('species', entry.ref);
  if (!sp || !sp.pendingBody) { await tx('outbox', 'readwrite', (t) => t.del('outbox', entry.id)); return; }
  let taxonID = sp.taxonID;
  try {
    const res = await apiPostJson('/species', sp.pendingBody, { timeoutMs: 30000 });
    await put('species', { ...res.species, pendingBody: undefined });
  } catch (e) {
    const stop = stopFor(e);
    if (stop) throw stop;
    if (e.status === 409 && e.data && e.data.taxonID) {
      taxonID = e.data.taxonID;
      await remapTaxon(sp.taxonID, taxonID);
    } else {
      return reject(entry, 'invalid_taxon', e.message);
    }
  }
  // Alta en el catálogo donde se creó (el servidor le pone su nivel sugerido).
  try {
    const res = await apiPostJson(`/catalogs/${encodeURIComponent(entry.catalogId)}/species`, { taxonID }, { timeoutMs: 30000 });
    await tx(['species', 'catalogSpecies', 'outbox'], 'readwrite', async (t) => {
      const old = await t.get('species', taxonID);
      await t.put('species', { ...(old || {}), ...res.species });
      await t.put('catalogSpecies', { catalogId: entry.catalogId, taxonID, sensitivity: res.sensitivity });
      await t.del('outbox', entry.id);
    });
  } catch (e) {
    const stop = stopFor(e);
    if (stop) throw stop;
    // La especie ya existe en el servidor: lo que falla es el alta en el catálogo.
    await put('species', { ...(await get('species', taxonID)), taxonID, pendingBody: undefined });
    const fixed = { ...entry, ref: taxonID };
    return reject(fixed, 'taxon_not_in_catalog', e.message);
  }
}

async function reject(entry, reason, detail) {
  await tx(['outbox', 'occurrences'], 'readwrite', async (t) => {
    await t.put('outbox', { ...entry, attempts: (entry.attempts || 0) + 1, lastError: detail, rejected: { reason, detail } });
    if (entry.kind !== 'species') {
      const occ = await t.get('occurrences', entry.ref);
      if (occ) await t.put('occurrences', { ...occ, status: 'rejected', reason: detail || reason });
    }
  });
}

// --- 2. Push -----------------------------------------------------------------------------------------

async function applyResults(catalogId, entries, results) {
  const byRef = new Map(entries.map((e) => [e.ref, e]));
  await tx(['outbox', 'occurrences'], 'readwrite', async (t) => {
    for (const r of results) {
      const entry = byRef.get(r.occurrenceID);
      if (!entry) continue;
      byRef.delete(r.occurrenceID);
      const occ = await t.get('occurrences', r.occurrenceID);
      if (r.status === 'accepted' || r.status === 'unchanged') {
        await t.del('outbox', entry.id);
        if (entry.kind === 'delete') await t.del('occurrences', r.occurrenceID);
        else if (occ) await t.put('occurrences', { ...occ, status: 'synced', reason: null, serverSeq: r.serverSeq });
      } else if (r.status === 'stale') {
        // Gana el servidor: se sustituye la local por su versión.
        await t.del('outbox', entry.id);
        const srv = r.occurrence;
        if (!srv || srv.deleted) await t.del('occurrences', r.occurrenceID);
        else await t.put('occurrences', { ...srv, catalogId, authorId: srv.userId, status: 'synced', reason: null, deletedAt: null });
      } else if (r.reason === 'not_found' && entry.kind === 'delete') {
        // Borrado de algo que el servidor nunca tuvo: no hay nada que borrar.
        await t.del('outbox', entry.id);
        await t.del('occurrences', r.occurrenceID);
      } else {
        await t.put('outbox', { ...entry, attempts: (entry.attempts || 0) + 1, lastError: r.detail || r.reason,
          rejected: { reason: r.reason, detail: r.detail || null } });
        if (occ) await t.put('occurrences', { ...occ, status: 'rejected', reason: r.detail || r.reason });
      }
    }
  });
}

async function pushCatalog(catalogId, entries, device) {
  for (let i = 0; i < entries.length; i += PUSH_BATCH) {
    const batch = entries.slice(i, i + PUSH_BATCH);
    const occurrences = [];
    const deletes = [];
    const sent = [];
    for (const e of batch) {
      const occ = await get('occurrences', e.ref);
      if (!occ) { await tx('outbox', 'readwrite', (t) => t.del('outbox', e.id)); continue; }
      if (e.kind === 'delete') deletes.push({ occurrenceID: e.ref, clientUpdatedAt: occ.clientUpdatedAt });
      else occurrences.push(pushPayload(occ));
      sent.push(e);
    }
    if (!sent.length) continue;
    let res;
    try {
      res = await apiPostJson('/sync/push', { catalogId, deviceId: device, occurrences, deletes }, { timeoutMs: 30000 });
    } catch (e) {
      const stop = stopFor(e);
      if (stop) throw stop;
      if (e.status === 403 && e.data && e.data.code === 'not_member') { await handleNotMember(catalogId); return; }
      // 403 forbidden (rol sin permiso para marcar) u otro error del lote: se rechaza con el motivo.
      for (const entry of sent) await reject(entry, e.data && e.data.code ? e.data.code : 'invalid_item', e.message);
      continue;
    }
    await applyResults(catalogId, sent, res.results || []);
  }
}

// --- 3. Pull -----------------------------------------------------------------------------------------

/** Aplica un lote del pull. Lo que tenga un cambio local en cola no se pisa (el push decide). */
async function applyPulled(catalogId, items, cursor, role) {
  await tx(['occurrences', 'outbox', 'meta', 'catalogs'], 'readwrite', async (t) => {
    for (const it of items) {
      if ((await t.byIndex('outbox', 'ref', it.occurrenceID)).length) continue;
      if (it.deleted) { await t.del('occurrences', it.occurrenceID); continue; }
      await t.put('occurrences', { ...it, catalogId, authorId: it.userId, status: 'synced', reason: null, deletedAt: null });
    }
    await t.put('meta', { key: 'cursor:' + catalogId, value: cursor });
    const cat = await t.get('catalogs', catalogId);
    if (cat && role && cat.role !== role) await t.put('catalogs', { ...cat, role });
  });
}

/** Pull completo de un catálogo desde su cursor. Devuelve catalogContentSeq, o null si ya no eres miembro. */
export async function pullAll(catalogId) {
  let since = await getMeta('cursor:' + catalogId, 0);
  for (;;) {
    let res;
    try {
      res = await apiGetJson(`/sync/pull?catalogId=${encodeURIComponent(catalogId)}&since=${since}&limit=${PULL_LIMIT}`, { timeoutMs: 30000 });
    } catch (e) {
      if (e.status === 403 && e.data && e.data.code === 'not_member') { await handleNotMember(catalogId); return null; }
      throw stopFor(e) || e;
    }
    await applyPulled(catalogId, res.items, res.cursor, res.role);
    since = res.cursor;
    if (!res.hasMore) return res.catalogContentSeq;
  }
}

/** Ya no eres miembro: se borra el catálogo del dispositivo, salvo que haya marcas propias sin subir. */
export async function handleNotMember(catalogId) {
  const pendingHere = (await outboxEntries()).filter((e) => e.catalogId === catalogId);
  if (!pendingHere.length) { await forgetCatalog(catalogId); return; }
  const cat = await get('catalogs', catalogId);
  if (cat) await put('catalogs', { ...cat, revoked: true });
  if (state.activeCatalog && state.activeCatalog.id === catalogId) setActiveCatalog(null);
  await refreshCounts();
}

// --- Pasada completa -------------------------------------------------------------------------------

async function runOnce() {
  const device = await deviceId();
  const entries = (await outboxEntries()).filter((e) => !e.rejected);
  const catalogs = await getAll('catalogs');
  const revoked = new Set(catalogs.filter((c) => c.revoked).map((c) => c.id));

  for (const e of entries.filter((x) => x.kind === 'species' && !revoked.has(x.catalogId))) await pushSpecies(e);

  const marks = (await outboxEntries()).filter((e) => !e.rejected && e.kind !== 'species' && !revoked.has(e.catalogId));
  const byCatalog = new Map();
  for (const e of marks) {
    if (!byCatalog.has(e.catalogId)) byCatalog.set(e.catalogId, []);
    byCatalog.get(e.catalogId).push(e);
  }
  for (const [catalogId, list] of byCatalog) await pushCatalog(catalogId, list, device);

  for (const c of catalogs.filter((x) => x.downloadedAt && !x.revoked)) {
    const seq = await pullAll(c.id);
    if (seq !== null && seq !== undefined && seq !== c.contentSeq && !isDownloading(c.id)) {
      await startDownload(c.id);
    }
  }
}

/**
 * Sincroniza ya. Si ya hay una pasada en curso, se encadena otra al terminar (no dos a la vez).
 * Devuelve la promesa de la pasada.
 */
export function syncNow() {
  if (!hasDb() || !state.user) return Promise.resolve();
  if (running) { again = true; return running; }
  clearTimeout(retryTimer);
  retryTimer = null;
  const exec = async () => {
    setSync({ running: true, error: null, nextRetryAt: null });
    try {
      await runOnce();
      failures = 0;
      const lastSync = Date.now();
      await setMeta('lastSync', lastSync);
      setSync({ lastSync, unauthorized: false });
    } catch (e) {
      if (e instanceof Stop && e.kind === 'unauthorized') {
        setSync({ unauthorized: true, error: e.message });
      } else {
        const delay = BACKOFF_S[Math.min(failures, BACKOFF_S.length - 1)];
        failures++;
        const nextRetryAt = Date.now() + delay * 1000;
        setSync({ error: e instanceof Stop ? 'Sin conexión con el servidor.' : e.message, nextRetryAt });
        retryTimer = setTimeout(syncNow, delay * 1000);
        if (!(e instanceof Stop)) console.info('Sincronización:', e);
      }
    } finally {
      await refreshCounts().catch(() => {});
      setSync({ running: false });
    }
  };
  const locked = navigator.locks && navigator.locks.request
    ? () => navigator.locks.request('biodex-sync', exec) : exec;
  running = locked().finally(() => {
    running = null;
    if (again) { again = false; syncNow(); }
  });
  return running;
}

/** Tras guardar algo: sincroniza en un par de segundos si hay red (varias marcas seguidas, una pasada). */
export function requestSync() {
  refreshCounts().catch(() => {});
  clearTimeout(soonTimer);
  if (state.online) soonTimer = setTimeout(syncNow, 1500);
}

/** Descarga (o actualiza) un catálogo publicando el progreso en state.downloads. */
export async function startDownload(catalogId) {
  setDownload(catalogId, { phase: 'package' });
  try {
    return await downloadCatalog(catalogId, { pullAll, onProgress: (p) => setDownload(catalogId, p) });
  } finally {
    setDownload(catalogId, null);
  }
}

/** El catálogo activo se descarga solo al activarlo si hay red. */
async function ensureActiveDownloaded() {
  const active = state.activeCatalog;
  if (!active || !state.online || !hasDb() || isDownloading(active.id)) return;
  const local = await get('catalogs', active.id);
  if (local && local.downloadedAt) return;
  startDownload(active.id).catch((e) => console.info('Descarga del catálogo activo:', e.message));
}

export function startSync() {
  stopSync();
  wasOnline = state.online;
  refreshCounts().catch(() => {});
  getMeta('lastSync').then((lastSync) => { if (lastSync) setSync({ lastSync }); }).catch(() => {});
  unsubscribe = subscribe((key) => {
    if (key === 'online') {
      if (state.online && !wasOnline) { syncNow(); ensureActiveDownloaded(); }
      wasOnline = state.online;
    }
    if (key === 'activeCatalog') ensureActiveDownloaded();
  });
  periodTimer = setInterval(() => { if (state.online) syncNow(); }, PERIOD_MS);
  syncNow().then(ensureActiveDownloaded);
}

export function stopSync() {
  if (unsubscribe) { unsubscribe(); unsubscribe = null; }
  clearInterval(periodTimer);
  clearTimeout(retryTimer);
  clearTimeout(soonTimer);
  periodTimer = retryTimer = soonTimer = null;
  failures = 0;
}

/** Espera a la pasada en curso (cerrar sesión no borra nada con una a medias). */
export const syncIdle = () => running || Promise.resolve();
