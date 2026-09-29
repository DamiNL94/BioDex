// ================================================================================================
// catalog-builder.js — constructor de catálogos y «Añadir especies» a uno existente.
// ------------------------------------------------------------------------------------------------
// Constructor («Nuevo catálogo»): nombre, descripción, buscador y la lista de la selección. La
// selección es un BORRADOR local (memoria + localStorage `biodex.catalogDraft`, para no perderla al
// recargar) hasta «Guardar en Mis catálogos» → POST /api/catalogs con taxonIDs, de una vez. Salir con
// cambios sin guardar pide confirmación con un diálogo propio.
// Catálogo existente: el mismo buscador, pero cada «Añadir» se guarda al momento
// (POST /catalogs/{id}/species), y «Quitar» solo para el owner.
// En el móvil el constructor va en pestañas «Buscar · Seleccionadas (n)»; en escritorio, lado a lado.
// ================================================================================================

import { apiGetJson, apiPostJson, apiDeleteJson } from '../api.js';
import { state, setActiveCatalog } from '../state.js';
import { html, render, $, $$, store, plural, sciName, sciNameText } from '../util.js';
import { openOverlay, confirmDialog } from '../components/overlay.js';
import { iucnBadge } from '../components/species-card.js';
import { mountSearch } from './search.js';
import { openSpeciesDetail } from './species-detail.js';
import { openSpeciesNew } from './species-new.js';

const DRAFT_KEY = 'biodex.catalogDraft';
const SAVE_TIMEOUT_MS = 180000;   // el servidor trae la ficha de EIDOS de cada especie (~1,5 s cada una)
const ADD_ROLES = ['owner', 'custodian', 'editor'];

/** Lo mínimo de una tarjeta que hace falta para pintar la selección y guardarla. */
function slim(sp) {
  return {
    taxonID: sp.taxonID, source: sp.source, scientificName: sp.scientificName, canonicalName: sp.canonicalName,
    scientificNameAuthorship: sp.scientificNameAuthorship, vernacularName: sp.vernacularName,
    taxonomicGroup: sp.taxonomicGroup, iucnPrimary: sp.iucnPrimary || null,
  };
}

// ================================================================================================
// Constructor
// ================================================================================================

