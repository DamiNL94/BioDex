// ================================================================================================
// Vista Catálogos: lista de GET /api/catalogs, elección del catálogo activo y sus datos.
// ------------------------------------------------------------------------------------------------
// F11c — dos disposiciones (app.js decide el modo; al cruzar los 900 px la vista se vuelve a montar):
//   ESTUDIO (escritorio/tablet): maestro-detalle a lo ancho. A la izquierda la lista; a la derecha el
//     catálogo elegido como espacio de trabajo con pestañas: Resumen · Equipo · Sensibilidad (owner) ·
//     Registro (owner) · Exportar. Sensibilidad, Registro y Exportar se pintan DENTRO de la pestaña
//     (mountLevels/mountAudit/mountExport), no en un panel encima.
//   CAMPO (móvil): «¿Listo para el campo?». Arriba el catálogo activo y su preparación (llevarlo al
//     dispositivo, zonas del mapa, pendientes); debajo, cambiar de catálogo; y la gestión (añadir especies,
//     equipo, niveles, registro, exportar, editar, borrar) plegada en «Gestionar catálogo»: todo está,
//     pero en segundo plano. Cada gestión abre su panel a pantalla completa.
// Resumen: recuentos por categoría UICN y grupo y árbol taxonómico plegable (GET /{id}/tree); tocar
// una familia filtra Especies y deja «← Volver a <catálogo>».
// F5: «Llevar al campo» (antes «Descargar para usar sin conexión»): tamaño estimado antes, progreso
// durante, fecha al terminar. Sin servidor, la lista y los datos salen del dispositivo (IndexedDB) y lo
// que necesita red se oculta. F6: equipo e invitaciones (catalog-team.js); «Tengo un código» (join.js).
// F10a: «Exportar datos» (antes «Descargar datos», catalog-export.js).
// ================================================================================================

import { apiGetJson, apiPatchJson, apiDeleteJson } from '../api.js';
import { state, subscribe, setActiveCatalog } from '../state.js';
import { html, render, $, plural } from '../util.js';
import { remoteOrLocal, saveCatalogList, localCatalogs, localCatalog, localCatalogData, localTree, localNote,
  estimateBytes, formatBytes, shortDate } from '../offline.js';
import { startDownload, handleNotMember } from '../sync.js';
import { confirmDialog, openOverlay } from '../components/overlay.js';
import { iucnEntries, filterSpeciesByFamily } from './species.js';
import { openCatalogBuilder, openAddSpecies } from './catalog-builder.js';
import { ROLE_LABELS, mountTeam, openLevels, openAudit, mountLevels, mountAudit } from './catalog-team.js';
import { openJoin } from './join.js';
import { openExport, mountExport } from './catalog-export.js';
import { listZones } from '../map/zones.js';

export { ROLE_LABELS };
const ADD_ROLES = ['owner', 'custodian', 'editor'];
const OFFLINE_EMPTY = 'Sin conexión con el servidor y aún no hay catálogos en este dispositivo. Con red, elige uno y pulsa «Llevar al campo».';
const PHASES = { package: 'Descargando fichas y niveles…', marks: 'Descargando marcas del equipo…', images: 'Guardando imágenes' };
const RANK_LABELS = { kingdom: 'Reino', phylum: 'Filo', class: 'Clase', order: 'Orden', family: 'Familia' };
const TABS = [
  ['summary', 'Resumen'], ['team', 'Equipo'], ['levels', 'Sensibilidad', 'owner'], ['audit', 'Registro', 'owner'], ['export', 'Exportar'],
];
const ICON_CHEV = html`<svg viewBox="0 0 24 24" aria-hidden="true" class="chev-r"><path d="m9 6 6 6-6 6"/></svg>`;

// La pestaña del detalle sobrevive a los cambios de sección: «← Volver» desde Especies la encuentra igual.
let lastTab = 'summary';

