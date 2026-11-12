// ================================================================================================
// server.mjs — servidor estático para las pruebas de Playwright: sirve frontend/ como lo hace nginx
// (sin CSP ni proxies). La API NO pasa por aquí: la simula cada prueba (mock-api.mjs, page.route).
//   /                 frontend/
//   /shared/<x>       los ficheros de shared/ que publica nginx
//   /tiles/, /img/fototeca|inat|respaldo/   404 (sin red de verdad: el mapa y las fotos caen a su respaldo)
//   /api/             503 (si llega aquí, una ruta sin simular)
// Uso: node server.mjs [puerto]   (por defecto 4173)
// ================================================================================================
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const FRONT = path.resolve(here, '../..');
const SHARED = path.resolve(here, '../../../shared');
const SHARED_PUBLIC = new Set(['iucn-engine.js', 'iucn-criteria.json', 'geo-metrics.js', 'species-groups.json',
  'iucn-quantifiers.js', 'iucn-threats.json', 'iucn-series.js', 'iucn-sufficiency.js']);
const PORT = Number(process.argv[2] || process.env.PORT || 4173);
const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8', '.webmanifest': 'application/manifest+json', '.svg': 'image/svg+xml',
  '.png': 'image/png', '.woff2': 'font/woff2', '.jpg': 'image/jpeg' };

function send(res, code, body = '', type = 'text/plain; charset=utf-8') {
  res.writeHead(code, { 'Content-Type': type, 'Cache-Control': 'no-cache' });
  res.end(body);
}

http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  let p = decodeURIComponent(url.pathname);
  if (p.startsWith('/api/')) return send(res, 503, JSON.stringify({ error: `Ruta sin simular: ${req.method} ${p}` }), TYPES['.json']);
  if (/^\/(tiles|img\/(fototeca|inat|respaldo))\//.test(p)) return send(res, 404);
  let file;
  if (p.startsWith('/shared/')) {
    const name = p.slice('/shared/'.length);
    if (!SHARED_PUBLIC.has(name)) return send(res, 404);
    file = path.join(SHARED, name);
  } else {
    if (p === '/') p = '/index.html';
    file = path.join(FRONT, p);
    if (!file.startsWith(FRONT + path.sep) || file.includes(`${path.sep}tests${path.sep}`)) return send(res, 404);
  }
  fs.readFile(file, (err, data) => {
    if (err) return send(res, 404);
    send(res, 200, data, TYPES[path.extname(file)] || 'application/octet-stream');
  });
}).listen(PORT, () => console.log(`BioDex (pruebas) en http://localhost:${PORT}`));
