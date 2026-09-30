// ================================================================================================
// Pruebas de js/geo/utm.js con puntos de control:  node frontend/tests/utm.test.mjs
// Referencia: PROJ 9 (el motor de QGIS/GDAL), `cs2cs -f %.4f EPSG:4258 EPSG:25830`, ejecutado con la
// imagen Docker osgeo/proj el 30/09/2026. Tolerancia: 1 cm en X/Y y ida y vuelta por debajo de 1 mm.
// (.mjs para que check-sw.php no lo cuente como fichero del armazón.)
// ================================================================================================

import { toUtm, fromUtm, utmZone, formatUtm } from '../js/geo/utm.js';

const CONTROL = [
  // [lat, lon, X, Y, dónde]
  [37.1761, -3.5881, 447793.8466, 4114569.9106, 'Alhambra (Granada)'],
  [37.0, -3.0, 500000.0, 4094872.3703, 'meridiano central del huso 30'],
  [38.0, -4.3, 385861.0795, 4206612.2956, 'esquina NO de la provincia de Granada'],
  [36.7, -2.2, 571460.1926, 4061891.3953, 'esquina SE de la provincia de Granada'],
  [40.4168, -3.7038, 440290.4581, 4474257.3819, 'Madrid'],
  [36.0, -6.0, 229578.63, 3988111.9622, 'Tarifa (borde del huso)'],
  [43.3623, -8.4115, 61480.3242, 4815289.6186, 'A Coruña (huso 29, forzado al 30)'],
  [41.3874, 2.1686, 932206.3816, 4594671.0363, 'Barcelona (huso 31, forzado al 30)'],
  [42.5, 3.3, 1017737.8373, 4724563.9608, 'cabo de Creus (huso 31, forzado al 30)'],
  [28.4636, -16.2518, -803684.7103, 3221249.2996, 'Tenerife (huso 28, forzado al 30)'],
];

let failures = 0;
const check = (label, ok, detail = '') => {
  if (!ok) failures++;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${label}${ok || !detail ? '' : `  → ${detail}`}`);
};

for (const [lat, lon, x, y, where] of CONTROL) {
  const u = toUtm(lat, lon);
  const dx = Math.abs(u.easting - x), dy = Math.abs(u.northing - y);
  check(`${where}: X/Y a menos de 1 cm de PROJ`, dx < 0.01 && dy < 0.01,
    `X ${u.easting.toFixed(4)} (Δ ${dx.toFixed(4)} m) · Y ${u.northing.toFixed(4)} (Δ ${dy.toFixed(4)} m)`);
  const back = fromUtm(u.easting, u.northing);
  // 1e-8° ≈ 1 mm
  check(`${where}: ida y vuelta`, Math.abs(back.lat - lat) < 1e-8 && Math.abs(back.lon - lon) < 1e-8,
    `${back.lat}, ${back.lon}`);
}

check('utmZone: Granada en el 30', utmZone(-3.6) === 30);
check('utmZone: A Coruña en el 29', utmZone(-8.4) === 29);
check('utmZone: Barcelona en el 31', utmZone(2.17) === 31);
check('formatUtm con separador de millar', formatUtm({ easting: 447793.8, northing: 4114569.9 }) === 'X 447 794 · Y 4 114 570',
  formatUtm({ easting: 447793.8, northing: 4114569.9 }));
let threw = false;
try { toUtm(NaN, 0); } catch (e) { threw = true; }
check('coordenadas no válidas → RangeError', threw);

console.log(failures ? `\n${failures} fallo(s)` : '\nTodo OK');
process.exit(failures ? 1 : 0);
