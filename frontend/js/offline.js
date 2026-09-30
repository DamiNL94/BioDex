// ================================================================================================
// offline.js — los catálogos en el dispositivo: «Llevar al campo» y lecturas locales.
// ------------------------------------------------------------------------------------------------
// Descargar un catálogo = GET /catalogs/{id}/offline (fichas y niveles) + pull completo de sus marcas
// (sync.js) + imágenes en Cache Storage `biodex-img-<catalogId>` (la primera foto de cada especie,
// que es también su miniatura). Sin red, Catálogos, Especies y la ficha leen de aquí.
// F6: también se guardan miembros, invitaciones y registro (cachedRemote) para verlos sin red.
//
// Las lecturas locales devuelven la MISMA forma que la API (GET /catalogs/{id}, /tree, /species/{id})
// para que las vistas no tengan dos caminos de pintado: el resumen y el árbol se calculan aquí.
// ================================================================================================

import { apiGetJson, getSpeciesFilters } from './api.js';
import { state, setActiveCatalog } from './state.js';
import { tx, get, getAll, byIndex, put, getMeta, setMeta } from './db.js';

export const IMG_CACHE_PREFIX = 'biodex-img-';
// Estimación para el aviso previo a la descarga: ficha (~8 KB) + una foto de la Fototeca (~180 KB).
const EST_BYTES_PER_SPECIES = 190 * 1024;
const IMG_CONCURRENCY = 4;

// Niveles de sensibilidad por si no hay red para GET /species/filters (shared/sensitivity.json).
const FALLBACK_LEVELS = [
  { level: 1, name: 'Extrema', approxKm: null, gridDeg: null, display: 'withheld', displayText: 'presente en la provincia' },
  { level: 2, name: 'Alta', approxKm: 10, gridDeg: 0.1 }, { level: 3, name: 'Media', approxKm: 1, gridDeg: 0.01 },
  { level: 4, name: 'Sin restricción', approxKm: null, gridDeg: null },
];

/** Niveles de sensibilidad: del servidor si contesta (y se guardan), si no, los guardados. */
export async function sensitivityLevels() {
  try {
    const f = await getSpeciesFilters();
    setMeta('sensitivityLevels', f.sensitivityLevels).catch(() => {});
    return f.sensitivityLevels;
  } catch (e) {
    return (await getMeta('sensitivityLevels').catch(() => null)) || FALLBACK_LEVELS;
  }
}

export const estimateBytes = (speciesCount) => (speciesCount || 0) * EST_BYTES_PER_SPECIES;

export function formatBytes(n) {
  if (!n) return '0 MB';
  if (n < 1024 * 1024) return `${Math.max(1, Math.round(n / 1024))} KB`;
  return `${(n / 1024 / 1024).toLocaleString('es-ES', { maximumFractionDigits: 1 })} MB`;
}

