// ================================================================================================
// assessment.js — evaluación UICN de una especie en el catálogo, en el dispositivo (F11g).
// ------------------------------------------------------------------------------------------------
// · Con servidor: el DEFINITIVO de GET /catalogs/{id}/species/{taxonID}/assessment (Lib/assessment.php: EOO/AOO,
//   maduros, subpoblaciones, fragmentación y localidades con las coordenadas exactas; geometría según confianza).
//   Se guarda en IndexedDB (meta `assessment:<catálogo>:<taxón>`) para verlo sin red con su fecha.
// · F11h: la serie temporal (A y C1) sale de las visitas a sitios (js/visits.js › localSeries; en el servidor, Lib/series.php).
// · Sin servidor: PROVISIONAL con shared/iucn-quantifiers.js + shared/iucn-engine.js y SOLO las marcas exactas
//   que hay en el dispositivo (como EOO/AOO, F9), los parámetros, las localidades confirmadas y los impactos
//   que bajaron por el pull. Mismas reglas en los dos lados (casos compartidos: shared/iucn-cases.json).
// recompute(): el motor otra vez con las decisiones del usuario (NT/LC, posiblemente extinta), sin red.
// ================================================================================================

import { apiGetJson } from './api.js';
import { state } from './state.js';
import { byIndex, get, getMeta, setMeta, hasDb } from './db.js';
import { remoteOrLocal } from './offline.js';
import { toUtm } from './geo/utm.js';
import { provisionalMetrics } from './metrics.js';
import { quantify, engineInputs } from '../shared/iucn-quantifiers.js';
import { assessIucn } from '../shared/iucn-engine.js';
import { loadIucnCriteria } from './views/iucn-wizard.js';
import { loadThreats } from './views/impact-sheet.js';
import { localParameterDefaults } from './components/species-parameters.js';
import { localSeries } from './visits.js';

const key = (catalogId, taxonID) => `assessment:${catalogId}:${taxonID}`;

/** Lo mínimo de la evaluación para guardarlo con una instantánea (sin geometría). */
export function quantifierSummary(a) {
  const m = a.mature || {};
  const sp = a.subpopulations || {};
  return {
    origin: a.origin, computedAt: a.computedAt, nPoints: a.nPoints,
    metrics: a.metrics ? { eooKm2: a.metrics.eooKm2, aooKm2: a.metrics.aooKm2, aooRange: a.metrics.aooRange, cellSizeKm: a.metrics.cellSizeKm } : null,
    mature: { min: m.min, best: m.best, max: m.max, quality: m.quality, method: m.method, density: m.density, areaKm2: m.areaKm2,
      areaSource: m.areaSource, doubleCountPairs: m.doubleCountPairs },
    subpopulations: { separationKm: sp.separationKm, c2a: sp.c2a,
      list: (sp.subpops || []).map((s) => ({ label: s.label, nRecords: s.nRecords, mature: s.mature, nearestKm: s.nearestKm })) },
    fragmentation: a.fragmentation ? { status: a.fragmentation.status, severe: a.fragmentation.severe, pctAoo: a.fragmentation.pctAoo,
      pctIndividuals: a.fragmentation.pctIndividuals, missing: a.fragmentation.missing } : null,
    locations: a.locations ? { count: a.locations.count, confirmed: true } : { count: a.locationsProposal ? a.locationsProposal.count : null, confirmed: false },
    series: a.series ? { status: a.series.status, reason: a.series.reason, nSites: a.series.nSites, nYears: a.series.nYears, firstYear: a.series.firstYear,
      lastYear: a.series.lastYear, trend: a.series.trend, window: a.series.window, reduction: a.series.reduction, fluctuation: a.series.fluctuation,
      windowYears: a.series.windowYears } : null,
  };
}

/** Impactos del dispositivo que afectan a la especie, proyectados (los generalizados, con su centro generalizado). */
async function localImpacts(catalogId, taxonID) {
  return (await byIndex('impacts', 'catalogId', catalogId))
    .filter((i) => !i.deletedAt && (i.species || []).includes(taxonID) && i.decimalLatitude !== null && i.decimalLatitude !== undefined)
    .map((i) => {
      const u = toUtm(i.decimalLatitude, i.decimalLongitude);
      return { impactID: i.impactID, threatCode: i.threatCode, intensity: i.intensity, scopePct: i.scopePct, timing: i.timing,
        x: u.easting, y: u.northing, radiusM: i.radiusM,
        polygonXY: i.polygon ? i.polygon.map(([lat, lon]) => { const p = toUtm(lat, lon); return { x: p.easting, y: p.northing }; }) : null };
    });
}