/** Abre el constructor. `onSaved(catalog)` tras guardar. */
export function openCatalogBuilder({ onSaved } = {}) {
  const saved = store.get(DRAFT_KEY, null);
  const draft = {
    name: (saved && saved.name) || '',
    description: (saved && saved.description) || '',
    selected: new Map(((saved && saved.selected) || []).map((sp) => [sp.taxonID, sp])),
  };
  let search = null;
  let saving = false;
  let tab = 'search';

  const dirty = () => Boolean(draft.name.trim() || draft.description.trim() || draft.selected.size);
  const persist = () => {
    if (dirty()) store.set(DRAFT_KEY, { name: draft.name, description: draft.description, selected: [...draft.selected.values()] });
    else store.remove(DRAFT_KEY);
  };

  const ov = openOverlay({
    key: 'constructor', title: 'Nuevo catálogo', variant: 'full', className: 'builder-overlay',
    isDirty: () => saving || dirty(),
    confirmClose: () => {
      if (saving) return Promise.resolve(false);   // se está guardando: no se cierra a medias
      return confirmDialog({
        key: 'constructor-salir', title: '¿Salir sin guardar?',
        text: html`<p>El catálogo aún no está en Mis catálogos${draft.selected.size
          ? html` y tiene ${plural(draft.selected.size, 'especie seleccionada', 'especies seleccionadas')}` : ''}.
          Si sales, se descarta el borrador.</p>`,
        confirmLabel: 'Descartar borrador', cancelLabel: 'Seguir editando', danger: true,
      }).then((ok) => { if (ok) store.remove(DRAFT_KEY); return ok; });
    },
    onClose: () => { if (search) search.destroy(); },
  });

  render(ov.body, html`
    <div class="builder">
      <form class="builder-meta" novalidate>
        <label class="field"><span>Nombre del catálogo</span>
          <input name="name" required maxlength="120" value="${draft.name}" placeholder="Flora amenazada de Sierra Nevada"></label>
        <label class="field"><span>Descripción <em>(opcional)</em></span>
          <textarea name="description" rows="2">${draft.description}</textarea></label>
      </form>
      ${saved && saved.selected && saved.selected.length ? html`<p class="guard-band info" role="status">
        Borrador recuperado: ${plural(saved.selected.length, 'especie', 'especies')} sin guardar.</p>` : ''}
      <div class="builder-tabs" role="tablist" aria-label="Constructor">
        <button type="button" role="tab" id="bt-search" aria-controls="bp-search" data-tab="search">Buscar</button>
        <button type="button" role="tab" id="bt-sel" aria-controls="bp-sel" data-tab="sel">Seleccionadas (0)</button>
      </div>
      <div class="builder-grid">
        <section class="builder-search" id="bp-search" role="tabpanel" aria-labelledby="bt-search"></section>
        <section class="builder-selection" id="bp-sel" role="tabpanel" aria-labelledby="bt-sel">
          <div class="builder-sel-head">
            <h3 class="sel-title">Seleccionadas</h3>
            <button type="button" class="link-btn" data-new-species>Crear especie</button>
          </div>
          <ul class="rows sel-list" aria-live="polite"></ul>
        </section>
      </div>
      <footer class="builder-foot">
        <p class="form-error" role="alert" hidden></p>
        <p class="builder-status muted" role="status"></p>
        <button type="button" class="btn-primary" data-save>Guardar en Mis catálogos</button>
      </footer>
    </div>`);

  const form = $('.builder-meta', ov.body);
  const selList = $('.sel-list', ov.body);
  const errEl = $('.builder-foot .form-error', ov.body);
  const statusEl = $('.builder-status', ov.body);
  const saveBtn = $('[data-save]', ov.body);
  const builderEl = $('.builder', ov.body);

  form.addEventListener('input', () => {
    draft.name = form.name.value;
    draft.description = form.description.value;
    persist();
  });
  form.addEventListener('submit', (ev) => ev.preventDefault());

  // --- Pestañas (solo se ven en el móvil) ----------------------------------------------------------
  function setTab(next, focus = false) {
    tab = next;
    builderEl.dataset.tab = tab;
    $$('[role="tab"]', ov.body).forEach((b) => {
      const on = b.dataset.tab === tab;
      b.setAttribute('aria-selected', String(on));
      b.tabIndex = on ? 0 : -1;
      if (on && focus) b.focus();
    });
  }
  $('.builder-tabs', ov.body).addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-tab]');
    if (b) setTab(b.dataset.tab);
  });
  $('.builder-tabs', ov.body).addEventListener('keydown', (ev) => {
    if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') setTab(tab === 'search' ? 'sel' : 'search', true);
  });

  // --- Selección -----------------------------------------------------------------------------------
  function paintSelection() {
    const n = draft.selected.size;
    $('#bt-sel', ov.body).textContent = `Seleccionadas (${n})`;
    $('.sel-title', ov.body).textContent = `Seleccionadas (${n})`;
    if (!n) {
      render(selList, html`<li class="empty"><p class="muted">Aún no has añadido especies. Búscalas y pulsa «Añadir»;
        se guardan aquí como borrador hasta que guardes el catálogo.</p></li>`);
      return;
    }
    const sorted = [...draft.selected.values()].sort((a, b) => sciNameText(a).localeCompare(sciNameText(b)));
    render(selList, html`${sorted.map((sp) => html`
      <li class="row sel-row" data-taxon="${sp.taxonID}">
        <button type="button" class="sel-open" data-open>${sciName(sp)}</button>
        <span class="row-meta">${sp.vernacularName ? html`<span class="row-text">${sp.vernacularName}</span>` : ''}</span>
        <span class="row-meta">${iucnBadge(sp.iucnPrimary, { primary: true })}</span>
        <span class="row-meta"><span class="badge">${sp.taxonomicGroup || '—'}</span></span>
        <button type="button" class="link-btn sel-remove" data-remove>Quitar<span class="sr-only"> ${sciNameText(sp)}</span></button>
      </li>`)}`);
  }
  selList.addEventListener('click', (ev) => {
    const row = ev.target.closest('.sel-row');
    if (!row) return;
    const id = row.dataset.taxon;
    if (ev.target.closest('[data-remove]')) {
      const next = row.nextElementSibling || row.previousElementSibling;
      draft.selected.delete(id);
      persist();
      paintSelection();
      if (search) search.refreshActions();
      const focus = next && selList.querySelector(`.sel-row[data-taxon="${CSS.escape(next.dataset.taxon)}"] [data-remove]`);
      (focus || $('[data-new-species]', ov.body)).focus();
    } else if (ev.target.closest('[data-open]')) openSpeciesDetail(id);
  });

  function addToSelection(sp) {
    draft.selected.set(sp.taxonID, slim(sp));
    persist();
    paintSelection();
    if (search) search.refreshActions();
  }

  $('[data-new-species]', ov.body).addEventListener('click', () => openSpeciesNew({ onAdd: async (sp) => addToSelection(sp) }));

  search = mountSearch($('#bp-search', ov.body), {
    actionFor: (sp) => (draft.selected.has(sp.taxonID) ? 'added' : 'add'),
    canRemove: () => true,   // el borrador es de quien lo construye
    onAdd: async (sp) => addToSelection(sp),
    onRemove: async (sp) => { draft.selected.delete(sp.taxonID); persist(); paintSelection(); },
    onOpen: (sp) => openSpeciesDetail(sp.taxonID),
    onCreate: (q) => openSpeciesNew({ initialName: q, onAdd: async (sp) => addToSelection(sp) }),
  });

  // --- Guardar -------------------------------------------------------------------------------------
  saveBtn.addEventListener('click', async () => {
    errEl.hidden = true;
    const name = draft.name.trim();
    if (!name) {
      errEl.textContent = 'El catálogo necesita un nombre.';
      errEl.hidden = false;
      form.name.focus();
      return;
    }
    if (!state.online) {
      errEl.textContent = 'Sin conexión: el borrador se queda en este dispositivo; guárdalo cuando vuelvas a tener red.';
      errEl.hidden = false;
      return;
    }
    saving = true;
    saveBtn.disabled = true;
    const n = draft.selected.size;
    statusEl.textContent = n ? `Guardando ${plural(n, 'especie', 'especies')}: se trae de EIDOS la ficha de cada una…` : 'Guardando…';
    try {
      const { catalog } = await apiPostJson('/catalogs', {
        name, description: draft.description.trim() || null, taxonIDs: [...draft.selected.keys()],
      }, { timeoutMs: SAVE_TIMEOUT_MS });
      store.remove(DRAFT_KEY);
      saving = false;
      ov.close(true);
      if (onSaved) onSaved(catalog);
      const makeActive = await confirmDialog({
        key: 'constructor-activo', title: 'Catálogo guardado',
        text: html`<p>«${catalog.name}» ya está en Mis catálogos con ${plural(catalog.speciesCount || 0, 'especie', 'especies')}.</p>
          <p>¿Lo marcas como catálogo activo? Será el que veas en Especies y, más adelante, en el mapa.</p>`,
        confirmLabel: 'Marcar como activo', cancelLabel: 'Ahora no',
      });
      if (makeActive) setActiveCatalog(catalog);
    } catch (e) {
      saving = false;
      saveBtn.disabled = false;
      statusEl.textContent = '';
      errEl.textContent = e.status === 404 && e.data && e.data.missing
        ? `Algunas especies ya no existen en EIDOS (${e.data.missing.join(', ')}). Quítalas y vuelve a guardar.` : e.message;
      errEl.hidden = false;
    }
  });

  setTab('search');
  paintSelection();
  (draft.name ? search : form.name).focus();
  return ov;
}

