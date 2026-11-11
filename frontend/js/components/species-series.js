// ================================================================================================
// components/species-series.js — serie temporal de una especie (F11h), en la pestaña Registros de su página.
// ------------------------------------------------------------------------------------------------
// Maduros por año: una línea por SITIO (el máximo de sus visitas de ese año) y el ÍNDICE total del modelo (Σ de los
// sitios, shared/iucn-series.js). Los años sin ninguna visita se ven como HUECOS (banda y línea cortada); un sitio sin
// visita ese año corta su línea. Debajo: tasa anual con IC 95 %, reducción en la ventana de A (lo observado y lo
// proyectado), fluctuación y la tabla. Se calcula en el dispositivo con las visitas y marcas sincronizadas (los maduros
// no dependen de las coordenadas: vale igual para quien ve marcas generalizadas); el definitivo, en Evaluación UICN.
// SVG propio (sin librerías); todo el texto pasa por html``.
// ================================================================================================

import { html, render, plural } from '../util.js';
import { byIndex, hasDb } from '../db.js';
import { subscribe, state } from '../state.js';
import { localSeries } from '../visits.js';
import { loadIucnCriteria } from '../views/iucn-wizard.js';

const W = 640;
const H = 260;
const M = { top: 16, right: 16, bottom: 30, left: 44 };
const fmt = (n, d = 1) => (n === null || n === undefined ? '—' : Number(n).toLocaleString('es-ES', { maximumFractionDigits: d }));
const pct = (n) => `${n > 0 ? '+' : ''}${fmt(n, 1)} %`;

/** Ticks «bonitos» para el eje y. */
function niceTicks(max, n = 4) {
  if (!(max > 0)) return [0, 1];
  const step0 = max / n;
  const mag = 10 ** Math.floor(Math.log10(step0));
  const step = [1, 2, 2.5, 5, 10].map((k) => k * mag).find((s) => s >= step0) || step0;
  const out = [];
  for (let v = 0; v <= max + step * 0.01; v += step) out.push(Math.round(v * 1000) / 1000);
  if (out[out.length - 1] < max) out.push(out[out.length - 1] + step);
  return out;
}

/** Segmentos de puntos consecutivos (por año) sin huecos. pts: [[year, value|null]…] ordenados. */
function segments(pts) {
  const out = [];
  let cur = [];
  let prevYear = null;
  for (const [y, v] of pts) {
    if (v === null || v === undefined || (prevYear !== null && y !== prevYear + 1)) { if (cur.length) out.push(cur); cur = []; }
    if (v !== null && v !== undefined) cur.push([y, v]);
    prevYear = v === null || v === undefined ? null : y;
  }
  if (cur.length) out.push(cur);
  return out;
}

