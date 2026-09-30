// ================================================================================================
// map/zones.js — zonas del mapa para usar sin conexión.
// ------------------------------------------------------------------------------------------------
// Una zona = rectángulo + capas con su rango de zoom. Las teselas van a Cache Storage
// `biodex-tiles-<zoneId>` (el SW las busca en todas las biodex-tiles-*); la ficha, al almacén
// `zones` de IndexedDB (db.js).
// Descarga: 4 peticiones a la vez, progreso, pausa, cancelar y reanudar. Reanudar recorre la zona
// entera pero salta lo que ya está en su caché; lo que ya estaba en otra caché de teselas (otra zona o
// las vistas con red) se copia sin volver a pedirlo. Las peticiones llevan `X-BioDex-Zone: 1` para
// que el SW no las sirva de caché ni las cambie por una tesela vacía: aquí un fallo tiene que ser fallo.
// El motor vive en este módulo, no en la vista: cambiar de pestaña no corta la descarga.
// ================================================================================================

import { getAll, get, put, del } from '../db.js';
import { uuid } from '../marks.js';
import { askPersist } from '../offline.js';
import { zoneTiles, estimateZone, TILE_CACHE_PREFIX } from './layers.js';

export const MAX_ZONE_TILES = 8000;
const CONCURRENCY = 4;
const FETCH_TIMEOUT_MS = 20000;
const MAX_CONSECUTIVE_FAILURES = 8;   // seguidas: la red se ha ido → pausa en vez de fallar la zona entera
const SAVE_EVERY = 40;                // cada cuántas teselas se guarda el progreso en IndexedDB

const cacheName = (zoneId) => TILE_CACHE_PREFIX + zoneId;
const listeners = new Set();
const running = new Map();            // zoneId → {zone, stop: null | 'pause' | 'cancel', promise}

/** Aviso de cambios: fn(zone | null). Devuelve la baja. */
export function onZones(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}
const emit = (zone) => listeners.forEach((fn) => { try { fn(zone); } catch (e) { console.error(e); } });

export const isRunning = (zoneId) => running.has(zoneId);

/** Zonas del usuario, la más reciente primero. Una «downloading» sin motor (app cerrada) está en pausa. */
export async function listZones() {
  const zones = await getAll('zones').catch(() => []);
  return zones
    .map((z) => (z.status === 'downloading' && !running.has(z.id) ? { ...z, status: 'paused' } : z))
    // En marcha: el progreso vivo del motor (si ya ha leído la zona; al arrancar aún no).
    .map((z) => (running.has(z.id) && running.get(z.id).zone) || z)
    .sort((a, b) => b.createdAt - a.createdAt);
}

/** ¿Cabe `bytes` en la cuota del navegador? → {ok, free, usage, quota} (ok si no se puede saber). */
export async function quotaCheck(bytes) {
  try {
    if (navigator.storage && navigator.storage.estimate) {
      const { usage = 0, quota = 0 } = await navigator.storage.estimate();
      const free = Math.max(0, quota - usage);
      // Margen del 20 %: la estimación de tamaño es una media y el navegador no deja llenar hasta el borde.
      return { ok: !quota || bytes * 1.2 < free, free, usage, quota };
    }
  } catch (e) { /* no disponible */ }
  return { ok: true, free: null, usage: null, quota: null };
}

/**
 * Crea la zona y empieza a descargarla. `layers`: [{key, minZoom, maxZoom}]. Lanza Error con texto
 * para el usuario si no vale (sin nombre, sin capas, demasiadas teselas).
 */
export async function createZone({ name, bounds, layers }) {
  name = String(name || '').trim();
  if (!name) throw new Error('Ponle un nombre a la zona.');
  if (!layers.length) throw new Error('Elige al menos una capa.');
  const est = estimateZone(bounds, layers);
  if (est.tiles > MAX_ZONE_TILES) throw new Error(`Son ${est.tiles} teselas: el máximo por zona es ${MAX_ZONE_TILES}. Reduce el área o el zoom.`);
  const zone = {
    id: uuid(), name, bounds, layers, tileCount: est.tiles, estimatedBytes: Math.round(est.bytes),
    doneCount: 0, failedCount: 0, bytes: 0, status: 'downloading', error: null,
    createdAt: Date.now(), completedAt: null,
  };
  await put('zones', zone);
  askPersist().catch(() => {});
  startZone(zone.id);
  return zone;
}

/** Empieza o reanuda la descarga de una zona. Devuelve la promesa de la pasada. */
export function startZone(zoneId) {
  if (running.has(zoneId)) return running.get(zoneId).promise;
  const entry = { zone: null, stop: null, promise: null };
  running.set(zoneId, entry);
  entry.promise = download(zoneId, entry).finally(() => { running.delete(zoneId); });
  return entry.promise;
}

export function pauseZone(zoneId) {
  const r = running.get(zoneId);
  if (r) r.stop = 'pause';
}

