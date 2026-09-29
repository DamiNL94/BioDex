// ================================================================================================
// Vista Catálogos: lista de GET /api/catalogs, alta de uno nuevo y elección del catálogo activo.
// En escritorio (≥ 900 px) se añade a la derecha el resumen del catálogo activo: allí es donde se
// preparan los catálogos y sobra ancho para verlo junto a la lista.
// ================================================================================================

import { apiGetJson, apiPostJson } from '../api.js';
import { state, subscribe, setActiveCatalog } from '../state.js';
import { html, render, $, plural } from '../util.js';
import { iucnEntries } from './species.js';

export const ROLE_LABELS = { owner: 'Propietario', custodian: 'Custodio', editor: 'Editor', viewer: 'Lector' };
const desktop = window.matchMedia('(min-width: 900px)');

export function mount(el) {
  let catalogs = null;
  let loadError = null;
  let alive = true;

  render(el, html`
    <div class="page-head">
      <h2>Catálogos</h2>
      <button type="button" class="btn-secondary" id="newCatalogBtn">Nuevo catálogo</button>
    </div>
    <form class="panel new-catalog" id="newCatalogForm" hidden novalidate>
      <h3>Nuevo catálogo</h3>
      <label class="field"><span>Nombre</span><input name="name" required maxlength="120"></label>
      <label class="field"><span>Descripción <em>(opcional)</em></span>
        <textarea name="description" rows="3"></textarea></label>
      <p class="form-error" role="alert" hidden></p>
      <div class="form-actions">
        <button type="submit" class="btn-primary">Crear catálogo</button>
        <button type="button" class="link-btn" id="cancelNew">Cancelar</button>
      </div>
    </form>
    <div class="split">
      <ul class="rows" id="catalogList" aria-live="polite"></ul>
      <aside class="aside" id="catalogAside"></aside>
    </div>`);

  const form = $('#newCatalogForm', el);
  const list = $('#catalogList', el);
  const aside = $('#catalogAside', el);

  // --- Lista ------------------------------------------------------------------------------------
  function paintList() {
    if (loadError) {
      render(list, html`<li class="empty"><p>${loadError}</p>
        <button type="button" class="btn-secondary" id="retry">Reintentar</button></li>`);
      $('#retry', list).addEventListener('click', load);
      return;
    }
    if (!catalogs) { render(list, html`<li class="empty"><p class="muted">Cargando catálogos…</p></li>`); return; }
    if (!catalogs.length) {
      render(list, html`<li class="empty">
        <h3>Aún no tienes catálogos</h3>
        <p>Un catálogo es la lista de especies con la que trabaja un proyecto o un equipo: de él salen
          las especies que podrás anotar en el campo, su nivel de sensibilidad y las evaluaciones UICN.
          Crea uno o pide a su responsable que te invite.</p></li>`);
      return;
    }
    const activeId = state.activeCatalog && state.activeCatalog.id;
    render(list, html`${catalogs.map((c) => html`
      <li>
        <button type="button" class="row role-${c.role} ${c.id === activeId ? 'is-active' : ''}" data-id="${c.id}"
                aria-pressed="${c.id === activeId}">
          <span class="row-title">${c.name}</span>
          ${c.description ? html`<span class="row-text">${c.description}</span>` : ''}
          <span class="row-meta">
            <span class="badge">${ROLE_LABELS[c.role] || c.role}</span>
            <span class="badge">${plural(c.speciesCount || 0, 'especie', 'especies')}</span>
            ${c.id === activeId ? html`<span class="badge ok">Activo</span>` : ''}
          </span>
        </button>
      </li>`)}`);
    list.querySelectorAll('.row').forEach((row) => row.addEventListener('click', () => {
      setActiveCatalog(catalogs.find((c) => c.id === row.dataset.id));
    }));
  }

  async function load() {
    loadError = null;
    paintList();
    try {
      const data = await apiGetJson('/catalogs');
      if (!alive) return;
      catalogs = data.catalogs;
      syncActive();
    } catch (e) {
      if (!alive) return;
      // TODO F5: sin red, leer los catálogos guardados en IndexedDB en lugar de fallar.
      loadError = e.status === 0 ? 'Sin conexión: la lista de catálogos necesita red por ahora.' : e.message;
      catalogs = null;
    }
    paintList();
    paintAside();
  }

  // El catálogo activo guardado puede haber cambiado de nombre o de rol, o ya no ser accesible.
  function syncActive() {
    const active = state.activeCatalog;
    if (!active) return;
    const fresh = catalogs.find((c) => c.id === active.id);
    if (!fresh) setActiveCatalog(null);
    else if (fresh.name !== active.name || fresh.role !== active.role) setActiveCatalog(fresh);
  }

  // --- Resumen del activo (solo escritorio) -----------------------------------------------------
  let asideFor = null;
  async function paintAside() {
    const active = state.activeCatalog;
    if (!desktop.matches || !active) {
      asideFor = null;
      render(aside, active ? html`` : html`<div class="panel muted">Elige un catálogo de la lista
        para trabajar con él: será el que se vea en Especies y, más adelante, en el mapa.</div>`);
      return;
    }
    if (asideFor === active.id) return;
    asideFor = active.id;
    render(aside, html`<div class="panel muted">Cargando resumen…</div>`);
    try {
      const { catalog, summary } = await apiGetJson('/catalogs/' + encodeURIComponent(active.id));
      if (!alive || asideFor !== active.id) return;
      const groups = Object.entries(summary.byTaxonomicGroup);
      const cats = iucnEntries(summary.byIucnCategory);
      render(aside, html`<div class="panel summary">
        <p class="eyebrow">${ROLE_LABELS[catalog.role] || catalog.role}</p>
        <h3>${catalog.name}</h3>
        ${catalog.description ? html`<p>${catalog.description}</p>` : ''}
        <p class="stat"><strong>${summary.total}</strong> ${summary.total === 1 ? 'especie' : 'especies'}</p>
        ${cats.length ? html`<h4>Por categoría UICN</h4><dl class="counts">${cats.map(([k, n]) => html`
          <div><dt class="iucn iucn-${k}">${k === 'none' ? 'Sin evaluar' : k}</dt><dd>${n}</dd></div>`)}</dl>` : ''}
        ${groups.length ? html`<h4>Por grupo</h4><dl class="counts">${groups.map(([k, n]) => html`
          <div><dt>${k}</dt><dd>${n}</dd></div>`)}</dl>` : ''}
      </div>`);
    } catch (e) {
      if (alive && asideFor === active.id) render(aside, html`<div class="panel muted">${e.message}</div>`);
    }
  }

  // --- Alta ---------------------------------------------------------------------------------------
  const errorEl = $('.form-error', form);
  function closeForm() { form.hidden = true; form.reset(); errorEl.hidden = true; }
  $('#newCatalogBtn', el).addEventListener('click', () => {
    form.hidden = false;
    window.BioDexNav.open('nuevo-catalogo', closeForm);
    form.name.focus();
  });
  $('#cancelNew', el).addEventListener('click', () => window.BioDexNav.close('nuevo-catalogo'));
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const name = form.name.value.trim();
    const description = form.description.value.trim();
    const fail = (t) => { errorEl.textContent = t; errorEl.hidden = false; };
    if (!name) return fail('El catálogo necesita un nombre.');
    const button = $('button[type="submit"]', form);
    button.disabled = true;
    try {
      const { catalog } = await apiPostJson('/catalogs', { name, description: description || null });
      window.BioDexNav.close('nuevo-catalogo');
      if (!state.activeCatalog) setActiveCatalog(catalog);
      await load();
    } catch (e) {
      fail(e.message);
    } finally {
      button.disabled = false;
    }
  });

  const unsubscribe = subscribe((key) => {
    if (key === 'activeCatalog') { paintList(); paintAside(); }
    if (key === 'online' && state.online && loadError) load();
  });
  const onResize = () => paintAside();
  desktop.addEventListener('change', onResize);

  load();
  return () => {
    alive = false;
    unsubscribe();
    desktop.removeEventListener('change', onResize);
    window.BioDexNav.close('nuevo-catalogo');
  };
}
