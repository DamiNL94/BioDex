// ================================================================================================
// f11j.spec.mjs — MODO BÚSQUEDA y esfuerzo de una visita en el móvil, SIN RED.
//   1. Empezar una visita con tiempo fijo en modo búsqueda y comprobar que NINGUNA marca anterior de la especie
//      objetivo aparece por ningún camino: mapa (recuento, filtro de especies, lista), «cerca de aquí» del buscador
//      de la hoja de marca, ficha de la especie (marcas, minimapa, distribución, evaluación) y exportación.
//   2. Una marca hecha durante la visita sí se ve.
//   3. El modo búsqueda sobrevive a una recarga sin red (vive en IndexedDB).
//   4. Salir del modo búsqueda (con confirmación): la visita queda como no ciega sin vuelta atrás y las marcas vuelven.
//   5. Cerrar y sincronizar: la visita sube con su protocolo, blind = false y su recorrido.
// ================================================================================================
import { test, expect } from '@playwright/test';
import { mockApi, FX, CATALOG_ID } from './mock-api.mjs';

const ME = FX.me.user;
const PINGUICULA = '6989';   // 5 marcas en la demo, junto a la posición simulada
const TOTAL = FX.pull.items.length;
const PREVIOUS = FX.pull.items.filter((o) => o.taxonID === PINGUICULA).length;

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

async function ready(page) {
  await expect.poll(async () => (await idb(page, 'catalogs')).some((c) => c.id === CATALOG_ID && c.downloadedAt), { timeout: 30000 }).toBe(true);
  await expect.poll(async () => (await idb(page, 'occurrences')).length, { timeout: 30000 }).toBe(TOTAL);
  await page.evaluate(() => navigator.serviceWorker.ready);
  if (!(await page.evaluate(() => !!navigator.serviceWorker.controller))) await page.reload();
  await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller), { timeout: 15000 }).toBe(true);
}

async function goTab(page, route) {
  await page.locator(`.tabs .tab[data-route="${route}"]`).click();
  await expect(page.locator('body')).toHaveAttribute('data-route', route);
}

const mapCount = (page) => page.locator('#mapNote .map-count');
const speciesOptions = (page) => page.locator('#mapFilters select[name="taxonID"] option').evaluateAll((os) => os.map((o) => o.value));

