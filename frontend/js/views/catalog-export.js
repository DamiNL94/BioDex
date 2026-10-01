// ================================================================================================
// catalog-export.js — «Exportar datos» de un catálogo (antes «Descargar datos») (F10a): opciones, resumen y descarga del ZIP.
// ------------------------------------------------------------------------------------------------
// La exportación se genera EN EL SERVIDOR (tiene las exactas y aplica la seguridad): sin conexión el
// panel lo explica y remite al respaldo JSON de Ajustes (F5b). Dos modos: «Para publicar» (cualquier
// miembro; coordenadas generalizadas según el nivel de cada especie) y «Completa (privada)» (owner y
// custodio; exactas, y cada descarga queda en el Registro del catálogo).
// POST /catalogs/{id}/export/preview da las opciones y el resumen; POST /catalogs/{id}/export, el ZIP.
// Se abre desde el catálogo (Datos del catálogo) y desde Ajustes › Descargas. F10c: «Formatos» (DwC-A, Plinian
// Core, hojas; al menos uno), que van en «formats».
// ================================================================================================

import { apiFetch, apiPostJson, ApiError } from '../api.js';
import { state } from '../state.js';
import { html, render, $, plural, debounce } from '../util.js';
import { openOverlay } from '../components/overlay.js';

const EXPORT_TIMEOUT_MS = 180000;   // un catálogo grande tarda: hojas, CSV y EOO/AOO de cada especie
const MODES = [['public', 'Para publicar'], ['full', 'Completa (privada)']];
// Formatos del ZIP (F10c): [valor, rótulo, marcado por defecto, ayuda, carpeta]. Al menos uno.
const FORMATS = [
  ['dwc', 'Darwin Core (DwC-A)', true, 'Observaciones, para GBIF u otras bases de datos.', 'dwca/ (Darwin Core Archive)'],
  ['plinian', 'Plinian Core', false, 'Fichas de especie: estado de amenaza y distribución. No incluye observaciones.', 'pliniancore/ (Plinian Core)'],
  ['sheets', 'Hojas (XLSX/CSV)', true, 'Para abrir con doble clic en Excel o LibreOffice.', 'hojas/ (Excel y CSV)'],
];

const catPath = (catalog) => '/catalogs/' + encodeURIComponent(catalog.id);

/** «attachment; filename="BioDex_x.zip"; filename*=UTF-8''…» → nombre del fichero. */
function filenameFrom(header) {
  if (!header) return null;
  const star = /filename\*=UTF-8''([^;]+)/i.exec(header);
  if (star) { try { return decodeURIComponent(star[1]); } catch (e) { /* sigue con el simple */ } }
  const plain = /filename="([^"]+)"/i.exec(header);
  return plain ? plain[1] : null;
}

function saveBlob(blob, name) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 10000);
}

/** Panel a pantalla completa (móvil, Ajustes › Descargas) con la exportación. */
export function openExport(catalog) {
  let stop = null;
  const ov = openOverlay({ key: 'exportar', title: `Exportar datos · ${catalog.name}`, variant: 'full', className: 'export-panel',
    onClose: () => { if (stop) stop(); } });
  stop = mountExport(ov.body, catalog);
  return ov;
}

