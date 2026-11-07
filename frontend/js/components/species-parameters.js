// ================================================================================================
// components/species-parameters.js — pestaña «Parámetros» de la ficha de especie (F11f).
// ------------------------------------------------------------------------------------------------
// Características de la especie EN ESTE CATÁLOGO que piden los criterios UICN y no salen de las marcas
// (docs/parametros.md). owner y custodio las editan (con red: PUT/DELETE /api/catalogs/{id}/species/
// {taxonID}/parameters/{key}); el resto las lee. Sin red se leen de IndexedDB (llegan por el pull).
// · Arriba, el resumen: cuántas faltan y qué criterios bloquean.
// · Cada parámetro: valor (o intervalo), calidad del dato, fuente, quién y cuándo, y para qué sirve.
// · `separation_distance` sin valor muestra el del grupo (shared/species-groups.json) como «por defecto
//   del grupo» hasta que alguien lo confirme (botón «Confirmar»).
// Las claves y sus reglas son las de backend/Lib/parameters.php; aquí, rótulos y ayuda.
// ================================================================================================

import { state } from '../state.js';
import { html, render, $, formatDateTime } from '../util.js';
import { apiGetJson, apiPutJson, apiDeleteJson } from '../api.js';
import { remoteOrLocal } from '../offline.js';
import { hasDb, byIndex, get, put, del } from '../db.js';
import { confirmDialog } from './overlay.js';

export const PARAMETERS = [
  { key: 'generation_length', label: 'Duración de una generación', type: 'number', unit: 'años', step: 'any',
    help: 'Edad media de los progenitores de la cohorte actual. Fija la ventana de 10 años o 3 generaciones.',
    criteria: ['A', 'C1'] },
  { key: 'separation_distance', label: 'Distancia de separación', type: 'number', unit: 'km', step: 'any',
    help: 'Distancia mínima entre dos grupos de registros para tratarlos como subpoblaciones distintas.',
    criteria: ['B (subpoblaciones)', 'C2a'] },
  { key: 'dispersal_distance', label: 'Distancia de dispersión', type: 'number', unit: 'km', step: 'any',
    help: 'Hasta dónde llegan los propágulos o los individuos: decide si la población está gravemente fragmentada.',
    criteria: ['B (fragmentación)'] },
  { key: 'mature_definition', label: 'Individuo maduro', type: 'text', unit: null,
    help: 'Qué se cuenta como individuo maduro. En plantas clonales, di si es el ramet (cada roseta o tallo) o el genet (el clon).',
    criteria: ['C', 'D'] },
  { key: 'min_viable_subpop', label: 'Subpoblación mínima viable', type: 'number', unit: 'individuos maduros', step: '1',
    help: 'Por debajo de este tamaño una subpoblación no se considera viable (fragmentación).',
    criteria: ['B (fragmentación)'] },
  { key: 'life_form', label: 'Forma de vida', type: 'choice', unit: null,
    choices: { perennial: 'Perenne', annual: 'Anual', biennial: 'Bianual', monocarpic: 'Monocárpica (florece una vez)', other: 'Otra' },
    help: 'Orienta la duración de la generación y cómo contar individuos.', criteria: [] },
];
export const QUALITY_LABELS = { observed: 'Observado', estimated: 'Estimado', inferred: 'Inferido', suspected: 'Sospechado', projected: 'Proyectado' };
const EDIT_ROLES = ['owner', 'custodian'];

const fmtNum = (n) => (n === null || n === undefined ? '' : Number(n).toLocaleString('es-ES', { maximumFractionDigits: 3 }));

/** «8 años (5–12)» · «50–250 individuos maduros» · texto · opción. */
export function valueText(def, p) {
  if (!p) return '';
  if (def.type === 'choice') return def.choices[p.value] || p.value;
  if (def.type === 'text') return p.value;
  const range = p.valueMin !== null && p.valueMin !== undefined ? `${fmtNum(p.valueMin)}–${fmtNum(p.valueMax)}` : '';
  const main = p.value !== null && p.value !== undefined ? fmtNum(p.value) : '';
  const unit = def.unit ? ` ${def.unit}` : '';
  if (main && range) return `${main}${unit} (${range})`;
  return `${main || range}${unit}`;
}

