// ================================================================================================
// f11g.spec.mjs — impactos y evaluación UICN (F11g), en móvil (campo) y escritorio (estudio):
//   · registrar un impacto SIN RED desde el botón «Impacto», volver a tener red, sincronizar (llega al «servidor»
//     y vuelve por el pull) y verlo en la pestaña Evaluación UICN: BioDex sugiere b(iii) por el pisoteo en curso.
//   · objetivos táctiles de la hoja de impacto (modo campo).
// La evaluación del servidor no se simula (mock-api.mjs): la pestaña calcula la provisional en el dispositivo.
// ================================================================================================
import { test, expect } from '@playwright/test';
import { mockApi, FX, CATALOG_ID } from './mock-api.mjs';

const ME = FX.me.user;
const PINGUICULA = '6989';
const isField = (testInfo) => testInfo.project.name === 'movil';

async function start(page, context, hash = '') {
  const api = await mockApi(context);
  await context.addInitScript(([user, cat]) => {
    if (sessionStorage.getItem('e2e-init')) return;
    sessionStorage.setItem('e2e-init', '1');
    localStorage.setItem('biodex.user', JSON.stringify(user));
    localStorage.setItem('biodex.activeCatalog', JSON.stringify(cat));
  }, [ME, { id: CATALOG_ID, name: FX.catalog.catalog.name, role: FX.catalog.catalog.role }]);
  await page.goto('/' + hash);
  await expect(page.locator('.shell')).toBeVisible();
  return api;
}

function idb(page, store) {
  return page.evaluate(([name, s]) => new Promise((resolve, reject) => {
    const open = indexedDB.open(name);
    open.onsuccess = () => {
      const db = open.result;
      if (!db.objectStoreNames.contains(s)) { db.close(); resolve([]); return; }
      const rq = db.transaction(s).objectStore(s).getAll();
      rq.onsuccess = () => { db.close(); resolve(rq.result); };
      rq.onerror = () => reject(rq.error);
    };
    open.onerror = () => reject(open.error);
  }), [`biodex-${ME.id}`, store]);
}

async function goTab(page, route) {
  await page.locator(`.tabs .tab[data-route="${route}"]`).click();
  await expect(page.locator('body')).toHaveAttribute('data-route', route);
}

/** Con el catálogo en el dispositivo y el SW al mando (para poder quedarse sin red). */
async function ready(page) {
  await expect.poll(async () => (await idb(page, 'catalogs')).some((c) => c.id === CATALOG_ID && c.downloadedAt), { timeout: 30000 }).toBe(true);
  await page.evaluate(() => navigator.serviceWorker.ready);
  if (!(await page.evaluate(() => !!navigator.serviceWorker.controller))) await page.reload();
  await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller), { timeout: 15000 }).toBe(true);
}

async function openImpactSheet(page, testInfo) {
  await goTab(page, isField(testInfo) ? 'especies' : 'mapa');
  await page.locator('.fab-stack [data-impact]').click();
  await expect(page.locator('.impact-sheet')).toBeVisible();
  return page.locator('.impact-sheet');
}

