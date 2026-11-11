// ================================================================================================
// visit-records.js — forma de sitios y visitas en el push, en el pull y en el respaldo. Sin imports (lo usan
// marks.js, sync.js y visits.js sin dependencias circulares). Campos de la API: docs/sync.md › «Sitios y visitas».
// En el dispositivo, abierta/cerrada va en `visitStatus` (en la API es `status`); `status` es el de sincronización.
// ================================================================================================

const SITE_PUSH_FIELDS = ['siteID', 'name', 'siteType', 'decimalLatitude', 'decimalLongitude', 'radiusM', 'footprintWKT', 'bandM',
  'areaM2', 'protocol', 'clientUpdatedAt'];
const VISIT_PUSH_FIELDS = ['visitID', 'protocol', 'bandM', 'effortMinutes', 'observers', 'eventStart', 'eventEnd', 'conditions',
  'decimalLatitude', 'decimalLongitude', 'clientUpdatedAt'];

const pick = (rec, fields) => {
  const out = {};
  for (const k of fields) if (rec[k] !== undefined && rec[k] !== null && rec[k] !== '') out[k] = rec[k];
  return out;
};

/** Sitio del almacén → cuerpo del push. Radio o geometría, nunca los dos. */
export function sitePayload(s) {
  const out = pick(s, SITE_PUSH_FIELDS);
  if (out.footprintWKT) delete out.radiusM;
  if (s.areaGiven === true && out.areaM2) out.areaGiven = true;
  else delete out.areaM2;   // calculada: la pone el servidor
  out.species = Array.isArray(s.species) ? s.species : [];
  return out;
}

/** Visita del almacén → cuerpo del push. */
export function visitPayload(v) {
  return { ...pick(v, VISIT_PUSH_FIELDS), siteID: v.siteID || null, status: v.visitStatus || 'open',
    species: Array.isArray(v.species) ? v.species : [] };
}

/** Visita como va en el push o en un respaldo → registro local (status → visitStatus). */
export function visitFromPayload(p) {
  const { status, ...rest } = p;
  return { ...rest, visitStatus: status || 'open' };
}

/** Sitio del servidor (pull o «stale») → registro local sincronizado. */
export function siteFromServer(it, catalogId) {
  return { ...it, catalogId, authorId: it.userId, status: 'synced', reason: null, deletedAt: null };
}

/** Visita del servidor → registro local sincronizado. */
export function visitFromServer(it, catalogId) {
  const { status, ...rest } = it;
  return { ...rest, catalogId, visitStatus: status, authorId: it.userId, status: 'synced', reason: null, deletedAt: null };
}
