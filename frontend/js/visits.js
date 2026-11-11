// ================================================================================================
// visits.js — sitios de muestreo y visitas en el dispositivo, también sin red.
// ------------------------------------------------------------------------------------------------
// Un SITIO se muestrea una y otra vez (parcela, transecto o punto de conteo); una VISITA es un muestreo con sitio,
// protocolo, esfuerzo, observadores y especies objetivo. Mismas reglas que las marcas (marks.js): almacén + una entrada
// de cola por registro (`site` / `siteDelete`, `visit` / `visitDelete`), solo el autor edita, sync.js los sube PRIMERO.
//   · Empezar: con un sitio existente (el más cercano primero), uno nuevo con punto + radio o «grabar transecto» (la
//     traza GPS va a meta `track:<visitID>` y al cerrar se convierte en la línea del sitio).
//   · Durante la visita: state.visit (barra fija, components/visit-bar.js); cada marca lleva su visitID y el buscador
//     pone primero las especies objetivo.
//   · Cerrar: las objetivo sin contar se proponen como AUSENTES (0 individuos, en el centro del sitio con su extensión
//     como precisión); el usuario confirma o quita alguna. Esfuerzo = minutos entre inicio y fin, editable.
// Las especies objetivo se recuerdan del sitio: las del propio sitio (si es tuyo, se actualizan) o, si es ajeno, meta
// `siteTargets:<siteID>` en este dispositivo.
// ================================================================================================

import { state, setVisit } from './state.js';
import { tx, byIndex, get, getMeta, setMeta } from './db.js';
import { uuid, isoNow, enqueue, GEOREF_MAP } from './marks.js';
import { siteMetrics, lineWkt } from './site-geom.js';
import { distanceM } from './species-search.js';
import { toUtm } from './geo/utm.js';
import { seriesFromVisits, analyzeSeries } from '../shared/iucn-series.js';

export const PROTOCOLS = {
  target_census: 'Censo completo de especies objetivo',
  plot_count: 'Conteo en parcela',
  transect: 'Transecto con banda',
  timed_search: 'Búsqueda por tiempo',
};
export const SITE_TYPES = { plot: 'Parcela', transect: 'Transecto', point: 'Punto de conteo' };
export const SITE_RADIUS_DEFAULT = 25;
export const BAND_DEFAULT = 10;
const TRACK_MIN_STEP_M = 5;
const TRACK_MAX_ACCURACY_M = 50;
const TRACK_MAX_POINTS = 5000;

export const defaultProtocol = (siteType) => (siteType === 'transect' ? 'transect' : siteType === 'plot' ? 'plot_count' : 'target_census');
const me = () => state.user && state.user.id;
const myName = () => (state.user && (state.user.name || state.user.email)) || '';

/** Cifras locales de un sitio (superficie, longitud, centro y extensión), las mismas que calcula el servidor. */
export function localSiteMetrics(s) {
  return siteMetrics({ radiusM: s.radiusM || null, wkt: s.footprintWKT || null, bandM: s.bandM || null,
    center: [s.decimalLatitude, s.decimalLongitude] }, toUtm);
}

function cleanName(name) {
  const v = String(name || '').trim();
  if (!v) throw new Error('Ponle un nombre al sitio.');
  if (v.length > 120) throw new Error('El nombre del sitio admite 120 caracteres.');
  return v;
}

/**
 * Registro de sitio nuevo (sin guardar). {catalogId, name, siteType, center {lat, lng}, radiusM | wkt, bandM, areaM2
 * (dada por el usuario), protocol, species}.
 */
