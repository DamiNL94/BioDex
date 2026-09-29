// ================================================================================================
// Vista Catálogos: lista de GET /api/catalogs, elección del catálogo activo y «Datos del catálogo».
// «Nuevo catálogo» abre el constructor (catalog-builder.js). En escritorio (≥ 900 px) los datos del
// activo van a la derecha; en el móvil, debajo de la lista.
// Datos del catálogo: resumen, árbol taxonómico plegable (GET /{id}/tree) con recuentos —tocar una
// familia filtra la pestaña Especies—, «Añadir especies» (editor o más) y, para el owner, renombrar,
// editar la descripción y borrar (diálogo propio con el nombre escrito para confirmar).
// ================================================================================================

import { apiGetJson, apiPatchJson, apiDeleteJson } from '../api.js';
import { state, subscribe, setActiveCatalog } from '../state.js';
import { html, render, $, plural } from '../util.js';
import { confirmDialog } from '../components/overlay.js';
import { iucnEntries, filterSpeciesByFamily } from './species.js';
import { openCatalogBuilder, openAddSpecies } from './catalog-builder.js';

export const ROLE_LABELS = { owner: 'Propietario', custodian: 'Custodio', editor: 'Editor', viewer: 'Lector' };
const ADD_ROLES = ['owner', 'custodian', 'editor'];
const RANK_LABELS = { kingdom: 'Reino', phylum: 'Filo', class: 'Clase', order: 'Orden', family: 'Familia' };