/** Cancela (si está en marcha) y borra la zona con sus teselas. */
export async function deleteZone(zoneId) {
  const r = running.get(zoneId);
  if (r) {
    r.stop = 'cancel';
    await r.promise.catch(() => {});
  }
  if ('caches' in window) await caches.delete(cacheName(zoneId)).catch(() => {});
  await del('zones', zoneId).catch(() => {});
  emit(null);
}

/** Cerrar sesión: fuera las cachés de las zonas de este usuario (la base se borra aparte). */
export async function forgetAllZones() {
  for (const r of running.values()) r.stop = 'cancel';
  await Promise.all([...running.values()].map((r) => r.promise.catch(() => {})));
  const zones = await getAll('zones').catch(() => []);
  if ('caches' in window) await Promise.all(zones.map((z) => caches.delete(cacheName(z.id)).catch(() => {})));
}

function fetchTile(url) {
  const ctl = new AbortController();
  const timer = setTimeout(() => ctl.abort(), FETCH_TIMEOUT_MS);
  return fetch(url, { headers: { 'X-BioDex-Zone': '1' }, signal: ctl.signal, cache: 'no-store' })
    .finally(() => clearTimeout(timer));
}

const sizeOf = async (res) => Number(res.headers.get('Content-Length')) || (await res.clone().blob()).size;

async function download(zoneId, entry) {
  const stored = await get('zones', zoneId);
  if (!stored) return;
  if (!('caches' in window)) {
    entry.zone = { ...stored, status: 'error', error: 'Este navegador no deja guardar teselas.' };
    await put('zones', entry.zone);
    emit(entry.zone);
    return;
  }
  const cache = await caches.open(cacheName(zoneId));
  const zone = { ...stored, status: 'downloading', error: null, doneCount: 0, failedCount: 0, bytes: 0 };
  entry.zone = zone;
  await put('zones', zone);
  emit(zone);

  const tiles = zoneTiles(zone.bounds, zone.layers);
  let consecutiveFailures = 0;
  let sinceSave = 0;
  let lastEmit = 0;

  const progress = async () => {
    if (++sinceSave >= SAVE_EVERY) { sinceSave = 0; await put('zones', { ...zone }).catch(() => {}); }
    const now = Date.now();
    if (now - lastEmit > 150) { lastEmit = now; emit({ ...zone }); }
  };

  async function one(t) {
    const hit = await cache.match(t.url);
    if (hit) { zone.bytes += await sizeOf(hit); zone.doneCount++; return; }
    // En otra caché de teselas (otra zona o las vistas con red): se copia, no se pide.
    const elsewhere = await caches.match(t.url);
    if (elsewhere && (elsewhere.headers.get('Content-Type') || '').startsWith('image/')) {
      await cache.put(t.url, elsewhere.clone());
      zone.bytes += await sizeOf(elsewhere);
      zone.doneCount++;
      return;
    }
    for (let attempt = 0; attempt < 2; attempt++) {
      try {
        const res = await fetchTile(t.url);
        // 404: fuera de la rejilla del IGN (bordes del mundo); no hay tesela que guardar.
        if (res.status === 404) { zone.doneCount++; consecutiveFailures = 0; return; }
        if (!res.ok || !(res.headers.get('Content-Type') || '').startsWith('image/')) throw new Error(`HTTP ${res.status}`);
        const size = await sizeOf(res);
        await cache.put(t.url, res);
        zone.bytes += size;
        zone.doneCount++;
        consecutiveFailures = 0;
        return;
      } catch (e) {
        if (entry.stop) return;
        if (e && e.name === 'QuotaExceededError') throw e;
      }
    }
    zone.failedCount++;
    consecutiveFailures++;
  }

  async function worker() {
    for (;;) {
      if (entry.stop || consecutiveFailures >= MAX_CONSECUTIVE_FAILURES) return;
      const next = tiles.next();
      if (next.done) return;
      await one(next.value);
      await progress();
    }
  }

  try {
    await Promise.all(Array.from({ length: CONCURRENCY }, worker));
  } catch (e) {
    zone.status = 'paused';
    zone.error = e && e.name === 'QuotaExceededError'
      ? 'No queda espacio en el dispositivo: borra alguna zona o catálogo y reanuda.'
      : 'La descarga se ha interrumpido.';
    await put('zones', { ...zone });
    emit({ ...zone });
    return;
  }

  if (entry.stop === 'cancel') return;   // deleteZone() borra caché y ficha
  if (entry.stop === 'pause') {
    zone.status = 'paused';
  } else if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES) {
    zone.status = 'paused';
    zone.error = 'La red no responde: descarga en pausa. Reanuda cuando vuelva la conexión (lo ya bajado no se repite).';
  } else if (zone.failedCount) {
    zone.status = 'incomplete';
    zone.error = `${zone.failedCount} teselas no se pudieron bajar. «Reanudar» vuelve a pedir solo esas.`;
  } else {
    zone.status = 'complete';
    zone.completedAt = Date.now();
  }
  await put('zones', { ...zone });
  emit({ ...zone });
}