/** Valor por defecto del grupo sin red: shared/species-groups.json (lo precachea el SW). */
let groupsCfg = null;
async function localDefaults(sp) {
  try {
    if (!groupsCfg) groupsCfg = await fetch('/shared/species-groups.json').then((r) => (r.ok ? r.json() : null));
  } catch (e) { groupsCfg = null; }
  const g = sp.silhouette || {};
  const km = groupsCfg && groupsCfg.default_separation_km ? groupsCfg.default_separation_km[g.id] : null;
  return { separation_distance: km === null || km === undefined ? null
    : { value: km, unit: 'km', group: g.id, groupLabel: g.label || (groupsCfg.groups || {})[g.id] } };
}

/** Lo que haya en el dispositivo (llega por el pull del catálogo descargado). */
async function localParameters(catalog, sp) {
  if (!hasDb()) return null;
  const cat = await get('catalogs', catalog.id);
  if (!cat || !cat.downloadedAt) return null;
  const parameters = await byIndex('parameters', 'catalogTaxon', [catalog.id, sp.taxonID]);
  return { parameters, defaults: await localDefaults(sp), canEdit: false };
}

/**
 * Monta la pestaña en `el`. `species`: ficha (con silhouette); `catalog`: {id, name, role}.
 * Devuelve {refresh(), destroy()}.
 */
