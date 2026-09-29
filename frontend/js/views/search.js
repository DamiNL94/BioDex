// ================================================================================================
// search.js — buscador de especies en EIDOS (GET /api/species). SOLO CON CONEXIÓN: es la parte que se
// hace en el ordenador antes de salir al campo. Lo usan el constructor de catálogos y «Añadir especies».
// ------------------------------------------------------------------------------------------------
// · Texto ≥ 2 caracteres; se espera 400 ms tras teclear y se CANCELA la petición anterior
//   (AbortController): EIDOS tarda ~1,5 s y una respuesta vieja no puede pisar a la nueva.
// · Filtros con las opciones de /api/species/filters. Grupo, origen y ámbito son de un valor (EIDOS
//   los filtra con eq.); marco legal y categoría admiten varios (la API los recibe con comas).
// · Qué hacer con cada resultado (añadir, quitar, abrir la ficha) lo decide quien monta el buscador.
// ================================================================================================

import { apiGetJson, getSpeciesFilters } from '../api.js';
import { state, subscribe } from '../state.js';
import { html, render, $, debounce, plural } from '../util.js';
import { speciesCard, bindSpeciesCards, cardSkeletons } from '../components/species-card.js';

const PAGE = 20;
const SEARCH_TIMEOUT_MS = 30000;   // con filtros legales el servidor pide hasta 5 estados a EIDOS
const MIN_CHARS = 2;
const desktop = window.matchMedia('(min-width: 900px)');
let seq = 0;

/**
 * Monta el buscador en `el`.
 *   actionFor(sp) → 'add' | 'added' | null     estado del botón de cada tarjeta
 *   canRemove(sp) → boolean                    «Quitar» en las añadidas
 *   onAdd(sp) / onRemove(sp) → Promise          la tarjeta muestra «Añadiendo…» mientras tanto
 *   onOpen(sp)                                  abrir la ficha
 *   onCreate(q)                                 «Crear especie» desde «Sin resultados»
 * Devuelve { destroy, refreshActions, focus }.
 */
