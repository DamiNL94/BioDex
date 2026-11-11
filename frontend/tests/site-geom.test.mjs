// ================================================================================================
// Pruebas de js/site-geom.js (F11h, geometría de los sitios):  node frontend/tests/site-geom.test.mjs
// WKT (orden «lon lat», cierre del polígono), superficie y longitud en UTM 30N (las mismas cifras que calcula el
// servidor, Lib/visits.php › siteGeometryMetrics), banda del transecto y distancias.
// ================================================================================================
import assert from 'node:assert/strict';
import { polygonWkt, lineWkt, parseWkt, siteMetrics, distanceM, formatArea, formatLength } from '../js/site-geom.js';
import { toUtm } from '../js/geo/utm.js';

// WKT: orden de la norma (lon lat) y polígono cerrado
const sq = [[37.1, -3.4], [37.1, -3.399], [37.099, -3.399], [37.099, -3.4]];
assert.equal(polygonWkt(sq), 'POLYGON((-3.4 37.1, -3.399 37.1, -3.399 37.099, -3.4 37.099, -3.4 37.1))');
assert.equal(lineWkt([[37.1, -3.4], [37.1, -3.39]]), 'LINESTRING(-3.4 37.1, -3.39 37.1)');
assert.throws(() => polygonWkt(sq.slice(0, 2)), /3 vértices/);
assert.throws(() => lineWkt(sq.slice(0, 1)), /2 puntos/);
const back = parseWkt(polygonWkt(sq));
assert.equal(back.type, 'POLYGON');
assert.deepEqual(back.latlngs, sq, 'ida y vuelta sin el vértice de cierre');
assert.equal(parseWkt('POINT(1 2)'), null);
assert.equal(parseWkt('LINESTRING(a b, 1 2)'), null);

// Superficie del polígono en UTM 30N: 0,001° × 0,001° a 37,1° N ≈ 88,8 m × 111 m ≈ 9 860 m²
const m = siteMetrics({ wkt: polygonWkt(sq) }, toUtm);
assert.ok(Math.abs(m.areaM2 - 9860) < 120, `superficie ${m.areaM2}`);
assert.equal(m.lengthM, null);
assert.ok(Math.abs(m.center[0] - 37.0995) < 1e-9 && Math.abs(m.center[1] + 3.3995) < 1e-9, 'centro = media de los vértices');
assert.ok(m.extentM > 70 && m.extentM < 75, `extensión ${m.extentM}`);

// Transecto: longitud y superficie = longitud × banda (la del servidor en bin/test-f11h.php: ≈ 1 446 m)
const tr = siteMetrics({ wkt: 'LINESTRING(-3.4 37.1, -3.39 37.1, -3.39 37.105)', bandM: 10 }, toUtm);
assert.ok(Math.abs(tr.lengthM - 1446) < 15, `longitud ${tr.lengthM}`);
assert.ok(Math.abs(tr.areaM2 - 10 * tr.lengthM) < 1e-6);
assert.equal(siteMetrics({ wkt: 'LINESTRING(-3.4 37.1, -3.39 37.1)' }, toUtm).areaM2, null, 'sin banda, sin superficie');

// Punto + radio
const c = siteMetrics({ radiusM: 20, center: [37, -3] }, toUtm);
assert.ok(Math.abs(c.areaM2 - Math.PI * 400) < 1e-9);
assert.equal(c.extentM, 20);

// Distancia y formatos
assert.ok(Math.abs(distanceM({ lat: 37, lng: -3 }, { lat: 37.001, lng: -3 }) - 111.2) < 0.5);
assert.equal(formatArea(350.4), '350 m²');
assert.equal(formatArea(12500), '1,25 ha');
assert.equal(formatLength(850), '850 m');
assert.equal(formatLength(2400), '2,4 km');
assert.equal(formatArea(null), '—');

console.log('site-geom: OK');