function siteRecord({ catalogId, name, siteType, center = null, radiusM = null, wkt = null, bandM = null, areaM2 = null, protocol = null, species = [] }) {
  if (!SITE_TYPES[siteType]) throw new Error('Elige el tipo de sitio.');
  if (!wkt && !(center && Number.isFinite(center.lat) && Number.isFinite(center.lng))) throw new Error('Falta la posición del sitio.');
  const r = wkt ? null : Math.round(Number(radiusM || SITE_RADIUS_DEFAULT));
  if (!wkt && (!Number.isFinite(r) || r < 1 || r > 100000)) throw new Error('El radio va de 1 m a 100 km.');
  const band = bandM === null || bandM === '' ? null : Number(String(bandM).replace(',', '.'));
  if (band !== null && (!Number.isFinite(band) || band <= 0 || band > 1000)) throw new Error('La banda va de 0 a 1000 m.');
  const given = areaM2 === null || areaM2 === '' ? null : Number(String(areaM2).replace(',', '.'));
  if (given !== null && (!Number.isFinite(given) || given <= 0)) throw new Error('La superficie tiene que ser mayor que 0.');
  const rec = { siteID: uuid(), catalogId, name: cleanName(name), siteType, radiusM: r, footprintWKT: wkt || null, bandM: band,
    decimalLatitude: center ? center.lat : null, decimalLongitude: center ? center.lng : null, protocol: protocol || defaultProtocol(siteType),
    species: [...new Set(species)], areaGiven: given !== null, areaM2: given };
  return withMetrics(rec);
}

/** Recalcula centro (de una geometría), superficie (si no es dada) y longitud. */
function withMetrics(rec) {
  const m = localSiteMetrics(rec);
  const out = { ...rec, lengthM: m.lengthM };
  if (rec.footprintWKT && m.center) { out.decimalLatitude = m.center[0]; out.decimalLongitude = m.center[1]; }
  if (!rec.areaGiven) out.areaM2 = m.areaM2 === null ? null : Math.round(m.areaM2 * 100) / 100;
  return out;
}

async function putSite(t, rec) {
  await t.put('sites', rec);
  await enqueue(t, { kind: 'site', ref: rec.siteID, catalogId: rec.catalogId });
}

/** Sitio nuevo (desde casa o en el campo). Devuelve el registro. */
export async function createSite(fields) {
  const rec = { ...siteRecord(fields), clientUpdatedAt: isoNow(), authorId: me(), recordedBy: myName(), generalized: false,
    status: 'pending', reason: null, serverSeq: null, deletedAt: null };
  await tx(['sites', 'outbox'], 'readwrite', (t) => putSite(t, rec));
  return rec;
}

/** Edita un sitio propio: nombre, tipo, geometría, banda, superficie dada, protocolo y especies objetivo. */
export async function updateSite(siteID, patch) {
  return tx(['sites', 'outbox'], 'readwrite', async (t) => {
    const old = await t.get('sites', siteID);
    if (!old || old.authorId !== me() || old.deletedAt) return null;
    const next = { ...old, ...patch };
    if (patch.name !== undefined) next.name = cleanName(patch.name);
    if (patch.footprintWKT) next.radiusM = null;
    if (patch.radiusM) next.footprintWKT = null;
    if (patch.areaM2 !== undefined) next.areaGiven = patch.areaM2 !== null && patch.areaM2 !== '';
    const rec = { ...withMetrics(next), clientUpdatedAt: isoNow(), status: 'pending', reason: null };
    await putSite(t, rec);
    return rec;
  });
}

/** Borrado lógico de un sitio propio. Sus visitas se quedan (con su siteID), como las marcas de un impacto borrado. */
export async function deleteSite(siteID) {
  await tx(['sites', 'outbox'], 'readwrite', async (t) => {
    const s = await t.get('sites', siteID);
    if (!s || s.authorId !== me()) return;
    const now = isoNow();
    await t.put('sites', { ...s, deletedAt: now, clientUpdatedAt: now, status: 'pending', reason: null });
    await enqueue(t, { kind: 'siteDelete', ref: siteID, catalogId: s.catalogId });
  });
}

