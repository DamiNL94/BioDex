// ================================================================================================
// f11f.spec.mjs — interfaz cómoda y página de especie, en móvil (campo) y escritorio (estudio):
//   · marcar contando desde el buscador          · cambiar de pestaña y volver con el estado intacto
//   · «atrás» cierra primero la hoja             · editar un parámetro y verlo tras recargar sin red
//   · auditoría de objetivos táctiles en campo: ningún elemento interactivo de menos de 48 px
// ================================================================================================
import { test, expect } from '@playwright/test';
import { mockApi, FX, CATALOG_ID } from './mock-api.mjs';

const ME = FX.me.user;
const PINGUICULA = '6989';
const isField = (testInfo) => testInfo.project.name === 'movil';

/** Sesión iniciada con el catálogo DEMO activo (lo que deja la app tras entrar y elegirlo). */
async function start(page, context, hash = '') {
  const api = await mockApi(context);
  await context.addInitScript(([user, cat]) => {
    if (sessionStorage.getItem('e2e-init')) return;   // solo la primera carga: recargar conserva lo de la app
    sessionStorage.setItem('e2e-init', '1');
    localStorage.setItem('biodex.user', JSON.stringify(user));
    localStorage.setItem('biodex.activeCatalog', JSON.stringify(cat));
  }, [ME, { id: CATALOG_ID, name: FX.catalog.catalog.name, role: FX.catalog.catalog.role }]);
  await page.goto('/' + hash);
  await expect(page.locator('.shell')).toBeVisible();
  return api;
}

/** Lee un almacén de la base del usuario (IndexedDB). */
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

async function waitDownloaded(page) {
  await expect.poll(async () => (await idb(page, 'catalogs')).some((c) => c.id === CATALOG_ID && c.downloadedAt),
    { timeout: 30000 }).toBe(true);
}

async function goTab(page, label) {
  await page.locator(`.tabs .tab[data-route="${label}"]`).click();
  await expect(page.locator('body')).toHaveAttribute('data-route', label);
}

/** Abre la hoja de marcar desde el botón flotante (campo: Especies; estudio: Mapa). */
async function openMarkSheet(page, testInfo) {
  if (isField(testInfo)) {
    await goTab(page, 'especies');
    await page.locator('.fab-stack [data-mark]').click();
  } else {
    await goTab(page, 'mapa');
    await page.locator('.fab-stack [data-mark]').click();
  }
  await expect(page.locator('.mark-sheet')).toBeVisible();
}

test('marcar contando desde el buscador', async ({ page, context }, testInfo) => {
  const api = await start(page, context, '#/especies');
  await openMarkSheet(page, testInfo);
  const sheet = page.locator('.mark-sheet');
  await expect(sheet.locator('.mark-gps')).toContainText('±8 m');
  await sheet.getByRole('searchbox').fill('pinnev');
  await expect(sheet.locator('.pk-row')).toHaveCount(1);
  await sheet.locator('.pk-row').click();
  await expect(sheet.locator('.mark-chosen')).toContainText('Pinguicula nevadensis');
  const flower = sheet.locator('[data-tally="flowering"]');
  await flower.click(); await flower.click(); await flower.click();
  await sheet.locator('[data-tally="vegetative"]').click();
  await sheet.locator('[data-tally-undo]').click();
  await expect(sheet.locator('.tally-sum')).toHaveText('3');
  await sheet.getByRole('button', { name: 'Guardar 3 individuos' }).click();
  await expect(sheet).toBeHidden();
  const marks = (await idb(page, 'occurrences')).filter((o) => o.taxonID === PINGUICULA && o.authorId === ME.id && o.individualCount === 3);
  expect(marks).toHaveLength(1);
  expect(marks[0]).toMatchObject({ lifeStage: 'flowering', mature_count: 3, occurrenceStatus: 'present' });
  await expect.poll(() => api.pushed.filter((o) => o.taxonID === PINGUICULA && o.individualCount === 3).length).toBe(1);
});

test('cambiar de pestaña y volver con el estado intacto', async ({ page, context }, testInfo) => {
  await start(page, context, '#/especies');
  const box = isField(testInfo) ? page.locator('input[name="pkq"]') : page.locator('.list-filters input[name="text"]');
  await box.fill('narciso');
  const rows = isField(testInfo) ? page.locator('.pk-row') : page.locator('#speciesList .sp');
  await expect(rows).toHaveCount(1);
  if (isField(testInfo)) {
    await goTab(page, 'mapa');
    const view = await page.evaluate(() => JSON.parse(localStorage.getItem('biodex.mapView') || 'null'));
    expect(view).not.toBeNull();
  } else {
    await goTab(page, 'catalogos');
  }
  await goTab(page, 'especies');
  await expect(box).toHaveValue('narciso');
  await expect(rows).toHaveCount(1);
  // Desplazamiento: Ajustes es larga; al volver sigue donde estaba.
  await goTab(page, 'ajustes');
  await page.evaluate(() => window.scrollTo(0, 300));
  const before = await page.evaluate(() => window.scrollY);
  await goTab(page, 'especies');
  await goTab(page, 'ajustes');
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(before);
});

test('«atrás» cierra primero la hoja y luego vuelve a la sección de inicio', async ({ page, context }, testInfo) => {
  await start(page, context, '#/especies');
  await openMarkSheet(page, testInfo);
  const route = await page.locator('body').getAttribute('data-route');
  await page.goBack();
  await expect(page.locator('.mark-sheet')).toHaveCount(0);
  await expect(page.locator('body')).toHaveAttribute('data-route', route);
  const home = isField(testInfo) ? 'mapa' : 'catalogos';
  if (route !== home) {
    await page.goBack();
    await expect(page.locator('body')).toHaveAttribute('data-route', home);
  }
  // En la sección de inicio, «atrás» no saca de la app: pide confirmación.
  await page.goBack();
  await expect(page.locator('#navExitToast')).toContainText('otra vez para salir');
  await expect(page.locator('.shell')).toBeVisible();
});

