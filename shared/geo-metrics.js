// ================================================================================================
// geo-metrics.js — EOO y AOO de la UICN a partir de registros (F9). JS puro, sin DOM.
// ------------------------------------------------------------------------------------------------
// Lo usa el dispositivo (cálculo PROVISIONAL sin red, /shared/geo-metrics.js) y lo repite en PHP
// backend/Lib/metrics.php (el DEFINITIVO, con las coordenadas exactas). Los dos pasan los mismos
// casos: shared/metrics-cases.json (node shared/geo-metrics.test.js · bin/test-f9.php).
//
// Definiciones de la UICN (docs/iucn.md › «EOO y AOO en BioDex»):
//   · EOO: área del polígono convexo mínimo de todos los registros, en km². Casco por la cadena monótona
//     de Andrew, área por la fórmula del lazo. Con menos de 3 puntos no colineales no hay EOO.
//   · AOO: nº de celdas de rejilla ocupadas × área de la celda. Celda por defecto 2 × 2 km; 1 km con
//     aviso. Rejilla anclada al origen UTM: celda = (floor(x / tamaño), floor(y / tamaño)).
//   · Si EOO < AOO → EOO = AOO (con aviso).
//   · Todo en ETRS89 / UTM 30N (EPSG:25830). La proyección se INYECTA (`project`): en el navegador es
//     toUtm() de js/geo/utm.js; en Node, la misma importada desde frontend/.
//   · Informativo: AOO mínima y máxima desplazando la rejilla medio tamaño en x, en y y en ambos.
// Qué registros cuentan: presentes; se excluyen los de precisión peor que el tamaño de celda. Borrados,
// catálogo y ámbito (equipo / propias) los filtra quien llama. Las localidades NO se calculan.
// ================================================================================================

export const DEFAULT_CELL_KM = 2;
export const CELL_OPTIONS_KM = [2, 1];
export const CELL_1KM_WARNING = 'Celdas de 1 km: los umbrales de B2 están calibrados para celdas de 2 km.';
const ZONE_MIN_LON = -6;   // huso 30: de 6° O a 0°
const ZONE_MAX_LON = 0;

/**
 * Registros → {used, excluded: {absent, imprecise, noCoordinates}}. Un registro: {decimalLatitude,
 * decimalLongitude, coordinateUncertaintyInMeters, occurrenceStatus}. Sin precisión conocida, cuenta.
 */
export function selectRecords(records, cellKm = DEFAULT_CELL_KM) {
  const used = [];
  const excluded = { absent: 0, imprecise: 0, noCoordinates: 0 };
  const maxM = cellKm * 1000;
  for (const r of records || []) {
    if (r.occurrenceStatus === 'absent') { excluded.absent++; continue; }
    const lat = Number(r.decimalLatitude);
    const lon = Number(r.decimalLongitude);
    if (r.decimalLatitude === null || r.decimalLatitude === undefined || !Number.isFinite(lat) || !Number.isFinite(lon)) {
      excluded.noCoordinates++;
      continue;
    }
    const u = r.coordinateUncertaintyInMeters;
    if (u !== null && u !== undefined && Number(u) > maxM) { excluded.imprecise++; continue; }
    used.push({ lat, lon });
  }
  return { used, excluded };
}

const cross = (o, a, b) => (a.x - o.x) * (b.y - o.y) - (a.y - o.y) * (b.x - o.x);

/** Casco convexo (cadena monótona de Andrew), antihorario y sin puntos colineales. */
export function convexHull(points) {
  const pts = [...points].sort((a, b) => a.x - b.x || a.y - b.y)
    .filter((p, i, arr) => i === 0 || p.x !== arr[i - 1].x || p.y !== arr[i - 1].y);
  if (pts.length < 3) return pts;
  const lower = [];
  for (const p of pts) {
    while (lower.length >= 2 && cross(lower[lower.length - 2], lower[lower.length - 1], p) <= 0) lower.pop();
    lower.push(p);
  }
  const upper = [];
  for (let i = pts.length - 1; i >= 0; i--) {
    const p = pts[i];
    while (upper.length >= 2 && cross(upper[upper.length - 2], upper[upper.length - 1], p) <= 0) upper.pop();
    upper.push(p);
  }
  lower.pop();
  upper.pop();
  return lower.concat(upper);
}

/** Área (m²) de un polígono por la fórmula del lazo. */
export function polygonArea(poly) {
  let s = 0;
  for (let i = 0; i < poly.length; i++) {
    const a = poly[i];
    const b = poly[(i + 1) % poly.length];
    s += a.x * b.y - b.x * a.y;
  }
  return Math.abs(s) / 2;
}