/** «30/09 18:40». */
export function shortDate(ms) {
  const d = new Date(ms);
  const p = (n) => String(n).padStart(2, '0');
  return `${p(d.getDate())}/${p(d.getMonth() + 1)} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

/** Línea de datos locales: «Datos del dispositivo · descargados el 30/09 18:40». */
export function localNote(catalog) {
  return catalog && catalog.downloadedAt
    ? `Datos del dispositivo · descargados el ${shortDate(catalog.downloadedAt)}`
    : 'Datos del dispositivo';
}

// --- Lista de catálogos ---------------------------------------------------------------------------

/** Guarda la lista del servidor sin pisar lo propio del dispositivo (contentSeq del paquete, fecha). */
export async function saveCatalogList(list) {
  await tx('catalogs', 'readwrite', async (t) => {
    for (const c of list) {
      const old = await t.get('catalogs', c.id);
      await t.put('catalogs', {
        ...(old || {}), id: c.id, name: c.name, description: c.description, role: c.role, ownerId: c.ownerId,
        speciesCount: c.speciesCount, remoteContentSeq: c.contentSeq,
      });
    }
  });
}

export const localCatalogs = () => getAll('catalogs');
export const localCatalog = (id) => get('catalogs', id);

// --- Descarga ---------------------------------------------------------------------------------------

let persistAsked = false;
/** La primera vez que se descarga algo se pide almacenamiento persistente (que el navegador no lo borre). */
export async function askPersist() {
  if (persistAsked || !navigator.storage || !navigator.storage.persist) return;
  persistAsked = true;
  if (await getMeta('persistAsked')) return;
  await setMeta('persistAsked', true);
  try { await navigator.storage.persist(); } catch (e) { /* el navegador decide */ }
}

/** Guarda el paquete offline: catálogo, fichas y niveles (las especies que ya no están, fuera). */
export async function savePackage(pkg) {
  const id = pkg.catalog.id;
  await tx(['catalogs', 'species', 'catalogSpecies'], 'readwrite', async (t) => {
    const old = await t.get('catalogs', id);
    await t.put('catalogs', {
      ...(old || {}), ...pkg.catalog, role: pkg.role, contentSeq: pkg.contentSeq, remoteContentSeq: pkg.contentSeq,
    });
    const keep = new Set();
    for (const sp of pkg.species) {
      const { sensitivity, ...card } = sp;
      card.thumbnail = card.thumbnail || (card.images && card.images[0] ? card.images[0].url : null);
      await t.put('species', card);
      await t.put('catalogSpecies', { catalogId: id, taxonID: sp.taxonID, sensitivity });
      keep.add(sp.taxonID);
    }
    // Las creadas sin red y aún sin subir (localOnly) se quedan hasta que las suba sync.js.
    for (const row of await t.byIndex('catalogSpecies', 'catalogId', id)) {
      if (!keep.has(row.taxonID) && !row.localOnly) await t.del('catalogSpecies', [id, row.taxonID]);
    }
  });
}

/** URLs de imagen que se guardan por especie: la primera foto (es también la miniatura). */
function imageUrls(species) {
  const urls = new Set();
  for (const sp of species) {
    if (sp.thumbnail) urls.add(sp.thumbnail);
    if (sp.images && sp.images[0]) urls.add(sp.images[0].url);
  }
  return [...urls].filter((u) => u && u.startsWith('/img/fototeca/'));
}

async function cacheImages(catalogId, species, onProgress) {
  if (!('caches' in window)) return;
  const cache = await caches.open(IMG_CACHE_PREFIX + catalogId);
  const urls = imageUrls(species);
  let done = 0;
  onProgress({ phase: 'images', done, total: urls.length });
  const queue = urls.slice();
  const worker = async () => {
    while (queue.length) {
      const url = queue.shift();
      try {
        if (!(await cache.match(url))) {
          const res = await fetch(url, { credentials: 'same-origin' });
          if (res.ok) await cache.put(url, res);
        }
      } catch (e) { /* una foto que no llega no para la descarga */ }
      onProgress({ phase: 'images', done: ++done, total: urls.length });
    }
  };
  await Promise.all(Array.from({ length: IMG_CONCURRENCY }, worker));
}

const downloads = new Map();   // catalogId → promesa en curso (no dos descargas del mismo)

// Claves de meta que son de un catálogo (se borran con él): cursor y época del pull, y las últimas
// respuestas de Equipo, Invitaciones, Registro y Niveles (F6) para verlas sin red.
const CATALOG_META = ['cursor:', 'syncv:', 'epoch:', 'team:', 'invites:', 'audit:', 'levels:'];

/**
 * Servidor con copia en el dispositivo: con red, `remote()` y se guarda en meta `key` con su hora; sin
 * servidor, lo último guardado. → {data, at, fromDevice}. Sin copia y sin servidor lanza el ApiError
 * con `offline = true` (como remoteOrLocal).
 */
export async function cachedRemote(key, remote) {
  const local = async () => {
    const row = await getMeta(key).catch(() => null);
    return row && row.data !== undefined ? row : null;
  };
  const res = await remoteOrLocal(async () => {
    const data = await remote();
    const at = Date.now();
    setMeta(key, { data, at }).catch(() => {});
    return { data, at };
  }, local);
  return { ...res.data, fromDevice: res.fromDevice };
}

/** Lo del equipo que conviene tener sin red: miembros y, para el owner, invitaciones y registro. */
async function prefetchTeam(catalogId, role) {
  const id = encodeURIComponent(catalogId);
  const jobs = [cachedRemote('team:' + catalogId, () => apiGetJson(`/catalogs/${id}/members`))];
  if (role === 'owner') {
    jobs.push(cachedRemote('invites:' + catalogId, () => apiGetJson(`/catalogs/${id}/invites`)));
    jobs.push(cachedRemote('audit:' + catalogId, () => apiGetJson(`/catalogs/${id}/audit?limit=50`)));
  }
  await Promise.all(jobs.map((p) => p.catch(() => null)));
}

/**
 * «Descargar para usar sin conexión». `onProgress({phase: 'package'|'marks'|'images', done, total})`.
 * `pullAll(catalogId)` lo inyecta sync.js (evita la dependencia circular). Devuelve el catálogo local.
 */
export function downloadCatalog(catalogId, { onProgress = () => {}, pullAll, images = true } = {}) {
  if (downloads.has(catalogId)) return downloads.get(catalogId);
  const run = (async () => {
    await askPersist();
    onProgress({ phase: 'package' });
    const pkg = await apiGetJson(`/catalogs/${encodeURIComponent(catalogId)}/offline`, { timeoutMs: 60000 });
    await savePackage(pkg);
    if (pullAll) {
      onProgress({ phase: 'marks' });
      if ((await pullAll(catalogId)) === null) throw new Error('Ya no eres miembro de este catálogo.');
    }
    if (images) await cacheImages(catalogId, await localSpeciesOf(catalogId), onProgress);
    await prefetchTeam(catalogId, pkg.role);
    const cat = await get('catalogs', catalogId);
    cat.downloadedAt = Date.now();
    await put('catalogs', cat);
    return cat;
  })();
  downloads.set(catalogId, run);
  run.finally(() => downloads.delete(catalogId)).catch(() => {});
  return run;
}

export const isDownloading = (catalogId) => downloads.has(catalogId);

/** Borra un catálogo del dispositivo (expulsado, salido o borrado): fichas, marcas, notas, evaluaciones, meta e imágenes. */
export async function forgetCatalog(catalogId) {
  await tx(['catalogs', 'catalogSpecies', 'occurrences', 'notes', 'assessments', 'outbox', 'meta'], 'readwrite', async (t) => {
    await t.del('catalogs', catalogId);
    for (const r of await t.byIndex('catalogSpecies', 'catalogId', catalogId)) await t.del('catalogSpecies', [catalogId, r.taxonID]);
    for (const o of await t.byIndex('occurrences', 'catalogId', catalogId)) await t.del('occurrences', o.occurrenceID);
    for (const n of await t.byIndex('notes', 'catalogId', catalogId)) await t.del('notes', n.noteID);
    for (const a of await t.byIndex('assessments', 'catalogId', catalogId)) await t.del('assessments', a.assessmentID);
    for (const o of await t.getAll('outbox')) if (o.catalogId === catalogId) await t.del('outbox', o.id);
    for (const prefix of CATALOG_META) await t.del('meta', prefix + catalogId);
  });
  if ('caches' in window) await caches.delete(IMG_CACHE_PREFIX + catalogId).catch(() => {});
  if (state.activeCatalog && state.activeCatalog.id === catalogId) setActiveCatalog(null);
}

// --- Lecturas locales con la forma de la API ----------------------------------------------------------

const isNetworkError = (e) => !e.aborted && (e.status === 0 || [502, 503, 504].includes(e.status));

/**
 * Servidor o dispositivo: con el servidor en línea, `remote()`; si no contesta (o ya se sabe que no),
 * `local()`. → {data, fromDevice}. Sin copia local y sin servidor lanza el ApiError con `offline = true`;
 * los errores del servidor (403, 404…) se lanzan tal cual.
 */
export async function remoteOrLocal(remote, local) {
  if (!state.online) {
    const data = await local().catch(() => null);
    if (data) return { data, fromDevice: true };
  }
  try {
    return { data: await remote(), fromDevice: false };
  } catch (e) {
    e.offline = isNetworkError(e);
    if (e.offline) {
      const data = await local().catch(() => null);
      if (data) return { data, fromDevice: true };
    }
    throw e;
  }
}

/** Especies de un catálogo (ficha + sensitivity), como las tarjetas de GET /catalogs/{id}. */
export async function localSpeciesOf(catalogId) {
  return tx(['catalogSpecies', 'species'], 'readonly', async (t) => {
    const rows = await t.byIndex('catalogSpecies', 'catalogId', catalogId);
    const out = [];
    for (const r of rows) {
      const sp = await t.get('species', r.taxonID);
      if (sp) out.push({ ...sp, sensitivity: r.sensitivity || { level: null } });
    }
    return out;
  });
}

function summaryOf(species) {
  const byTaxonomicGroup = {};
  const byIucnCategory = {};
  for (const s of species) {
    const g = s.taxonomicGroup || 'Sin grupo';
    byTaxonomicGroup[g] = (byTaxonomicGroup[g] || 0) + 1;
    const c = (s.iucnPrimary && s.iucnPrimary.code) || 'none';
    byIucnCategory[c] = (byIucnCategory[c] || 0) + 1;
  }
  return { total: species.length, byTaxonomicGroup, byIucnCategory };
}

/** Como GET /catalogs/{id}: {catalog, species, summary}, más `local` (el registro del dispositivo). */
export async function localCatalogData(catalogId) {
  const catalog = await get('catalogs', catalogId);
  if (!catalog || !catalog.downloadedAt) return null;
  const species = await localSpeciesOf(catalogId);
  return { catalog, species, summary: summaryOf(species), local: catalog };
}

/** Como GET /catalogs/{id}/tree, a partir de la clasificación de las fichas guardadas. */
export async function localTree(catalogId) {
  const species = await localSpeciesOf(catalogId);
  const RANKS = ['kingdom', 'phylum', 'class', 'order', 'family'];
  const root = { children: new Map() };
  for (const s of species) {
    const cls = s.classification || {};
    let node = root;
    for (const rank of RANKS) {
      const name = rank === 'family' ? (cls.family || s.family || null) : (cls[rank] || null);
      const key = name || '';
      if (!node.children.has(key)) node.children.set(key, { rank, id: name ? `name:${name}` : null, name, count: 0, children: new Map() });
      node = node.children.get(key);
      node.count++;
    }
  }
  const out = (n) => [...n.children.values()]
    .sort((a, b) => (a.name || '~').localeCompare(b.name || '~'))
    .map((c) => (c.rank === 'family' ? { rank: c.rank, id: c.id, name: c.name, count: c.count }
      : { rank: c.rank, id: c.id, name: c.name, count: c.count, children: out(c) }));
  return out(root);
}

/** Ficha guardada (o null). */
export const localSpecies = (taxonID) => get('species', taxonID);

/** Nivel de una especie en un catálogo guardado. */
export async function localSensitivity(catalogId, taxonID) {
  const row = await get('catalogSpecies', [catalogId, taxonID]);
  return row ? row.sensitivity : null;
}

export const localSpeciesInCatalogs = (taxonID) => byIndex('catalogSpecies', 'taxonID', taxonID);

// --- Almacenamiento (Ajustes) ---------------------------------------------------------------------
export async function storageInfo() {
  const out = { usage: null, quota: null, persisted: null };
  try {
    if (navigator.storage && navigator.storage.estimate) Object.assign(out, await navigator.storage.estimate());
    if (navigator.storage && navigator.storage.persisted) out.persisted = await navigator.storage.persisted();
  } catch (e) { /* no disponible */ }
  return out;
}
