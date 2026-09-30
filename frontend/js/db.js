// ================================================================================================
// db.js — IndexedDB sin librerías: un envoltorio mínimo con promesas.
// ------------------------------------------------------------------------------------------------
// Una base POR USUARIO (`biodex-<userId>`): dos cuentas en el mismo dispositivo no se ven los datos.
// Almacenes:
//   catalogs        id → {id, name, description, role, contentSeq, downloadedAt, …}
//   species         taxonID → ficha del paquete offline (o especie creada sin red, con `pendingBody`)
//   catalogSpecies  [catalogId, taxonID] → {sensitivity, localOnly?}
//   occurrences     occurrenceID → marca DwC + status (pending · synced · rejected), reason, authorId,
//                   generalized, serverSeq, clientUpdatedAt, deletedAt, catalogId
//   outbox          id autoincremental → {kind: species · occurrence · delete, ref, catalogId, attempts,
//                   lastError, rejected?}
//   meta            key → value (cursor:<catalogId>, deviceId, lastSync, persistAsked…)
//   zones           id → zona de mapa para usar sin conexión (F7, js/map/zones.js): {id, name, bounds,
//                   layers: [{key, minZoom, maxZoom}], tileCount, doneCount, failedCount, bytes, status,
//                   createdAt, completedAt}. Las teselas van a Cache Storage `biodex-tiles-<id>`.
// ================================================================================================

const DB_VERSION = 2;   // 2 (F7): almacén zones
let current = null;       // {name, db}

const req = (r) => new Promise((resolve, reject) => {
  r.onsuccess = () => resolve(r.result);
  r.onerror = () => reject(r.error);
});

export const dbName = (userId) => `biodex-${userId}`;

function upgrade(db, oldVersion) {
  if (oldVersion < 1) createV1(db);
  if (oldVersion < 2) db.createObjectStore('zones', { keyPath: 'id' });
}

function createV1(db) {
  db.createObjectStore('catalogs', { keyPath: 'id' });
  db.createObjectStore('species', { keyPath: 'taxonID' });
  const cs = db.createObjectStore('catalogSpecies', { keyPath: ['catalogId', 'taxonID'] });
  cs.createIndex('catalogId', 'catalogId');
  cs.createIndex('taxonID', 'taxonID');
  const occ = db.createObjectStore('occurrences', { keyPath: 'occurrenceID' });
  occ.createIndex('catalogId', 'catalogId');
  occ.createIndex('taxonID', 'taxonID');
  const out = db.createObjectStore('outbox', { keyPath: 'id', autoIncrement: true });
  out.createIndex('ref', 'ref');
  db.createObjectStore('meta', { keyPath: 'key' });
}

/** Abre (o crea) la base del usuario. Se llama al entrar en la app. */
export async function openUserDb(userId) {
  const name = dbName(userId);
  if (current && current.name === name) return current.db;
  closeDb();
  const open = indexedDB.open(name, DB_VERSION);
  open.onupgradeneeded = (ev) => upgrade(open.result, ev.oldVersion);
  const db = await req(open);
  // Otra pestaña borra la base (cerrar sesión): se suelta para no bloquearla.
  db.onversionchange = () => { db.close(); if (current && current.db === db) current = null; };
  current = { name, db };
  return db;
}

export function closeDb() {
  if (current) { current.db.close(); current = null; }
}

export const hasDb = () => !!current;

/** Borra la base de un usuario (cerrar sesión sin pendientes). */
export async function deleteUserDb(userId) {
  if (current && current.name === dbName(userId)) closeDb();
  await req(indexedDB.deleteDatabase(dbName(userId))).catch(() => {});
}

function db() {
  if (!current) throw new Error('La base local no está abierta.');
  return current.db;
}

/**
 * Transacción sobre `stores` (nombre o lista). `fn(t)` recibe {get, getAll, put, add, del, byIndex,
 * clear}, cada uno devuelve promesa. Se resuelve con lo que devuelva `fn` cuando la transacción termina.
 */
export function tx(stores, mode, fn) {
  const t = db().transaction(stores, mode);
  const s = (name) => t.objectStore(name);
  const api = {
    get: (store, key) => req(s(store).get(key)),
    getAll: (store, query) => req(s(store).getAll(query)),
    byIndex: (store, index, key) => req(s(store).index(index).getAll(key)),
    put: (store, value) => req(s(store).put(value)),
    add: (store, value) => req(s(store).add(value)),
    del: (store, key) => req(s(store).delete(key)),
    clear: (store) => req(s(store).clear()),
  };
  return new Promise((resolve, reject) => {
    let result;
    t.oncomplete = () => resolve(result);
    t.onerror = () => reject(t.error);
    t.onabort = () => reject(t.error || new Error('Transacción cancelada.'));
    Promise.resolve(fn(api)).then((r) => { result = r; }, (e) => { try { t.abort(); } catch (x) { /* ya cerrada */ } reject(e); });
  });
}

// --- Atajos de una sola operación -------------------------------------------------------------------
export const get = (store, key) => tx(store, 'readonly', (t) => t.get(store, key));
export const getAll = (store, query) => tx(store, 'readonly', (t) => t.getAll(store, query));
export const byIndex = (store, index, key) => tx(store, 'readonly', (t) => t.byIndex(store, index, key));
export const put = (store, value) => tx(store, 'readwrite', (t) => t.put(store, value));
export const del = (store, key) => tx(store, 'readwrite', (t) => t.del(store, key));

export async function getMeta(key, fallback = null) {
  const row = await get('meta', key);
  return row ? row.value : fallback;
}
export const setMeta = (key, value) => put('meta', { key, value });
