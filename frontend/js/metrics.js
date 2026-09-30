// ================================================================================================
// metrics.js — EOO y AOO de una especie en el catálogo, en el dispositivo (F9).
// ------------------------------------------------------------------------------------------------
// · Con servidor: el DEFINITIVO de GET /catalogs/{id}/species/{taxonID}/metrics (calculado con las
//   coordenadas exactas). Se guarda en IndexedDB (meta `metrics:<catálogo>:<taxón>:<ámbito>:<celda>`)
//   para mostrarlo sin red con su fecha. La geometría llega ya recortada según la confianza.
// · Sin servidor: PROVISIONAL con shared/geo-metrics.js y solo las marcas EXACTAS que hay en este
//   dispositivo (las generalizadas o retenidas no cuentan), junto al último definitivo guardado.
// Mismas reglas en los dos lados: docs/iucn.md › «EOO y AOO en BioDex».
// ================================================================================================

import { apiGetJson } from './api.js';
import { state } from './state.js';
import { byIndex, getMeta, setMeta } from './db.js';
import { remoteOrLocal } from './offline.js';
import { toUtm, fromUtm } from './geo/utm.js';
import { computeMetrics, DEFAULT_CELL_KM } from '../shared/geo-metrics.js';

export const PROVISIONAL_TEXT = (n) => `Provisional · ${n} ${n === 1 ? 'registro exacto' : 'registros exactos'} en este dispositivo · el definitivo lo calcula el servidor`;

export const metricsKey = (catalogId, taxonID, scope, cellKm) => `metrics:${catalogId}:${taxonID}:${scope}:${cellKm}`;

/** Marcas exactas de la especie en el dispositivo (ámbito team o me). Nunca las generalizadas. */
async function exactLocalRecords(catalogId, taxonID, scope) {
  const me = state.user && state.user.id;
  return (await byIndex('occurrences', 'taxonID', taxonID)).filter((o) => o.catalogId === catalogId && !o.deletedAt
    && !o.generalized && o.decimalLatitude !== null && o.decimalLatitude !== undefined
    && (scope !== 'me' || o.authorId === me));
}

/** Provisional con las marcas exactas del dispositivo → métricas con la forma de la API (geometría completa). */
export async function provisionalMetrics(catalogId, taxonID, { scope = 'team', cellKm = DEFAULT_CELL_KM } = {}) {
  const records = await exactLocalRecords(catalogId, taxonID, scope);
  const m = computeMetrics(records, { cellKm, project: toUtm });
  return {
    ...m, taxonID, scope, cellSizeKm: cellKm, computedAt: new Date().toISOString(), nExact: records.length,
    geometry: { access: 'full', crs: 'EPSG:25830', cellSizeKm: cellKm, hull: m.hull.map((p) => [p.lat, p.lon]), cells: m.cells },
  };
}

/**
 * Métricas para la ficha, el mapa y el asistente → {source: 'server' | 'device', data, savedAt?,
 * lastServer?: {data, savedAt}}. Con servidor, el definitivo (y se guarda); sin él, el provisional y
 * el último definitivo guardado (si lo hay).
 */
export async function speciesMetrics(catalogId, taxonID, { scope = 'team', cellKm = DEFAULT_CELL_KM } = {}) {
  const key = metricsKey(catalogId, taxonID, scope, cellKm);
  const res = await remoteOrLocal(async () => {
    const data = await apiGetJson(`/catalogs/${encodeURIComponent(catalogId)}/species/${encodeURIComponent(taxonID)}/metrics`
      + `?scope=${scope}&cell=${cellKm}`, { timeoutMs: state.online ? 30000 : 3000 });
    const savedAt = Date.now();
    setMeta(key, { data, savedAt }).catch(() => {});
    return { source: 'server', data, savedAt };
  }, async () => {
    const [data, last] = await Promise.all([provisionalMetrics(catalogId, taxonID, { scope, cellKm }), getMeta(key).catch(() => null)]);
    return { source: 'device', data, lastServer: last && last.data ? last : null };
  });
  return res.data;
}

/** Celda UTM [i, j] de `sizeKm` → sus 4 esquinas en lat/lon (para dibujarla: en grados no es un rectángulo). */
export function utmCellCorners([i, j], sizeKm) {
  const s = sizeKm * 1000;
  return [[i, j], [i + 1, j], [i + 1, j + 1], [i, j + 1]].map(([a, b]) => { const p = fromUtm(a * s, b * s); return [p.lat, p.lon]; });
}

/** «30 sept 2026, 18:12». */
export const fmtMetricsDate = (iso) => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleString('es-ES', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

/** «1.234,5 km²». */
export const fmtKm2 = (v) => (v === null || v === undefined ? '—' : `${Number(v).toLocaleString('es-ES', { maximumFractionDigits: 2 })} km²`);
