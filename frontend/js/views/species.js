// ================================================================================================
// Vista Especies: especies del catálogo activo (GET /api/catalogs/{id}). Sin buscador hasta F4.
// Cada fila lleva el filete lateral del color de su categoría UICN principal.
// ================================================================================================

import { apiGetJson } from '../api.js';
import { state, subscribe, setActiveCatalog } from '../state.js';
import { html, render, plural } from '../util.js';

// Orden de gravedad de la Lista Roja: el de los recuentos por categoría.
export const IUCN_CODES = ['EX', 'EW', 'RE', 'CR', 'EN', 'VU', 'NT', 'LC', 'DD', 'NA', 'NE'];

/** Entradas de summary.byIucnCategory ordenadas por gravedad; «none» (sin evaluar) al final. */
export function iucnEntries(byCategory) {
  const rank = (k) => (IUCN_CODES.includes(k) ? IUCN_CODES.indexOf(k) : IUCN_CODES.length + (k === 'none'));
  return Object.entries(byCategory).sort(([a], [b]) => rank(a) - rank(b));
}

function iucnBadge(iucn) {
  if (!iucn || !iucn.code) return html`<span class="badge iucn iucn-none">Sin evaluar</span>`;
  const code = IUCN_CODES.includes(iucn.code) ? iucn.code : 'other';
  return html`<span class="badge iucn iucn-${code}" title="${[iucn.scope, iucn.year].filter(Boolean).join(' · ')}">${iucn.code}${iucn.scope ? html` · ${iucn.scope}` : ''}</span>`;
}

function sensitivityBadge(s) {
  if (!s || s.level === null) return html`<span class="badge">Sin nivel</span>`;
  return html`<span class="badge level level-${s.level}">Nivel ${s.level}${s.confirmed ? '' : ' · sugerido'}</span>`;
}

export function mount(el, { go }) {
  let alive = true;

  function paint(body) { if (alive) render(el, body); }

  async function load() {
    const active = state.activeCatalog;
    if (!active) {
      paint(html`<section class="placeholder">
        <p class="eyebrow">Especies</p>
        <h2>Elige un catálogo</h2>
        <p>Las especies se ven siempre dentro de un catálogo. Elige con cuál trabajas y aparecerán aquí.</p>
        <a class="btn-primary" href="#/catalogos" id="toCatalogs">Ir a Catálogos</a>
      </section>`);
      el.querySelector('#toCatalogs').addEventListener('click', (ev) => { ev.preventDefault(); go('catalogos'); });
      return;
    }
    paint(html`<div class="page-head"><h2>Especies</h2></div><p class="muted">Cargando especies…</p>`);
    try {
      const data = await apiGetJson('/catalogs/' + encodeURIComponent(active.id));
      if (!alive || !state.activeCatalog || state.activeCatalog.id !== active.id) return;
      paintSpecies(data);
    } catch (e) {
      if (!alive) return;
      if (e.status === 403 || e.status === 404) {
        // Borrado o sin acceso: el activo guardado ya no vale. El subscribe repinta la invitación.
        setActiveCatalog(null);
        return;
      }
      // TODO F5: sin red, leer las especies del catálogo desde IndexedDB.
      paint(html`<div class="page-head"><h2>Especies</h2></div>
        <p class="guard-band warn">${e.status === 0 ? 'Sin conexión: la lista de especies necesita red por ahora.' : e.message}</p>`);
    }
  }

  function paintSpecies({ catalog, species, summary }) {
    const cats = iucnEntries(summary.byIucnCategory)
      .map(([k, n]) => html`<span class="badge iucn iucn-${k}">${k === 'none' ? 'Sin evaluar' : k} ${n}</span>`);
    paint(html`
      <div class="page-head">
        <div><h2>Especies</h2><p class="muted">${catalog.name} · ${plural(summary.total, 'especie', 'especies')}</p></div>
        <div class="row-meta">${cats}</div>
      </div>
      ${species.length ? html`
        <div class="table-head" aria-hidden="true">
          <span>Especie</span><span>Grupo</span><span>UICN</span><span>Sensibilidad</span></div>
        <ul class="rows species-rows">${species.map((s) => html`
          <li class="row sp iucn-edge-${s.iucnPrimary && IUCN_CODES.includes(s.iucnPrimary.code) ? s.iucnPrimary.code : 'none'}">
            <span class="sp-name"><em class="sci">${s.scientificName}</em>
              ${s.vernacularName ? html`<span class="row-text">${s.vernacularName}</span>` : ''}</span>
            <span class="sp-group row-text">${s.taxonomicGroup || '—'}</span>
            <span class="sp-iucn">${iucnBadge(s.iucnPrimary)}</span>
            <span class="sp-level">${sensitivityBadge(s.sensitivity)}</span>
          </li>`)}</ul>` : html`
        <section class="placeholder">
          <h3>Este catálogo aún no tiene especies</h3>
          <p>El buscador para añadirlas desde EIDOS llega en F4.</p>
        </section>`}`);
  }

  const unsubscribe = subscribe((key) => {
    if (key === 'activeCatalog') load();
    if (key === 'online' && state.online) load();
  });
  load();
  return () => { alive = false; unsubscribe(); };
}
