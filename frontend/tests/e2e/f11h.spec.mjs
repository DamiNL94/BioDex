// ================================================================================================
// f11h.spec.mjs — visita completa en el móvil, SIN RED: empezar una visita en un sitio nuevo con tres especies
// objetivo, contar dos desde «Marcar aquí» (el buscador pone primero las objetivo), cerrarla con la tercera como
// AUSENTE, comprobar lo guardado en IndexedDB y en la cola, volver a tener red y sincronizar: sitio, visita (cerrada) y
// marcas llegan al «servidor» (mock-api.mjs) con su visitID, el sitio y la visita antes que sus marcas, y vuelven por
// el pull como sincronizadas.
// ================================================================================================
import { test, expect } from '@playwright/test';
import { mockApi, FX, CATALOG_ID } from './mock-api.mjs';

const ME = FX.me.user;
const PINGUICULA = '6989';
const VIOLA = '9392';
const SEMPERVIVUM = '8242';

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

/** Con el catálogo en el dispositivo y el SW al mando (para poder quedarse sin red). */
async function ready(page) {
  await expect.poll(async () => (await idb(page, 'catalogs')).some((c) => c.id === CATALOG_ID && c.downloadedAt), { timeout: 30000 }).toBe(true);
  await page.evaluate(() => navigator.serviceWorker.ready);
  if (!(await page.evaluate(() => !!navigator.serviceWorker.controller))) await page.reload();
  await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller), { timeout: 15000 }).toBe(true);
}

/** «Marcar aquí» → la especie (debe salir en «Objetivo de la visita») → n toques en «En flor» → guardar. */
async function count(page, taxonID, n) {
  await page.locator('.fab-stack [data-mark]').click();
  const sheet = page.locator('.mark-sheet');
  await expect(sheet).toBeVisible();
  await expect(sheet.locator('.mark-visit')).toContainText('Parcela de la prueba');
  await expect(sheet.locator('.pk-tier').first()).toHaveText(/Objetivo de la visita/i);
  await sheet.locator(`[data-pick="${taxonID}"]`).click();
  for (let i = 0; i < n; i++) await sheet.locator('[data-tally="flowering"]').click();
  await expect(sheet.locator('.mark-gps')).toContainText('±8 m');
  await sheet.locator('.save-mark').click();
  await expect(sheet).toBeHidden();
}