test('modo búsqueda sin red: ninguna marca previa por ningún camino; salir lo deja como no ciega', async ({ page, context }, testInfo) => {
  test.skip(testInfo.project.name !== 'movil', 'la visita es del modo campo (móvil)');
  const api = await start(page, context, '#/mapa');
  await ready(page);
  await expect(mapCount(page)).toContainText(`${TOTAL} marcas en el mapa`);
  expect(await speciesOptions(page)).toContain(PINGUICULA);
  await context.setOffline(true);

  // --- Empezar: sin sitio, búsqueda por tiempo → tiempo fijo de 30 min y modo búsqueda por defecto ----------------
  await page.locator('.fab-stack [data-visit]').click();
  const sheet = page.locator('.visit-sheet');
  await expect(sheet.locator('.visit-gps')).toContainText('±8 m');
  await sheet.locator('[data-site="none"]').click();
  await expect(sheet.getByRole('radio', { name: 'Búsqueda por tiempo' })).toHaveAttribute('aria-checked', 'true');
  await expect(sheet.getByRole('radio', { name: 'Tiempo fijo' })).toHaveAttribute('aria-checked', 'true');
  await expect(sheet.locator('input[name="effortTarget"]')).toHaveValue('30');
  await expect(sheet.locator('input[name="blind"]')).toBeChecked();
  // «Libre» no admite modo búsqueda; al volver a tiempo fijo se marca otra vez.
  await sheet.getByRole('radio', { name: 'Libre' }).click();
  await expect(sheet.locator('input[name="blind"]')).toBeDisabled();
  await expect(sheet.locator('input[name="blind"]')).not.toBeChecked();
  await sheet.getByRole('radio', { name: 'Tiempo fijo' }).click();
  await expect(sheet.locator('input[name="blind"]')).toBeChecked();
  await expect(sheet.getByRole('radio', { name: 'Exploratoria' })).toHaveAttribute('aria-checked', 'true');
  await sheet.locator(`[data-target="${PINGUICULA}"]`).check();
  await sheet.getByRole('button', { name: 'Empezar visita' }).click();
  await expect(sheet).toBeHidden();

  const bar = page.locator('#visitBar .visit-bar');
  await expect(bar).toContainText('Modo búsqueda');
  await expect(bar).toContainText('0 de 30 min');
  await expect(bar.locator('.visit-progress')).toBeVisible();
  await expect(bar).toContainText('Grabando el recorrido');
  let visit = (await idb(page, 'visits'))[0];
  expect(visit).toMatchObject({ visitStatus: 'open', effortProtocol: 'timed', effortTarget: 30, blind: true, visitKind: 'exploratory' });

  // --- Mapa: fuera las marcas anteriores de la especie objetivo (recuento, filtro y aviso) -----------------------
  await expect(mapCount(page)).toContainText(`${TOTAL - PREVIOUS} marcas en el mapa`);
  await expect(page.locator('#mapNote .map-blind')).toContainText('Modo búsqueda');
  expect(await speciesOptions(page)).not.toContain(PINGUICULA);

  // --- Hoja de marca: «cerca de aquí» no delata la Pinguicula, que está a pocos metros ----------------------------
  await page.locator('.fab-stack [data-mark]').click();
  const mark = page.locator('.mark-sheet');
  await expect(mark.locator('.pk-tier').first()).toHaveText(/Objetivo de la visita/i);
  await expect(mark.locator('.pk-tier').filter({ hasText: /Cerca de aquí/i })).toHaveCount(0);
  await mark.locator(`[data-pick="${PINGUICULA}"]`).click();
  await mark.locator('[data-tally="flowering"]').click();
  await expect(mark.locator('.mark-gps')).toContainText('±8 m');
  await mark.locator('.save-mark').click();
  await expect(mark).toBeHidden();
  // La marca de la propia visita sí se ve.
  await expect(mapCount(page)).toContainText(`${TOTAL - PREVIOUS + 1} marcas en el mapa`);
  expect(await speciesOptions(page)).toContain(PINGUICULA);

  // --- Ficha de la especie: solo la marca de esta visita; distribución y evaluación, ocultas ----------------------
  await goTab(page, 'especies');
  await page.locator(`[data-pick="${PINGUICULA}"], #speciesList [data-taxon="${PINGUICULA}"]`).first().click();
  await page.getByRole('tab', { name: 'Registros' }).click();
  await expect(page.locator('.marks-box .mark')).toHaveCount(1);
  await expect(page.locator('.minimap-box .minimap-note')).toContainText('1 marca en el mapa');
  await expect(page.locator('.dist-box')).toContainText('Modo búsqueda');
  await expect(page.locator('.dist-box .dist-counts')).toHaveCount(0);
  await page.getByRole('tab', { name: 'Evaluación UICN' }).click();
  await expect(page.locator('.assess-box')).toContainText('Modo búsqueda');
  await expect(page.locator('.assess-box .iucn-head')).toHaveCount(0);
  await page.keyboard.press('Escape');

  // --- Recarga sin red: el modo búsqueda sigue (IndexedDB) -------------------------------------------------------
  await page.evaluate(() => { const v = JSON.parse(localStorage.getItem('biodex.activeVisit')); delete v.blind; localStorage.setItem('biodex.activeVisit', JSON.stringify(v)); });
  await page.goto('/#/mapa');
  await expect(page.locator('#visitBar .visit-bar')).toContainText('Modo búsqueda');
  await expect(mapCount(page)).toContainText(`${TOTAL - PREVIOUS + 1} marcas en el mapa`);
  expect(await speciesOptions(page)).toContain(PINGUICULA);

  // --- Salir del modo búsqueda: confirmación, irreversible, y las marcas vuelven -------------------------------
  await page.locator('#visitBar [data-leave-blind]').click();
  const dialog = page.getByRole('dialog').filter({ hasText: 'No se puede deshacer' });
  await expect(dialog).toContainText('búsqueda no ciega');
  await dialog.getByRole('button', { name: 'Cancelar' }).click();
  await expect(page.locator('#visitBar .visit-bar')).toContainText('Modo búsqueda');
  await page.locator('#visitBar [data-leave-blind]').click();
  await page.getByRole('dialog').getByRole('button', { name: 'Salir y ver las marcas' }).click();
  await expect(page.locator('#visitBar .visit-bar')).toContainText('Búsqueda no ciega');
  await expect(page.locator('#visitBar [data-leave-blind]')).toHaveCount(0);
  await expect(mapCount(page)).toContainText(`${TOTAL + 1} marcas en el mapa`);
  await expect(page.locator('#mapNote .map-blind')).toHaveCount(0);
  visit = (await idb(page, 'visits'))[0];
  expect(visit.blind).toBe(false);
  expect(visit.blindEndedAt).toBeTruthy();

  // --- Pausa y cierre: el resumen dice por qué no cuenta ----------------------------------------------------------
  await page.locator('#visitBar [data-pause-visit]').click();
  await expect(page.locator('#visitBar .visit-bar')).toContainText('En pausa');
  await page.locator('#visitBar [data-pause-visit]').click();
  await expect(page.locator('#visitBar .visit-bar')).toContainText('Grabando el recorrido');
  await page.locator('#visitBar [data-close-visit]').click();
  const close = page.locator('.visit-sheet');
  await expect(close.locator('.visit-effort-summary')).toContainText('Tiempo fijo');
  await expect(close.locator('.visit-occasion')).toContainText('No contará para la completitud');
  await expect(close.locator('.visit-occasion')).toContainText('no se hizo entera en modo búsqueda');
  await expect(close.locator('.visit-occasion')).toContainText('no alcanza el esfuerzo objetivo');
  await close.locator('.visit-close').click();
  await expect(close).toBeHidden();
  await expect(page.locator('#visitBar')).toBeHidden();
  visit = (await idb(page, 'visits'))[0];
  expect(visit).toMatchObject({ visitStatus: 'closed', blind: false, effortProtocol: 'timed', targetMet: false, status: 'pending' });
  expect(Array.isArray(visit.track)).toBe(true);
  expect(visit.track.length).toBeGreaterThanOrEqual(1);
  expect(visit.pauses).toHaveLength(1);
  expect(visit.coveredCells).toHaveLength(1);
  expect(api.visits).toHaveLength(0);

  // --- Con red: sube con su protocolo, no ciega y con el recorrido ------------------------------------------------
  await context.setOffline(false);
  await page.evaluate(() => window.dispatchEvent(new Event('online')));
  await expect.poll(() => api.visits.length, { timeout: 20000 }).toBe(1);
  await expect.poll(() => api.visits[0].status, { timeout: 20000 }).toBe('closed');
  expect(api.visits[0]).toMatchObject({ visitID: visit.visitID, effortProtocol: 'timed', effortTarget: 30, blind: false, visitKind: 'exploratory' });
  expect(api.visits[0].track.length).toBeGreaterThanOrEqual(1);
  expect(api.visits[0].track[0]).toHaveLength(5);
  // Lo derivado del recorrido lo calcula el servidor: el cliente no lo manda.
  expect(api.visits[0]).not.toHaveProperty('distanceM');
  expect(api.visits[0]).not.toHaveProperty('coveredCells');
});

test('modo búsqueda sin especies objetivo (transecto): se ocultan todas las marcas anteriores del catálogo', async ({ page, context }, testInfo) => {
  test.skip(testInfo.project.name !== 'movil', 'la visita es del modo campo (móvil)');
  await start(page, context, '#/mapa');
  await ready(page);
  await page.locator('.fab-stack [data-visit]').click();
  const sheet = page.locator('.visit-sheet');
  await sheet.locator('[data-site="none"]').click();
  await sheet.getByRole('radio', { name: 'Transecto', exact: true }).click();
  await expect(sheet.locator('input[name="effortTarget"]')).toHaveValue('1000');
  await sheet.getByRole('button', { name: 'Empezar visita' }).click();
  await expect(sheet).toBeHidden();
  // Sin especies objetivo se busca todo: se ocultan todas las marcas anteriores del catálogo.
  await expect(page.locator('#visitBar .visit-bar')).toContainText('0 de 1000 m');
  await expect(mapCount(page)).toContainText('0 marcas en el mapa');
  // Las barras de la visita no usan style="" (CSP estricta): el ancho va en clases.
  expect(await page.locator('#visitBar [style]').count()).toBe(0);
});
