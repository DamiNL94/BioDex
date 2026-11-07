// Hoja de marcar (F11f): contraste AAA (7:1) de cada texto visible en claro y oscuro, nombre accesible de cada
// control y foco atrapado en la hoja. Solo en móvil (modo campo).
import { test, expect } from '@playwright/test';
import { mockApi, FX, CATALOG_ID } from './mock-api.mjs';
for (const theme of ['light', 'dark']) test('hoja de marcar: AAA, nombres y foco (' + theme + ')', async ({ page, context }, ti) => {
  test.skip(ti.project.name !== 'movil');
  await mockApi(context);
  await context.addInitScript(([u, c, t]) => { localStorage.setItem('biodex.user', JSON.stringify(u)); localStorage.setItem('biodex.activeCatalog', JSON.stringify(c)); localStorage.setItem('biodex.theme', JSON.stringify(t)); }, [FX.me.user, { id: CATALOG_ID, name: FX.catalog.catalog.name, role: 'owner' }, theme]);
  await page.goto('/#/especies');
  await page.locator('.fab-stack [data-mark]').click();
  await expect(page.locator('.mark-sheet .pk-row').first()).toBeVisible();
  const check = (label) => page.evaluate((label) => {
    const lum = (c) => { const m = c.match(/[\d.]+/g).map(Number); const [r,g,b] = m.slice(0,3).map(v => { v/=255; return v<=.03928? v/12.92 : ((v+.055)/1.055)**2.4; }); return [.2126*r+.7152*g+.0722*b, m[3] ?? 1]; };
    const bgOf = (el) => { for (let e = el; e; e = e.parentElement) { const c = getComputedStyle(e).backgroundColor; const [, a] = lum(c); if (a > 0.5) return c; } return 'rgb(255,255,255)'; };
    const out = [];
    const walker = document.createTreeWalker(document.querySelector('.mark-sheet'), NodeFilter.SHOW_TEXT);
    const seen = new Set();
    while (walker.nextNode()) {
      const n = walker.currentNode; const el = n.parentElement;
      if (!n.textContent.trim() || seen.has(el) || el.closest('.sr-only,[hidden]') || !el.offsetParent) continue;
      seen.add(el);
      const cs = getComputedStyle(el); if (cs.opacity === '0') continue;
      const [l1] = lum(cs.color); const [l2] = lum(bgOf(el));
      const ratio = (Math.max(l1,l2)+.05)/(Math.min(l1,l2)+.05);
      const disabled = el.closest('button:disabled');
      if (ratio < 7 && !disabled) out.push(`${label}: «${n.textContent.trim().slice(0,30)}» ${ratio.toFixed(2)} (${cs.color} / ${bgOf(el)})`);
    }
    // nombres accesibles
    for (const b of document.querySelectorAll('.mark-sheet button, .mark-sheet input, .mark-sheet select, .mark-sheet textarea')) {
      if (!b.offsetParent) continue;
      const name = b.getAttribute('aria-label') || b.textContent.trim() || (b.labels && b.labels[0] && b.labels[0].textContent.trim()) || b.getAttribute('aria-labelledby') || b.placeholder;
      if (!name) out.push(`${label}: sin nombre <${b.tagName} class=${b.className}>`);
    }
    return out;
  }, label);
  const found = [...await check('buscador')];
  await page.locator('.mark-sheet .pk-row').first().click();
  await page.locator('[data-tally="flowering"]').click();
  found.push(...await check('conteo'));
  // teclado: Tab recorre sin salir de la hoja
  const order = [];
  for (let i = 0; i < 25; i++) { await page.keyboard.press('Tab'); order.push(await page.evaluate(() => { const a = document.activeElement; return (a.closest('.mark-sheet') ? '' : 'FUERA ') + (a.getAttribute('aria-label') || a.textContent.trim().slice(0, 18) || a.name); })); }
  expect(found, found.join(String.fromCharCode(10))).toEqual([]);
  expect(order.filter((o) => o.startsWith('FUERA'))).toEqual([]);
});
