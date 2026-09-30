// ================================================================================================
// Vista Catálogos: lista de GET /api/catalogs, elección del catálogo activo y «Datos del catálogo».
// «Nuevo catálogo» abre el constructor (catalog-builder.js). En escritorio (≥ 900 px) los datos del
// activo van a la derecha; en el móvil, debajo de la lista.
// Datos del catálogo: resumen, árbol taxonómico plegable (GET /{id}/tree) con recuentos —tocar una
// familia filtra la pestaña Especies—, «Añadir especies» (editor o más) y, para el owner, renombrar,
// editar la descripción y borrar (diálogo propio con el nombre escrito para confirmar).
// F5: «Descargar para usar sin conexión» (tamaño estimado antes, progreso durante, fecha al terminar).
// Sin servidor, la lista y los datos salen del dispositivo (IndexedDB) y lo que necesita red se oculta.
// ================================================================================================

import { apiGetJson, apiPatchJson, apiDeleteJson } from '../api.js';
import { state, subscribe, setActiveCatalog } from '../state.js';
import { html, render, $, plural } from '../util.js';
import { remoteOrLocal, saveCatalogList, localCatalogs, localCatalog, localCatalogData, localTree, localNote,
  estimateBytes, formatBytes, shortDate } from '../offline.js';
import { startDownload, handleNotMember } from '../sync.js';
import { confirmDialog } from '../components/overlay.js';
import { iucnEntries, filterSpeciesByFamily } from './species.js';
import { openCatalogBuilder, openAddSpecies } from './catalog-builder.js';

export const ROLE_LABELS = { owner: 'Propietario', custodian: 'Custodio', editor: 'Editor', viewer: 'Lector' };
const ADD_ROLES = ['owner', 'custodian', 'editor'];
const OFFLINE_EMPTY = 'Sin conexión con el servidor y aún no hay catálogos en este dispositivo. Con red, elige uno y pulsa «Descargar para usar sin conexión».';
const PHASES = { package: 'Descargando fichas y niveles…', marks: 'Descargando marcas del equipo…', images: 'Guardando imágenes' };
const RANK_LABELS = { kingdom: 'Reino', phylum: 'Filo', class: 'Clase', order: 'Orden', family: 'Familia' };