/** Provisional en el dispositivo, con la misma forma que la respuesta del servidor. */
export async function provisionalAssessment(catalog, species) {
  const [criteria, threats] = await Promise.all([loadIucnCriteria(), loadThreats().catch(() => ({ habitat_codes: [] }))]);
  const taxonID = species.taxonID;
  const metricsData = await provisionalMetrics(catalog.id, taxonID);
  const metrics = { eooKm2: metricsData.eooKm2, aooKm2: metricsData.aooKm2, aooRange: metricsData.aooRange, aooCells: metricsData.aooCells,
    nRecords: metricsData.nRecords, cellSizeKm: metricsData.cellSizeKm, warnings: metricsData.warnings, computedAt: metricsData.computedAt };
  const records = (await byIndex('occurrences', 'taxonID', taxonID)).filter((o) => o.catalogId === catalog.id && !o.deletedAt && !o.generalized);
  const paramList = hasDb() ? await byIndex('parameters', 'catalogTaxon', [catalog.id, taxonID]) : [];
  const params = Object.fromEntries(paramList.map((p) => [p.key, p]));
  const defaults = await localParameterDefaults(species);
  const loc = hasDb() ? await get('locations', [catalog.id, taxonID]) : null;
  const impacts = await localImpacts(catalog.id, taxonID);
  const q = quantify(records, { project: toUtm, params, defaults, impacts, metrics, habitatCodes: threats.habitat_codes });
  // F11h: serie temporal con las visitas y marcas del dispositivo (A y C1).
  const series = await localSeries(catalog.id, taxonID, { generationLength: params.generation_length ? params.generation_length.value : null, criteria })
    .catch(() => null);
  const inputs = engineInputs(q, { params, metrics, locations: loc || null, series });
  const result = assessIucn(inputs, criteria);
  const { kept: _kept, ...mature } = q.mature;
  const subpops = { ...q.subpops, subpops: q.subpops.subpops.map(({ points, ...s }) => s) };
  return {
    taxonID, computedAt: new Date().toISOString(), origin: 'device', geometry: 'full', nExact: records.length, metrics, nPoints: q.nPoints,
    mature, subpopulations: subpops, fragmentation: q.fragmentation, locationsProposal: q.locations,
    locations: loc || null, suggestions: [...q.suggestions, ...((series && series.suggestions) || [])], separation: q.separation, parameters: params,
    defaults, inputs, result, series,
    canEdit: false,
  };
}

/**
 * Evaluación para la pestaña y el mapa → {source: 'server' | 'device', data, lastServer?}. Con servidor, la
 * definitiva (y se guarda); sin él, la provisional y la última definitiva guardada. `gbif`: añade la comparación.
 */
export async function speciesAssessment(catalog, species, { gbif = false } = {}) {
  const k = key(catalog.id, species.taxonID);
  const res = await remoteOrLocal(async () => {
    const data = await apiGetJson(`/catalogs/${encodeURIComponent(catalog.id)}/species/${encodeURIComponent(species.taxonID)}/assessment`
      + (gbif ? '?gbif=1' : ''), { timeoutMs: state.online ? (gbif ? 90000 : 30000) : 3000 });
    const { gbif: _g, ...plain } = data;
    if (hasDb()) setMeta(k, { data: plain, savedAt: Date.now() }).catch(() => {});
    return { source: 'server', data };
  }, async () => {
    const [data, last] = await Promise.all([provisionalAssessment(catalog, species), hasDb() ? getMeta(k).catch(() => null) : null]);
    return { source: 'device', data, lastServer: last && last.data ? last : null };
  });
  return res.data;
}

/** El motor otra vez con las decisiones del usuario (NT/LC por juicio experto, CR(PE)). */
export async function recompute(inputs, choices) {
  const criteria = await loadIucnCriteria();
  const next = { ...inputs, expertCategory: choices.expertCategory === 'NT' || choices.expertCategory === 'LC' ? choices.expertCategory : null,
    possiblyExtinct: choices.possiblyExtinct === true };
  return { inputs: next, result: assessIucn(next, criteria) };
}