/** Sitios visibles de un catálogo, por nombre. */
export async function sitesFor(catalogId) {
  return (await byIndex('sites', 'catalogId', catalogId)).filter((s) => !s.deletedAt)
    .sort((a, b) => a.name.localeCompare(b.name, 'es'));
}

/** Sitios del catálogo por cercanía a `pos` {lat, lng}: distancia al borde (0 = dentro). → [{site, distance}] */
export async function nearestSites(catalogId, pos) {
  const list = await sitesFor(catalogId);
  if (!pos) return list.map((site) => ({ site, distance: null }));
  // Sin coordenadas (nivel 1 para quien no es de confianza) no hay distancia, pero el sitio se puede visitar: al final.
  const located = (s) => s.decimalLatitude !== null && s.decimalLatitude !== undefined;
  return list.map((site) => {
    if (!located(site)) return { site, distance: null };
    const d = distanceM(pos.lat, pos.lng, site.decimalLatitude, site.decimalLongitude);
    const extent = site.radiusM || (site.footprintWKT ? localSiteMetrics(site).extentM : 0) || 0;
    return { site, distance: Math.max(0, d - extent) };
  }).sort((a, b) => (a.distance === null) - (b.distance === null) || (a.distance ?? 0) - (b.distance ?? 0)
    || a.site.name.localeCompare(b.site.name, 'es'));
}

/** Especies objetivo que se proponen para una visita al sitio: las recordadas en este dispositivo o las del sitio. */
export async function rememberedTargets(site) {
  if (!site) return [];
  const local = await getMeta('siteTargets:' + site.siteID, null);
  return Array.isArray(local) ? local : (site.species || []);
}

// --- Visitas ------------------------------------------------------------------------------------------

/** Visita en curso en un catálogo (de este usuario), o null. */
export function activeVisitFor(catalogId) {
  const v = state.visit;
  return v && v.userId === me() && v.catalogId === catalogId ? v : null;
}

/**
 * Empieza una visita. `site`: sitio existente, o `newSite` {name, siteType, radiusM, bandM} (punto + radio en `pos`), o
 * `transect` {name, bandM}: se crea el sitio de tipo transecto y se graba la traza. `pos`: {lat, lng, accuracy} o null.
 * Devuelve la visita.
 */