/** Celdas ocupadas (Map "i,j" → [i, j]) con la rejilla desplazada (ox, oy) metros. */
export function occupiedCells(points, cellM, ox = 0, oy = 0) {
  const cells = new Map();
  for (const p of points) {
    const i = Math.floor((p.x - ox) / cellM);
    const j = Math.floor((p.y - oy) / cellM);
    cells.set(`${i},${j}`, [i, j]);
  }
  return cells;
}

const round3 = (v) => Math.round(v * 1000) / 1000;
/** «31,829» (coma decimal, como en PHP: str_replace sobre el número). */
const decimalEs = (v) => String(v).replace('.', ',');


/**
 * Métricas a partir de puntos YA proyectados [{x, y, lat?, lon?}] (metros, EPSG:25830).
 * → {eooKm2, eooAdjusted, aooKm2, aooCells, cellSizeKm, aooRange: {min, max}, hull: [{x, y, lat, lon}],
 *    cells: [[i, j]], warnings}
 */
export function metricsFromPoints(points, cellKm = DEFAULT_CELL_KM) {
  const warnings = [];
  const cellM = cellKm * 1000;
  const n = points.length;
  if (cellKm !== DEFAULT_CELL_KM) warnings.push(CELL_1KM_WARNING);
  if (!n) {
    return { eooKm2: null, eooAdjusted: false, aooKm2: null, aooCells: 0, cellSizeKm: cellKm, aooRange: null,
      hull: [], cells: [], warnings: [...warnings, 'Sin registros que cuenten: ni EOO ni AOO.'] };
  }
  const cells = occupiedCells(points, cellM);
  const aooCells = cells.size;
  const aooKm2 = round3(aooCells * cellKm * cellKm);
  const counts = [[0, 0], [cellM / 2, 0], [0, cellM / 2], [cellM / 2, cellM / 2]]
    .map(([ox, oy]) => occupiedCells(points, cellM, ox, oy).size);
  const aooRange = { min: round3(Math.min(...counts) * cellKm * cellKm), max: round3(Math.max(...counts) * cellKm * cellKm) };

  const hull = convexHull(points);
  let eooKm2 = null;
  let eooAdjusted = false;
  if (hull.length < 3) {
    warnings.push('Menos de 3 puntos no colineales: no hay EOO.');
  } else {
    eooKm2 = round3(polygonArea(hull) / 1e6);
    if (eooKm2 < aooKm2) {
      warnings.push(`EOO (${decimalEs(eooKm2)} km²) menor que AOO (${decimalEs(aooKm2)} km²): se toma EOO = AOO.`);
      eooKm2 = aooKm2;
      eooAdjusted = true;
    }
  }
  return { eooKm2, eooAdjusted, aooKm2, aooCells, cellSizeKm: cellKm, aooRange, hull: hull.length >= 3 ? hull : [],
    cells: [...cells.values()].sort((a, b) => a[0] - b[0] || a[1] - b[1]), warnings };
}

/**
 * Métricas de registros con lat/lon. `project(lat, lon)` → {easting, northing} en EPSG:25830.
 * → las de metricsFromPoints() + {nRecords, nExcluded, excluded}.
 */
export function computeMetrics(records, { cellKm = DEFAULT_CELL_KM, project } = {}) {
  if (typeof project !== 'function') throw new Error('computeMetrics necesita la proyección (toUtm de geo/utm.js).');
  if (!CELL_OPTIONS_KM.includes(cellKm)) throw new RangeError(`Celda de ${cellKm} km: solo ${CELL_OPTIONS_KM.join(' o ')} km.`);
  const { used, excluded } = selectRecords(records, cellKm);
  const points = used.map((r) => { const u = project(r.lat, r.lon); return { x: u.easting, y: u.northing, lat: r.lat, lon: r.lon }; });
  const m = metricsFromPoints(points, cellKm);
  const outside = used.filter((r) => r.lon < ZONE_MIN_LON || r.lon > ZONE_MAX_LON).length;
  if (outside) m.warnings.push(`${outside} ${outside === 1 ? 'registro' : 'registros'} fuera del huso 30 (6° O–0°): se proyectan igual, con deformación.`);
  const nExcluded = excluded.absent + excluded.imprecise + excluded.noCoordinates;
  if (excluded.imprecise) m.warnings.push(`${excluded.imprecise} ${excluded.imprecise === 1 ? 'registro excluido' : 'registros excluidos'} por precisión peor que ${cellKm} km.`);
  return { ...m, nRecords: used.length, nExcluded, excluded };
}
