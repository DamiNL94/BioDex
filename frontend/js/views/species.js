// ================================================================================================
// Vista Especies: especies del catálogo activo (GET /api/catalogs/{id}) con filtro LOCAL por texto,
// grupo, categoría, nivel y familia (esta llega desde el árbol de «Datos del catálogo»), y orden por
// nombre, categoría (de CR a LC) o grupo. Tocar una fila abre la ficha.
// Cada fila lleva el filete lateral del color de su categoría UICN principal y «Marcar aquí» (F5).
// Sin servidor, las especies salen del catálogo descargado (IndexedDB).
// F11c: en el móvil (campo) solo se ve el buscador; Grupo, Categoría, Nivel y Orden van en una hoja que
// sube desde abajo («Filtros (n)», nivel de BioDexNav) y los filtros activos quedan como fichas que se
// quitan con un toque. En escritorio (estudio) los filtros van en línea y la ficha abierta pasa a la
// especie anterior/siguiente con ↑/↓.
// F11b: owner y custodio tienen el filtro «Fotos por revisar»: fotos de iNaturalist propuestas porque
// iNat usa otro nombre (sinónimo) para la especie. Aceptar la muestra; rechazar no la vuelve a proponer.
// ================================================================================================

import { apiGetJson, apiPostJson } from '../api.js';
import { state, subscribe, setActiveCatalog } from '../state.js';
import { html, render, $, $$, plural, sciName, sciNameText, store, isStudio } from '../util.js';

