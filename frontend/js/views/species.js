// ================================================================================================
// Vista Especies: especies del catálogo activo (GET /api/catalogs/{id}) con filtro LOCAL por texto,
// grupo, categoría, nivel y familia (esta llega desde el árbol de «Datos del catálogo»), y orden por
// nombre, categoría (de CR a LC) o grupo. Tocar una fila abre la ficha.
// Cada fila lleva el filete lateral del color de su categoría UICN principal y «Marcar aquí» (F5).
// Sin servidor, las especies salen del catálogo descargado (IndexedDB).
// ================================================================================================

import { apiGetJson } from '../api.js';
import { state, subscribe, setActiveCatalog } from '../state.js';
import { html, render, $, plural, sciName, sciNameText, store } from '../util.js';
import { remoteOrLocal, localCatalogData, localNote } from '../offline.js';
import { openMarkHere, canMark } from '../components/marks.js';
import { openSpeciesNew } from './species-new.js';
import { IUCN_CODES, iucnBadge, iucnClass } from '../components/species-card.js';
import { openSpeciesDetail } from './species-detail.js';
import { openAddSpecies } from './catalog-builder.js';

export { IUCN_CODES };
const ADD_ROLES = ['owner', 'custodian', 'editor'];
// Orden por gravedad de la pestaña: de CR a LC, luego lo demás (EX, DD, NE…) y sin evaluar.
const SEVERITY = ['CR', 'EN', 'VU', 'NT', 'LC', 'RE', 'EW', 'EX', 'DD', 'NA', 'NE'];

/** Entradas de summary.byIucnCategory ordenadas por gravedad; «none» (sin evaluar) al final. */
export function iucnEntries(byCategory) {
  const rank = (k) => (IUCN_CODES.includes(k) ? IUCN_CODES.indexOf(k) : IUCN_CODES.length + (k === 'none'));
  return Object.entries(byCategory).sort(([a], [b]) => rank(a) - rank(b));
}

// Filtro por familia pedido desde el árbol del catálogo; lo recoge el próximo montaje de la vista.
let pendingFamily;
export function filterSpeciesByFamily(family) {
  pendingFamily = family;
}

function sensitivityBadge(s) {
  if (!s || s.level === null) return html`<span class="badge">Sin nivel</span>`;
  return html`<span class="badge level level-${s.level}">Nivel ${s.level}${s.confirmed ? '' : ' · sugerido'}</span>`;
}

const fold = (s) => String(s || '').normalize('NFD').replace(/\p{Mn}+/gu, '').toLowerCase();