export async function startVisit({ catalog, site = null, newSite = null, transect = null, protocol, bandM = null, species = [],
  observers = '', conditions = '', pos = null }) {
  if (activeVisitFor(catalog.id)) throw new Error('Ya hay una visita en curso en este catálogo: ciérrala antes de empezar otra.');
  if (!PROTOCOLS[protocol]) throw new Error('Elige el protocolo.');
  const targets = [...new Set(species)];
  if (protocol === 'target_census' && !targets.length) throw new Error('Un censo de especies objetivo necesita al menos una especie objetivo.');
  if ((newSite || transect) && !pos) throw new Error('Sin posición no se puede crear el sitio aquí: espera al GPS.');
  const now = isoNow();
  let siteRec = site;
  if (newSite || transect) {
    const spec = transect
      ? { name: transect.name, siteType: 'transect', radiusM: Math.max(SITE_RADIUS_DEFAULT, Math.round(pos.accuracy || 0)), bandM: transect.bandM }
      : newSite;
    siteRec = { ...siteRecord({ catalogId: catalog.id, center: pos, protocol, species: targets, ...spec }), clientUpdatedAt: now,
      authorId: me(), recordedBy: myName(), generalized: false, status: 'pending', reason: null, serverSeq: null, deletedAt: null };
  }
  const band = protocol === 'transect' ? (bandM ?? (siteRec && siteRec.bandM) ?? BAND_DEFAULT) : null;
  const visit = {
    visitID: uuid(), catalogId: catalog.id, siteID: siteRec ? siteRec.siteID : null, protocol,
    bandM: band === null || band === '' ? null : Number(String(band).replace(',', '.')),
    effortMinutes: null, observers: String(observers || '').trim().slice(0, 500) || myName(),
    eventStart: now, eventEnd: null, conditions: String(conditions || '').trim().slice(0, 2000) || null, visitStatus: 'open',
    species: targets, decimalLatitude: pos ? pos.lat : null, decimalLongitude: pos ? pos.lng : null,
    clientUpdatedAt: now, authorId: me(), recordedBy: myName(), generalized: false, status: 'pending', reason: null,
    serverSeq: null, deletedAt: null,
  };
  await tx(['sites', 'visits', 'outbox', 'meta'], 'readwrite', async (t) => {
    if (newSite || transect) await putSite(t, siteRec);
    else if (siteRec && siteRec.authorId === me()) {
      // Sitio propio: recuerda las especies objetivo y el protocolo de esta visita.
      const same = JSON.stringify([...(siteRec.species || [])].sort()) === JSON.stringify([...targets].sort()) && siteRec.protocol === protocol;
      if (!same) await putSite(t, { ...siteRec, species: targets, protocol, clientUpdatedAt: now, status: 'pending', reason: null });
    }
    if (siteRec) await t.put('meta', { key: 'siteTargets:' + siteRec.siteID, value: targets });
    if (transect) await t.put('meta', { key: 'track:' + visit.visitID, value: pos ? [[pos.lat, pos.lng]] : [] });
    await t.put('visits', visit);
    await enqueue(t, { kind: 'visit', ref: visit.visitID, catalogId: catalog.id });
  });
  setVisit({ userId: me(), visitID: visit.visitID, catalogId: catalog.id, catalogName: catalog.name, siteID: visit.siteID,
    siteName: siteRec ? siteRec.name : null, startedAt: now, species: targets, recording: !!transect, protocol });
  return visit;
}

/** Añade un punto a la traza del transecto en grabación (si se ha movido y la precisión vale). → nº de puntos. */
export async function appendTrack(visitID, pos) {
  if (!pos || !(pos.accuracy <= TRACK_MAX_ACCURACY_M)) return null;
  const track = (await getMeta('track:' + visitID, [])) || [];
  const last = track[track.length - 1];
  if (last && distanceM(last[0], last[1], pos.lat, pos.lng) < TRACK_MIN_STEP_M) return track.length;
  if (track.length >= TRACK_MAX_POINTS) return track.length;
  track.push([Math.round(pos.lat * 1e6) / 1e6, Math.round(pos.lng * 1e6) / 1e6]);
  await setMeta('track:' + visitID, track);
  return track.length;
}

export const trackOf = async (visitID) => (await getMeta('track:' + visitID, [])) || [];

/** Marcas de una visita (del dispositivo), sin borradas. */
export async function visitMarks(visitID) {
  return (await byIndex('occurrences', 'visitID', visitID)).filter((o) => !o.deletedAt);
}

/**
 * Lo contado en la visita por especie → Map taxonID → {individuals, mature, marks, absent}. `targets`: [taxonID].
 * → {byTaxon, counted: [taxonID objetivo con alguna presencia o ausencia], pending: [objetivo sin nada]}.
 */
export async function visitTally(visitID, targets = []) {
  const byTaxon = new Map();
  for (const o of await visitMarks(visitID)) {
    const a = byTaxon.get(o.taxonID) || { individuals: 0, mature: 0, marks: 0, absent: false, present: false };
    a.marks++;
    if (o.occurrenceStatus === 'absent') a.absent = true;
    else { a.present = true; a.individuals += o.individualCount || 0; a.mature += o.mature_count || 0; }
    byTaxon.set(o.taxonID, a);
  }
  return { byTaxon, counted: targets.filter((t) => byTaxon.has(t)), pending: targets.filter((t) => !byTaxon.has(t)) };
}

/** Minutos entre dos instantes ISO. */
export const minutesBetween = (a, b) => Math.max(0, Math.round((Date.parse(b) - Date.parse(a)) / 60000));

