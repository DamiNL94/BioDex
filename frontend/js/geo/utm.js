// ================================================================================================
// geo/utm.js — UTM sobre ETRS89 (elipsoide GRS80) sin dependencias. Por defecto huso 30 N, que es
// EPSG:25830: el de toda la España peninsular (fuera del huso se sigue proyectando en el 30, como
// hacen los SIG con ese EPSG). F9 lo reutiliza para EOO/AOO.
// ------------------------------------------------------------------------------------------------
// Mercator Transversa con las series de Krüger hasta n⁶ (Karney 2011, «Transverse Mercator with an
// accuracy of a few nanometers»): error por debajo del milímetro a miles de km del meridiano central.
// Puntos de control contra PROJ (cs2cs EPSG:4258 → EPSG:25830) en frontend/tests/utm.test.mjs.
// ================================================================================================

const A = 6378137;                  // GRS80: semieje mayor
const F = 1 / 298.257222101;        // GRS80: aplanamiento
const K0 = 0.9996;
const FALSE_EASTING = 500000;
const FALSE_NORTHING_SOUTH = 10000000;

const N = F / (2 - F);
const E = Math.sqrt(F * (2 - F));   // excentricidad
const N2 = N * N, N3 = N2 * N, N4 = N3 * N, N5 = N4 * N, N6 = N5 * N;
// Radio rectificador
const AA = (A / (1 + N)) * (1 + N2 / 4 + N4 / 64 + N6 / 256);
const ALPHA = [
  N / 2 - (2 / 3) * N2 + (5 / 16) * N3 + (41 / 180) * N4 - (127 / 288) * N5 + (7891 / 37800) * N6,
  (13 / 48) * N2 - (3 / 5) * N3 + (557 / 1440) * N4 + (281 / 630) * N5 - (1983433 / 1935360) * N6,
  (61 / 240) * N3 - (103 / 140) * N4 + (15061 / 26880) * N5 + (167603 / 181440) * N6,
  (49561 / 161280) * N4 - (179 / 168) * N5 + (6601661 / 7257600) * N6,
  (34729 / 80640) * N5 - (3418889 / 1995840) * N6,
  (212378941 / 319334400) * N6,
];
const BETA = [
  N / 2 - (2 / 3) * N2 + (37 / 96) * N3 - (1 / 360) * N4 - (81 / 512) * N5 + (96199 / 604800) * N6,
  (1 / 48) * N2 + (1 / 15) * N3 - (437 / 1440) * N4 + (46 / 105) * N5 - (1118711 / 3870720) * N6,
  (17 / 480) * N3 - (37 / 840) * N4 - (209 / 4480) * N5 + (5569 / 90720) * N6,
  (4397 / 161280) * N4 - (11 / 504) * N5 - (830251 / 7257600) * N6,
  (4583 / 161280) * N5 - (108847 / 3991680) * N6,
  (20648693 / 638668800) * N6,
];

const RAD = Math.PI / 180;
const centralMeridian = (zone) => (zone - 1) * 6 - 180 + 3;

/** Huso UTM «natural» de una longitud (1–60). EPSG:25830 es el 30 aunque el punto caiga fuera. */
export const utmZone = (lon) => Math.min(60, Math.max(1, Math.floor((lon + 180) / 6) + 1));

/**
 * Latitud/longitud ETRS89 (grados) → {easting, northing, zone, north} en metros.
 * `zone` por defecto 30 (EPSG:25830).
 */
export function toUtm(lat, lon, zone = 30) {
  if (!Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat) > 90) throw new RangeError('Coordenadas no válidas');
  const phi = lat * RAD;
  const lam = (lon - centralMeridian(zone)) * RAD;
  const cosLam = Math.cos(lam), sinLam = Math.sin(lam);

  // Latitud conforme (como tangente)
  const tau = Math.tan(phi);
  const sigma = Math.sinh(E * Math.atanh((E * tau) / Math.sqrt(1 + tau * tau)));
  const tauP = tau * Math.sqrt(1 + sigma * sigma) - sigma * Math.sqrt(1 + tau * tau);

  const xiP = Math.atan2(tauP, cosLam);
  const etaP = Math.asinh(sinLam / Math.sqrt(tauP * tauP + cosLam * cosLam));

  let xi = xiP, eta = etaP;
  for (let j = 1; j <= 6; j++) {
    xi += ALPHA[j - 1] * Math.sin(2 * j * xiP) * Math.cosh(2 * j * etaP);
    eta += ALPHA[j - 1] * Math.cos(2 * j * xiP) * Math.sinh(2 * j * etaP);
  }
  const north = lat >= 0;
  return {
    easting: FALSE_EASTING + K0 * AA * eta,
    northing: K0 * AA * xi + (north ? 0 : FALSE_NORTHING_SOUTH),
    zone,
    north,
  };
}

/** {easting, northing} en el huso `zone` (30 por defecto) → {lat, lon} ETRS89 en grados. */
export function fromUtm(easting, northing, zone = 30, north = true) {
  const eta = (easting - FALSE_EASTING) / (K0 * AA);
  const xi = (northing - (north ? 0 : FALSE_NORTHING_SOUTH)) / (K0 * AA);

  let xiP = xi, etaP = eta;
  for (let j = 1; j <= 6; j++) {
    xiP -= BETA[j - 1] * Math.sin(2 * j * xi) * Math.cosh(2 * j * eta);
    etaP -= BETA[j - 1] * Math.cos(2 * j * xi) * Math.sinh(2 * j * eta);
  }
  const sinhEtaP = Math.sinh(etaP);
  const sinXiP = Math.sin(xiP), cosXiP = Math.cos(xiP);
  const tauP = sinXiP / Math.sqrt(sinhEtaP * sinhEtaP + cosXiP * cosXiP);

  // De latitud conforme a geodésica: Newton-Raphson (converge en 2–3 pasos)
  let tau = tauP;
  for (let i = 0; i < 10; i++) {
    const sigma = Math.sinh(E * Math.atanh((E * tau) / Math.sqrt(1 + tau * tau)));
    const tauI = tau * Math.sqrt(1 + sigma * sigma) - sigma * Math.sqrt(1 + tau * tau);
    const d = ((tauP - tauI) / Math.sqrt(1 + tauI * tauI)) *
      ((1 + (1 - E * E) * tau * tau) / ((1 - E * E) * Math.sqrt(1 + tau * tau)));
    tau += d;
    if (Math.abs(d) < 1e-12) break;
  }
  return {
    lat: Math.atan(tau) / RAD,
    lon: centralMeridian(zone) + Math.atan2(sinhEtaP, cosXiP) / RAD,
  };
}

/** «X 447 794 · Y 4 114 570» (metros enteros, espacios finos de millar). */
export function formatUtm({ easting, northing }) {
  const f = (v) => String(Math.round(v)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  return `X ${f(easting)} · Y ${f(northing)}`;
}