export function mount(el, { go }) {
  let catalogs = null;
  let loadError = null;
  let alive = true;

  render(el, html`
    <div class="page-head">
      <h2>Catálogos</h2>
      <button type="button" class="btn-secondary" id="newCatalogBtn">Nuevo catálogo</button>
    </div>
    <div class="split">
      <ul class="rows" id="catalogList" aria-live="polite"></ul>
      <aside class="aside" id="catalogAside" aria-label="Datos del catálogo"></aside>
    </div>`);

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
    asideFor = null;
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

  // --- Datos del catálogo activo -----------------------------------------------------------------
  let asideFor = null;
  let editing = false;

  function treeNode(node) {
    const label = html`<span class="tree-rank">${RANK_LABELS[node.rank] || node.rank}</span>
      <span class="tree-name">${node.name || 'Sin asignar'}</span><span class="tree-count">${node.count}</span>`;
    if (node.rank === 'family') {
      return html`<li><button type="button" class="tree-leaf" data-family="${node.name || ''}"
        aria-label="Ver en Especies las ${plural(node.count, 'especie', 'especies')} de ${node.name || 'familia sin asignar'}">${label}</button></li>`;
    }
    return html`<li><details ${node.rank === 'kingdom' ? html`open` : ''}><summary>${label}</summary>
      <ul class="tree">${(node.children || []).map(treeNode)}</ul></details></li>`;
  }

  async function paintAside() {
    const active = state.activeCatalog;
    if (!active) {
      asideFor = null;
      render(aside, html`<div class="panel muted">Elige un catálogo de la lista para trabajar con él:
        será el que se vea en Especies y, más adelante, en el mapa.</div>`);
      return;
    }
    if (asideFor === active.id) return;
    asideFor = active.id;
    editing = false;
    render(aside, html`<div class="panel muted">Cargando datos del catálogo…</div>`);
    try {
      const id = encodeURIComponent(active.id);
      const [{ catalog, summary }, { tree }] = await Promise.all([
        apiGetJson('/catalogs/' + id), apiGetJson(`/catalogs/${id}/tree`),
      ]);
      if (!alive || asideFor !== active.id) return;
      paintData(catalog, summary, tree);
    } catch (e) {
      if (alive && asideFor === active.id) {
        render(aside, html`<div class="panel muted">${e.status === 0 ? 'Sin conexión: los datos del catálogo necesitan red por ahora.' : e.message}</div>`);
      }
    }
  }

  function paintData(catalog, summary, tree) {
    const groups = Object.entries(summary.byTaxonomicGroup);
    const cats = iucnEntries(summary.byIucnCategory);
    const isOwner = catalog.role === 'owner';
    render(aside, html`<div class="panel summary">
      <p class="eyebrow">Datos del catálogo · ${ROLE_LABELS[catalog.role] || catalog.role}</p>
      ${editing ? html`
        <form class="form edit-catalog" novalidate>
          <label class="field"><span>Nombre</span><input name="name" required maxlength="120" value="${catalog.name}"></label>
          <label class="field"><span>Descripción</span><textarea name="description" rows="3">${catalog.description || ''}</textarea></label>
          <p class="form-error" role="alert" hidden></p>
          <div class="form-actions"><button type="submit" class="btn-primary">Guardar</button>
            <button type="button" class="link-btn" data-cancel-edit>Cancelar</button></div>
        </form>` : html`
        <h3>${catalog.name}</h3>
        ${catalog.description ? html`<p>${catalog.description}</p>` : ''}`}
      <p class="stat"><strong>${summary.total}</strong> ${summary.total === 1 ? 'especie' : 'especies'}</p>
      <div class="form-actions aside-actions">
        ${ADD_ROLES.includes(catalog.role) ? html`<button type="button" class="btn-primary" data-add-species>Añadir especies</button>` : ''}
        ${isOwner && !editing ? html`<button type="button" class="link-btn" data-edit>Renombrar o editar descripción</button>` : ''}
      </div>
      ${cats.length ? html`<h4>Por categoría UICN</h4><dl class="counts">${cats.map(([k, n]) => html`
        <div><dt class="iucn iucn-${k}">${k === 'none' ? 'Sin evaluar' : k}</dt><dd>${n}</dd></div>`)}</dl>` : ''}
      ${groups.length ? html`<h4>Por grupo</h4><dl class="counts">${groups.map(([k, n]) => html`
        <div><dt>${k}</dt><dd>${n}</dd></div>`)}</dl>` : ''}
      ${tree.length ? html`<h4>Árbol taxonómico</h4>
        <p class="hint">Toca una familia para ver sus especies.</p>
        <ul class="tree tree-root">${tree.map(treeNode)}</ul>` : ''}
      ${isOwner ? html`<div class="danger-zone"><button type="button" class="btn-secondary danger" data-delete>Borrar catálogo</button></div>` : ''}
    </div>`);

    const on = (sel, fn) => { const n = $(sel, aside); if (n) n.addEventListener('click', fn); };
    on('[data-add-species]', () => openAddSpecies(catalog, { onChanged: () => { asideFor = null; load(); } }));
    on('[data-edit]', () => { editing = true; paintData(catalog, summary, tree); $('.edit-catalog input', aside).focus(); });
    on('[data-cancel-edit]', () => { editing = false; paintData(catalog, summary, tree); $('[data-edit]', aside).focus(); });
    on('[data-delete]', () => removeCatalog(catalog));
    aside.querySelectorAll('[data-family]').forEach((b) => b.addEventListener('click', () => {
      filterSpeciesByFamily(b.dataset.family || null);
      go('especies');
    }));

    const form = $('.edit-catalog', aside);
    if (form) {
      form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const err = $('.form-error', form);
        const name = form.name.value.trim();
        if (!name) { err.textContent = 'El catálogo necesita un nombre.'; err.hidden = false; return; }
        const button = $('button[type="submit"]', form);
        button.disabled = true;
        try {
          const res = await apiPatchJson('/catalogs/' + encodeURIComponent(catalog.id),
            { name, description: form.description.value.trim() || null });
          editing = false;
          if (state.activeCatalog && state.activeCatalog.id === catalog.id) setActiveCatalog({ ...res.catalog, role: catalog.role });
          asideFor = null;
          await load();
        } catch (e) {
          err.textContent = e.message;
          err.hidden = false;
          button.disabled = false;
        }
      });
    }
  }

  async function removeCatalog(catalog) {
    const ok = await confirmDialog({
      key: 'borrar-catalogo', title: 'Borrar catálogo', danger: true, confirmLabel: 'Borrar catálogo',
      requireText: catalog.name,
      text: html`<p>Se borra «${catalog.name}» para todo el equipo: nadie podrá abrirlo ni anotar en él.
        Las especies siguen en BioDex; solo desaparece esta lista.</p>`,
    });
    if (!ok) return;
    try {
      await apiDeleteJson('/catalogs/' + encodeURIComponent(catalog.id));
      if (state.activeCatalog && state.activeCatalog.id === catalog.id) setActiveCatalog(null);
      asideFor = null;
      await load();
      $('#newCatalogBtn', el).focus();
    } catch (e) {
      render(aside, html`<div class="panel"><p class="form-error">${e.message}</p></div>`);
    }
  }

  // --- Nuevo catálogo ---------------------------------------------------------------------------
  $('#newCatalogBtn', el).addEventListener('click', () => openCatalogBuilder({ onSaved: () => load() }));

  const unsubscribe = subscribe((key) => {
    if (key === 'activeCatalog') { paintList(); paintAside(); }
    if (key === 'online' && state.online && loadError) load();
  });

  load();
  return () => {
    alive = false;
    unsubscribe();
  };
}
