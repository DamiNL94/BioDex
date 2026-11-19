// ================================================================================================
// catalog-marks.js — Catálogo › Marcas (solo owner): revisar y eliminar marcas de cualquier autor.
// ------------------------------------------------------------------------------------------------
// Filtros por especie, autor, fechas y presencia/ausencia (GET /api/catalogs/{id}/marks). Se marcan
// filas y «Eliminar» pide confirmación y un motivo opcional (POST …/marks/delete, en lotes de 200).
// El borrado es lógico: llega como borrado a todos los dispositivos con la siguiente sincronización,
// el autor no puede deshacerlo editando la marca y queda en el Registro del catálogo.
// Necesita conexión (no se guarda copia en el dispositivo: son coordenadas exactas de todo el equipo).
// Todo el HTML pasa por html`` (nombres, notas y especies vienen de usuarios).
// ================================================================================================

import { apiGetJson, apiPostJson } from '../api.js';
import { state, subscribe } from '../state.js';
import { html, render, $, plural, formatDateTime } from '../util.js';
import { openOverlay } from '../components/overlay.js';
import { syncNow } from '../sync.js';
import { LIFE_STAGE_LABELS, SEX_LABELS } from './mark-sheet.js';

const PAGE = 200;
const DELETE_BATCH = 200;
const REASON_MAX = 500;

const catPath = (catalog) => '/catalogs/' + encodeURIComponent(catalog.id);
const fmtCoord = (o) => `${Number(o.decimalLatitude).toFixed(5)}, ${Number(o.decimalLongitude).toFixed(5)}`;

/** «Presente · 3 ind. · Adulto · Macho» o «Ausente». */
function whatText(o) {
  if (o.occurrenceStatus === 'absent') return 'Ausente';
  return ['Presente', o.individualCount !== null ? `${o.individualCount} ind.` : null,
    o.lifeStage ? LIFE_STAGE_LABELS[o.lifeStage] || o.lifeStage : null,
    o.sex ? SEX_LABELS[o.sex] || o.sex : null].filter(Boolean).join(' · ');
}

/** Panel a pantalla completa (modo campo: Catálogos › Gestionar › Marcas). */
export function openMarks(catalog) {
  let stop = null;
  const ov = openOverlay({ key: 'marcas', title: `Marcas · ${catalog.name}`, variant: 'full', className: 'team-panel',
    onClose: () => { if (stop) stop(); } });
  stop = mountMarks(ov.body, catalog);
  return ov;
}