test('registrar un impacto sin red, sincronizar y verlo en la evaluación', async ({ page, context }, testInfo) => {
  const api = await start(page, context, '#/especies');
  await ready(page);

  await context.setOffline(true);
  const sheet = await openImpactSheet(page, testInfo);
  await sheet.getByRole('button', { name: /^Pisoteo/ }).click();
  await expect(sheet.locator('.impact-chosen')).toContainText('6.1 Actividades recreativas');
  await expect(sheet.locator('.mark-gps')).toContainText('±8 m');
  await sheet.getByRole('radio', { name: 'Alta' }).click();
  await expect(sheet.getByRole('radio', { name: 'En curso' })).toHaveAttribute('aria-checked', 'true');
  await sheet.getByLabel(/Alcance/).fill('30');
  await sheet.locator('input[name="radius"]').fill('1000');
  await sheet.locator('input[name="radius"]').dispatchEvent('change');
  const pingui = sheet.locator(`[data-sp="${PINGUICULA}"]`);
  if (!(await pingui.isChecked())) await pingui.check();
  await sheet.getByRole('button', { name: 'Guardar impacto' }).click();
  await expect(sheet).toBeHidden();

  // Sin red: en el dispositivo y en la cola, nada en el «servidor».
  const local = (await idb(page, 'impacts')).filter((i) => i.threatCode === '6.1');
  expect(local).toHaveLength(1);
  expect(local[0]).toMatchObject({ intensity: 'high', timing: 'ongoing', scopePct: 30, status: 'pending', radiusM: 1000 });
  expect(local[0].species).toContain(PINGUICULA);
  expect((await idb(page, 'outbox')).some((e) => e.kind === 'impact' && e.ref === local[0].impactID)).toBe(true);
  expect(api.impacts).toHaveLength(0);

  // Con red: se sube y vuelve por el pull.
  await context.setOffline(false);
  await page.evaluate(() => window.dispatchEvent(new Event('online')));
  await expect.poll(() => api.impacts.length, { timeout: 20000 }).toBe(1);
  expect(api.impacts[0]).toMatchObject({ threatCode: '6.1', intensity: 'high', timing: 'ongoing', species: expect.arrayContaining([PINGUICULA]) });
  await expect.poll(async () => (await idb(page, 'impacts')).find((i) => i.impactID === local[0].impactID)?.status, { timeout: 20000 }).toBe('synced');

  // En la evaluación de la especie: la sugerencia de b(iii) por el impacto en curso (sin fijarla).
  await goTab(page, 'especies');
  await page.locator(`[data-pick="${PINGUICULA}"], #speciesList [data-taxon="${PINGUICULA}"]`).first().click();
  await page.getByRole('tab', { name: 'Evaluación UICN' }).click();
  const tab = page.locator('.iucn-tab');
  await expect(tab.locator('.iucn-head')).toBeVisible({ timeout: 20000 });
  await expect(tab.locator('.iucn-origin')).toContainText('Provisional');
  const b3 = tab.locator('.fact[data-fact="decline_b_iii"]');
  await expect(b3).toContainText('BioDex sugiere «Sí»');
  await expect(b3).toContainText('1 impacto en curso');
  await expect(b3).toContainText('Sin fijar');
  await expect(tab.locator('.crit-card').filter({ hasText: 'Distribución geográfica' }).locator('.locations')).toContainText('6.1 Actividades recreativas');
});

test('objetivos táctiles de la hoja de impacto (modo campo)', async ({ page, context }, testInfo) => {
  test.skip(!isField(testInfo), 'solo en modo campo');
  await start(page, context, '#/especies');
  const audit = () => page.evaluate(() => {
    const out = [];
    for (const el of document.querySelectorAll('.impact-sheet button, .impact-sheet input:not([type="hidden"]), .impact-sheet select, .impact-sheet textarea, .impact-sheet summary')) {
      if (el.closest('[hidden]') || el.closest('.sr-only')) continue;
      const target = (el.matches('input[type="checkbox"]') && el.closest('label')) || el;
      const r = target.getBoundingClientRect();
      if (!r.width || !r.height) continue;
      if (r.width < 47.5 || r.height < 47.5) out.push(`<${el.tagName.toLowerCase()} class="${el.className}"> «${(el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 40)}» ${Math.round(r.width)}×${Math.round(r.height)}`);
    }
    return out;
  });
  const sheet = await openImpactSheet(page, testInfo);
  const found = [...await audit()];
  await sheet.getByRole('button', { name: 'Otro' }).click();
  await sheet.locator('.impact-tree summary').first().click();
  found.push(...await audit());
  await sheet.locator('[data-threat]').first().click();
  await expect(sheet.locator('.impact-rest')).toBeVisible();
  found.push(...await audit());
  expect(found, found.join('\n')).toEqual([]);
});
