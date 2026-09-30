// ================================================================================================
// components/species-distribution.js — bloque «Distribución» de la ficha (F9): EOO y AOO de la especie
// en el catálogo activo, con nº de registros y excluidos, conmutadores equipo/propias y 2 km/1 km, y el
// ORIGEN del dato: servidor (con fecha) o provisional del dispositivo (sin red). Cálculo: js/metrics.js.
// ================================================================================================

import { state, subscribe } from '../state.js';
import { html, render } from '../util.js';
import { speciesMetrics, PROVISIONAL_TEXT, fmtMetricsDate, fmtKm2 } from '../metrics.js';
import { CELL_OPTIONS_KM, DEFAULT_CELL_KM } from '../../shared/geo-metrics.js';

const SCOPES = [['team', 'Equipo'], ['me', 'Propias']];

/** Texto de los excluidos: «2 (1 ausencia, 1 impreciso)». */
export function excludedText(m) {
  const e = m.excluded || {};
  const bits = [];
  if (e.absent) bits.push(`${e.absent} ${e.absent === 1 ? 'ausencia' : 'ausencias'}`);
  if (e.imprecise) bits.push(`${e.imprecise} ${e.imprecise === 1 ? 'impreciso' : 'imprecisos'}`);
  if (e.noCoordinates) bits.push(`${e.noCoordinates} sin coordenadas`);
  return `${m.nExcluded || 0}${bits.length ? ` (${bits.join(', ')})` : ''}`;
}

/** Línea del origen del dato. */
export function originHtml(res) {
  if (res.source === 'server') {
    return html`<p class="dist-origin is-server"><span class="badge ok">Servidor</span> Calculado con las coordenadas exactas el
      ${fmtMetricsDate(res.data.computedAt)}.</p>`;
  }
  const last = res.lastServer;
  return html`<p class="dist-origin is-device"><span class="badge warn">Dispositivo</span> ${PROVISIONAL_TEXT(res.data.nExact)}.</p>
    ${last ? html`<p class="hint">Último del servidor (${fmtMetricsDate(last.data.computedAt)}): EOO ${fmtKm2(last.data.eooKm2)} ·
      AOO ${fmtKm2(last.data.aooKm2)} · ${last.data.nRecords} registros.</p>` : ''}`;
}

/** Pinta en `el` la distribución de `species` en `catalog`. Devuelve {refresh, destroy}. */
export function mountDistribution(el, { species, catalog }) {
  let alive = true;
  let scope = 'team';
  let cellKm = DEFAULT_CELL_KM;
  let seq = 0;

  function controls() {
    return html`<div class="dist-controls">
      <div class="segmented" role="radiogroup" aria-label="Registros">
        ${SCOPES.map(([v, label]) => html`<button type="button" role="radio" data-scope="${v}" aria-checked="${scope === v}"
          class="${scope === v ? 'active' : ''}">${label}</button>`)}</div>
      <div class="segmented" role="radiogroup" aria-label="Celda de la AOO">
        ${CELL_OPTIONS_KM.map((k) => html`<button type="button" role="radio" data-cell="${k}" aria-checked="${cellKm === k}"
          class="${cellKm === k ? 'active' : ''}">${k} km</button>`)}</div>
    </div>`;
  }

  async function refresh() {
    const mine = ++seq;
    if (!el.querySelector('.dist-controls')) render(el, html`${controls()}<div class="dist-body"><p class="muted">Calculando…</p></div>`);
    let res;
    try {
      res = await speciesMetrics(catalog.id, species.taxonID, { scope, cellKm });
    } catch (e) {
      if (!alive || mine !== seq) return;
      render(el, html`${controls()}<div class="dist-body"><p class="guard-band warn">${e.message}</p></div>`);
      return;
    }
    if (!alive || mine !== seq) return;
    const m = res.data;
    render(el, html`${controls()}<div class="dist-body">
      <dl class="counts dist-counts">
        <div><dt>EOO</dt><dd>${fmtKm2(m.eooKm2)}${m.eooAdjusted ? html`<span class="dd-sub">igualada a la AOO</span>` : ''}</dd></div>
        <div><dt>AOO</dt><dd>${fmtKm2(m.aooKm2)}<span class="dd-sub">${m.aooCells} ${m.aooCells === 1 ? 'celda' : 'celdas'} de ${m.cellSizeKm} km</span></dd></div>
        ${m.aooRange ? html`<div><dt>AOO según la rejilla</dt><dd>${m.aooRange.min === m.aooRange.max ? fmtKm2(m.aooRange.min)
          : html`${fmtKm2(m.aooRange.min)} – ${fmtKm2(m.aooRange.max)}`}<span class="dd-sub">desplazándola medio tamaño</span></dd></div>` : ''}
        <div><dt>Registros</dt><dd>${m.nRecords}</dd></div>
        <div><dt>Excluidos</dt><dd class="dd-date">${excludedText(m)}</dd></div>
      </dl>
      ${originHtml(res)}
      ${m.warnings && m.warnings.length ? html`<ul class="wz-warnings">${m.warnings.map((w) => html`<li>${w}</li>`)}</ul>` : ''}
      <p class="hint">Solo presencias, con precisión mejor que la celda. Reflejan los registros del catálogo, no la distribución
        completa de la especie. El nº de localidades no se calcula: depende de las amenazas y lo decides tú.</p>
    </div>`);
  }

  el.addEventListener('click', (ev) => {
    const s = ev.target.closest('[data-scope]');
    const c = ev.target.closest('[data-cell]');
    if (!s && !c) return;
    if (s) scope = s.dataset.scope;
    if (c) cellKm = Number(c.dataset.cell);
    render(el, html`${controls()}<div class="dist-body"><p class="muted">Calculando…</p></div>`);
    refresh();
  });

  // Tras sincronizar (marcas nuevas) o al volver la red, otra vez: el servidor solo recalcula si cambió algo.
  const unsubscribe = subscribe((key) => {
    if ((key === 'sync' && !state.sync.running) || key === 'online') refresh();
  });
  refresh();
  return { refresh, destroy: () => { alive = false; unsubscribe(); } };
}