/** Pestaña Marcas en `el`. Devuelve la función de desmontaje. */
export function mountMarks(el, catalog) {
  let alive = true;
  const filters = { taxonID: '', authorId: '', from: '', to: '', status: '' };
  let items = [];
  let total = 0;
  let hasMore = false;
  let facets = { species: [], authors: [] };
  const selected = new Set();
  let busy = false;
  let notice = null;   // {kind: 'info'|'warn', text}
  let error = null;

  function query(offset) {
    const p = new URLSearchParams({ limit: String(PAGE), offset: String(offset) });
    for (const [k, v] of Object.entries(filters)) if (v) p.set(k, v);
    return `${catPath(catalog)}/marks?${p}`;
  }

  async function load({ keepNotice = false } = {}) {
    if (!state.online) { error = 'Revisar y eliminar marcas necesita conexión.'; paint(); return; }
    busy = true;
    error = null;
    if (!keepNotice) notice = null;
    paint();
    try {
      const res = await apiGetJson(query(0));
      if (!alive) return;
      items = res.items;
      total = res.total;
      hasMore = res.hasMore;
      facets = { species: res.species, authors: res.authors };
      // Lo seleccionado que ya no está en la lista (otro filtro, o borrado) deja de estarlo.
      const ids = new Set(items.map((o) => o.occurrenceID));
      for (const id of [...selected]) if (!ids.has(id)) selected.delete(id);
    } catch (e) {
      error = e.offline ? 'Sin conexión: revisar y eliminar marcas necesita red.' : e.message;
    }
    busy = false;
    paint();
  }

  async function loadMore() {
    busy = true;
    paint();
    try {
      const res = await apiGetJson(query(items.length));
      if (!alive) return;
      items = items.concat(res.items);
      total = res.total;
      hasMore = res.hasMore;
    } catch (e) { error = e.message; }
    busy = false;
    paint();
  }

  function paint() {
    if (!alive) return;
    const allOnPage = items.length > 0 && items.every((o) => selected.has(o.occurrenceID));
    const n = selected.size;
    render(el, html`
      <p class="hint">Todas las marcas del catálogo, de cualquier autor, con su coordenada exacta. Como propietario puedes
        eliminar las que sean erróneas o duplicadas: desaparecen de los dispositivos de todo el equipo en la siguiente
        sincronización, su autor no puede recuperarlas y el borrado queda en el Registro.</p>
      <form class="marks-filters" data-filters>
        <label class="field"><span>Especie</span>
          <select name="taxonID"><option value="">Todas</option>
            ${facets.species.map((s) => html`<option value="${s.taxonID}" ${filters.taxonID === s.taxonID ? html`selected` : ''}>${s.name} (${s.n})</option>`)}
          </select></label>
        <label class="field"><span>Autor</span>
          <select name="authorId"><option value="">Todos</option>
            ${facets.authors.map((a) => html`<option value="${String(a.id)}" ${filters.authorId === String(a.id) ? html`selected` : ''}>${a.name} (${a.n})</option>`)}
          </select></label>
        <label class="field"><span>Desde</span><input type="date" name="from" value="${filters.from}"></label>
        <label class="field"><span>Hasta</span><input type="date" name="to" value="${filters.to}"></label>
        <label class="field"><span>Tipo</span>
          <select name="status">
            <option value="" ${filters.status === '' ? html`selected` : ''}>Todas</option>
            <option value="present" ${filters.status === 'present' ? html`selected` : ''}>Presencias</option>
            <option value="absent" ${filters.status === 'absent' ? html`selected` : ''}>Ausencias</option>
          </select></label>
      </form>
      ${notice ? html`<p class="guard-band ${notice.kind}" role="status">${notice.text}</p>` : ''}
      ${error ? html`<p class="guard-band warn" role="alert">${error}</p>` : ''}
      <div class="marks-bar" aria-live="polite">
        <span>${busy && !items.length ? 'Cargando marcas…' : `${plural(total, 'marca', 'marcas')}${n ? ` · ${n} seleccionada${n === 1 ? '' : 's'}` : ''}`}</span>
        ${n ? html`<button type="button" class="link-btn" data-clear>Quitar selección</button>` : ''}
        <button type="button" class="btn-secondary danger" data-delete ${!n || busy || !state.online ? html`disabled` : ''}>
          ${n ? `Eliminar ${plural(n, 'marca', 'marcas')}` : 'Eliminar'}</button>
      </div>
      ${items.length ? html`<div class="table-scroll"><table class="data-table marks-table">
        <thead><tr>
          <th scope="col"><input type="checkbox" data-all aria-label="Seleccionar todas las cargadas" ${allOnPage ? html`checked` : ''}></th>
          <th scope="col">Fecha</th><th scope="col">Especie</th><th scope="col">Autor</th><th scope="col">Registro</th>
          <th scope="col">Coordenadas</th><th scope="col">Nota</th></tr></thead>
        <tbody>${items.map((o) => html`<tr class="${selected.has(o.occurrenceID) ? 'is-selected' : ''}">
          <td><input type="checkbox" data-id="${o.occurrenceID}" ${selected.has(o.occurrenceID) ? html`checked` : ''}
            aria-label="${`Seleccionar la marca de ${o.scientificName} del ${formatDateTime(o.eventDate, 'numeric')}`}"></td>
          <td class="nowrap">${formatDateTime(o.eventDate, 'numeric')}</td>
          <td><em>${o.scientificName}</em>${o.vernacularName ? html`<br><span class="muted">${o.vernacularName}</span>` : ''}</td>
          <td>${o.authorName}</td>
          <td>${whatText(o)}</td>
          <td class="nowrap">${fmtCoord(o)}${o.coordinateUncertaintyInMeters !== null ? html`<br><span class="muted">± ${o.coordinateUncertaintyInMeters} m</span>` : ''}</td>
          <td>${o.occurrenceRemarks || html`<span class="muted">—</span>`}</td></tr>`)}</tbody></table></div>`
      : !busy && !error ? html`<p class="muted">No hay marcas${Object.values(filters).some(Boolean) ? ' con estos filtros' : ' en el catálogo'}.</p>` : ''}
      ${hasMore ? html`<div class="form-actions"><button type="button" class="btn-secondary" data-more ${busy ? html`disabled` : ''}>
        Cargar más (${total - items.length} restantes)</button></div>` : ''}`);
    wire();
  }

  function wire() {
    const form = $('[data-filters]', el);
    form.addEventListener('change', (ev) => {
      const t = ev.target;
      if (!(t.name in filters)) return;
      filters[t.name] = t.value;
      load();
    });
    form.addEventListener('submit', (ev) => ev.preventDefault());
    el.querySelectorAll('input[data-id]').forEach((cb) => cb.addEventListener('change', () => {
      if (cb.checked) selected.add(cb.dataset.id); else selected.delete(cb.dataset.id);
      paint();
      const again = $(`input[data-id="${cb.dataset.id}"]`, el);
      if (again) again.focus();
    }));
    const all = $('[data-all]', el);
    if (all) all.addEventListener('change', () => {
      for (const o of items) { if (all.checked) selected.add(o.occurrenceID); else selected.delete(o.occurrenceID); }
      paint();
      const again = $('[data-all]', el);
      if (again) again.focus();
    });
    const clear = $('[data-clear]', el);
    if (clear) clear.addEventListener('click', () => { selected.clear(); paint(); });
    const more = $('[data-more]', el);
    if (more) more.addEventListener('click', loadMore);
    const del = $('[data-delete]', el);
    if (del) del.addEventListener('click', confirmDelete);
  }

  async function confirmDelete() {
    const ids = [...selected];
    if (!ids.length) return;
    const chosen = items.filter((o) => selected.has(o.occurrenceID));
    const authors = [...new Set(chosen.map((o) => o.authorName))];
    const reason = await askReason(ids.length, authors);
    if (reason === null) return;
    busy = true;
    paint();
    let deleted = 0;
    let failed = null;
    for (let i = 0; i < ids.length; i += DELETE_BATCH) {
      try {
        const res = await apiPostJson(`${catPath(catalog)}/marks/delete`, { occurrenceIDs: ids.slice(i, i + DELETE_BATCH), reason });
        deleted += res.deleted;
        for (const r of res.results) selected.delete(r.occurrenceID);
      } catch (e) { failed = e.offline ? 'Se cortó la conexión.' : e.message; break; }
    }
    notice = failed
      ? { kind: 'warn', text: `${deleted ? `Se eliminaron ${plural(deleted, 'marca', 'marcas')}, pero no todas: ` : 'No se eliminó ninguna marca: '}${failed}` }
      : { kind: 'info', text: `${deleted === 1 ? 'Eliminada 1 marca' : `Eliminadas ${deleted} marcas`}. Desaparecerán de los dispositivos del equipo al sincronizar.` };
    // Este dispositivo recibe los borrados ya (el pull los trae como borrados).
    syncNow();
    await load({ keepNotice: true });
  }

  const unsubscribe = subscribe((key) => { if (key === 'online' && state.online && error) load(); });
  load();
  return () => { alive = false; unsubscribe(); };
}

