// ================================================================================================
// map/layers.js — capas del IGN (por nuestro proxy /tiles/, docs/mapa.md) y cuentas de teselas.
// ------------------------------------------------------------------------------------------------
// maxZoom es el de nginx (map $tile_ok_zoom de default.conf): pedir más allá da 404. Leaflet sigue
// ampliando la última tesela nativa (maxNativeZoom) hasta MAP_MAX_ZOOM.
// Tamaños medios medidos el 30/09/2026 con 12 teselas al azar por zoom en la provincia de Granada
// (KB por tesela); entre zooms medidos se interpola y por encima vale el último.
// ================================================================================================

export const MAP_MAX_ZOOM = 20;
export const TILE_CACHE_PREFIX = 'biodex-tiles-';
export const RUNTIME_TILE_CACHE = 'biodex-tiles-runtime';

export const LAYERS = [
  { key: 'base', label: 'IGN Base', short: 'Base', attribution: 'IGN Base', maxZoom: 20,
    defaultZooms: [12, 17], sampleKB: { 12: 12.3, 14: 10.2, 16: 5.1, 17: 3.9 } },
  { key: 'pnoa', label: 'PNOA (ortofoto)', short: 'PNOA', attribution: 'PNOA', maxZoom: 19,
    defaultZooms: [12, 16], sampleKB: { 12: 14.8, 14: 14.1, 16: 17.6 } },
  { key: 'mtn', label: 'MTN', short: 'MTN', attribution: 'MTN', maxZoom: 17,
    defaultZooms: [12, 17], sampleKB: { 12: 21.7, 14: 19.7, 16: 14.7, 17: 9.9 } },
];
export const layerByKey = (key) => LAYERS.find((l) => l.key === key) || LAYERS[0];

export const tileUrl = (key, z, x, y) => `/tiles/${key}/${z}/${x}/${y}.jpg`;
export const tileTemplate = (key) => `/tiles/${key}/{z}/{x}/{y}.jpg`;

export function attributionFor(layer) {
  return `© <a href="https://www.ign.es" rel="noopener" target="_blank">Instituto Geográfico Nacional</a> · ${layer.attribution} · ` +
    '<a href="https://creativecommons.org/licenses/by/4.0/deed.es" rel="noopener" target="_blank">CC BY 4.0</a>';
}

// --- Rejilla Web Mercator (GoogleMapsCompatible) -------------------------------------------------
const clampLat = (lat) => Math.max(-85.05112878, Math.min(85.05112878, lat));
export const lon2x = (lon, z) => Math.min(2 ** z - 1, Math.max(0, Math.floor(((lon + 180) / 360) * 2 ** z)));
export function lat2y(lat, z) {
  const r = (clampLat(lat) * Math.PI) / 180;
  return Math.min(2 ** z - 1, Math.max(0, Math.floor(((1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2) * 2 ** z)));
}

/** Rango de teselas de `bounds` ({south, west, north, east}) en el zoom z. */
export function tileRange(bounds, z) {
  return { x0: lon2x(bounds.west, z), x1: lon2x(bounds.east, z), y0: lat2y(bounds.north, z), y1: lat2y(bounds.south, z) };
}

export function countTiles(bounds, z) {
  const r = tileRange(bounds, z);
  return (r.x1 - r.x0 + 1) * (r.y1 - r.y0 + 1);
}

/** Recorre las teselas de una zona: [{key, z, x, y, url}], capa a capa y de menos a más zoom. */
export function* zoneTiles(bounds, layers) {
  for (const { key, minZoom, maxZoom } of layers) {
    for (let z = minZoom; z <= maxZoom; z++) {
      const r = tileRange(bounds, z);
      for (let x = r.x0; x <= r.x1; x++) for (let y = r.y0; y <= r.y1; y++) yield { key, z, x, y, url: tileUrl(key, z, x, y) };
    }
  }
}

/** KB medios de una tesela de `key` en el zoom z. */
export function tileKB(key, z) {
  const pts = Object.entries(layerByKey(key).sampleKB).map(([k, v]) => [Number(k), v]).sort((a, b) => a[0] - b[0]);
  if (z <= pts[0][0]) return pts[0][1];
  for (let i = 1; i < pts.length; i++) {
    const [z1, v1] = pts[i];
    const [z0, v0] = pts[i - 1];
    if (z <= z1) return v0 + ((v1 - v0) * (z - z0)) / (z1 - z0);
  }
  return pts[pts.length - 1][1];
}

/** {tiles, bytes, perLayer: {key: {tiles, bytes}}} de una zona antes de descargarla. */
export function estimateZone(bounds, layers) {
  const out = { tiles: 0, bytes: 0, perLayer: {} };
  for (const { key, minZoom, maxZoom } of layers) {
    let tiles = 0;
    let bytes = 0;
    for (let z = minZoom; z <= maxZoom; z++) {
      const n = countTiles(bounds, z);
      tiles += n;
      bytes += n * tileKB(key, z) * 1024;
    }
    out.perLayer[key] = { tiles, bytes };
    out.tiles += tiles;
    out.bytes += bytes;
  }
  return out;
}