test('editar un parámetro y verlo tras recargar sin red', async ({ page, context }) => {
  const api = await start(page, context, '#/especies');
  await waitDownloaded(page);
  await page.evaluate(() => navigator.serviceWorker.ready);
  await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller), { timeout: 15000 })
    .toBe(true).catch(async () => { await page.reload(); });   // primera visita: el SW toma el control al recargar

  async function openParams() {
    await goTab(page, 'especies');
    const open = page.locator('[data-pick="6989"], #speciesList [data-taxon="6989"]').first();
    await open.click();
    await page.getByRole('tab', { name: 'Parámetros' }).click();
    return page.locator('.param[data-key="generation_length"]');
  }
  let item = await openParams();
  await expect(item).toContainText('Sin rellenar');
  await item.getByRole('button', { name: /Rellenar/ }).click();
  const form = item.locator('form');
  await form.getByLabel(/^Valor/).fill('12');
  await form.getByLabel('Mínimo').fill('8');
  await form.getByLabel('Máximo').fill('20');
  await form.getByLabel('Calidad del dato').selectOption('estimated');
  await form.getByLabel(/Fuente/).fill('Prueba de Playwright');
  await form.getByRole('button', { name: 'Guardar' }).click();
  await expect(item).toContainText('12 años (8–20)');
  expect(api.params.get('6989|generation_length')).toMatchObject({ value: 12, valueMin: 8, valueMax: 20, quality: 'estimated' });
  await expect.poll(async () => (await idb(page, 'parameters')).some((p) => p.key === 'generation_length' && p.value === 12)).toBe(true);
  await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller), { timeout: 15000 }).toBe(true);

  await context.setOffline(true);
  await page.reload();
  await expect(page.locator('.shell')).toBeVisible();
  item = await openParams();
  await expect(item).toContainText('12 años (8–20)');
  await expect(item).toContainText('Estimado');
  await expect(page.locator('.params-box .copy-note')).toContainText('Copia del dispositivo');
  await expect(item.getByRole('button', { name: /Cambiar/ })).toHaveCount(0);   // sin red no se edita
  await context.setOffline(false);
});

test('auditoría de objetivos táctiles (modo campo)', async ({ page, context }, testInfo) => {
  test.skip(!isField(testInfo), 'solo en modo campo');
  await start(page, context, '#/mapa');
  await waitDownloaded(page);

  /** Elementos interactivos visibles de menos de 48 × 48 px (salvo enlaces dentro de un texto: WCAG 2.5.8). */
  const audit = (where) => page.evaluate((label) => {
    const MIN = 48;
    const SEL = 'a[href],button,input:not([type="hidden"]),select,textarea,summary,[role="button"],[role="tab"],[role="radio"],[tabindex]:not([tabindex="-1"])';
    const out = [];
    for (const el of document.querySelectorAll(SEL)) {
      if (el.closest('[hidden],[inert]') || el.disabled && el.matches('.sr-only *')) continue;
      const cs = getComputedStyle(el);
      if (cs.visibility === 'hidden' || cs.display === 'none' || el.closest('.sr-only')) continue;
      // Un control dentro de su <label> (casilla, radio, fichero): el objetivo es la etiqueta entera.
      const target = (el.matches('input[type="checkbox"],input[type="radio"],input[type="file"]') && el.closest('label')) || el;
      const r = target.getBoundingClientRect();
      if (!r.width || !r.height) continue;
      // Excepción «en línea» de WCAG 2.5.8: enlace dentro de una frase (atribuciones del mapa, créditos).
      if (el.tagName === 'A' && cs.display === 'inline' && el.parentElement && el.parentElement.textContent.trim() !== el.textContent.trim()) continue;
      if (r.width < MIN - 0.5 || r.height < MIN - 0.5) {
        out.push(`${label}: <${el.tagName.toLowerCase()} class="${el.className}"> «${(el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 40)}» ${Math.round(r.width)}×${Math.round(r.height)}`);
      }
    }
    return out;
  }, where);

  const found = [];
  await page.reload();
  await expect(page.locator('.fab-stack .impact-fab')).toBeVisible();
  found.push(...await audit('Mapa'));
  await page.locator('[data-filters]').click();
  found.push(...await audit('Mapa › Filtros'));
  await page.goBack();
  await goTab(page, 'especies');
  await expect(page.locator('.pk-row').first()).toBeVisible();
  found.push(...await audit('Especies'));
  await page.locator('.fab-stack [data-mark]').click();
  await expect(page.locator('.mark-sheet .pk-row').first()).toBeVisible();
  found.push(...await audit('Hoja de marcar (especie)'));
  await page.locator('.mark-sheet .pk-row').first().click();
  await expect(page.locator('.mark-sheet .tally-cell').first()).toBeVisible();
  found.push(...await audit('Hoja de marcar (conteo)'));
  await page.goBack();
  await page.locator('.pk-row').first().click();
  for (const tab of ['Resumen', 'Registros', 'Parámetros', 'Evaluación UICN']) {
    await page.getByRole('tab', { name: tab }).click();
    await page.waitForTimeout(400);
    found.push(...await audit(`Ficha › ${tab}`));
  }
  await page.goBack();
  await goTab(page, 'catalogos');
  found.push(...await audit('Catálogo'));
  await page.locator('[data-switch]').first().click();
  found.push(...await audit('Selector de catálogo'));
  await page.goBack();
  await goTab(page, 'ajustes');
  found.push(...await audit('Ajustes'));
  expect(found, found.join('\n')).toEqual([]);
});