/** SVG de la serie (string html``). */
export function seriesSvg(series) {
  const years = series.years || [];
  if (!years.length) return '';
  const y0 = years[0].year;
  const y1 = years[years.length - 1].year;
  const sites = series.sites || [];
  const maxV = Math.max(1, ...sites.flatMap((s) => Object.values(s.counts)), ...years.map((x) => x.index || 0));
  const ticks = niceTicks(maxV);
  const top = ticks[ticks.length - 1];
  const iw = W - M.left - M.right;
  const ih = H - M.top - M.bottom;
  const span = Math.max(1, y1 - y0);
  const x = (yr) => M.left + ((yr - y0) / span) * iw;
  const y = (v) => M.top + ih - (v / top) * ih;
  const colW = iw / (span + 1);
  const xTicks = years.map((r) => r.year).filter((yr, i, a) => a.length <= 14 || (yr - y0) % Math.ceil(a.length / 12) === 0);
  const line = (pts) => pts.map(([yr, v], i) => `${i ? 'L' : 'M'}${x(yr).toFixed(1)},${y(v).toFixed(1)}`).join(' ');
  const win = series.status === 'ok' && series.window ? series.window : null;
  return html`<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Maduros por año: por sitio y total">
    ${win ? html`<rect class="series-window" x="${Math.max(M.left, x(win.from))}" y="${M.top}" width="${Math.max(0, x(win.to) - Math.max(M.left, x(win.from)))}" height="${ih}"></rect>` : ''}
    ${years.filter((r) => !r.visited).map((r) => html`<rect class="series-gap" x="${(x(r.year) - colW / 2).toFixed(1)}" y="${M.top}" width="${colW.toFixed(1)}" height="${ih}"></rect>
      <text class="series-gap-label" x="${x(r.year)}" y="${M.top + 12}" text-anchor="middle">sin visita</text>`)}
    ${ticks.map((t) => html`<line class="series-grid" x1="${M.left}" x2="${W - M.right}" y1="${y(t)}" y2="${y(t)}"></line>
      <text class="series-tick" x="${M.left - 6}" y="${y(t) + 4}" text-anchor="end">${fmt(t, 0)}</text>`)}
    <line class="series-axis" x1="${M.left}" x2="${W - M.right}" y1="${M.top + ih}" y2="${M.top + ih}"></line>
    ${xTicks.map((yr) => html`<text class="series-tick" x="${x(yr)}" y="${H - 8}" text-anchor="middle">${yr}</text>`)}
    ${sites.map((s, i) => {
      const pts = years.map((r) => [r.year, s.counts[r.year] ?? null]);
      return html`<g class="sp-c${i % 8}">${segments(pts).map((seg) => html`<path class="series-line is-site" d="${line(seg)}"></path>`)}
        ${pts.filter(([, v]) => v !== null).map(([yr, v]) => html`<circle class="series-pt is-site" cx="${x(yr)}" cy="${y(v)}" r="3"><title>${s.name || 'Sitio'} · ${yr}: ${fmt(v, 0)} maduros</title></circle>`)}</g>`;
    })}
    ${series.status === 'ok' ? html`${segments(years.map((r) => [r.year, r.index])).map((seg) => html`<path class="series-line is-total" d="${line(seg)}"></path>`)}
      ${years.filter((r) => r.index !== null).map((r) => html`<circle class="series-pt is-total" cx="${x(r.year)}" cy="${y(r.index)}" r="4"><title>Índice ${r.year}: ${fmt(r.index, 1)} maduros</title></circle>`)}` : ''}
  </svg>`;
}

/** Texto de resumen (tasa, reducción, ventana, fluctuación). */
export function seriesStats(series) {
  if (series.status === 'no_data') return html`<p class="muted">${series.reason} Empieza visitas a sitios desde el Mapa («Empezar visita»).</p>`;
  if (series.status !== 'ok') return html`<p class="guard-band warn">No evaluable: ${series.reason}</p>`;
  const t = series.trend;
  const w = series.window;
  const r = series.reduction;
  return html`<dl class="crit-data series-stats">
    <dt>Tendencia</dt><dd><strong>${pct(t.annualChangePct)} al año</strong> (IC 95 %: ${pct(t.annualChangeLo)} a ${pct(t.annualChangeHi)}) ·
      r = ${fmt(t.r, 4)} ± ${fmt(t.se, 4)}${t.phi > 1 ? ` · sobredispersión φ = ${fmt(t.phi, 2)}` : ''}</dd>
    <dt>Reducción en la ventana de A</dt><dd><strong>${fmt(r.best, 1)} %</strong> (${fmt(r.min, 1)}–${fmt(r.max, 1)} %) en ${fmt(w.years, 1)} años
      (${w.from}–${w.to}; el mayor de 10 años y 3 generaciones${series.generationLength ? ` de ${fmt(series.generationLength, 1)} años` : ': sin duración de generación, 10 años'})</dd>
    <dt>Observado</dt><dd>${fmt(w.observedPct, 1)} % de la ventana (${series.firstYear}–${series.lastYear})${w.projected
      ? html` · <span class="badge warn">el resto, proyectado</span>` : ''}</dd>
    <dt>Datos</dt><dd>${plural(series.nSites, 'sitio', 'sitios')} con dos o más años · ${plural(series.nYears, 'año', 'años')} con recuentos
      ${series.sites.some((s) => !s.included) ? html` · fuera: ${series.sites.filter((s) => !s.included).map((s) => `${s.name || 'sitio'} (${s.excluded})`).join(', ')}` : ''}
      ${series.skippedVisits ? html` · ${plural(series.skippedVisits, 'visita sin maduros no cuenta', 'visitas sin maduros no cuentan')}` : ''}</dd>
    <dt>Fluctuación</dt><dd>${series.fluctuation.ratio !== null ? `máx./mín. del índice ×${fmt(series.fluctuation.ratio, 1)}` : 'el índice llega a 0'}
      ${series.fluctuation.extreme ? html` · <span class="badge warn">posible fluctuación extrema</span>` : ''}</dd>
  </dl>`;
}