/** Exportación en `el` (F11c: también en la pestaña «Exportar» del catálogo en escritorio). Devuelve el desmontaje. */
export function mountExport(el, catalog) {
  let alive = true;
  let info = null;            // preview inicial: available, licenses, defaults, canFull
  let summary = null;
  let summaryError = null;
  let status = null;          // {kind, text} tras descargar o fallar
  let downloading = false;
  let seq = 0;                // descarta resúmenes que llegan tarde
  const opts = { mode: 'public', dateFrom: '', dateTo: '', speciesAll: true, taxonIDs: new Set(), observersAll: true,
    observers: new Set(), includeAbsences: true, license: null, title: '', description: '',
    formats: new Set(FORMATS.filter((f) => f[2]).map((f) => f[0])) };

  if (!state.online) {
    render(el, html`<div class="export-offline">
      <p class="guard-band warn">La exportación se genera en el servidor y necesita conexión.</p>
      <p>Sin red, tus marcas no se pierden: en <strong>Ajustes › Datos en este dispositivo</strong>, «Exportar respaldo»
        guarda en un fichero JSON las que aún no se han sincronizado. Cuando vuelvas a tener conexión, esta descarga
        tendrá las del equipo entero.</p></div>`);
    return () => { alive = false; };
  }

  function body() {
    return {
      mode: opts.mode,
      dateFrom: opts.dateFrom || null,
      dateTo: opts.dateTo || null,
      taxonIDs: opts.speciesAll ? null : [...opts.taxonIDs],
      observers: opts.observersAll ? null : [...opts.observers],
      includeAbsences: opts.includeAbsences,
      license: opts.license,
      title: opts.title.trim() || null,
      description: opts.description.trim() || null,
      formats: FORMATS.map((f) => f[0]).filter((f) => opts.formats.has(f)),
    };
  }

  async function start() {
    render(el, html`<p class="muted">Preparando las opciones…</p>`);
    try {
      info = await apiPostJson(`${catPath(catalog)}/export/preview`, { mode: 'public' });
      if (!alive) return;
      summary = info.summary;
      opts.license = info.defaults.license;
      opts.title = info.defaults.title;
      opts.description = info.defaults.description;
      paint();
    } catch (e) {
      if (alive) render(el, html`<p class="guard-band warn">${e.status === 0
        ? 'Sin conexión con el servidor: la exportación se genera allí. Sin red, usa «Exportar respaldo» en Ajustes.' : e.message}</p>`);
    }
  }

  const refreshSummary = debounce(async () => {
    const mine = ++seq;
    summaryError = null;
    paintSummary(true);
    try {
      const res = await apiPostJson(`${catPath(catalog)}/export/preview`, body());
      if (!alive || mine !== seq) return;
      summary = res.summary;
    } catch (e) {
      if (!alive || mine !== seq) return;
      summaryError = e.message;
    }
    paintSummary(false);
  }, 350);

  function paintSummary(loading) {
    const box = $('#exportSummary', el);
    if (!box) return;
    const s = summary;
    const full = opts.mode === 'full';
    render(box, html`
      ${summaryError ? html`<p class="form-error" role="alert">${summaryError}</p>` : ''}
      ${s ? html`<p class="export-count ${loading ? 'is-loading' : ''}"><strong>${s.occurrences}</strong>
          ${s.occurrences === 1 ? 'observación' : 'observaciones'}
          <span class="muted">(${plural(s.presences, 'presencia', 'presencias')} · ${plural(s.absences, 'ausencia', 'ausencias')})</span></p>
        <p>${plural(s.species, 'especie', 'especies')} · ${plural(s.observers, 'observador', 'observadores')}
          ${s.measurements ? html` · ${s.measurements} con individuos maduros` : ''}</p>
        ${full ? html`<p class="guard-band warn">Coordenadas <strong>exactas</strong> de todas las especies. No la publiques ni la
            compartas fuera del equipo: la descarga queda en el Registro del catálogo.</p>`
          : html`<p class="guard-band info">${s.generalized
            ? html`${plural(s.generalized, 'observación se generaliza', 'observaciones se generalizan')} por la sensibilidad de su especie${
              s.withheld ? html` (${s.withheld} sin coordenadas, nivel 1)` : ''}; en ellas tampoco salen las notas de campo.`
            : 'Ninguna observación necesita generalizarse: todas son de especies sin restricción.'}</p>`}` : ''}`);
    const btn = $('[data-download]', el);
    if (btn) btn.disabled = downloading || loading || !s || s.occurrences === 0 || !!summaryError || opts.formats.size === 0;
    const empty = $('#exportEmpty', el);
    if (empty) empty.hidden = !s || s.occurrences > 0 || loading;
  }

  function paint() {
    const av = info.available;
    const full = opts.mode === 'full';
    render(el, html`<form class="form export-form" novalidate>
      <p class="hint">Un ZIP con los formatos que elijas: un <strong>Darwin Core Archive</strong> para GBIF u otras bases, fichas
        de especie en <strong>Plinian Core</strong> y <strong>hojas en castellano</strong> (Excel y CSV). Se genera en el servidor.</p>
      <fieldset class="checks export-formats">
        <legend>Formatos</legend>${FORMATS.map(([value, label, , help]) => html`
        <label class="check"><input type="checkbox" data-format="${value}" ${opts.formats.has(value) ? html`checked` : ''}>
          <span>${label}<span class="hint">${help}</span></span></label>`)}
        ${opts.formats.size === 0 ? html`<p class="form-error" role="alert">Elige al menos un formato.</p>` : ''}
      </fieldset>
      <fieldset class="checks">
        <legend>Modo</legend>
        <div class="segmented" role="radiogroup" aria-label="Modo de la exportación">${MODES.map(([value, label]) => html`
          <button type="button" role="radio" data-mode="${value}" aria-checked="${opts.mode === value}"
            class="${opts.mode === value ? 'active' : ''}" ${value === 'full' && !info.canFull ? html`disabled` : ''}>${label}</button>`)}</div>
        <p class="hint">${full ? 'Coordenadas exactas, para el equipo. Solo propietario y custodios; queda en el Registro.'
          : html`Coordenadas generalizadas según el nivel de cada especie (nivel 1: sin coordenadas).
            ${info.canFull ? '' : ' La completa es solo para el propietario y los custodios.'}`}</p>
      </fieldset>

      <div class="export-dates">
        <label class="field"><span>Desde</span><input type="date" name="dateFrom" value="${opts.dateFrom}"
          min="${av.dateMin || ''}" max="${av.dateMax || ''}"></label>
        <label class="field"><span>Hasta</span><input type="date" name="dateTo" value="${opts.dateTo}"
          min="${av.dateMin || ''}" max="${av.dateMax || ''}"></label>
      </div>
      <p class="hint">${av.dateMin ? `Marcas del ${new Date(av.dateMin + 'T12:00').toLocaleDateString('es-ES')} al
        ${new Date(av.dateMax + 'T12:00').toLocaleDateString('es-ES')}. Vacío = todas.` : 'Aún no hay marcas en este catálogo.'}</p>

      <fieldset class="checks">
        <legend>Especies</legend>
        <label class="check"><input type="radio" name="speciesAll" value="all" ${opts.speciesAll ? html`checked` : ''}> Todas (${av.species.length})</label>
        <label class="check"><input type="radio" name="speciesAll" value="pick" ${opts.speciesAll ? '' : html`checked`}> Elegir</label>
        ${opts.speciesAll ? '' : html`<div class="export-picks">${av.species.map((sp) => html`
          <label class="check"><input type="checkbox" data-taxon="${sp.taxonID}" ${opts.taxonIDs.has(sp.taxonID) ? html`checked` : ''}>
            <span><em>${sp.canonicalName || sp.scientificName}</em>${sp.vernacularName ? html` <span class="muted">· ${sp.vernacularName}</span>` : ''}
            <span class="muted">(${sp.count})</span></span></label>`)}</div>`}
      </fieldset>

      <fieldset class="checks">
        <legend>Observadores</legend>
        <label class="check"><input type="radio" name="observersAll" value="all" ${opts.observersAll ? html`checked` : ''}> Todos (${av.observers.length})</label>
        <label class="check"><input type="radio" name="observersAll" value="pick" ${opts.observersAll ? '' : html`checked`}> Elegir</label>
        ${opts.observersAll ? '' : html`<div class="export-picks">${av.observers.map((o) => html`
          <label class="check"><input type="checkbox" data-observer="${o.userId}" ${opts.observers.has(o.userId) ? html`checked` : ''}>
            <span>${o.name} <span class="muted">(${o.count})</span></span></label>`)}</div>`}
      </fieldset>

      <label class="check"><input type="checkbox" name="includeAbsences" ${opts.includeAbsences ? html`checked` : ''}>
        Incluir ausencias (puntos prospectados sin la especie)</label>

      <label class="field"><span>Licencia</span><select name="license">${info.licenses.map((l) => html`
        <option value="${l.value}" ${l.value === opts.license ? html`selected` : ''}>${l.label}</option>`)}</select></label>
      <p class="hint">Las tres que acepta GBIF. CC BY 4.0 obliga a citar a los autores.</p>
      <label class="field"><span>Título del conjunto</span><input name="title" maxlength="200" value="${opts.title}"></label>
      <label class="field"><span>Descripción</span><textarea name="description" rows="3" maxlength="4000">${opts.description}</textarea></label>

      <section class="panel export-summary" aria-live="polite">
        <h3>Resumen</h3>
        <div id="exportSummary"></div>
        <p class="hint" id="exportEmpty" hidden>No hay observaciones con estos filtros.</p>
      </section>
      ${status ? html`<p class="${status.kind === 'error' ? 'form-error' : 'guard-band info'}" role="${status.kind === 'error' ? 'alert' : 'status'}">${status.text}</p>` : ''}
      <div class="form-actions">
        <button type="submit" class="btn-primary" data-download>${downloading ? 'Generando…' : 'Descargar ZIP'}</button>
      </div>
    </form>`);
    paintSummary(false);

    const form = $('.export-form', el);
    const repaint = () => { paint(); refreshSummary(); };
    form.querySelectorAll('[data-mode]').forEach((b) => b.addEventListener('click', () => {
      if (opts.mode === b.dataset.mode) return;
      opts.mode = b.dataset.mode;
      status = null;
      repaint();
      const again = $(`[data-mode="${opts.mode}"]`, el);
      if (again) again.focus();
    }));
    form.addEventListener('change', (ev) => {
      const t = ev.target;
      if (t.name === 'dateFrom' || t.name === 'dateTo') { opts[t.name] = t.value; refreshSummary(); }
      else if (t.name === 'speciesAll') { opts.speciesAll = t.value === 'all'; repaint(); $(`input[name="speciesAll"][value="${t.value}"]`, el).focus(); }
      else if (t.name === 'observersAll') { opts.observersAll = t.value === 'all'; repaint(); $(`input[name="observersAll"][value="${t.value}"]`, el).focus(); }
      else if (t.dataset.taxon) { t.checked ? opts.taxonIDs.add(t.dataset.taxon) : opts.taxonIDs.delete(t.dataset.taxon); refreshSummary(); }
      else if (t.dataset.format) {
        t.checked ? opts.formats.add(t.dataset.format) : opts.formats.delete(t.dataset.format);
        status = null;
        paint();
        $(`[data-format="${t.dataset.format}"]`, el).focus();
      }
      else if (t.dataset.observer) { const id = Number(t.dataset.observer); t.checked ? opts.observers.add(id) : opts.observers.delete(id); refreshSummary(); }
      else if (t.name === 'includeAbsences') { opts.includeAbsences = t.checked; refreshSummary(); }
      else if (t.name === 'license') opts.license = t.value;
    });
    form.elements.title.addEventListener('input', () => { opts.title = form.elements.title.value; });
    form.elements.description.addEventListener('input', () => { opts.description = form.elements.description.value; });
    form.addEventListener('submit', (ev) => { ev.preventDefault(); download(); });
  }

  async function download() {
    if (downloading) return;
    downloading = true;
    status = null;
    const btn = $('[data-download]', el);
    if (btn) { btn.disabled = true; btn.textContent = 'Generando…'; }
    try {
      const res = await apiFetch(`${catPath(catalog)}/export`, { method: 'POST', body: body(), timeoutMs: EXPORT_TIMEOUT_MS });
      if (!res.ok) {
        const data = await res.json().catch(() => null);
        throw new ApiError(data && data.error ? data.error : 'No se pudo generar la exportación. Inténtalo de nuevo en un momento.', res.status);
      }
      const blob = await res.blob();
      const name = filenameFrom(res.headers.get('Content-Disposition')) || 'BioDex.zip';
      saveBlob(blob, name);
      const inside = FORMATS.filter((f) => opts.formats.has(f[0])).map((f) => f[4]).join(', ');
      status = { kind: 'info', text: `Descargado ${name}. Dentro: ${inside} y LEEME.txt.` };
    } catch (e) {
      status = { kind: 'error', text: e.status === 0 ? `${e.message} La exportación necesita conexión.` : e.message };
    }
    downloading = false;
    if (alive) paint();
  }

  start();
  return () => { alive = false; };
}