/** Diálogo de confirmación con motivo opcional → motivo ('' si no hay) o null si se cancela. */
function askReason(n, authors) {
  return new Promise((resolve) => {
    let result = null;
    const ov = openOverlay({ key: 'eliminar-marcas', title: `¿Eliminar ${plural(n, 'marca', 'marcas')}?`, variant: 'dialog',
      onClose: () => resolve(result) });
    render(ov.body, html`
      <div class="dialog-text">
        <p>${n === 1 ? 'La marca es' : 'Las marcas son'} de ${authors.join(', ')}. Se eliminarán del catálogo y de los
          dispositivos de todo el equipo, dejarán de contar para la evaluación UICN y la exportación, y su autor no podrá
          recuperarlas. Queda anotado en el Registro.</p>
      </div>
      <label class="field"><span>Motivo <em>(opcional, se guarda en el Registro)</em></span>
        <textarea name="reason" rows="3" maxlength="${String(REASON_MAX)}" placeholder="Duplicada, especie mal identificada, prueba…"></textarea></label>
      <div class="form-actions dialog-actions">
        <button type="button" class="btn-secondary danger" data-ok>Eliminar ${plural(n, 'marca', 'marcas')}</button>
        <button type="button" class="link-btn" data-cancel>Cancelar</button>
      </div>`);
    const text = $('textarea', ov.body);
    $('[data-ok]', ov.body).addEventListener('click', () => { result = text.value.trim(); ov.close(true); });
    $('[data-cancel]', ov.body).addEventListener('click', () => ov.close(true));
    $('[data-cancel]', ov.body).focus();
  });
}