/**
 * Cierra la visita en curso. `absences`: especies objetivo sin contar que el usuario confirma como ausentes (0).
 * `effortMinutes` (editable; por defecto, inicio–fin) y `conditions`. Sin red también. → {visit, absences: n}
 */
export async function closeVisit({ visitID, absences = [], effortMinutes = null, conditions = null, pos = null }) {
  const visit = await get('visits', visitID);
  if (!visit || visit.authorId !== me()) throw new Error('Esa visita no es tuya o ya no está en el dispositivo.');
  const site = visit.siteID ? await get('sites', visit.siteID) : null;
  const end = isoNow();
  const effort = effortMinutes === null || effortMinutes === '' ? minutesBetween(visit.eventStart, end) : Math.round(Number(effortMinutes));
  if (!Number.isFinite(effort) || effort < 0 || effort > 100000) throw new Error('El esfuerzo va en minutos (0–100 000).');
  const track = await trackOf(visitID);
  const recording = state.visit && state.visit.visitID === visitID && state.visit.recording;

  // Dónde se guardan las ausencias: el centro del sitio con su extensión como precisión (o la posición, sin sitio).
  let where = null;
  let siteAfter = site;
  if (recording && site && site.authorId === me() && track.length >= 2) {
    siteAfter = withMetrics({ ...site, footprintWKT: lineWkt(track), radiusM: null });
  }
  if (siteAfter && siteAfter.decimalLatitude !== null && siteAfter.decimalLatitude !== undefined) {
    const extent = siteAfter.radiusM || Math.ceil(localSiteMetrics(siteAfter).extentM || 0) || SITE_RADIUS_DEFAULT;
    where = { lat: siteAfter.decimalLatitude, lng: siteAfter.decimalLongitude, uncertainty: Math.max(1, extent),
      remarks: `Ausencia en la visita al sitio «${siteAfter.name}» (centro del sitio)` };
  } else if (pos || visit.decimalLatitude !== null) {
    const p = pos || { lat: visit.decimalLatitude, lng: visit.decimalLongitude, accuracy: SITE_RADIUS_DEFAULT };
    where = { lat: p.lat, lng: p.lng, uncertainty: Math.max(1, Math.round(p.accuracy || SITE_RADIUS_DEFAULT)), remarks: 'Ausencia en la visita (sin sitio)' };
  }
  if (absences.length && !where) throw new Error('Sin posición no se pueden guardar las ausencias: espera al GPS.');

  const now = isoNow();
  const next = { ...visit, visitStatus: 'closed', eventEnd: end, effortMinutes: effort,
    conditions: conditions === null ? visit.conditions : (String(conditions).trim().slice(0, 2000) || null),
    clientUpdatedAt: now, status: 'pending', reason: null };
  await tx(['occurrences', 'sites', 'visits', 'outbox', 'meta'], 'readwrite', async (t) => {
    for (const taxonID of [...new Set(absences)]) {
      const occ = { occurrenceID: uuid(), catalogId: visit.catalogId, taxonID, eventDate: end, decimalLatitude: where.lat,
        decimalLongitude: where.lng, coordinateUncertaintyInMeters: where.uncertainty, georeferenceProtocol: GEOREF_MAP,
        georeferenceRemarks: where.remarks, basisOfRecord: 'HumanObservation', occurrenceStatus: 'absent', individualCount: 0,
        mature_count: null, sampleSizeValue: null, lifeStage: null, sex: null, habitat: null, occurrenceRemarks: null, visitID,
        clientUpdatedAt: now, status: 'pending', reason: null, authorId: me(), recordedBy: myName(), generalized: false,
        serverSeq: null, deletedAt: null };
      await t.put('occurrences', occ);
      await enqueue(t, { kind: 'occurrence', ref: occ.occurrenceID, catalogId: visit.catalogId });
    }
    if (siteAfter !== site) await putSite(t, { ...siteAfter, clientUpdatedAt: now, status: 'pending', reason: null });
    await t.put('visits', next);
    await enqueue(t, { kind: 'visit', ref: visitID, catalogId: visit.catalogId });
    await t.del('meta', 'track:' + visitID);
  });
  if (state.visit && state.visit.visitID === visitID) setVisit(null);
  return { visit: next, absences: absences.length };
}