export function mountParameters(el, { species, catalog }) {
  let alive = true;
  let data = null;          // {parameters, defaults, canEdit}
  let fromDevice = false;
  let editing = null;       // clave en edición
  let status = '';          // aviso tras guardar (lo lee el lector de pantalla)
  const base = `/catalogs/${encodeURIComponent(catalog.id)}/species/${encodeURIComponent(species.taxonID)}/parameters`;

  async function refresh() {
    try {
      const res = await remoteOrLocal(() => apiGetJson(base), () => localParameters(catalog, species));
      if (!alive) return;
      data = res.data;
      fromDevice = res.fromDevice;
    } catch (e) {
      if (!alive) return;
      render(el, html`<p class="guard-band warn">${e.offline
        ? 'Sin conexión: los parámetros se ven sin red cuando el catálogo está en el dispositivo («Llevar al campo»).'
        : e.message}</p>`);
      return;
    }
    paint();
  }

  const byKey = () => new Map((data.parameters || []).map((p) => [p.key, p]));
  const canEdit = () => !!data && data.canEdit && !fromDevice && state.online && EDIT_ROLES.includes(catalog.role);

  function paint() {
    const values = byKey();
    const def0 = data.defaults && data.defaults.separation_distance;
    const missing = PARAMETERS.filter((d) => !values.has(d.key) && !(d.key === 'separation_distance' && def0));
    const blocked = [...new Set(missing.flatMap((d) => d.criteria))];
    const usingDefault = !values.has('separation_distance') && def0;
    const editable = canEdit();
    render(el, html`
      <section class="params-summary${missing.length ? ' has-missing' : ''}" aria-label="Estado de los parámetros">
        <p class="params-count"><strong>${PARAMETERS.length - missing.length}</strong> de ${PARAMETERS.length} con valor${
          usingDefault ? ' (uno, por defecto del grupo)' : ''}</p>
        ${missing.length ? html`<p class="params-missing">Sin rellenar: ${missing.map((d) => d.label.toLowerCase()).join(', ')}.</p>` : ''}
        ${blocked.length ? html`<p class="params-blocked"><span class="eyebrow">Bloquean</span> ${blocked.map((c) => html`<span class="crit-tag">${c}</span>`)}</p>`
          : html`<p class="params-ok">Ningún criterio bloqueado por falta de parámetros.</p>`}
      </section>
      ${fromDevice ? html`<p class="copy-note" role="status">Copia del dispositivo: se editan con red.</p>` : ''}
      ${!editable && !fromDevice ? html`<p class="hint">${EDIT_ROLES.includes(catalog.role)
        ? 'Sin conexión: los parámetros se cambian con red.' : 'Solo el propietario y los custodios del catálogo los cambian.'}</p>` : ''}
      <p class="sr-only" role="status" aria-live="polite">${status}</p>
      <ul class="params">${PARAMETERS.map((d) => paramItem(d, values.get(d.key), d.key === 'separation_distance' ? def0 : null, editable))}</ul>`);
    if (editing) {
      const first = $(`.param-form[data-key="${editing}"] [name="value"], .param-form[data-key="${editing}"] [name="valueMin"]`, el);
      if (first) first.focus();
    }
  }

  function paramItem(d, p, fallback, editable) {
    const isEditing = editing === d.key;
    const state0 = p ? 'is-set' : fallback ? 'is-default' : 'is-missing';
    return html`<li class="param ${state0}" data-key="${d.key}">
      <div class="param-head">
        <h5 class="param-label" id="pl-${d.key}">${d.label}${d.unit ? html` <span class="param-unit">(${d.unit})</span>` : ''}</h5>
        ${p ? '' : fallback ? html`<span class="badge warn">Por defecto del grupo</span>` : html`<span class="badge warn">Sin rellenar</span>`}
      </div>
      ${isEditing ? paramForm(d, p || (fallback ? { value: fallback.value } : null)) : html`
        ${p ? html`<p class="param-value">${valueText(d, p)}</p>
          <p class="param-meta">${p.quality ? html`<span class="badge">${QUALITY_LABELS[p.quality] || p.quality}</span>` : ''}
            <span class="muted">${p.setBy || '—'}${p.setAt ? html`, ${formatDateTime(p.setAt, 'numeric')}` : ''}</span></p>
          ${p.source ? html`<p class="param-source"><span class="mark-label">Fuente</span> ${p.source}</p>` : ''}`
        : fallback ? html`<p class="param-value">${fmtNum(fallback.value)} km</p>
          <p class="hint">Valor por defecto para ${fallback.groupLabel || 'este grupo'}, sin confirmar.</p>` : ''}
        <p class="param-help hint">${d.help}${d.criteria.length ? html` <span class="param-for">Necesario para ${d.criteria.join(', ')}.</span>` : ''}</p>
        ${editable ? html`<div class="form-actions param-actions">
          <button type="button" class="btn-secondary" data-edit="${d.key}">${p ? 'Cambiar' : 'Rellenar'}<span class="sr-only"> ${d.label.toLowerCase()}</span></button>
          ${!p && fallback ? html`<button type="button" class="btn-secondary" data-confirm="${d.key}">Confirmar ${fmtNum(fallback.value)} km</button>` : ''}
        </div>` : ''}`}
    </li>`;
  }

  function paramForm(d, p) {
    const v = p || {};
    const q = v.quality || '';
    const valueField = d.type === 'choice'
      ? html`<label class="field"><span>Valor</span><select name="value" required>
          <option value="">—</option>${Object.entries(d.choices).map(([k, label]) => html`<option value="${k}" ${v.value === k ? html`selected` : ''}>${label}</option>`)}
        </select></label>`
      : d.type === 'text'
        ? html`<label class="field"><span>Valor</span><textarea name="value" rows="3" maxlength="2000" required>${v.value || ''}</textarea></label>`
        : html`<label class="field"><span>Valor${d.unit ? ` (${d.unit})` : ''} <em>(o solo el intervalo)</em></span>
            <input name="value" type="number" inputmode="decimal" step="${d.step}" min="0" value="${v.value ?? ''}"></label>
          <div class="field-row param-range">
            <label class="field"><span>Mínimo</span><input name="valueMin" type="number" inputmode="decimal" step="${d.step}" min="0" value="${v.valueMin ?? ''}"></label>
            <label class="field"><span>Máximo</span><input name="valueMax" type="number" inputmode="decimal" step="${d.step}" min="0" value="${v.valueMax ?? ''}"></label>
          </div>`;
    return html`<form class="form param-form" data-key="${d.key}" novalidate aria-labelledby="pl-${d.key}">
      ${valueField}
      <label class="field"><span>Calidad del dato</span><select name="quality">
        <option value="" ${q ? '' : html`selected`}>Sin indicar</option>
        ${Object.entries(QUALITY_LABELS).map(([k, label]) => html`<option value="${k}" ${q === k ? html`selected` : ''}>${label}</option>`)}
      </select></label>
      <label class="field"><span>Fuente <em>(texto libre o referencia)</em></span>
        <textarea name="source" rows="2" maxlength="1000">${v.source || ''}</textarea></label>
      <p class="form-error" role="alert" hidden></p>
      <div class="form-actions">
        <button type="submit" class="btn-primary">Guardar</button>
        <button type="button" class="link-btn" data-cancel>Cancelar</button>
        ${p && p.setAt ? html`<button type="button" class="link-btn danger-link param-clear" data-clear="${d.key}">Dejar sin valor</button>` : ''}
      </div>
    </form>`;
  }

  /** Guarda en el dispositivo lo que ha devuelto el servidor (se ve sin red sin esperar al pull). */
  async function storeLocal(key, p) {
    if (!hasDb()) return;
    try {
      if (p) await put('parameters', { ...p, catalogId: catalog.id });
      else await del('parameters', [catalog.id, species.taxonID, key]);
    } catch (e) { /* sin base local: llegará con el pull */ }
  }

  async function save(key, body) {
    const res = await apiPutJson(`${base}/${key}`, body);
    await storeLocal(key, res.parameter);
    data.parameters = [...(data.parameters || []).filter((p) => p.key !== key), res.parameter];
  }

  el.addEventListener('click', async (ev) => {
    const edit = ev.target.closest('[data-edit]');
    if (edit) { editing = edit.dataset.edit; status = ''; paint(); return; }
    if (ev.target.closest('[data-cancel]')) { const k = editing; editing = null; paint(); focusItem(k); return; }
    const confirmBtn = ev.target.closest('[data-confirm]');
    if (confirmBtn) {
      const def0 = data.defaults.separation_distance;
      confirmBtn.disabled = true;
      try {
        await save('separation_distance', { value: def0.value, quality: 'estimated',
          source: `Valor por defecto del grupo (${def0.groupLabel || def0.group}) en BioDex` });
        status = 'Distancia de separación confirmada.';
      } catch (e) { status = e.message; }
      if (alive) { paint(); focusItem('separation_distance'); }
      return;
    }
    const clear = ev.target.closest('[data-clear]');
    if (clear) {
      const d = PARAMETERS.find((x) => x.key === clear.dataset.clear);
      const ok = await confirmDialog({ key: 'borrar-parametro', title: 'Dejar sin valor', danger: true, confirmLabel: 'Dejar sin valor',
        text: html`<p>«${d.label}» quedará sin valor en «${catalog.name}» para todo el equipo.</p>` });
      if (!ok || !alive) return;
      try {
        await apiDeleteJson(`${base}/${d.key}`);
        await storeLocal(d.key, null);
        data.parameters = (data.parameters || []).filter((p) => p.key !== d.key);
        editing = null;
        status = `${d.label}: sin valor.`;
      } catch (e) { status = e.message; }
      paint();
      focusItem(d.key);
    }
  });

  el.addEventListener('submit', async (ev) => {
    const form = ev.target.closest('.param-form');
    if (!form) return;
    ev.preventDefault();
    const key = form.dataset.key;
    const d = PARAMETERS.find((x) => x.key === key);
    const err = $('.form-error', form);
    err.hidden = true;
    const field = (name) => form.elements.namedItem(name);
    const orNull = (name) => (field(name).value === '' ? null : field(name).value);
    const body = { value: field('value').value, quality: field('quality').value || null, source: field('source').value };
    if (d.type === 'number') Object.assign(body, { value: orNull('value'), valueMin: orNull('valueMin'), valueMax: orNull('valueMax') });
    const btn = $('button[type="submit"]', form);
    btn.disabled = true;
    try {
      await save(key, body);
      if (!alive) return;
      editing = null;
      status = `${d.label}: guardado.`;
      paint();
      focusItem(key);
    } catch (e) {
      if (!alive) return;
      err.textContent = e.message;
      err.hidden = false;
      btn.disabled = false;
    }
  });

  function focusItem(key) {
    const btn = key && $(`.param[data-key="${key}"] [data-edit]`, el);
    if (btn) btn.focus();
  }

  refresh();
  return { refresh, destroy() { alive = false; } };
}