test('visita completa sin red: empezar, contar dos especies, cerrar con una ausente y sincronizar', async ({ page, context }, testInfo) => {
  test.skip(testInfo.project.name !== 'movil', 'la visita es del modo campo (móvil)');
  const api = await start(page, context, '#/mapa');
  await ready(page);
  await context.setOffline(true);

  // Empezar: sitio nuevo aquí (punto + radio), censo de especies objetivo con tres especies.
  await page.locator('.fab-stack [data-visit]').click();
  const start_ = page.locator('.visit-sheet');
  await expect(start_).toBeVisible();
  await expect(start_.locator('.visit-gps')).toContainText('±8 m');
  await start_.locator('[data-site="new"]').click();
  await start_.locator('input[name="siteName"]').fill('Parcela de la prueba');
  await start_.locator('input[name="radius"]').fill('40');
  await expect(start_.getByRole('radio', { name: 'Censo completo de especies objetivo' })).toHaveAttribute('aria-checked', 'true');
  for (const t of [PINGUICULA, VIOLA, SEMPERVIVUM]) await start_.locator(`[data-target="${t}"]`).check();
  await start_.getByRole('button', { name: 'Empezar visita' }).click();
  await expect(start_).toBeHidden();

  const bar = page.locator('#visitBar .visit-bar');
  await expect(bar).toContainText('Parcela de la prueba');
  await expect(bar).toContainText('0 de 3 objetivo contadas');
  await expect(page.locator('.fab-stack [data-visit]')).toBeHidden();

  // Contar dos de las tres.
  await count(page, PINGUICULA, 3);
  await count(page, VIOLA, 2);
  await expect(bar).toContainText('2 de 3 objetivo contadas');
  await expect(bar).toContainText('1 pendiente');

  // Cerrar: la tercera se propone como ausente.
  await bar.getByRole('button', { name: 'Cerrar visita' }).click();
  const close = page.locator('.visit-sheet');
  await expect(close).toContainText('Contado en la visita');
  await expect(close.locator(`[data-absent="${SEMPERVIVUM}"]`)).toBeChecked();
  await close.getByRole('button', { name: 'Cerrar visita (1 ausencia)' }).click();
  await expect(close).toBeHidden();
  await expect(page.locator('#visitBar')).toBeHidden();

  // Sin red: todo en el dispositivo y en la cola; nada en el «servidor».
  const visits = await idb(page, 'visits');
  expect(visits).toHaveLength(1);
  const visit = visits[0];
  expect(visit).toMatchObject({ visitStatus: 'closed', protocol: 'target_census', status: 'pending' });
  expect(visit.species.sort()).toEqual([PINGUICULA, SEMPERVIVUM, VIOLA].sort());
  expect(visit.eventEnd).toBeTruthy();
  expect(Number.isInteger(visit.effortMinutes)).toBe(true);
  const sites = await idb(page, 'sites');
  expect(sites).toHaveLength(1);
  expect(sites[0]).toMatchObject({ siteID: visit.siteID, name: 'Parcela de la prueba', siteType: 'point', radiusM: 40 });
  const marks = (await idb(page, 'occurrences')).filter((o) => o.visitID === visit.visitID);
  expect(marks).toHaveLength(3);
  expect(marks.find((o) => o.taxonID === PINGUICULA)).toMatchObject({ occurrenceStatus: 'present', individualCount: 3, mature_count: 3 });
  expect(marks.find((o) => o.taxonID === VIOLA)).toMatchObject({ occurrenceStatus: 'present', individualCount: 2 });
  expect(marks.find((o) => o.taxonID === SEMPERVIVUM)).toMatchObject({ occurrenceStatus: 'absent', individualCount: 0, coordinateUncertaintyInMeters: 40 });
  const outbox = await idb(page, 'outbox');
  expect(outbox.filter((e) => e.kind === 'site')).toHaveLength(1);
  expect(outbox.filter((e) => e.kind === 'visit')).toHaveLength(1);
  expect(outbox.filter((e) => e.kind === 'occurrence' && marks.some((m) => m.occurrenceID === e.ref))).toHaveLength(3);
  expect(api.sites).toHaveLength(0);
  expect(api.visits).toHaveLength(0);

  // Con red: se sube (sitio y visita primero) y vuelve por el pull.
  await context.setOffline(false);
  await page.evaluate(() => window.dispatchEvent(new Event('online')));
  await expect.poll(() => api.pushed.filter((o) => o.visitID === visit.visitID).length, { timeout: 20000 }).toBe(3);
  expect(api.sites).toHaveLength(1);
  expect(api.visits).toHaveLength(1);
  expect(api.visits[0]).toMatchObject({ visitID: visit.visitID, siteID: visit.siteID, status: 'closed', protocol: 'target_census' });
  expect(api.sites[0]).toMatchObject({ siteID: visit.siteID, radiusM: 40, siteType: 'point' });
  const firstWithMarks = api.batches.findIndex((b) => b.occurrences.some((id) => marks.some((m) => m.occurrenceID === id)));
  expect(api.batches.findIndex((b) => b.visits.includes(visit.visitID))).toBeLessThanOrEqual(firstWithMarks);
  expect(api.batches.findIndex((b) => b.sites.includes(visit.siteID))).toBeLessThanOrEqual(firstWithMarks);
  await expect.poll(async () => (await idb(page, 'visits')).find((v) => v.visitID === visit.visitID)?.status, { timeout: 20000 }).toBe('synced');
  await expect.poll(async () => (await idb(page, 'visits')).find((v) => v.visitID === visit.visitID)?.visitStatus).toBe('closed');
  await expect.poll(async () => (await idb(page, 'occurrences')).filter((o) => o.visitID === visit.visitID && o.status === 'synced').length).toBe(3);
  expect((await idb(page, 'outbox')).filter((e) => e.kind === 'site' || e.kind === 'visit')).toHaveLength(0);
});