/**
 * Descarta la visita en curso SIN marcas (empezada por error): se borra; un sitio nuevo creado al empezarla, también.
 * Con marcas no se descarta (son datos): hay que cerrarla.
 */
export async function discardVisit(visitID) {
  if ((await visitMarks(visitID)).length) throw new Error('La visita ya tiene marcas: ciérrala en lugar de descartarla.');
  const visit = await get('visits', visitID);
  if (visit && visit.authorId === me()) {
    const now = isoNow();
    await tx(['visits', 'sites', 'outbox', 'meta'], 'readwrite', async (t) => {
      if (visit.serverSeq) {
        await t.put('visits', { ...visit, deletedAt: now, clientUpdatedAt: now, status: 'pending', reason: null });
        await enqueue(t, { kind: 'visitDelete', ref: visitID, catalogId: visit.catalogId });
      } else {
        for (const e of await t.byIndex('outbox', 'ref', visitID)) await t.del('outbox', e.id);
        await t.del('visits', visitID);
      }
      // Sitio creado con esta visita y aún sin subir ni usar: fuera también.
      const site = visit.siteID ? await t.get('sites', visit.siteID) : null;
      if (site && !site.serverSeq && site.authorId === me()) {
        const others = (await t.byIndex('visits', 'siteID', site.siteID)).filter((v) => v.visitID !== visitID);
        if (!others.length) {
          for (const e of await t.byIndex('outbox', 'ref', site.siteID)) await t.del('outbox', e.id);
          await t.del('sites', site.siteID);
        }
      }
      await t.del('meta', 'track:' + visitID);
    });
  }
  if (state.visit && state.visit.visitID === visitID) setVisit(null);
}

/** Visitas de un catálogo (de un sitio, si se da), más recientes primero. */
export async function visitsFor(catalogId, siteID = null) {
  const list = siteID ? await byIndex('visits', 'siteID', siteID) : await byIndex('visits', 'catalogId', catalogId);
  return list.filter((v) => !v.deletedAt && v.catalogId === catalogId).sort((a, b) => Date.parse(b.eventStart) - Date.parse(a.eventStart));
}

/**
 * Serie temporal de una especie con lo que hay en el dispositivo (visitas a sitios + sus marcas, también las ajenas:
 * los maduros no dependen de las coordenadas). → analyzeSeries() + {skippedVisits}.
 */
export async function localSeries(catalogId, taxonID, { generationLength = null, criteria = null } = {}) {
  const [sites, visits, occ] = await Promise.all([sitesFor(catalogId), byIndex('visits', 'catalogId', catalogId),
    byIndex('occurrences', 'taxonID', taxonID)]);
  const siteMap = new Map(sites.map((s) => [s.siteID, s]));
  const from = seriesFromVisits({ visits: visits.filter((v) => siteMap.has(v.siteID)), sites: siteMap,
    occurrences: occ.filter((o) => o.catalogId === catalogId), taxonID });
  return { ...analyzeSeries(from.obs, { generationLength, criteria }), skippedVisits: from.skipped };
}

/** Quita la visita activa si ya no existe en el dispositivo (p. ej. se borró el catálogo). */
export async function checkActiveVisit() {
  const v = state.visit;
  if (!v) return;
  if (v.userId !== me()) return;   // es de otra cuenta de este dispositivo: activeVisitFor() ya la ignora
  const rec = await get('visits', v.visitID).catch(() => null);
  if (!rec || rec.visitStatus === 'closed' || rec.deletedAt) setVisit(null);
}