/** Tabla año × sitio. */
function seriesTable(series) {
  const sites = series.sites || [];
  return html`<details class="more"><summary>Tabla de recuentos (${plural(sites.length, 'sitio', 'sitios')})</summary>
    <div class="table-scroll"><table class="data-table series-table"><thead><tr><th>Año</th>${sites.map((s) => html`<th>${s.name || 'Sitio'}</th>`)}
      <th>Total observado</th><th>Índice</th></tr></thead>
    <tbody>${(series.years || []).map((r) => html`<tr><th scope="row">${r.year}</th>
      ${sites.map((s) => html`<td class="${s.counts[r.year] === undefined ? 'is-gap' : ''}">${s.counts[r.year] === undefined ? '—' : fmt(s.counts[r.year], 0)}</td>`)}
      <td class="${r.visited ? '' : 'is-gap'}">${r.visited ? fmt(r.total, 0) : 'sin visita'}</td><td>${r.index === null ? '—' : fmt(r.index, 1)}</td></tr>`)}</tbody>
    </table></div></details>`;
}

/** Monta la serie en `el`. → {refresh, destroy} */
export function mountSpeciesSeries(el, { species, catalog }) {
  let alive = true;
  async function refresh() {
    if (!hasDb()) { render(el, html`<p class="muted">Este navegador no guarda datos en el dispositivo.</p>`); return; }
    try {
      const params = await byIndex('parameters', 'catalogTaxon', [catalog.id, species.taxonID]).catch(() => []);
      const gen = params.find((p) => p.key === 'generation_length');
      const criteria = await loadIucnCriteria().catch(() => null);
      const series = await localSeries(catalog.id, species.taxonID, { generationLength: gen ? gen.value : null, criteria });
      if (!alive) return;
      render(el, html`
        ${(series.years || []).length ? html`<div class="series-chart">${seriesSvg(series)}</div>
          <p class="series-legend"><span><span class="series-key is-total"></span>Índice (todos los sitios)</span>
            ${series.sites.map((s, i) => html`<span class="sp-c${i % 8}"><span class="series-key"></span>${s.name || 'Sitio'}${s.included ? '' : ' (fuera del modelo)'}</span>`)}
            ${series.years.some((r) => !r.visited) ? html`<span><span class="series-key is-gap"></span>Año sin visita</span>` : ''}</p>` : ''}
        ${seriesStats(series)}
        ${(series.years || []).length ? seriesTable(series) : ''}
        <p class="hint">Calculada en este dispositivo con las visitas sincronizadas. La que cuenta para la evaluación es la del servidor
          (pestaña Evaluación UICN).</p>`);
    } catch (e) {
      if (alive) render(el, html`<p class="guard-band warn">No se pudo calcular la serie: ${e.message}</p>`);
    }
  }
  const unsubscribe = subscribe((key) => { if (key === 'sync' && !state.sync.running) refresh(); });
  refresh();
  return { refresh, destroy() { alive = false; unsubscribe(); } };
}