const Nav = window.BioDexNav;
import { remoteOrLocal, localCatalogData, localNote } from '../offline.js';
import { openMarkHere, canMark } from '../components/marks.js';
import { openSpeciesNew } from './species-new.js';
import { IUCN_CODES, iucnBadge, iucnClass, silhouette } from '../components/species-card.js';
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
    family: '', sort: saved.sort || 'name', photos: false };
  let lastOpened = null;    // taxonID de la ficha abierta desde la lista (↑/↓)
  let reviewStatus = '';   // aviso tras aceptar o rechazar una foto (lo lee el lector de pantalla)
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
      el.querySelector('#toCatalogs').addEventListener('click', (ev) => {
        ev.preventDefault();
        go('catalogos', { returnTo: { label: 'Especies', run: () => go('especies') } });
      });
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
          <p>Las especies de «${active.name}» se guardan al llevarlo al campo con red («Llevar al campo», en
            Catálogo). En cuanto vuelva la conexión se descargará solo.</p>
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
    const reviews = data.photoReviews;   // solo llega a owner y custodio, y con servidor
    if (!reviews || (!reviews.length && !f.photos)) f.photos = false;
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
          <button type="button" class="btn-secondary lf-toggle" aria-expanded="false" aria-controls="lfSheet">Filtros</button>
          <div class="lf-sheet" id="lfSheet" role="group" aria-label="Filtros y orden">
          <div class="lf-sheet-head"><h3>Filtros</h3></div>
          <label class="field"><span>Grupo</span><select name="group">${opt('', 'Todos', f.group)}${groups.map((g) => opt(g, g, f.group))}</select></label>
          <label class="field"><span>Categoría</span><select name="category">${opt('', 'Todas', f.category)}${cats.map((c) => opt(c, c === 'none' ? 'Sin evaluar' : c, f.category))}</select></label>
          <label class="field"><span>Nivel</span><select name="level">${opt('', 'Todos', f.level)}${['1', '2', '3', '4'].map((n) => opt(n, `Nivel ${n}`, f.level))}</select></label>
          <label class="field"><span>Ordenar por</span><select name="sort">${opt('name', 'Nombre', f.sort)}${opt('category', 'Categoría (CR → LC)', f.sort)}${opt('group', 'Grupo', f.sort)}</select></label>
          <button type="button" class="btn-primary lf-done">Ver especies</button>
          </div>
        </form>
        ${reviews && (reviews.length || f.photos) ? html`<p class="photo-review-toggle">
          <button type="button" class="chip" id="photoReviewBtn" aria-pressed="${f.photos ? 'true' : 'false'}"
            >Fotos por revisar (${reviews.length})</button></p>` : ''}
        <div class="chips" id="familyChip"></div>
        <p class="search-count" id="listCount" role="status" aria-live="polite"></p>
        <div class="table-head" aria-hidden="true">
          <span>Especie</span><span>Grupo</span><span>UICN</span><span>Sensibilidad</span><span></span></div>
        <ul class="rows species-rows" id="speciesList"></ul>
        <section class="photo-reviews" id="photoReviews" aria-labelledby="pr-title" hidden></section>` : html`
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
    // Hoja de filtros (móvil): nivel de BioDexNav, así «atrás» la cierra.
    const sheet = $('#lfSheet', el);
    const toggle = $('.lf-toggle', el);
    const closeSheet = () => { sheet.classList.remove('is-open'); toggle.setAttribute('aria-expanded', 'false'); };
    toggle.addEventListener('click', () => {
      sheet.classList.add('is-open');
      toggle.setAttribute('aria-expanded', 'true');
      Nav.open('filtros-especies', closeSheet);
      const first = $('select', sheet);
      if (first) first.focus();
    });
    $('.lf-done', el).addEventListener('click', () => { if (!Nav.close('filtros-especies')) closeSheet(); toggle.focus(); });
    $('#speciesList', el).addEventListener('click', (ev) => {
      const mark = ev.target.closest('[data-mark]');
      if (mark) {
        const sp = data.species.find((s) => s.taxonID === mark.dataset.mark);
        if (sp) openMarkHere(sp, catalog);
        return;
      }
      const row = ev.target.closest('[data-taxon]');
      if (row) {
        lastOpened = row.dataset.taxon;
        openSpeciesDetail(row.dataset.taxon, { onChanged: load, step: isStudio() ? stepFrom : undefined,
          onOpen: (id) => { lastOpened = id; } });
      }
    });
    const prBtn = $('#photoReviewBtn', el);
    if (prBtn) prBtn.addEventListener('click', () => {
      f.photos = !f.photos;
      prBtn.setAttribute('aria-pressed', f.photos ? 'true' : 'false');
      reviewStatus = '';
      paintRows();
    });
    $('#photoReviews', el).addEventListener('click', (ev) => {
      const b = ev.target.closest('[data-decision]');
      if (b) decidePhoto(b.dataset.taxon, b.dataset.decision);
    });
    // La foto propuesta no carga (iNat caído): silueta genérica en su lugar, nunca el icono roto.
    $('#photoReviews', el).addEventListener('error', (ev) => {
      const fig = ev.target instanceof HTMLImageElement && ev.target.closest('.pr-photo');
      if (fig) render(fig, silhouette(null));
    }, true);
    $('#familyChip', el).addEventListener('click', (ev) => {
      const chip = ev.target.closest('.chip');
      if (!chip) return;
      f[chip.dataset.clear] = '';
      if (chip.dataset.clear !== 'family') {
        const sel = form.elements[chip.dataset.clear];
        if (sel) sel.value = '';
        store.set('biodex.speciesFilters', { group: f.group, category: f.category, level: f.level, sort: f.sort });
      }
      paintRows();
      form.text.focus();
    });
    paintRows();
  }

  /** Lista de fotos por revisar (F11b), en lugar de la tabla mientras el filtro está activo. */
  function paintReviews() {
    const box = $('#photoReviews', el);
    const list = data.photoReviews || [];
    render(box, html`
      <h3 id="pr-title">Fotos por revisar</h3>
      <p class="hint">iNaturalist llama a estas especies con otro nombre. Acepta la foto solo si es la misma especie;
        si dudas, recházala: mejor sin foto que con la de otra especie. La decisión vale para todos los catálogos.</p>
      <p class="search-count" role="status" aria-live="polite">${reviewStatus}</p>
      ${list.length ? html`<ul class="rows pr-list">${list.map((r) => html`
        <li class="pr-item">
          <figure class="pr-photo">
            <img src="${r.photo}" loading="lazy" decoding="async" alt="Foto propuesta de iNaturalist para ${r.canonicalName}">
            <figcaption class="credit-inat">Foto: ${r.photoCredit.text}${r.photoCredit.license ? html` · ${r.photoCredit.license}` : ''} ·
              <a href="${r.photoCredit.url}" target="_blank" rel="noopener noreferrer">iNaturalist<span class="sr-only"> (se abre en otra pestaña)</span></a></figcaption>
          </figure>
          <div class="pr-body">
            <p><span class="muted">EIDOS:</span> ${sciName(r)}${r.vernacularName ? html` <span class="row-text">${r.vernacularName}</span>` : ''}</p>
            <p><span class="muted">iNaturalist:</span> <a href="${r.inatTaxonUrl}" target="_blank" rel="noopener noreferrer"><i>${r.inatName}</i><span
              class="sr-only"> en iNaturalist (se abre en otra pestaña)</span></a></p>
            <div class="form-actions">
              <button type="button" class="btn-primary" data-taxon="${r.taxonID}" data-decision="accept"
                >Aceptar<span class="sr-only"> la foto para ${r.canonicalName}</span></button>
              <button type="button" class="btn-secondary" data-taxon="${r.taxonID}" data-decision="reject"
                >Rechazar<span class="sr-only"> la foto para ${r.canonicalName}</span></button>
            </div>
          </div>
        </li>`)}</ul>` : html`<p class="muted">No quedan fotos por revisar en este catálogo.</p>`}`);
  }

  async function decidePhoto(taxonID, decision) {
    const item = (data.photoReviews || []).find((r) => r.taxonID === taxonID);
    if (!item) return;
    for (const b of $$('[data-decision]', $('#photoReviews', el))) b.disabled = true;
    try {
      const res = await apiPostJson(`/species/${encodeURIComponent(taxonID)}/photo-review`,
        { catalogId: data.catalog.id, decision });
      if (!alive) return;
      data.photoReviews = data.photoReviews.filter((r) => r.taxonID !== taxonID);
      const sp = data.species.find((s) => s.taxonID === taxonID);
      if (sp && res.species) Object.assign(sp, { thumbnail: res.species.thumbnail, photoCredit: res.species.photoCredit });
      reviewStatus = `${decision === 'accept' ? 'Foto aceptada' : 'Foto rechazada'} para ${item.canonicalName}.`;
    } catch (e) {
      if (!alive) return;
      reviewStatus = e.status === 409 ? 'Esa foto ya no estaba pendiente (la ha revisado otra persona).' : e.message;
      if (e.status === 409) data.photoReviews = data.photoReviews.filter((r) => r.taxonID !== taxonID);
    }
    const btn = $('#photoReviewBtn', el);
    if (btn) btn.textContent = `Fotos por revisar (${data.photoReviews.length})`;
    paintRows();
    const next = $('#photoReviews [data-decision]', el) || btn;
    if (next) next.focus();
  }

  function paintRows() {
    const reviewing = f.photos && !!data.photoReviews;
    for (const sel of ['.table-head', '#speciesList', '#listCount', '#familyChip']) {
      const n = $(sel, el);
      if (n) n.hidden = reviewing;
    }
    $('#photoReviews', el).hidden = !reviewing;
    if (reviewing) { paintReviews(); return; }
    const rows = filtered();
    const total = data.species.length;
    const chips = [
      ['family', f.family && `Familia ${f.family}`],
      ['group', f.group],
      ['category', f.category && (f.category === 'none' ? 'Sin evaluar' : `UICN ${f.category}`)],
      ['level', f.level && `Nivel ${f.level}`],
    ].filter(([, label]) => label);
    render($('#familyChip', el), html`${chips.map(([k, label]) => html`<button type="button" class="chip" data-clear="${k}"
      aria-label="Quitar filtro ${label}">${label} <span aria-hidden="true">×</span></button>`)}`);
    const n = chips.filter(([k]) => k !== 'family').length;
    const toggle = $('.lf-toggle', el);
    if (toggle) toggle.textContent = n ? `Filtros (${n})` : 'Filtros';
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
        ><span class="lbl-studio">Marcar aquí</span><span class="lbl-field">Marcar</span><span class="sr-only"> ${sciNameText(s)}</span></button>` : ''}</li>`)}` : html`<li class="empty"><p class="muted">Ninguna especie cumple los filtros.</p></li>`);
  }

  // Ficha abierta desde la lista: anterior/siguiente según el orden y los filtros que se ven ahora.
  // step(dir) se consulta también para activar/desactivar los botones: no mueve nada por sí solo;
  // lastOpened lo actualiza la ficha nueva al abrirse (onOpen).
  function stepFrom(dir) {
    if (!data) return null;
    const rows = filtered();
    const i = rows.findIndex((s) => s.taxonID === lastOpened);
    if (i === -1) return null;
    const next = rows[i + dir];
    return next ? next.taxonID : null;
  }

  const unsubscribe = subscribe((key) => {
    if (key === 'activeCatalog') { data = null; f.family = ''; load(); }
    if (key === 'online' && state.online && (device || !data)) load();
    if (key === 'download' && !data && state.activeCatalog && !state.downloads[state.activeCatalog.id]) load();
  });
  load();
  return () => { alive = false; unsubscribe(); if (Nav.has('filtros-especies')) Nav.close('filtros-especies'); };
}