// ================================================================================================
// Añadir especies a un catálogo existente
// ================================================================================================

/** Abre el buscador sobre `catalog` ({id, name, role}). `onChanged()` tras cada alta o baja. */
export function openAddSpecies(catalog, { onChanged } = {}) {
  let search = null;
  let current = null;   // Set de taxonID del catálogo
  const canAdd = ADD_ROLES.includes(catalog.role);
  const ov = openOverlay({ key: 'anadir-especies', title: `Añadir especies · ${catalog.name}`, variant: 'full',
    className: 'builder-overlay', onClose: () => { if (search) search.destroy(); } });
  render(ov.body, html`
    <div class="builder builder-add">
      <p class="builder-status muted" role="status">Cada especie que añadas se guarda al momento en el catálogo.</p>
      <p class="form-error" role="alert" hidden></p>
      <div class="builder-search"></div>
    </div>`);
  const statusEl = $('.builder-status', ov.body);
  const errEl = $('.form-error', ov.body);
  const base = `/catalogs/${encodeURIComponent(catalog.id)}/species`;

  if (!canAdd) {
    render($('.builder-search', ov.body), html`<p class="guard-band warn">Tu rol en este catálogo no permite añadir especies.</p>`);
    return ov;
  }

  function fail(e) {
    errEl.textContent = e.message;
    errEl.hidden = false;
    throw e;
  }

  apiGetJson('/catalogs/' + encodeURIComponent(catalog.id)).then((data) => {
    if (!ov.isOpen()) return;
    current = new Set(data.species.map((s) => s.taxonID));
    const owner = data.catalog.role === 'owner';
    search = mountSearch($('.builder-search', ov.body), {
      actionFor: (sp) => (current.has(sp.taxonID) ? 'added' : 'add'),
      canRemove: () => owner,
      onAdd: async (sp) => {
        errEl.hidden = true;
        try {
          const res = await apiPostJson(base, { taxonID: sp.taxonID }, { timeoutMs: 30000 });
          current.add(sp.taxonID);
          statusEl.textContent = `Añadida ${sciNameText(sp)} · nivel sugerido ${res.sensitivity.level}.`;
          if (onChanged) onChanged();
        } catch (e) { fail(e); }
      },
      onRemove: async (sp) => {
        errEl.hidden = true;
        try {
          await apiDeleteJson(`${base}/${encodeURIComponent(sp.taxonID)}`);
          current.delete(sp.taxonID);
          statusEl.textContent = `Quitada ${sciNameText(sp)}.`;
          if (onChanged) onChanged();
        } catch (e) { fail(e); }
      },
      onOpen: (sp) => openSpeciesDetail(sp.taxonID, { onChanged }),
      onCreate: (q) => openSpeciesNew({
        initialName: q,
        onAdd: async (sp) => {
          await apiPostJson(base, { taxonID: sp.taxonID }, { timeoutMs: 30000 });
          current.add(sp.taxonID);
          search.refreshActions();
          statusEl.textContent = `Añadida ${sciNameText(sp)}.`;
          if (onChanged) onChanged();
        },
      }),
    });
    search.focus();
  }).catch((e) => {
    if (ov.isOpen()) render($('.builder-search', ov.body), html`<p class="form-error">${e.message}</p>`);
  });
  return ov;
}