export function mountSearch(el, { initialQuery = '', actionFor = () => 'add', canRemove = () => false,
  onAdd, onRemove, onOpen, onCreate } = {}) {
  const uid = `srch${++seq}`;
  const filters = { group: '', origin: '', scope: '', legal: new Set(), category: new Set() };
  let options = null;          // respuesta de /species/filters
  let items = [];
  let byId = new Map();
  let total = 0;
  let notice = null;           // aviso de filtros legales pendientes / búsqueda truncada
  let status = 'idle';         // idle | loading | ready | more | error
  let errorText = '';
  let controller = null;
  let alive = true;
  const busy = new Set();

  render(el, html`
    <div class="search">
      <div class="search-bar">
        <label class="field search-field" for="${uid}-q"><span>Buscar en EIDOS</span>
          <input id="${uid}-q" type="search" name="q" autocomplete="off" spellcheck="false" enterkeyhint="search"
                 placeholder="Nombre científico o común (mín. 2 letras)" value="${initialQuery}"></label>
        <button type="button" class="btn-secondary filters-toggle" aria-expanded="false" aria-controls="${uid}-filters">Filtros</button>
      </div>
      <p class="guard-band warn search-offline" role="status" hidden>El buscador necesita conexión. Prepara tus catálogos antes de salir al campo.</p>
      <div class="chips" aria-label="Filtros activos"></div>
      <div class="search-layout">
        <aside class="search-filters" id="${uid}-filters" aria-label="Filtros">
          <div class="search-filters-head"><h3>Filtros</h3>
            <button type="button" class="link-btn" data-clear>Quitar todos</button></div>
          <div class="search-filters-body"><p class="muted">Cargando filtros…</p></div>
          <button type="button" class="btn-primary filters-done">Ver resultados</button>
        </aside>
        <div class="search-results">
          <p class="search-count" aria-live="polite" role="status"></p>
          <p class="search-notice" hidden></p>
          <div class="cards" aria-busy="false"></div>
          <div class="search-more"></div>
        </div>
      </div>
    </div>`);

  const input = $('input[name="q"]', el);
  const toggle = $('.filters-toggle', el);
  const panel = $('.search-filters', el);
  const panelBody = $('.search-filters-body', el);
  const chipsEl = $('.chips', el);
  const countEl = $('.search-count', el);
  const noticeEl = $('.search-notice', el);
  const cardsEl = $('.cards', el);
  const moreEl = $('.search-more', el);
  const offlineEl = $('.search-offline', el);

  // --- Filtros --------------------------------------------------------------------------------------
  function activeCount() {
    return (filters.group ? 1 : 0) + (filters.origin ? 1 : 0) + (filters.scope ? 1 : 0) + filters.legal.size + filters.category.size;
  }

  function label(list, value) {
    const o = options && options[list] && options[list].find((x) => x.value === value);
    return o ? (o.short || o.label) : value;
  }

  function paintFilters() {
    if (!options) return;
    const select = (key, list, all) => html`
      <label class="field"><span>${all}</span>
        <select name="${key}">
          <option value="">Todos</option>
          ${options[list].map((o) => html`<option value="${o.value}" ${filters[key] === o.value ? html`selected` : ''}>${o.label}</option>`)}
        </select></label>`;
    const checks = (key, list, legend, withCode) => html`
      <fieldset class="checks"><legend>${legend}</legend>
        ${options[list].map((o) => html`<label class="check">
          <input type="checkbox" name="${key}" value="${o.value}" ${filters[key].has(o.value) ? html`checked` : ''}>
          <span>${withCode ? html`<span class="badge iucn iucn-${o.value}">${o.value}</span> ` : ''}${o.label}</span></label>`)}
      </fieldset>`;
    render(panelBody, html`
      ${select('group', 'groups', 'Grupo taxonómico')}
      ${select('origin', 'origins', 'Origen')}
      ${select('scope', 'scopes', 'Ámbito')}
      ${checks('legal', 'legal', 'Marco legal', false)}
      ${checks('category', 'iucn', 'Categoría UICN', true)}`);
  }

  function paintChips() {
    const chip = (kind, value, text) => html`<button type="button" class="chip" data-kind="${kind}" data-value="${value}"
      aria-label="Quitar filtro ${text}">${text} <span aria-hidden="true">×</span></button>`;
    const list = [];
    if (filters.group) list.push(chip('group', filters.group, label('groups', filters.group)));
    if (filters.origin) list.push(chip('origin', filters.origin, label('origins', filters.origin)));
    if (filters.scope) list.push(chip('scope', filters.scope, label('scopes', filters.scope)));
    filters.legal.forEach((v) => list.push(chip('legal', v, label('legal', v))));
    filters.category.forEach((v) => list.push(chip('category', v, `UICN ${v}`)));
    render(chipsEl, html`${list}`);
    const n = activeCount();
    toggle.textContent = n ? `Filtros (${n})` : 'Filtros';
  }

  panelBody.addEventListener('change', (ev) => {
    const t = ev.target;
    if (t.tagName === 'SELECT') filters[t.name] = t.value;
    else if (t.type === 'checkbox') filters[t.name][t.checked ? 'add' : 'delete'](t.value);
    else return;
    paintChips();
    search(true);
  });
  chipsEl.addEventListener('click', (ev) => {
    const chip = ev.target.closest('.chip');
    if (!chip) return;
    const { kind, value } = chip.dataset;
    if (filters[kind] instanceof Set) filters[kind].delete(value); else filters[kind] = '';
    // El foco no se pierde al desaparecer la ficha: pasa a la siguiente o al campo de búsqueda.
    const next = chip.nextElementSibling || chip.previousElementSibling;
    paintFilters();
    paintChips();
    const again = next && chipsEl.querySelector(`.chip[data-kind="${next.dataset.kind}"][data-value="${CSS.escape(next.dataset.value)}"]`);
    (again || input).focus();
    search(true);
  });
  $('[data-clear]', el).addEventListener('click', () => {
    filters.group = filters.origin = filters.scope = '';
    filters.legal.clear();
    filters.category.clear();
    paintFilters();
    paintChips();
    search(true);
  });

  // Móvil: los filtros son una hoja desplegable, con su nivel en la pila de «atrás».
  const navKey = `${uid}-filtros`;
  function setPanel(open) {
    panel.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
  }
  toggle.addEventListener('click', () => {
    const open = !panel.classList.contains('is-open');
    setPanel(open);
    if (open) {
      window.BioDexNav.open(navKey, () => { setPanel(false); toggle.focus(); });
      const first = panel.querySelector('select, input');
      if (first) first.focus();
    } else window.BioDexNav.close(navKey);
  });
  $('.filters-done', el).addEventListener('click', () => window.BioDexNav.close(navKey));
  const onDesktop = () => { if (desktop.matches) window.BioDexNav.close(navKey); };
  desktop.addEventListener('change', onDesktop);

  getSpeciesFilters().then((data) => {
    if (!alive) return;
    options = data;
    paintFilters();
    paintChips();
  }).catch((e) => {
    if (alive) render(panelBody, html`<p class="muted">No se pudieron cargar los filtros: ${e.message}</p>`);
  });

  // --- Búsqueda -------------------------------------------------------------------------------------
  function query() {
    return input.value.trim().replace(/\s+/g, ' ');
  }

  function params(offset) {
    const p = new URLSearchParams({ q: query(), limit: String(PAGE), offset: String(offset) });
    if (filters.group) p.set('group', filters.group);
    if (filters.origin) p.set('origin', filters.origin);
    if (filters.scope) p.set('scope', filters.scope);
    if (filters.legal.size) p.set('legal', [...filters.legal].join(','));
    if (filters.category.size) p.set('category', [...filters.category].join(','));
    return p.toString();
  }

  async function search(reset) {
    debounced.cancel();
    if (controller) controller.abort();
    controller = null;
    if (!state.online) { paint(); return; }
    const q = query();
    if (q.length < MIN_CHARS) {
      status = 'idle';
      items = []; byId = new Map(); total = 0; notice = null;
      paint();
      return;
    }
    const ctrl = new AbortController();
    controller = ctrl;
    if (reset) { items = []; byId = new Map(); total = 0; notice = null; status = 'loading'; }
    else status = 'more';
    paint();
    try {
      const data = await apiGetJson('/species?' + params(reset ? 0 : items.length),
        { signal: ctrl.signal, timeoutMs: SEARCH_TIMEOUT_MS });
      if (!alive || controller !== ctrl) return;
      for (const sp of data.items) {
        if (byId.has(sp.taxonID)) continue;
        byId.set(sp.taxonID, sp);
        items.push(sp);
      }
      total = data.total;
      const pending = data.items.some((sp) => sp.detailPending);
      const filtered = filters.legal.size || filters.category.size;
      if (pending || (data.truncated && filtered)) notice = 'Algunos filtros legales se están completando; vuelve a buscar en unos segundos.';
      else if (data.truncated) notice = 'Búsqueda muy amplia: EIDOS devuelve solo las primeras coincidencias. Afina el texto.';
      else if (reset) notice = null;
      status = 'ready';
    } catch (e) {
      if (e.aborted || !alive || controller !== ctrl) return;
      status = 'error';
      errorText = e.status === 0 && !state.online ? 'Sin conexión.' : e.message;
    } finally {
      if (controller === ctrl) controller = null;
    }
    paint();
  }
  const debounced = debounce(() => search(true), 400);

  input.addEventListener('input', () => debounced());
  input.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); search(true); } });

  // --- Pintado --------------------------------------------------------------------------------------
  function cardFor(sp) {
    const action = busy.has(sp.taxonID) ? 'busy' : actionFor(sp);
    return speciesCard(sp, { action, canRemove: canRemove(sp) });
  }

  function paint() {
    if (!alive) return;
    const online = state.online;
    offlineEl.hidden = online;
    input.disabled = !online;
    toggle.disabled = !online;
    el.querySelector('.search').classList.toggle('is-offline', !online);
    noticeEl.hidden = !notice || !online;
    noticeEl.textContent = notice || '';
    cardsEl.setAttribute('aria-busy', String(status === 'loading'));

    if (!online) {
      countEl.textContent = '';
      render(cardsEl, html``);
      render(moreEl, html``);
      return;
    }
    const q = query();
    if (status === 'idle') {
      countEl.textContent = '';
      render(cardsEl, html`<div class="search-empty muted"><p>${q.length ? 'Escribe al menos 2 letras.'
        : 'Escribe un nombre científico o común: «aguila», «Quercus», «lince»…'}</p>
        ${onCreate ? html`<p>¿No está en EIDOS? <button type="button" class="link-btn" data-create>Crear especie</button></p>` : ''}</div>`);
      render(moreEl, html``);
      return;
    }
    if (status === 'loading') {
      countEl.textContent = 'Buscando…';
      render(cardsEl, cardSkeletons(3));
      render(moreEl, html``);
      return;
    }
    if (status === 'error') {
      countEl.textContent = '';
      render(cardsEl, html`<div class="search-empty"><p class="form-error">${errorText}</p>
        <button type="button" class="btn-secondary" data-retry>Reintentar</button></div>`);
      render(moreEl, html``);
      return;
    }
    if (!items.length) {
      countEl.textContent = 'Sin resultados';
      render(cardsEl, html`<div class="search-empty">
        <h3>Sin resultados para «${q}»</h3>
        <p>${activeCount() ? 'Prueba a quitar algún filtro. ' : ''}Si la especie no está en EIDOS puedes crearla:
          quedará marcada como especie de usuario.</p>
        ${onCreate ? html`<button type="button" class="btn-secondary" data-create>Crear especie «${q}»</button>` : ''}</div>`);
      render(moreEl, html``);
      return;
    }
    countEl.textContent = items.length < total
      ? `${plural(total, 'resultado', 'resultados')} · se muestran ${items.length}`
      : plural(total, 'resultado', 'resultados');
    render(cardsEl, html`${items.map(cardFor)}`);
    render(moreEl, items.length < total
      ? html`<button type="button" class="btn-secondary" data-more ${status === 'more' ? html`disabled` : ''}>
          ${status === 'more' ? 'Cargando…' : 'Cargar más'}</button>` : html``);
  }

  /** Repinta solo la tarjeta de `id` (tras añadir o quitar) sin perder el scroll ni el foco del resto. */
  function repaintCard(id) {
    const old = cardsEl.querySelector(`.sp-card[data-taxon="${CSS.escape(id)}"]`);
    const sp = byId.get(id);
    if (!old || !sp) return;
    const hadFocus = old.contains(document.activeElement);
    const tmp = document.createElement('div');
    render(tmp, cardFor(sp));
    const fresh = tmp.firstElementChild;
    old.replaceWith(fresh);
    if (hadFocus) {
      const target = fresh.querySelector('[data-add], [data-remove], [data-open]');
      if (target) target.focus();
    }
  }

  el.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-retry]')) search(true);
    else if (ev.target.closest('[data-more]')) search(false);
    else if (ev.target.closest('[data-create]') && onCreate) onCreate(query());
  });

  async function act(id, fn) {
    const sp = byId.get(id);
    if (!sp || !fn || busy.has(id)) return;
    busy.add(id);
    repaintCard(id);
    try { await fn(sp); } catch (e) { /* quien llama avisa del error */ }
    busy.delete(id);
    if (alive) repaintCard(id);
  }
  bindSpeciesCards(cardsEl, {
    onOpen: (id) => { if (onOpen && byId.has(id)) onOpen(byId.get(id)); },
    onAdd: (id) => act(id, onAdd),
    onRemove: (id) => act(id, onRemove),
  });

  const unsubscribe = subscribe((key) => {
    if (key !== 'online') return;
    if (state.online && query().length >= MIN_CHARS) search(true); else paint();
  });

  paint();
  if (query().length >= MIN_CHARS) search(true);

  return {
    destroy() {
      alive = false;
      debounced.cancel();
      if (controller) controller.abort();
      unsubscribe();
      desktop.removeEventListener('change', onDesktop);
      window.BioDexNav.close(navKey);
    },
    /** Tras cambiar la selección desde fuera (lista de seleccionadas): repinta los botones. */
    refreshActions() {
      if (status === 'ready' && items.length) items.forEach((sp) => repaintCard(sp.taxonID));
    },
    focus() { input.focus(); },
  };
}
