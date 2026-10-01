// ================================================================================================
// catalog-switcher.js — «Cambiar de catálogo» desde la cabecera (F11c), en cualquier sección.
// ------------------------------------------------------------------------------------------------
// Campo: hoja que sube desde abajo. Estudio: menú anclado bajo el selector de la barra lateral.
// Es un panel (overlay.js, variante 'sheet') y por tanto un nivel de BioDexNav: «atrás», Escape y la
// × lo cierran. Pinta primero lo que hay en el dispositivo (abre sin red) y, con servidor, refresca
// la lista con GET /catalogs. Elegir uno lo hace activo; «Gestionar catálogos» lleva a Catálogos.
// ================================================================================================

import { apiGetJson } from '../api.js';
import { state, setActiveCatalog } from '../state.js';
import { html, render, $, plural } from '../util.js';
import { localCatalogs, saveCatalogList } from '../offline.js';
import { openOverlay } from './overlay.js';
import { ROLE_LABELS } from '../views/catalog-team.js';

export function openCatalogSwitcher({ anchor = null, onManage } = {}) {
  const ov = openOverlay({ key: 'cambiar-catalogo', title: 'Cambiar de catálogo', variant: 'sheet', className: 'cat-switcher' });
  // Estudio: el menú se ancla bajo el botón que lo abrió (la posición la pone CSS con estas variables).
  if (anchor) {
    const r = anchor.getBoundingClientRect();
    ov.el.style.setProperty('--anchor-top', `${Math.round(r.bottom + 6)}px`);
    ov.el.style.setProperty('--anchor-left', `${Math.round(r.left)}px`);
  }
  let list = null;
  let note = '';

  function paint() {
    const activeId = state.activeCatalog && state.activeCatalog.id;
    render(ov.body, html`
      ${note ? html`<p class="copy-note">${note}</p>` : ''}
      ${list === null ? html`<p class="muted">Cargando…</p>` : list.length ? html`
        <ul class="switch-list">${list.map((c) => html`<li>
          <button type="button" class="switch-row ${c.id === activeId ? 'is-active' : ''}" data-id="${c.id}"
                  aria-pressed="${c.id === activeId}">
            <span class="switch-name">${c.name}</span>
            <span class="switch-meta">${ROLE_LABELS[c.role] || c.role} · ${plural(c.speciesCount || 0, 'especie', 'especies')}
              ${c.downloadedAt ? html` · <span class="ok">sin conexión ✓</span>` : ''}</span>
          </button></li>`)}</ul>` : html`<p class="muted">Aún no tienes catálogos en este dispositivo.</p>`}
      <div class="switch-foot"><button type="button" class="link-btn" data-manage>Gestionar catálogos</button></div>`);
    ov.body.querySelectorAll('[data-id]').forEach((b) => b.addEventListener('click', () => {
      const c = list.find((x) => x.id === b.dataset.id);
      if (c) setActiveCatalog(c);
      ov.close(true);
    }));
    $('[data-manage]', ov.body).addEventListener('click', () => {
      ov.close(true);
      if (onManage) onManage();
    });
  }

  async function load() {
    const locals = await localCatalogs().catch(() => []);
    const byId = new Map(locals.map((c) => [c.id, c]));
    list = locals.filter((c) => !c.revoked && c.role);
    if (ov.isOpen()) paint();
    if (!state.online) { note = 'Sin conexión: catálogos de este dispositivo.'; if (ov.isOpen()) paint(); return; }
    try {
      const { catalogs } = await apiGetJson('/catalogs');
      await saveCatalogList(catalogs).catch(() => {});
      list = catalogs.map((c) => ({ ...c, downloadedAt: byId.get(c.id) && byId.get(c.id).downloadedAt }));
      note = '';
    } catch (e) {
      note = 'No se pudo consultar el servidor: catálogos de este dispositivo.';
    }
    if (ov.isOpen()) paint();
  }

  paint();
  load();
  return ov;
}
