// ================================================================================================
// Pruebas del QR de invitación (components/qr.js + vendor/qrcode-generator):  node frontend/tests/qr.test.mjs
// Comprueba la matriz (tamaño, patrones de posición) y que el trazado cubre exactamente los módulos oscuros.
// ================================================================================================
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// util.js usa window.matchMedia al cargar: un window mínimo basta para importar qr.js.
globalThis.window = { matchMedia: () => ({ matches: false, addEventListener() {} }) };
globalThis.document = {};
const ctx = { module: { exports: {} } };
ctx.exports = ctx.module.exports;
vm.runInNewContext(readFileSync(new URL('../vendor/qrcode-generator/qrcode.js', import.meta.url), 'utf8'), ctx);
const qrcode = ctx.module.exports;
window.qrcode = qrcode;
const { qrMatrix, qrPath } = await import('../js/components/qr.js');

const link = 'https://biodex-production-6847.up.railway.app/#/unirse/01JA2B3C4D5E6F7G8H9J0KMNPQ';
const m = qrMatrix(link, qrcode);
const n = m.length;
assert.ok(n >= 21 && (n - 17) % 4 === 0, `tamaño de QR válido (${n})`);
// Patrón de posición 7×7 en tres esquinas: borde oscuro, anillo claro, centro 3×3 oscuro
const finder = (ox, oy) => {
  for (let y = 0; y < 7; y++) for (let x = 0; x < 7; x++) {
    const ring = Math.max(Math.abs(x - 3), Math.abs(y - 3));
    assert.equal(m[oy + y][ox + x], ring !== 2, `finder ${ox},${oy} en ${x},${y}`);
  }
};
finder(0, 0); finder(n - 7, 0); finder(0, n - 7);

// El trazado: cada tramo «M x y h w v1 h-w z» cubre módulos oscuros y entre todos, todos
const covered = new Set();
for (const [, x, y, w] of qrPath(m, 4).matchAll(/M(\d+) (\d+)h(\d+)v1h-\d+z/g)) {
  for (let i = 0; i < Number(w); i++) covered.add(`${Number(x) - 4 + i},${Number(y) - 4}`);
}
let dark = 0;
m.forEach((row, y) => row.forEach((v, x) => { if (v) { dark++; assert.ok(covered.has(`${x},${y}`)); } }));
assert.equal(covered.size, dark);

assert.throws(() => qrMatrix('x', null), /generador/);
console.log(`qr.test.mjs: OK (QR ${n}×${n})`);