export function mount(el, { go }) {
  let alive = true;
  let data = null;
  let device = null;        // registro local del catálogo si las especies salen del dispositivo
  // Filtros y orden: se recuerdan por dispositivo (comodidad), salvo la familia, que es de un viaje.
  const saved = store.get('biodex.speciesFilters', {});
  const f = { text: '', group: saved.group || '', category: saved.category || '', level: saved.level || '',
    family: '', sort: saved.sort || 'name' };
  if (pendingFamily !== undefined) { f.family = pendingFamily || ''; pendingFamily = undefined; }

  function paint(body) { if (alive) render(el, body); }

  async function load() {
    const active = state.activeCatalog;
    if (!active) {
      data = null;
      paint(html`<section class="placeholder">
        <p class="eyebrow">Especies</p>
        <h2>Elige un catálogo</h2>
        <p>Las especies se ven siempre dentro de un catálogo. Elige con cuál trabajas y aparecerán aquí.</p>
        <a class="btn-primary" href="#/catalogos" id="toCatalogs">Ir a Catálogos</a>
      </section>`);
      el.querySelector('#toCatalogs').addEventListener('click', (ev) => { ev.preventDefault(); go('catalogos'); });
      return;
    }
    if (!data) paint(html`<div class="page-head"><h2>Especies</h2></div><p class="muted">Cargando especies…</p>`);
    try {
      const res = await remoteOrLocal(() => apiGetJson('/catalogs/' + encodeURIComponent(active.id)),
        () => localCatalogData(active.id));
      if (!alive || !state.activeCatalog || state.activeCatalog.id !== active.id) return;
      data = res.data;
      device = res.fromDevice ? res.data.local : null;
      paintPage();
    } catch (e) {
      if (!alive) return;
      if (e.status === 403 || e.status === 404) {
        // Borrado o sin acceso: el activo guardado ya no vale. El subscribe repinta la invitación.
        setActiveCatalog(null);
        return;
      }
      data = null;
      paint(e.offline ? html`<div class="page-head"><h2>Especies</h2></div>
        <section class="placeholder">
          <h3>Este catálogo no está en el dispositivo</h3>
          <p>Las especies de «${active.name}» se guardan al descargarlo con red («Descargar para usar sin
            conexión» en Catálogos). En cuanto vuelva la conexión se descargará solo.</p>
        </section>` : html`<div class="page-head"><h2>Especies</h2></div>
        <p class="guard-band warn">${e.message}</p>`);
    }
  }

  function filtered() {
    const q = fold(f.text.trim());
    const list = data.species.filter((s) => {
      if (q && !fold(`${s.scientificName} ${s.vernacularName || ''}`).includes(q)) return false;
      if (f.group && (s.taxonomicGroup || '') !== f.group) return false;
      if (f.category && ((s.iucnPrimary && s.iucnPrimary.code) || 'none') !== f.category) return false;
      if (f.level && String(s.sensitivity && s.sensitivity.level) !== f.level) return false;
      if (f.family && (s.family || '') !== f.family) return false;
      return true;
    });
    const sev = (s) => { const i = SEVERITY.indexOf(s.iucnPrimary && s.iucnPrimary.code); return i === -1 ? 99 : i; };
    const byName = (a, b) => sciNameText(a).localeCompare(sciNameText(b));
    const sorters = {
      name: byName,
      category: (a, b) => sev(a) - sev(b) || byName(a, b),
      group: (a, b) => (a.taxonomicGroup || '~').localeCompare(b.taxonomicGroup || '~') || byName(a, b),
    };
    return list.sort(sorters[f.sort] || byName);
  }

  function paintPage() {
    const { catalog, species, summary } = data;
    const groups = Object.keys(summary.byTaxonomicGroup);
    const cats = iucnEntries(summary.byIucnCategory).map(([k]) => k);
    const canAdd = ADD_ROLES.includes(catalog.role);
    const opt = (value, label, current) => html`<option value="${value}" ${current === value ? html`selected` : ''}>${label}</option>`;
    paint(html`
      <div class="page-head">
        <div><h2>Especies</h2><p class="muted">${catalog.name} · ${plural(summary.total, 'especie', 'especies')}</p>
          ${device ? html`<p class="copy-note" role="status">${localNote(device)}</p>` : ''}</div>
        ${canAdd && !device ? html`<button type="button" class="btn-secondary" id="addSpeciesBtn">Añadir especies</button>` : ''}
        ${canAdd && device ? html`<button type="button" class="btn-secondary" id="newSpeciesBtn">Crear especie</button>` : ''}
      </div>
      ${species.length ? html`
        <form class="list-filters" role="search" aria-label="Filtrar especies" novalidate>
          <label class="field lf-text"><span>Buscar en el catálogo</span>
            <input type="search" name="text" value="${f.text}" autocomplete="off" placeholder="Nombre científico o común"></label>
          <label class="field"><span>Grupo</span><select name="group">${opt('', 'Todos', f.group)}${groups.map((g) => opt(g, g, f.group))}</select></label>
          <label class="field"><span>Categoría</span><select name="category">${opt('', 'Todas', f.category)}${cats.map((c) => opt(c, c === 'none' ? 'Sin evaluar' : c, f.category))}</select></label>
          <label class="field"><span>Nivel</span><select name="level">${opt('', 'Todos', f.level)}${['1', '2', '3', '4'].map((n) => opt(n, `Nivel ${n}`, f.level))}</select></label>
          <label class="field"><span>Ordenar por</span><select name="sort">${opt('name', 'Nombre', f.sort)}${opt('category', 'Categoría (CR → LC)', f.sort)}${opt('group', 'Grupo', f.sort)}</select></label>
        </form>
        <div class="chips" id="familyChip"></div>
        <p class="search-count" id="listCount" role="status" aria-live="polite"></p>
        <div class="table-head" aria-hidden="true">
          <span>Especie</span><span>Grupo</span><span>UICN</span><span>Sensibilidad</span><span></span></div>
        <ul class="rows species-rows" id="speciesList"></ul>` : html`
        <section class="placeholder">
          <h3>Este catálogo aún no tiene especies</h3>
          <p>${canAdd ? 'Búscalas en EIDOS con «Añadir especies».' : 'Quien lo administra aún no ha añadido ninguna.'}</p>
        </section>`}`);

    const add = $('#addSpeciesBtn', el);
    if (add) add.addEventListener('click', () => openAddSpecies(catalog, { onChanged: load }));
    // Sin servidor no hay buscador (EIDOS), pero sí se puede dar de alta una especie de usuario: se
    // guarda en el dispositivo y sync.js la sube (y la añade al catálogo) al volver la red.
    const create = $('#newSpeciesBtn', el);
    if (create) create.addEventListener('click', () => openSpeciesNew({ catalog, onAdd: async () => load() }));
    const form = $('.list-filters', el);
    if (!form) return;
    form.addEventListener('input', (ev) => {
      const t = ev.target;
      if (!t.name) return;
      f[t.name] = t.value;
      if (t.name !== 'text') store.set('biodex.speciesFilters', { group: f.group, category: f.category, level: f.level, sort: f.sort });
      paintRows();
    });
    form.addEventListener('submit', (ev) => ev.preventDefault());
    $('#speciesList', el).addEventListener('click', (ev) => {
      const mark = ev.target.closest('[data-mark]');
      if (mark) {
        const sp = data.species.find((s) => s.taxonID === mark.dataset.mark);
        if (sp) openMarkHere(sp, catalog);
        return;
      }
      const row = ev.target.closest('[data-taxon]');
      if (row) openSpeciesDetail(row.dataset.taxon, { onChanged: load });
    });
    $('#familyChip', el).addEventListener('click', (ev) => {
      if (!ev.target.closest('.chip')) return;
      f.family = '';
      paintRows();
      form.text.focus();
    });
    paintRows();
  }

  function paintRows() {
    const rows = filtered();
    const total = data.species.length;
    render($('#familyChip', el), f.family ? html`<button type="button" class="chip" aria-label="Quitar filtro familia ${f.family}"
      >Familia ${f.family} <span aria-hidden="true">×</span></button>` : html``);
    $('#listCount', el).textContent = rows.length === total ? plural(total, 'especie', 'especies')
      : `${rows.length} de ${plural(total, 'especie', 'especies')}`;
    const markable = canMark(data.catalog);
    render($('#speciesList', el), rows.length ? html`${rows.map((s) => html`
      <li class="sp-item"><button type="button" class="row sp iucn-edge-${iucnClass(s.iucnPrimary && s.iucnPrimary.code)}" data-taxon="${s.taxonID}">
        <span class="sp-name">${sciName(s)}
          ${s.vernacularName ? html`<span class="row-text">${s.vernacularName}</span>` : ''}</span>
        <span class="sp-group row-text">${s.taxonomicGroup || '—'}</span>
        <span class="sp-iucn">${iucnBadge(s.iucnPrimary)}</span>
        <span class="sp-level">${sensitivityBadge(s.sensitivity)}</span>
      </button>${markable ? html`<button type="button" class="sp-mark" data-mark="${s.taxonID}"
        >Marcar aquí<span class="sr-only"> ${sciNameText(s)}</span></button>` : ''}</li>`)}` : html`<li class="empty"><p class="muted">Ninguna especie cumple los filtros.</p></li>`);
  }

  const unsubscribe = subscribe((key) => {
    if (key === 'activeCatalog') { data = null; f.family = ''; load(); }
    if (key === 'online' && state.online && (device || !data)) load();
    if (key === 'download' && !data && state.activeCatalog && !state.downloads[state.activeCatalog.id]) load();
  });
  load();
  return () => { alive = false; unsubscribe(); };
}
