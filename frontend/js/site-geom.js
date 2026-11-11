// ================================================================================================
// site-geom.js — geometría de los sitios de muestreo (F11h): WKT, superficie, longitud y centro. Sin DOM ni imports:
// lo prueba node (frontend/tests/site-geom.test.mjs). La proyección (UTM 30N, js/geo/utm.js) se INYECTA.
// Mismas cifras que el servidor (backend/Lib/visits.php › siteGeometryMetrics), que es quien manda: lo de aquí es
// para enseñarlo mientras se dibuja y sin red.
// WKT en WGS84 con el orden de la norma: «lon lat». En la app los vértices van como [lat, lon] (Leaflet).
// ================================================================================================

const num = (v) => Number(v.toFixed(6)).toString();

/** [[lat, lon]…] → POLYGON((lon lat, …, primero)). */
export function polygonWkt(latlngs) {
  if (!latlngs || latlngs.length < 3) throw new Error('Un polígono necesita al menos 3 vértices.');
  const ring = [...latlngs, latlngs[0]];
  return `POLYGON((${ring.map(([lat, lon]) => `${num(lon)} ${num(lat)}`).join(', ')}))`;
}

/** [[lat, lon]…] → LINESTRING(lon lat, …). */
export function lineWkt(latlngs) {
  if (!latlngs || latlngs.length < 2) throw new Error('Una línea necesita al menos 2 puntos.');
  return `LINESTRING(${latlngs.map(([lat, lon]) => `${num(lon)} ${num(lat)}`).join(', ')})`;
}

/** WKT → {type: POLYGON | LINESTRING, latlngs: [[lat, lon]…]} (el polígono sin repetir el vértice de cierre), o null. */
export function parseWkt(wkt) {
  const m = /^\s*(POLYGON|LINESTRING)\s*\(\s*(\(?)([^()]*)\)?\s*\)\s*$/i.exec(wkt || '');
  if (!m) return null;
  const type = m[1].toUpperCase();
  const latlngs = [];
  for (const pair of m[3].split(',')) {
    const [x, y] = pair.trim().split(/\s+/).map(Number);
    if (!Number.isFinite(x) || !Number.isFinite(y)) return null;
    latlngs.push([y, x]);
  }
  if (type === 'POLYGON' && latlngs.length > 1) {
    const [a, b] = [latlngs[0], latlngs[latlngs.length - 1]];
    if (a[0] === b[0] && a[1] === b[1]) latlngs.pop();
  }
  return { type, latlngs };
}

/**
 * Cifras de un sitio. {radiusM} o {wkt}; `bandM` (ancho total de la banda) para un transecto; `project(lat, lon)` →
 * {easting, northing}. → {areaM2, lengthM, center: [lat, lon], extentM}. Superficie: círculo, polígono o longitud × banda.
 */
export function siteMetrics({ radiusM = null, wkt = null, bandM = null, center = null }, project) {
  if (radiusM) return { areaM2: Math.PI * radiusM * radiusM, lengthM: null, center, extentM: radiusM };
  const g = parseWkt(wkt);
  if (!g) return { areaM2: null, lengthM: null, center, extentM: null };
  const ring = g.type === 'POLYGON' ? [...g.latlngs, g.latlngs[0]] : g.latlngs;
  const pts = ring.map(([lat, lon]) => { const u = project(lat, lon); return [u.easting, u.northing]; });
  let length = 0;
  for (let i = 1; i < pts.length; i++) length += Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]);
  let area = null;
  if (g.type === 'POLYGON') {
    let s = 0;
    for (let i = 0; i < pts.length - 1; i++) s += pts[i][0] * pts[i + 1][1] - pts[i + 1][0] * pts[i][1];
    area = Math.abs(s) / 2;
  }
  const c = [g.latlngs.reduce((a, p) => a + p[0], 0) / g.latlngs.length, g.latlngs.reduce((a, p) => a + p[1], 0) / g.latlngs.length];
  const cu = project(c[0], c[1]);
  const extent = Math.max(...pts.map((p) => Math.hypot(p[0] - cu.easting, p[1] - cu.northing)));
  const lengthM = g.type === 'LINESTRING' ? length : null;
  if (area === null && lengthM !== null && bandM) area = lengthM * bandM;
  return { areaM2: area, lengthM, center: c, extentM: extent };
}

/** «350 m²» o «1,25 ha». */
export function formatArea(m2) {
  if (m2 === null || m2 === undefined || !Number.isFinite(Number(m2))) return '—';
  const v = Number(m2);
  return v >= 10000 ? `${(v / 10000).toLocaleString('es-ES', { maximumFractionDigits: 2 })} ha`
    : `${Math.round(v).toLocaleString('es-ES')} m²`;
}

/** «850 m» o «2,4 km». */
export function formatLength(m) {
  if (m === null || m === undefined || !Number.isFinite(Number(m))) return '—';
  const v = Number(m);
  return v >= 1000 ? `${(v / 1000).toLocaleString('es-ES', { maximumFractionDigits: 1 })} km` : `${Math.round(v)} m`;
}