export function mount(el, { go, isStudio }) {
  const studio = isStudio();
  let catalogs = null;
  let fromDevice = false;   // la lista sale del dispositivo (sin servidor)
  let locals = new Map();   // id → registro local (downloadedAt…)
  let loadError = null;
  let alive = true;

  if (studio) {
    render(el, html`
      <div class="page-head">
        <div><h2>Catálogos</h2><p class="muted">Crea catálogos, organiza tu equipo y prepara los datos.</p></div>
        <div class="page-tools">
          <form class="join-code" id="joinForm" novalidate>
            <label class="sr-only" for="joinCode">Tengo un código de invitación</label>
            <input id="joinCode" name="code" placeholder="Código de invitación" autocomplete="off" autocapitalize="characters" spellcheck="false">
            <button type="submit" class="btn-secondary">Unirme</button>
          </form>
          <button type="button" class="btn-primary" id="newCatalogBtn">Nuevo catálogo</button>
        </div>
      </div>
      <p class="copy-note" id="catalogNote" role="status" hidden></p>
      <div class="cat-layout">
        <div class="cat-master"><ul class="rows cat-list" id="catalogList" aria-live="polite"></ul></div>
        <section class="cat-detail" id="catalogAside" aria-label="Catálogo elegido"></section>
      </div>`);
  } else {
    render(el, html`
      <section class="field-card" id="catalogAside" aria-label="Catálogo activo"></section>
      <p class="copy-note" id="catalogNote" role="status" hidden></p>
      <h3 class="sec-title">Mis catálogos</h3>
      <ul class="rows cat-list" id="catalogList" aria-live="polite"></ul>
      <div class="field-more">
        <form class="join-code" id="joinForm" novalidate>
          <label class="sr-only" for="joinCode">Tengo un código de invitación</label>
          <input id="joinCode" name="code" placeholder="Tengo un código" autocomplete="off" autocapitalize="characters" spellcheck="false">
          <button type="submit" class="btn-secondary">Unirme</button>
        </form>
        <button type="button" class="btn-quiet" id="newCatalogBtn">+ Nuevo catálogo</button>
      </div>`);
  }

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
    // En el móvil el activo ya está arriba: la lista es para cambiar.
    const shown = studio ? catalogs : catalogs.filter((c) => c.id !== activeId);
    if (!shown.length) {
      render(list, html`<li class="empty"><p class="muted">No tienes más catálogos.</p></li>`);
      return;
    }
    render(list, html`${shown.map((c) => html`
      <li>
        <button type="button" class="row role-${c.role} ${c.id === activeId ? 'is-active' : ''}" data-id="${c.id}"
                aria-pressed="${c.id === activeId}">
          <span class="row-title">${c.name}</span>
          ${studio && c.description ? html`<span class="row-text">${c.description}</span>` : ''}
          <span class="row-meta">
            <span class="badge">${ROLE_LABELS[c.role] || c.role}</span>
            <span class="badge">${plural(c.speciesCount || 0, 'especie', 'especies')}</span>
            ${locals.get(c.id) && locals.get(c.id).downloadedAt ? html`<span class="badge ok">Sin conexión ✓</span>` : ''}
          </span>
        </button>
      </li>`)}`);
    list.querySelectorAll('.row').forEach((row) => row.addEventListener('click', () => {
      setActiveCatalog(catalogs.find((c) => c.id === row.dataset.id));
      if (!studio) window.scrollTo({ top: 0, behavior: 'smooth' });
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
  let tabCleanup = null;     // desmontaje de Equipo / Sensibilidad / Registro / Exportar
  let lastData = null;       // [catalog, summary, tree] pintados (para cambiar de pestaña sin recargar)

  function stopTab() { if (tabCleanup) { tabCleanup(); tabCleanup = null; } }

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
      stopTab();
      render(aside, html`<div class="cat-empty">
        <p class="eyebrow">Catálogo activo</p>
        <h3>Ninguno elegido</h3>
        <p class="muted">${studio ? 'Elige un catálogo de la lista para trabajar con él: será el que se vea en Especies y en el Mapa.'
          : 'Elige uno de tus catálogos para llevarlo al campo.'}</p></div>`);
      return;
    }
    if (asideFor === active.id) return;
    if (asideFor !== null && !studio) lastTab = 'summary';
    asideFor = active.id;
    editing = false;
    stopTab();
    render(aside, html`<div class="cat-empty"><p class="muted">Cargando datos del catálogo…</p></div>`);
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
        render(aside, html`<div class="cat-empty"><h3>${active.name}</h3><p class="muted">${e.offline
          ? 'Sin conexión con el servidor y este catálogo no está descargado en el dispositivo.' : e.message}</p></div>`);
      }
    }
  }

  function paintData(catalog, summary, tree) {
    lastData = [catalog, summary, tree];
    stopTab();
    if (studio) paintStudio(catalog, summary, tree); else paintField(catalog, summary, tree);
  }

  // Recuentos y árbol (los usan las dos disposiciones).
  function countsHtml(summary) {
    const groups = Object.entries(summary.byTaxonomicGroup);
    const cats = iucnEntries(summary.byIucnCategory);
    return html`
      ${cats.length ? html`<h4>Por categoría UICN</h4><dl class="counts">${cats.map(([k, n]) => html`
        <div><dt class="iucn iucn-${k}">${k === 'none' ? 'Sin evaluar' : k}</dt><dd>${n}</dd></div>`)}</dl>` : ''}
      ${groups.length ? html`<h4>Por grupo</h4><dl class="counts">${groups.map(([k, n]) => html`
        <div><dt>${k}</dt><dd>${n}</dd></div>`)}</dl>` : ''}`;
  }
  function treeHtml(tree) {
    return tree.length ? html`<h4>Árbol taxonómico</h4>
      <p class="hint">Toca una familia para ver sus especies.</p>
      <ul class="tree tree-root">${tree.map(treeNode)}</ul>` : '';
  }
  function editForm(catalog) {
    return html`<form class="form edit-catalog" novalidate>
      <label class="field"><span>Nombre</span><input name="name" required maxlength="120" value="${catalog.name}"></label>
      <label class="field"><span>Descripción</span><textarea name="description" rows="3">${catalog.description || ''}</textarea></label>
      <p class="form-error" role="alert" hidden></p>
      <div class="form-actions"><button type="submit" class="btn-primary">Guardar</button>
        <button type="button" class="link-btn" data-cancel-edit>Cancelar</button></div>
    </form>`;
  }

  // --- Estudio: espacio de trabajo con pestañas ------------------------------------------------------
  function paintStudio(catalog, summary, tree) {
    const isOwner = catalog.role === 'owner' && !asideDevice;
    const tabs = TABS.filter((t) => !t[2] || t[2] === catalog.role);
    if (!tabs.some((t) => t[0] === lastTab)) lastTab = 'summary';
    const canAdd = ADD_ROLES.includes(catalog.role) && !asideDevice;
    render(aside, html`
      <nav class="crumbs-bar" aria-label="Ruta"><ol class="crumbs">
        <li>Catálogos</li><li>${catalog.name}</li><li aria-current="page">${tabs.find((t) => t[0] === lastTab)[1]}</li></ol></nav>
      <header class="cat-head">
        ${editing ? editForm(catalog) : html`<div class="cat-head-main">
          <h3 class="cat-name">${catalog.name}</h3>
          <p class="cat-meta"><span class="badge role-badge role-${catalog.role}">${ROLE_LABELS[catalog.role] || catalog.role}</span>
            <span class="badge">${plural(summary.total, 'especie', 'especies')}</span></p>
          ${catalog.description ? html`<p class="cat-desc">${catalog.description}</p>` : ''}
        </div>
        <div class="cat-head-actions">
          ${canAdd ? html`<button type="button" class="btn-primary" data-add-species>Añadir especies</button>` : ''}
          ${isOwner ? html`<button type="button" class="btn-secondary" data-edit>Editar</button>` : ''}
        </div>`}
      </header>
      ${asideDevice ? html`<p class="copy-note">${localNote(asideDevice)}</p>` : ''}
      <div class="tabbar" role="tablist" aria-label="Datos del catálogo">
        ${tabs.map(([k, label]) => html`<button type="button" role="tab" class="tabbar-tab" data-tab="${k}" id="ctab-${k}"
          aria-selected="${lastTab === k}" aria-controls="ctabPanel" tabindex="${lastTab === k ? '0' : '-1'}">${label}</button>`)}
      </div>
      <div class="tab-panel" id="ctabPanel" role="tabpanel" aria-labelledby="ctab-${lastTab}"></div>`);

    const panel = $('#ctabPanel', aside);
    if (lastTab === 'summary') {
      render(panel, html`<div class="summary-grid">
        <div class="summary-main">
          <div class="stat-row">
            <div class="stat-box"><strong>${summary.total}</strong><span>${summary.total === 1 ? 'especie' : 'especies'}</span></div>
            ${iucnEntries(summary.byIucnCategory).filter(([k]) => ['CR', 'EN', 'VU'].includes(k)).length ? html`
              <div class="stat-box"><strong>${iucnEntries(summary.byIucnCategory).filter(([k]) => ['CR', 'EN', 'VU'].includes(k))
                .reduce((n, [, v]) => n + v, 0)}</strong><span>amenazadas (CR, EN, VU)</span></div>` : ''}
            <div class="stat-box"><strong>${Object.keys(summary.byTaxonomicGroup).length}</strong>
              <span>${Object.keys(summary.byTaxonomicGroup).length === 1 ? 'grupo' : 'grupos'}</span></div>
          </div>
          <div class="offline-box" id="offlineBox" aria-live="polite"></div>
          ${countsHtml(summary)}
          ${isOwner ? html`<div class="danger-zone"><button type="button" class="btn-secondary danger" data-delete>Borrar catálogo</button></div>` : ''}
        </div>
        <div class="summary-side">${treeHtml(tree) || html`<p class="muted">Sin árbol taxonómico.</p>`}</div>
      </div>`);
      paintOffline(catalog, summary.total);
    } else if (lastTab === 'team') {
      tabCleanup = mountTeam(panel, catalog, {
        // Salir o transferir: cambia el rol o desaparece el catálogo; se recarga todo.
        onLeft: () => { lastTab = 'summary'; asideFor = null; load(); },
      });
    } else if (lastTab === 'levels') {
      tabCleanup = mountLevels(panel, catalog, { onChanged: () => {} });
    } else if (lastTab === 'audit') {
      tabCleanup = mountAudit(panel, catalog);
    } else if (lastTab === 'export') {
      tabCleanup = mountExport(panel, catalog);
    }
    wireCommon(catalog, summary, tree);

    // Pestañas: clic y flechas ←/→ (patrón ARIA de pestañas)
    const tabEls = Array.from(aside.querySelectorAll('[data-tab]'));
    const select = (k) => {
      if (lastTab === k) return;
      lastTab = k;
      paintData(...lastData);
      const again = $(`[data-tab="${k}"]`, aside);
      if (again) again.focus();
    };
    tabEls.forEach((b, i) => {
      b.addEventListener('click', () => select(b.dataset.tab));
      b.addEventListener('keydown', (ev) => {
        if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft') return;
        ev.preventDefault();
        const next = tabEls[(i + (ev.key === 'ArrowRight' ? 1 : tabEls.length - 1)) % tabEls.length];
        select(next.dataset.tab);
      });
    });
  }

  // --- Campo: «¿Listo para el campo?» -----------------------------------------------------------------
  function paintField(catalog, summary, tree) {
    const isOwner = catalog.role === 'owner' && !asideDevice;
    const canAdd = ADD_ROLES.includes(catalog.role) && !asideDevice;
    const manage = [
      canAdd && ['add', 'Añadir especies', 'Buscar en EIDOS y añadir al catálogo'],
      ['team', 'Equipo', 'Miembros, roles e invitaciones'],
      catalog.role === 'owner' && ['levels', 'Niveles de sensibilidad', 'Qué coordenadas ve cada uno'],
      catalog.role === 'owner' && ['audit', 'Registro', 'Accesos y cambios'],
      ['export', 'Exportar datos', 'Darwin Core, Plinian Core y hojas (necesita conexión)'],
      isOwner && ['edit', 'Renombrar o editar descripción', null],
    ].filter(Boolean);
    render(aside, html`
      <p class="eyebrow">Catálogo activo</p>
      ${editing ? editForm(catalog) : html`<h2 class="field-name">${catalog.name}</h2>
        <p class="cat-meta"><span class="badge role-badge role-${catalog.role}">${ROLE_LABELS[catalog.role] || catalog.role}</span>
          <span class="badge">${plural(summary.total, 'especie', 'especies')}</span></p>`}
      ${asideDevice ? html`<p class="copy-note">${localNote(asideDevice)}</p>` : ''}
      <ul class="ready-list">
        <li class="ready-item"><div class="offline-box" id="offlineBox" aria-live="polite"></div></li>
        <li class="ready-item" id="zonesItem"><span class="muted">Zonas del mapa…</span></li>
        <li class="ready-item" id="pendingItem" hidden></li>
      </ul>
      <details class="manage">
        <summary>Gestionar catálogo</summary>
        <ul class="menu-list">${manage.map(([k, label, hint]) => html`<li>
          <button type="button" class="menu-row" data-manage="${k}"><span><span class="menu-label">${label}</span>
            ${hint ? html`<span class="menu-hint">${hint}</span>` : ''}</span>${ICON_CHEV}</button></li>`)}</ul>
        ${isOwner ? html`<div class="danger-zone"><button type="button" class="btn-secondary danger" data-delete>Borrar catálogo</button></div>` : ''}
      </details>
      <details class="manage">
        <summary>Resumen del catálogo</summary>
        ${countsHtml(summary)}
        ${treeHtml(tree)}
      </details>`);
    paintOffline(catalog, summary.total);
    paintZones();
    paintPending();
    wireCommon(catalog, summary, tree);
    aside.querySelectorAll('[data-manage]').forEach((b) => b.addEventListener('click', () => {
      const k = b.dataset.manage;
      if (k === 'add') openAddSpecies(catalog, { onChanged: () => { asideFor = null; load(); } });
      else if (k === 'team') openTeamPanel(catalog);
      else if (k === 'levels') openLevels(catalog, { onChanged: () => { asideFor = null; load(); } });
      else if (k === 'audit') openAudit(catalog);
      else if (k === 'export') openExport(catalog);
      else if (k === 'edit') {
        editing = true;
        paintData(catalog, summary, tree);
        $('.edit-catalog input', aside).focus();
      }
    }));
  }

  // Pendientes de sincronizar: se repinta solo esta línea con cada pasada (no se cierran los plegables).
  function paintPending() {
    const item = $('#pendingItem', aside);
    if (!item) return;
    const n = state.sync.unsynced;
    item.hidden = !n;
    if (!n) { render(item, html``); return; }
    render(item, html`<a class="ready-link warn" href="#/ajustes" data-to-settings>
      <span>${plural(n, 'marca pendiente', 'marcas pendientes')} de sincronizar</span>${ICON_CHEV}</a>`);
    $('[data-to-settings]', item).addEventListener('click', (ev) => { ev.preventDefault(); go('ajustes'); });
  }

  function openTeamPanel(catalog) {
    let stop = null;
    const ov = openOverlay({ key: 'equipo', title: `Equipo · ${catalog.name}`, variant: 'full', className: 'team-panel',
      onClose: () => { if (stop) stop(); } });
    stop = mountTeam(ov.body, catalog, {
      onLeft: () => { ov.close(true); asideFor = null; load(); },
    });
  }

  async function paintZones() {
    const item = $('#zonesItem', aside);
    if (!item) return;
    const zones = await listZones().catch(() => []);
    if (!alive || !item.isConnected) return;
    const done = zones.filter((z) => z.status === 'complete').length;
    render(item, html`<button type="button" class="ready-link ${done ? 'ok' : ''}" data-zones>
      <span>${done ? html`✓ ${plural(done, 'zona del mapa descargada', 'zonas del mapa descargadas')}`
        : 'Descarga una zona del mapa para verlo sin cobertura'}</span>${ICON_CHEV}</button>`);
    $('[data-zones]', item).addEventListener('click', () => {
      go('mapa', { returnTo: { label: 'Catálogo', run: () => go('catalogos') } });
      window.dispatchEvent(new CustomEvent('biodex:open-zones'));
    });
  }

  // Acciones comunes a las dos disposiciones.
  function wireCommon(catalog, summary, tree) {
    const on = (sel, fn) => { const n = $(sel, aside); if (n) n.addEventListener('click', fn); };
    on('[data-add-species]', () => openAddSpecies(catalog, { onChanged: () => { asideFor = null; load(); } }));
    on('[data-edit]', () => { editing = true; paintData(catalog, summary, tree); $('.edit-catalog input', aside).focus(); });
    on('[data-cancel-edit]', () => { editing = false; paintData(catalog, summary, tree); });
    on('[data-delete]', () => removeCatalog(catalog));
    aside.querySelectorAll('[data-family]').forEach((b) => b.addEventListener('click', () => {
      filterSpeciesByFamily(b.dataset.family || null);
      go('especies', { returnTo: { label: catalog.name, run: () => go('catalogos') } });
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
      ${done ? html`<p class="offline-status ok"><strong>✓ Listo sin conexión</strong> · ${shortDate(local.downloadedAt)}</p>` : ''}
      ${offlineError ? html`<p class="form-error" role="alert">${offlineError}</p>` : ''}
      ${state.online ? html`<button type="button" class="${done ? 'link-btn' : 'btn-primary btn-field'}" data-download>
          ${done ? 'Actualizar la copia del dispositivo' : 'Llevar al campo'}</button>
        ${done ? '' : html`<p class="hint">Guarda en este dispositivo las fichas, las marcas del equipo y una foto de cada una de las
          ${plural(total, 'especie', 'especies')} (~${size}) para trabajar sin cobertura.</p>`}`
        : done ? '' : html`<p class="hint">Necesitas conexión para llevarlo al campo.</p>`}`);
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
      render(aside, html`<div class="cat-empty"><p class="form-error">${e.message}</p></div>`);
    }
  }

  // --- Nuevo catálogo ---------------------------------------------------------------------------
  $('#newCatalogBtn', el).addEventListener('click', () => openCatalogBuilder({ onSaved: () => load() }));
  $('#joinForm', el).addEventListener('submit', (ev) => {
    ev.preventDefault();
    const input = $('#joinCode', el);
    const code = input.value.trim();
    if (!code) { input.focus(); return; }
    openJoin(code, { onJoined: () => { input.value = ''; } });
  });
  // Unirse a un catálogo (join.js) o cambios de equipo que cambian la lista.
  const onCatalogsChanged = () => { asideFor = null; load(); };
  window.addEventListener('biodex:catalogs-changed', onCatalogsChanged);

  const unsubscribe = subscribe((key) => {
    if (key === 'activeCatalog') { paintList(); paintAside(); }
    if (key === 'online' && state.online && (loadError || fromDevice || asideDevice)) load();
    else if (key === 'online') repaintOffline();
    if (key === 'sync') paintPending();
    if (key === 'download') {
      repaintOffline();
      refreshLocals().then(() => { if (alive && catalogs) paintList(); });
    }
  });

  load();
  return () => {
    alive = false;
    unsubscribe();
    window.removeEventListener('biodex:catalogs-changed', onCatalogsChanged);
    stopTab();
  };
}