export function mount(el, { go }) {
  let catalogs = null;
  let fromDevice = false;   // la lista sale del dispositivo (sin servidor)
  let locals = new Map();   // id → registro local (downloadedAt…)
  let loadError = null;
  let alive = true;

  render(el, html`
    <div class="page-head">
      <h2>Catálogos</h2>
      <button type="button" class="btn-secondary" id="newCatalogBtn">Nuevo catálogo</button>
    </div>
    <p class="copy-note" id="catalogNote" role="status" hidden></p>
    <div class="split">
      <ul class="rows" id="catalogList" aria-live="polite"></ul>
      <aside class="aside" id="catalogAside" aria-label="Datos del catálogo"></aside>
    </div>`);

  const list = $('#catalogList', el);
  const aside = $('#catalogAside', el);

  // --- Lista ------------------------------------------------------------------------------------
  function paintList() {
    const note = $('#catalogNote', el);
    note.hidden = !fromDevice;
    note.textContent = fromDevice ? 'Datos del dispositivo · sin conexión con el servidor' : '';
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
            ${locals.get(c.id) && locals.get(c.id).downloadedAt ? html`<span class="badge ok">Sin conexión ✓</span>` : ''}
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
      const res = await remoteOrLocal(async () => {
        const { catalogs: list } = await apiGetJson('/catalogs');
        await saveCatalogList(list);
        // Los que ya no llegan (expulsado o borrado) salen del dispositivo, salvo marcas sin subir.
        const ids = new Set(list.map((c) => c.id));
        for (const c of await localCatalogs()) if (!ids.has(c.id) && !c.revoked) await handleNotMember(c.id);
        return list;
      }, async () => {
        const list = (await localCatalogs()).filter((c) => !c.revoked && c.role);
        return list.length ? list : null;
      });
      if (!alive) return;
      catalogs = res.data;
      fromDevice = res.fromDevice;
      await refreshLocals();
      // Sin servidor no se sabe si el activo sigue siendo accesible: se comprueba al volver.
      if (!fromDevice) syncActive();
    } catch (e) {
      if (!alive) return;
      loadError = e.offline ? OFFLINE_EMPTY : e.message;
      catalogs = null;
      fromDevice = false;
    }
    paintList();
    asideFor = null;
    paintAside();
  }

  async function refreshLocals() {
    locals = new Map((await localCatalogs().catch(() => [])).map((c) => [c.id, c]));
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
  let asideDevice = null;    // registro local si los datos salen del dispositivo
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
      const [one, tree] = await Promise.all([
        remoteOrLocal(() => apiGetJson('/catalogs/' + id), () => localCatalogData(active.id)),
        remoteOrLocal(() => apiGetJson(`/catalogs/${id}/tree`), async () => {
          const c = await localCatalog(active.id);
          return c && c.downloadedAt ? { tree: await localTree(active.id) } : null;
        }),
      ]);
      if (!alive || asideFor !== active.id) return;
      asideDevice = one.fromDevice ? one.data.local : null;
      paintData(one.data.catalog, one.data.summary, tree.data.tree);
    } catch (e) {
      if (alive && asideFor === active.id) {
        render(aside, html`<div class="panel muted">${e.offline
          ? 'Sin conexión con el servidor y este catálogo no está descargado en el dispositivo.' : e.message}</div>`);
      }
    }
  }

  function paintData(catalog, summary, tree) {
    const groups = Object.entries(summary.byTaxonomicGroup);
    const cats = iucnEntries(summary.byIucnCategory);
    // Sin servidor no se edita: lo que necesita red no se ofrece.
    const isOwner = catalog.role === 'owner' && !asideDevice;
    render(aside, html`<div class="panel summary">
      <p class="eyebrow">Datos del catálogo · ${ROLE_LABELS[catalog.role] || catalog.role}</p>
      ${asideDevice ? html`<p class="copy-note">${localNote(asideDevice)}</p>` : ''}
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
      <div class="offline-box" id="offlineBox" aria-live="polite"></div>
      <div class="form-actions aside-actions">
        ${ADD_ROLES.includes(catalog.role) && !asideDevice ? html`<button type="button" class="btn-primary" data-add-species>Añadir especies</button>` : ''}
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
    paintOffline(catalog, summary.total);
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

  // --- «Llevar al campo» ---------------------------------------------------------------------------
  let offlineFor = null;    // {catalog, total} del recuadro pintado
  let offlineError = null;

  async function paintOffline(catalog, total) {
    offlineFor = { catalog, total };
    const box = $('#offlineBox', aside);
    if (!box) return;
    const local = await localCatalog(catalog.id).catch(() => null);
    if (!alive || !box.isConnected) return;
    const dl = state.downloads[catalog.id];
    if (dl) {
      const pct = dl.phase === 'images' && dl.total ? Math.round((dl.done / dl.total) * 100) : null;
      render(box, html`<p class="offline-status"><strong>${PHASES[dl.phase] || 'Descargando…'}</strong>
          ${dl.phase === 'images' && dl.total ? html` ${dl.done} de ${dl.total}` : ''}</p>
        <progress max="100" ${pct === null ? '' : html`value="${pct}"`} aria-label="Progreso de la descarga"></progress>`);
      return;
    }
    const done = local && local.downloadedAt;
    const size = formatBytes(estimateBytes(total));
    render(box, html`
      ${done ? html`<p class="offline-status ok"><strong>Disponible sin conexión · ${shortDate(local.downloadedAt)}</strong></p>` : ''}
      ${offlineError ? html`<p class="form-error" role="alert">${offlineError}</p>` : ''}
      ${state.online ? html`<button type="button" class="${done ? 'link-btn' : 'btn-primary'}" data-download>
          ${done ? 'Actualizar la copia del dispositivo' : 'Descargar para usar sin conexión'}</button>
        ${done ? '' : html`<p class="hint">Tamaño estimado: ~${size} (fichas, marcas del equipo y una foto de cada una de las
          ${plural(total, 'especie', 'especies')}).</p>`}`
        : done ? '' : html`<p class="hint">Necesitas conexión para descargarlo.</p>`}`);
    const btn = $('[data-download]', box);
    if (btn) {
      btn.addEventListener('click', () => {
        offlineError = null;
        startDownload(catalog.id).catch((e) => { offlineError = e.message; })
          .finally(async () => { await refreshLocals(); if (alive) { paintList(); repaintOffline(); } });
      });
    }
  }

  function repaintOffline() {
    if (offlineFor && state.activeCatalog && state.activeCatalog.id === offlineFor.catalog.id) {
      paintOffline(offlineFor.catalog, offlineFor.total);
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
    if (key === 'online' && state.online && (loadError || fromDevice || asideDevice)) load();
    else if (key === 'online') repaintOffline();
    if (key === 'download') {
      repaintOffline();
      refreshLocals().then(() => { if (alive && catalogs) paintList(); });
    }
  });

  load();
  return () => {
    alive = false;
    unsubscribe();
  };
}
