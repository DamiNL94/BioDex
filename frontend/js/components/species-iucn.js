// ================================================================================================
// components/species-iucn.js — pestaña «Evaluación UICN» de la página de especie (F11g; sustituye al asistente).
// ------------------------------------------------------------------------------------------------
// Arriba: categoría propuesta (con «posiblemente extinta» solo si el usuario lo decide), rango plausible, cadena de
// criterios y fecha y origen del cálculo (servidor = definitivo; dispositivo = provisional, js/assessment.js).
// Debajo, una tarjeta por criterio (A–E): sus datos con su calidad, qué umbral cumple y qué falta para poder
// evaluarlo, con enlace directo al parámetro (pestaña Parámetros), al hecho o a las localidades. F11h: A y C1 salen de
// la serie temporal de las visitas a sitios (shared/iucn-series.js): tasa con IC, reducción en la ventana (lo observado
// y lo proyectado) y los hechos que eligen A1/A2 y añaden A3/A4; E queda fuera de alcance (lo dice su tarjeta).
// Hechos de B y C (b(i)–(v), c(i)–(iv)), amenaza plausible (D2) y preguntas del ajuste regional: sí · no ·
// desconocido con calidad, fuente y justificación; los fijan owner y custodio con red (son parámetros,
// Lib/parameters.php). BioDex SUGIERE b(iii) con impactos en curso, nunca lo fija.
// Localidades: la propuesta por amenaza (Lib/iucn.php › proposeLocations) y, para owner y custodio, unir o separar
// subpoblaciones con justificación (PUT …/locations).
// «Añadir registros de GBIF» (?gbif=1): EOO, AOO, subpoblaciones y B «solo BioDex» frente a «BioDex + GBIF».
// «Guardar evaluación»: instantánea versionada (entradas + resultado + autor + fecha) en species_assessments, sin
// red también; el servidor la recalcula con el motor en PHP al sincronizar. Debajo, las guardadas
// (components/species-assessments.js) con su comparación.
// Todo el HTML pasa por html`` (las justificaciones y los nombres vienen de usuarios).
// ================================================================================================

import { state } from '../state.js';
import { html, render, $, $$, formatDateTime } from '../util.js';
import { apiPutJson, apiDeleteJson } from '../api.js';
import { put, del, hasDb } from '../db.js';
import { saveAssessment, MARK_ROLES, JUSTIFICATION_MAX } from '../marks.js';
import { requestSync } from '../sync.js';
import { speciesAssessment, recompute, quantifierSummary } from '../assessment.js';
import { loadIucnCriteria, DISCLAIMER } from '../views/iucn-wizard.js';
import { loadThreats, threatLabel } from '../views/impact-sheet.js';
import { fmtKm2 } from '../metrics.js';
import { QUALITY_LABELS, PARAMETERS } from './species-parameters.js';
import { mountAssessments } from './species-assessments.js';
import { mountGbifMap } from './gbif-compare-map.js';
import { categoryLabel, rangeLabel, criterionSummary } from '../../shared/iucn-engine.js';

const EDIT_ROLES = ['owner', 'custodian'];
const FACT_LABELS = { yes: 'Sí', no: 'No', unknown: 'Desconocido' };
export const FACTS = {
  decline: [['decline_b_i', '(i) EOO'], ['decline_b_ii', '(ii) AOO'], ['decline_b_iii', '(iii) Área, extensión o calidad del hábitat'],
    ['decline_b_iv', '(iv) Nº de localidades o subpoblaciones'], ['decline_b_v', '(v) Nº de individuos maduros']],
  fluct: [['fluct_c_i', '(i) EOO'], ['fluct_c_ii', '(ii) AOO'], ['fluct_c_iii', '(iii) Nº de localidades o subpoblaciones'],
    ['fluct_c_iv', '(iv) Nº de individuos maduros']],
  d2: [['plausible_threat', 'Amenaza futura plausible que podría llevarla a CR o EX en muy poco tiempo']],
  // F11h: criterio A con series temporales.
  aCauses: [['a_reversible', '¿Las causas de la reducción son reversibles?'], ['a_understood', '¿Se comprenden las causas?'],
    ['a_ceased', '¿Han cesado las causas?']],
  aFuture: [['a_future', '¿Se espera que la reducción continúe en el futuro? (añade A3, proyección, y A4, ventana pasado + futuro)']],
  aBasis: [['a_basis_a', '(a) Observación directa (no vale para A3)'], ['a_basis_b', '(b) Índice de abundancia apropiado para el taxón'],
    ['a_basis_c', '(c) Reducción de AOO, EOO o calidad del hábitat'], ['a_basis_d', '(d) Niveles de explotación reales o potenciales'],
    ['a_basis_e', '(e) Taxones introducidos, hibridación, patógenos, contaminantes, competidores o parásitos']],
  regional: [['regional_endemic', '¿Es endémica del área de evaluación?'],
    ['regional_immigration', '¿Recibe inmigración significativa de propágulos capaces de reproducirse en la región?'],
    ['regional_immigration_decrease', '¿Se espera que esa inmigración disminuya?'],
    ['regional_sink', '¿La población regional es un sumidero (depende de la inmigración)?']],
};
const ALL_FACTS = Object.fromEntries(Object.values(FACTS).flat());
const PARAM_LABELS = Object.fromEntries(PARAMETERS.map((p) => [p.key, p.label]));
const fmtN = (n) => (n === null || n === undefined ? '—' : Number(n).toLocaleString('es-ES', { maximumFractionDigits: 2 }));
const STATUS_TEXT = { met: 'Cumple', not_met: 'No alcanza umbrales', skipped: 'Sin datos', incomplete: 'Datos incompletos' };

/** Monta la pestaña en `el`. `goToParam(key)` abre la pestaña Parámetros en esa clave. → {refresh, destroy} */
export function mountSpeciesIucn(el, { species, catalog, goToParam }) {
  let alive = true;
  let data = null;          // respuesta del servidor o provisional
  let source = null;        // 'server' | 'device'
  let lastServer = null;
  let crit = null;
  let threats = null;
  let view = null;          // {inputs, result} con las decisiones del usuario
  const choices = { expertCategory: null, possiblyExtinct: false };
  let editingFact = null;
  let editingLocations = false;
  let gbifOn = false;
  let gbifData = null;
  let gbifMap = null;
  let status = '';
  let snapshots = null;
  const base = `/catalogs/${encodeURIComponent(catalog.id)}/species/${encodeURIComponent(species.taxonID)}`;
  const canEdit = () => source === 'server' && state.online && EDIT_ROLES.includes(catalog.role) && data && data.canEdit;
  const canSave = MARK_ROLES.includes(catalog.role);

  async function refresh() {
    try {
      [crit, threats] = await Promise.all([loadIucnCriteria(), loadThreats().catch(() => null)]);
      const res = await speciesAssessment(catalog, species, { gbif: gbifOn });
      if (!alive) return;
      source = res.source;
      data = res.data;
      lastServer = res.lastServer || null;
      gbifData = gbifOn && data.gbif ? data.gbif : null;
      view = await recompute(data.inputs, choices);
    } catch (e) {
      if (!alive) return;
      render(el, html`<p class="guard-band warn">${e.offline
        ? 'Sin conexión y sin el catálogo en el dispositivo: la evaluación se calcula con red o con el catálogo descargado («Llevar al campo»).'
        : e.message}</p>`);
      return;
    }
    if (alive) paint();
  }

  // --- Pintado -------------------------------------------------------------------------------------
  const param = (k) => (data.parameters || {})[k] || null;
  /** Confirmadas, pero el motor no las usa: las subpoblaciones ya no son las que se agruparon (locationsCurrent). */
  const locStale = () => !!data.locations && !(data.inputs && data.inputs.B && Number.isInteger(data.inputs.B.locations));
  const factValue = (k) => (param(k) ? param(k).value : null);
  const gotoParam = (k) => html`<button type="button" class="link-btn" data-goto-param="${k}">${PARAM_LABELS[k] || k}</button>`;
  const gotoFact = (k, label) => html`<button type="button" class="link-btn" data-goto-fact="${k}">${label}</button>`;

  function head() {
    const r = view.result;
    const cat = r.displayCategory || r.category;
    const origin = source === 'server' ? html`Calculado en el servidor (definitivo) · ${formatDateTime(data.computedAt)}`
      : html`Provisional · ${data.nExact} ${data.nExact === 1 ? 'registro exacto' : 'registros exactos'} en este dispositivo · el definitivo lo calcula el servidor${
        lastServer ? html` · último definitivo: ${lastServer.data.result.displayCategory || lastServer.data.result.category || '—'}, ${formatDateTime(new Date(lastServer.savedAt).toISOString())}` : ''}`;
    return html`<section class="iucn-head iucn-edge-${r.category || 'none'}" aria-labelledby="iucnCat">
      <p class="eyebrow">Categoría propuesta</p>
      <p class="iucn-cat-line"><span class="assess-cat iucn-${r.category || 'none'}" id="iucnCat">${cat || '—'}</span>
        <span class="iucn-cat-label">${cat ? categoryLabel(crit, r.category) + (r.possiblyExtinct ? ' (posiblemente extinta)' : '') : 'Sin categoría'}</span></p>
      ${r.criteriaString ? html`<p class="iucn-string">${r.criteriaString}</p>` : r.message ? html`<p class="iucn-string muted">${r.message}</p>` : ''}
      <p class="iucn-range"><span class="mark-label">Rango plausible</span> ${rangeLabel(r.range)}${r.range && r.range.crossesCategories
        ? html` <span class="badge warn">Los datos inciertos cruzan categorías</span>` : ''}</p>
      ${r.regional && r.regional.applied ? html`<p class="hint">Ajuste regional (v4.0): ${r.regional.from} → ${r.regional.to}.</p>` : ''}
      <p class="hint iucn-origin">${origin}</p>
      ${choicesHtml(r)}
    </section>`;
  }

  function choicesHtml(r) {
    const expert = r.basis === 'none' || r.basis === 'expert';
    const pe = r.category === 'CR' || choices.possiblyExtinct;
    if (!expert && !pe) return '';
    return html`<div class="iucn-choices">
      ${expert ? html`<fieldset class="field"><legend>No alcanza umbrales: a juicio experto</legend>
        <div class="status-toggle three" role="radiogroup">
          ${[[null, 'Sin decidir'], ['NT', 'NT · Casi amenazada'], ['LC', 'LC · Preocupación menor']].map(([v, l]) => html`<button type="button" role="radio"
            data-expert="${v || ''}" aria-checked="${choices.expertCategory === v}" class="${choices.expertCategory === v ? 'active' : ''}">${l}</button>`)}
        </div></fieldset>` : ''}
      ${pe ? html`<label class="check"><input type="checkbox" data-pe ${choices.possiblyExtinct ? html`checked` : ''}>
        <span>Posiblemente extinta, CR(PE): solo si hay indicios de que ha desaparecido (lo decides tú, BioDex nunca lo propone)</span></label>` : ''}
    </div>`;
  }

  function factRow(k, label, { suggestion = null } = {}) {
    const p = param(k);
    const editable = canEdit();
    if (editingFact === k) {
      const v = p || {};
      return html`<li class="fact is-editing" data-fact="${k}"><form class="form fact-form" data-key="${k}" novalidate>
        <p class="fact-label" id="fl-${k}">${label}</p>
        <div class="status-toggle three" role="radiogroup" aria-labelledby="fl-${k}">
          ${Object.entries(FACT_LABELS).map(([val, l]) => html`<button type="button" role="radio" data-fact-value="${val}"
            aria-checked="${(v.value || (suggestion && suggestion.value)) === val}" class="${(v.value || (suggestion && suggestion.value)) === val ? 'active' : ''}">${l}</button>`)}
        </div>
        <input type="hidden" name="value" value="${v.value || (suggestion ? suggestion.value : '')}">
        <label class="field"><span>Calidad del dato</span><select name="quality"><option value="">Sin indicar</option>
          ${Object.entries(QUALITY_LABELS).map(([q, l]) => html`<option value="${q}" ${(v.quality || (suggestion ? 'inferred' : '')) === q ? html`selected` : ''}>${l}</option>`)}</select></label>
        <label class="field"><span>Justificación</span><textarea name="justification" rows="2" maxlength="4000">${v.justification || (suggestion ? suggestion.reason : '')}</textarea></label>
        <label class="field"><span>Fuente <em>(opcional)</em></span><input name="source" maxlength="1000" value="${v.source || ''}"></label>
        <p class="form-error" role="alert" hidden></p>
        <div class="form-actions"><button type="submit" class="btn-primary">Guardar</button>
          <button type="button" class="link-btn" data-fact-cancel>Cancelar</button>
          ${p ? html`<button type="button" class="link-btn danger-link" data-fact-clear="${k}">Dejar sin fijar</button>` : ''}</div>
      </form></li>`;
    }
    return html`<li class="fact${p ? ' is-set' : ''}" data-fact="${k}">
      <span class="fact-label">${label}</span>
      <span class="badge ${p && p.value === 'yes' ? 'ok' : p ? '' : 'warn'}">${p ? FACT_LABELS[p.value] || p.value : 'Sin fijar'}</span>
      ${p && p.quality ? html`<span class="badge">${QUALITY_LABELS[p.quality]}</span>` : ''}
      ${suggestion && !p ? html`<span class="badge suggest">BioDex sugiere «${FACT_LABELS[suggestion.value]}»: ${suggestion.reason}</span>` : ''}
      ${p && p.justification ? html`<span class="fact-just hint">${p.justification}</span>` : ''}
      ${p ? html`<span class="muted fact-meta">${p.setBy || '—'}${p.setAt ? html`, ${formatDateTime(p.setAt, 'numeric')}` : ''}</span>` : ''}
      ${editable ? html`<button type="button" class="link-btn" data-fact-edit="${k}">${suggestion && !p ? 'Revisar la sugerencia' : p ? 'Cambiar' : 'Fijar'}<span class="sr-only"> ${label}</span></button>` : ''}
    </li>`;
  }

  function criterionCard(key, title, body, needs = [], { pending = null, badge = 'Pendiente de series temporales' } = {}) {
    const c = view.result.byCriterion[key];
    const determining = (view.result.determining || []).includes(key);
    const met = c && c.status === 'met';
    return html`<section class="crit-card${determining ? ' is-determining' : ''}" aria-labelledby="cc-${key}">
      <header class="crit-card-head"><h5 id="cc-${key}"><span class="crit-key">${key}</span> ${title}</h5>
        ${pending ? html`<span class="badge">${badge}</span>`
          : html`<span class="badge ${met ? 'ok' : c && c.status === 'not_met' ? '' : 'warn'}">${met ? `${c.category} · ${c.notation}` : STATUS_TEXT[c ? c.status : 'skipped']}</span>`}
      </header>
      ${pending ? html`<p class="hint">${pending}</p>` : ''}
      ${body}
      ${needs.length ? html`<div class="crit-needs"><p class="eyebrow">Falta para evaluarlo bien</p><ul>${needs.map((n) => html`<li>${n}</li>`)}</ul></div>` : ''}
      ${c && !pending && c.status !== 'skipped' ? html`<details class="more"><summary>Umbrales, por categoría</summary>
        <ul class="crit-reasons">${crit.categories.map((cat) => html`<li><strong class="iucn-${cat}">${cat}</strong> ${c.levels[cat].reasons.join(' · ') || '—'}</li>`)}</ul>
        ${(c.notes || []).length ? html`<p class="hint">${c.notes.join(' ')}</p>` : ''}</details>` : ''}
    </section>`;
  }

  function cardB() {
    const m = data.metrics || {};
    const frag = data.fragmentation || {};
    const loc = data.locations;
    const prop = data.locationsProposal || { count: 0, groups: [] };
    const sugg = new Map((data.suggestions || []).map((s) => [s.key, s]));
    const needs = [];
    if (m.eooKm2 === null && m.aooKm2 === null) needs.push(html`Marcas con coordenadas (EOO y AOO salen de ellas).`);
    if (!loc && frag.severe !== true) needs.push(html`${gotoFact('locations', 'Confirmar el nº de localidades')} (la propuesta no cuenta hasta que se confirma).`);
    if (locStale()) needs.push(html`${gotoFact('locations', 'Revisar las localidades')}: las subpoblaciones han cambiado desde que se confirmaron y no cuentan.`);
    if (frag.status === 'not_evaluable') {
      needs.push(html`Fragmentación: ${frag.missing.filter((k) => PARAM_LABELS[k]).map((k, i) => html`${i ? ', ' : ''}${gotoParam(k)}`)}${frag.missing.includes('subpopulations') ? ' (y subpoblaciones)' : ''}.`);
    }
    if (!FACTS.decline.some(([k]) => factValue(k) === 'yes') && !FACTS.fluct.some(([k]) => factValue(k) === 'yes')) {
      needs.push(html`Algún ${gotoFact('decline_b_i', 'hecho de disminución continua (b) o de fluctuaciones extremas (c)')}.`);
    }
    return criterionCard('B', 'Distribución geográfica', html`
      <dl class="crit-data">
        <dt>EOO</dt><dd>${fmtKm2(m.eooKm2)}</dd>
        <dt>AOO</dt><dd>${fmtKm2(m.aooKm2)}${m.aooRange ? html` <span class="muted">(${fmtN(m.aooRange.min)}–${fmtN(m.aooRange.max)} km² según la rejilla)</span>` : ''}</dd>
        <dt>Localidades</dt><dd>${loc ? html`<strong>${loc.count}</strong> confirmadas${loc.quality ? html` · ${QUALITY_LABELS[loc.quality]}` : ''} <span class="muted">(${loc.setBy || '—'})</span>${
          locStale() ? html` <span class="badge warn">Desactualizadas: no cuentan</span>` : ''}`
          : html`sin confirmar · propuesta: ${prop.count}`}</dd>
        <dt>Fragmentación grave</dt><dd>${frag.status === 'not_evaluable' ? 'No evaluable'
          : html`${frag.severe ? 'Sí' : 'No'} <span class="muted">· ${fmtN(frag.pctAoo)} % de la AOO en subpoblaciones pequeñas y aisladas${frag.pctIndividuals !== null ? html`, ${fmtN(frag.pctIndividuals)} % de los individuos` : ''}</span>`}</dd>
      </dl>
      ${locationsHtml()}
      <h6>(b) Disminución continua</h6><ul class="facts">${FACTS.decline.map(([k, l]) => factRow(k, l, { suggestion: sugg.get(k) }))}</ul>
      <h6>(c) Fluctuaciones extremas</h6><ul class="facts">${FACTS.fluct.map(([k, l]) => factRow(k, l, { suggestion: sugg.get(k) }))}</ul>`, needs);
  }

  function subpopsTable() {
    const sp = data.subpopulations || {};
    const list = sp.subpops || [];
    if (!list.length) return html`<p class="hint">${(sp.warnings || []).join(' ') || 'Sin subpoblaciones.'}</p>`;
    return html`<div class="table-scroll"><table class="data-table subpops-table">
      <caption>Subpoblaciones (enlace simple a ${fmtN(sp.separationKm)} km${data.separation && data.separation.fromDefault ? ', por defecto del grupo' : ''})</caption>
      <thead><tr><th scope="col">Sub.</th><th scope="col">Registros</th><th scope="col">Maduros (mín. · mejor · máx.)</th><th scope="col">Vecina más cercana</th></tr></thead>
      <tbody>${list.map((s, i) => html`<tr><th scope="row"><span class="subpop-swatch sp-c${i % 8}" aria-hidden="true"></span>${s.label}</th><td>${s.nRecords}</td>
        <td>${s.mature.min === null ? '—' : `${fmtN(s.mature.min)} · ${fmtN(s.mature.best)} · ${fmtN(s.mature.max)}`}</td>
        <td>${s.nearest ? `${s.nearest}, ${fmtN(s.nearestKm)} km` : '—'}</td></tr>`)}</tbody></table></div>
      ${(sp.warnings || []).length ? html`<p class="hint">${sp.warnings.join(' ')}</p>` : ''}`;
  }

  function matureHtml() {
    const m = data.mature || {};
    const q = m.quality || {};
    return html`<dl class="crit-data">
      <dt>Maduros · mínimo</dt><dd>${fmtN(m.min)} <span class="badge">${QUALITY_LABELS[q.min] || '—'}</span></dd>
      <dt>Maduros · mejor</dt><dd>${fmtN(m.best)} <span class="badge">${QUALITY_LABELS[q.best] || '—'}</span></dd>
      <dt>Maduros · máximo</dt><dd>${m.method === 'observed' && m.min !== null ? html`desconocido <span class="muted">(solo hay recuentos: sin parcelas
        con superficie, el total no se puede acotar)</span>` : html`${fmtN(m.max)} <span class="badge">${QUALITY_LABELS[q.max] || '—'}</span>${
        m.areaSource === 'aoo' ? html` <span class="muted">(AOO como cota)</span>` : ''}`}</dd>
      ${m.density ? html`<dt>Densidad (${m.density.plots} parcelas)</dt><dd>${fmtN(m.density.mean)} /km² <span class="muted">(IC 95 %: ${fmtN(m.density.low)}–${fmtN(m.density.high)})</span></dd>` : ''}
    </dl>
    ${(m.warnings || []).length ? html`<p class="hint">${m.warnings.join(' ')}</p>` : ''}`;
  }

  function cardC() {
    const m = data.mature || {};
    const c2a = (data.subpopulations || {}).c2a;
    const needs = [];
    if (m.min === null || m.min === undefined) needs.push(html`Recuentos de maduros en las marcas («Individuo maduro» al marcar).`);
    if (factValue('decline_b_v') === null) needs.push(html`${gotoFact('decline_b_v', 'Disminución continua de maduros (b(v))')}: C2 la exige.`);
    if (!param('occupied_habitat_km2') && m.density) needs.push(html`${gotoParam('occupied_habitat_km2')} para estimar el total con las parcelas.`);
    if (!param('separation_distance')) needs.push(html`${gotoParam('separation_distance')} confirmada (subpoblaciones de C2a).`);
    return criterionCard('C', 'Población pequeña y en disminución', html`${matureHtml()}
      ${subpopsTable()}
      <dl class="crit-data">
        <dt>C2a(i) · máx. por subpoblación</dt><dd>${c2a ? `${fmtN(c2a.maxPerSubpop.best)} (${fmtN(c2a.maxPerSubpop.min)}–${fmtN(c2a.maxPerSubpop.max)})` : '—'}</dd>
        <dt>C2a(ii) · % en la mayor</dt><dd>${c2a && c2a.pctInLargest.best !== null ? `${fmtN(c2a.pctInLargest.best)} % (${fmtN(c2a.pctInLargest.min)}–${fmtN(c2a.pctInLargest.max)})` : '—'}</dd>
        <dt>C1 · disminución continua</dt><dd>${seriesOk() ? html`tasa anual ${fmtPct(data.series.trend.annualChangePct)} (IC 95 %:
          ${fmtPct(data.series.trend.annualChangeLo)} a ${fmtPct(data.series.trend.annualChangeHi)}); el motor la lleva a la ventana de cada categoría
          (3 años o 1 generación, 5 o 2, 10 o 3)` : html`<span class="muted">Sin serie temporal: ${seriesReason()}</span>`}</dd>
      </dl>`, needs);
  }

  // --- A · Reducción (F11h: series temporales) -------------------------------------------------------
  const seriesOk = () => !!(data.series && data.series.status === 'ok');
  const seriesReason = () => (data.series ? data.series.reason : 'sin visitas a sitios.') || '';
  const fmtPct = (n) => (n === null || n === undefined ? '—' : `${n > 0 ? '+' : ''}${fmtN(n)} %`);

  function cardA() {
    const s = data.series;
    const items = (view.inputs.A && view.inputs.A.items) || [];
    const needs = [];
    if (!seriesOk()) needs.push(html`Visitas a sitios con recuentos en al menos 3 años (Mapa › «Empezar visita»; sitios en Catálogo › Sitios).`);
    if (!param('generation_length')) needs.push(html`${gotoParam('generation_length')}: sin ella la ventana es de 10 años (con ella, 3 generaciones si es mayor).`);
    if (!FACTS.aBasis.some(([k]) => factValue(k) === 'yes')) needs.push(html`Al menos una ${gotoFact('a_basis_b', 'base (a)–(e)')} de la reducción.`);
    if (FACTS.aCauses.some(([k]) => factValue(k) === null)) needs.push(html`Las ${gotoFact('a_reversible', 'causas')} (reversibles, comprendidas y cesadas) para elegir entre A1 y A2 (sin responder: A2).`);
    const body = !s || s.status === 'no_data' ? html`<p class="hint">${s ? s.reason : 'Sin visitas a sitios.'} La reducción se mide repitiendo el mismo muestreo:
        empieza visitas a sitios desde el Mapa.</p>`
      : s.status !== 'ok' ? html`<p class="guard-band warn">No evaluable: ${s.reason}</p>`
        : html`<dl class="crit-data">
          <dt>Serie</dt><dd>${s.nSites} ${s.nSites === 1 ? 'sitio' : 'sitios'} · ${s.firstYear}–${s.lastYear} (${s.nYears} años con recuentos)</dd>
          <dt>Tendencia</dt><dd><strong>${fmtPct(s.trend.annualChangePct)} al año</strong> <span class="muted">(IC 95 %: ${fmtPct(s.trend.annualChangeLo)} a
            ${fmtPct(s.trend.annualChangeHi)}${s.trend.phi > 1 ? `; sobredispersión φ = ${fmtN(s.trend.phi)}` : ''})</span></dd>
          <dt>Reducción en ${fmtN(s.window.years)} años</dt><dd><strong>${fmtN(s.reduction.best)} %</strong> <span class="muted">(${fmtN(s.reduction.min)}–${fmtN(s.reduction.max)} %,
            ventana ${s.window.from}–${s.window.to})</span></dd>
          <dt>Observado</dt><dd>${fmtN(s.window.observedPct)} % de la ventana${s.window.projected ? html` · <span class="badge warn">el resto (${fmtN(100 - s.window.observedPct)} %) es proyectado</span>` : ''}</dd>
          <dt>Subcriterios</dt><dd>${items.map((it) => it.subcriterion).join(', ') || '—'} <span class="muted">(misma tasa: cambian el umbral y las bases admitidas)</span></dd>
        </dl>
        <p class="hint">El gráfico y la tabla de la serie están en la pestaña Registros.</p>`;
    return criterionCard('A', 'Reducción del tamaño poblacional', html`${body}
      <h6>Causas: A1 (las tres «sí») o A2</h6><ul class="facts">${FACTS.aCauses.map(([k, l]) => factRow(k, l))}</ul>
      <h6>Futuro: A3 y A4</h6><ul class="facts">${FACTS.aFuture.map(([k, l]) => factRow(k, l))}</ul>
      <h6>Bases de la reducción</h6><ul class="facts">${FACTS.aBasis.map(([k, l]) => factRow(k, l))}</ul>`, needs);
  }

  function cardD() {
    const m = data.mature || {};
    const needs = [];
    if ((m.min === null || m.min === undefined) && factValue('plausible_threat') !== 'yes') needs.push(html`Recuentos de maduros (D1) o la ${gotoFact('plausible_threat', 'amenaza plausible')} (D2).`);
    if (factValue('plausible_threat') === null) needs.push(html`Decidir la ${gotoFact('plausible_threat', 'amenaza plausible')} de D2 (solo VU).`);
    return criterionCard('D', 'Población muy pequeña o restringida', html`<dl class="crit-data">
        <dt>Maduros (mejor)</dt><dd>${fmtN(m.best)} <span class="muted">(${fmtN(m.min)}–${fmtN(m.max)})</span></dd>
        <dt>D2 · AOO y localidades</dt><dd>${fmtKm2((data.metrics || {}).aooKm2)} · ${data.locations ? `${data.locations.count} localidades` : 'localidades sin confirmar'}</dd>
      </dl><ul class="facts">${FACTS.d2.map(([k, l]) => factRow(k, l))}</ul>`, needs);
  }

  // --- Localidades -----------------------------------------------------------------------------------
  function locationsHtml() {
    const prop = data.locationsProposal || { groups: [] };
    const loc = data.locations;
    const groupText = (g) => html`<li><strong>${g.label}</strong>: ${g.subpops.join(' + ')} — ${g.noThreat
      ? html`<span class="muted">sin amenaza: localidad no aplicable</span>` : threatLabel(threats, g.threatCode)}</li>`;
    if (editingLocations) return locationsForm();
    return html`<div class="locations" id="loc-${species.taxonID}" tabindex="-1">
      <h6>Localidades (definidas por amenaza)</h6>
      ${locStale() ? html`<p class="guard-band warn">Las subpoblaciones han cambiado desde que se confirmaron (marcas nuevas u otra distancia de
        separación): estas localidades no entran en la evaluación hasta revisarlas.</p>` : ''}
      ${loc ? html`<p><strong>${loc.count}</strong> confirmadas: ${loc.justification}</p><ul class="loc-groups">${(loc.groups || []).map(groupText)}</ul>`
        : html`<p class="hint">Propuesta de BioDex (no cuenta hasta que se confirme): las subpoblaciones bajo el mismo impacto más grave
          en curso o futuro forman una localidad; las no afectadas, una cada una.</p>`}
      ${!loc || prop.count !== loc.count ? html`<p class="eyebrow">Propuesta de BioDex ahora: ${prop.count}</p><ul class="loc-groups">${(prop.groups || []).map(groupText)}</ul>` : ''}
      ${canEdit() && (data.subpopulations.subpops || []).length ? html`<div class="form-actions">
        <button type="button" class="btn-secondary" data-loc-edit>${loc ? 'Revisar las localidades' : 'Confirmar o ajustar la propuesta'}</button>
        ${loc ? html`<button type="button" class="link-btn danger-link" data-loc-clear>Volver a «sin confirmar»</button>` : ''}</div>`
        : !loc ? html`<p class="hint">${EDIT_ROLES.includes(catalog.role) ? 'Las localidades se confirman con red.' : 'Las confirman el propietario y los custodios.'}</p>` : ''}
    </div>`;
  }

  function locationsForm() {
    const subs = (data.subpopulations.subpops || []).map((s) => s.label);
    const from = data.locations && (data.locations.groups || []).length ? data.locations.groups : data.locationsProposal.groups;
    const assign = {};
    from.forEach((g, i) => g.subpops.forEach((s) => { assign[s] = i; }));
    let next = from.length;
    subs.forEach((s) => { if (assign[s] === undefined) assign[s] = next++; });
    const options = Array.from({ length: Math.max(next, subs.length) }, (_, i) => i);
    const loc = data.locations || {};
    return html`<form class="form loc-form" novalidate aria-label="Localidades">
      <p class="hint">Asigna cada subpoblación a una localidad: misma localidad = unir; otra = separar.</p>
      ${subs.map((s) => html`<label class="field field-inline"><span>${s}</span><select name="sub-${s}" data-sub="${s}">
        ${options.map((i) => html`<option value="${i}" ${assign[s] === i ? html`selected` : ''}>L${i + 1}</option>`)}</select></label>`)}
      <p class="loc-count" role="status" aria-live="polite"></p>
      <label class="field"><span>Justificación <em>(obligatoria)</em></span><textarea name="justification" rows="3" maxlength="4000" required>${loc.justification || ''}</textarea></label>
      <label class="field"><span>Calidad del dato</span><select name="quality"><option value="">Sin indicar</option>
        ${Object.entries(QUALITY_LABELS).map(([q, l]) => html`<option value="${q}" ${loc.quality === q ? html`selected` : ''}>${l}</option>`)}</select></label>
      <label class="field"><span>Fuente <em>(opcional)</em></span><input name="source" maxlength="1000" value="${loc.source || ''}"></label>
      <p class="form-error" role="alert" hidden></p>
      <div class="form-actions"><button type="submit" class="btn-primary">Guardar localidades</button>
        <button type="button" class="link-btn" data-loc-cancel>Cancelar</button></div>
    </form>`;
  }

  /** Grupos del formulario de localidades → {count, groups} (impacto del grupo: el de la propuesta si coincide). */
  function locationGroups(form) {
    const byLoc = new Map();
    for (const sel of $$('[data-sub]', form)) {
      const k = Number(sel.value);
      if (!byLoc.has(k)) byLoc.set(k, []);
      byLoc.get(k).push(sel.dataset.sub);
    }
    const prop = data.locationsProposal.groups || [];
    const groups = [...byLoc.entries()].sort((a, b) => a[0] - b[0]).map(([, subs], i) => {
      const hit = prop.find((g) => !g.noThreat && g.subpops.some((s) => subs.includes(s)));
      return { label: `L${i + 1}`, subpops: subs, impactID: hit ? hit.impactID : null, threatCode: hit ? hit.threatCode : null, noThreat: !hit };
    });
    return { count: groups.length, groups };
  }

  // --- Regional, GBIF y guardar ------------------------------------------------------------------------
  function regionalHtml() {
    const r = view.result.regionalV4;
    return html`<section class="crit-card" aria-labelledby="cc-reg"><header class="crit-card-head"><h5 id="cc-reg">Ajuste regional (Directrices regionales v4.0, paso 2)</h5>
      <span class="badge ${view.result.regional && view.result.regional.applied ? 'ok' : ''}">${view.result.regional && view.result.regional.applied
        ? `${view.result.regional.from} → ${view.result.regional.to}` : 'Sin ajuste'}</span></header>
      <ul class="facts">${FACTS.regional.map(([k, l]) => factRow(k, l))}</ul>
      ${r ? html`<p class="hint">${r.justification}</p>` : html`<p class="hint">Responde a las preguntas para aplicar el paso 2 (endémica del área: sin ajuste).</p>`}
    </section>`;
  }

  function gbifHtml() {
    return html`<section class="crit-card gbif-card" aria-labelledby="cc-gbif"><header class="crit-card-head"><h5 id="cc-gbif">Comparación de fuentes (GBIF)</h5></header>
      <label class="check"><input type="checkbox" data-gbif ${gbifOn ? html`checked` : ''} ${state.online ? '' : html`disabled`}>
        <span>Añadir registros de GBIF ${state.online ? '' : '(necesita conexión)'}</span></label>
      <p class="hint">Solo para comparar EOO, AOO, subpoblaciones y B: los registros de GBIF no se guardan como ocurrencias ni se exportan.</p>
      ${gbifOn && !gbifData ? html`<p class="muted" role="status">Consultando GBIF…</p>` : ''}
      ${gbifData ? gbifResult() : ''}
    </section>`;
  }

  function gbifResult() {
    const g = gbifData;
    if (g.error) return html`<p class="guard-band warn">${g.error}</p>`;
    const row = (label, a, b) => html`<tr><th scope="row">${label}</th><td>${a}</td><td>${b}</td></tr>`;
    return html`<div class="table-scroll"><table class="data-table"><caption>Solo BioDex frente a BioDex + GBIF</caption>
      <thead><tr><th scope="col"></th><th scope="col">Solo BioDex</th><th scope="col">BioDex + GBIF</th></tr></thead>
      <tbody>${row('Registros', fmtN(g.biodex.nRecords), fmtN(g.combined.nRecords))}
        ${row('EOO', fmtKm2(g.biodex.eooKm2), fmtKm2(g.combined.eooKm2))}
        ${row('AOO', fmtKm2(g.biodex.aooKm2), fmtKm2(g.combined.aooKm2))}
        ${row('Subpoblaciones', fmtN(g.biodex.subpops), fmtN(g.combined.subpops))}
        ${row('Criterio B', criterionSummary(g.biodex.B), criterionSummary(g.combined.B))}</tbody></table></div>
      <p class="hint">${fmtN(g.gbif.nRecords)} registros de GBIF (${g.gbif.usedInArea} en el área del catálogo) · ${g.gbif.cached ? 'de la caché' : 'consultados ahora'}, ${formatDateTime(g.gbif.fetchedAt)}.
        ${g.gbif.citation} ${g.gbif.doi ? html`<a href="https://doi.org/${g.gbif.doi}" target="_blank" rel="noopener noreferrer">doi:${g.gbif.doi}</a>` : ''}</p>
      <div class="gbif-map" role="img" aria-label="Mapa: registros de BioDex y de GBIF en colores distintos"></div>
      <p class="hint"><span class="legend-dot is-biodex" aria-hidden="true"></span> BioDex <span class="legend-dot is-gbif" aria-hidden="true"></span> GBIF</p>`;
  }

  function saveHtml() {
    if (!canSave) return html`<p class="hint">Tu rol en «${catalog.name}» solo permite leer las evaluaciones.</p>`;
    return html`<form class="form iucn-save" novalidate aria-labelledby="iucnSaveTitle"><h5 id="iucnSaveTitle">Guardar evaluación</h5>
      <p class="hint">Se guarda una instantánea (datos de entrada, resultado, autor y fecha) para compararla con otras más adelante.
        ${source === 'device' ? 'Sin red: es la provisional; el servidor la recalcula al sincronizar.' : ''}</p>
      <label class="field"><span>Justificación <em>(opcional)</em></span><textarea name="justification" rows="3" maxlength="${JUSTIFICATION_MAX}"></textarea></label>
      <label class="check"><input type="checkbox" name="final"><span>Evaluación final (si no, borrador)</span></label>
      <p class="form-error" role="alert" hidden></p>
      <div class="form-actions"><button type="submit" class="btn-primary">Guardar evaluación</button></div>
    </form>`;
  }

  function paint() {
    const r = view.result;
    render(el, html`<div class="iucn-tab">
      <p class="hint">${DISCLAIMER}</p>
      ${head()}
      <p class="sr-only" role="status" aria-live="polite">${status}</p>
      ${(r.warnings || []).length ? html`<ul class="wz-warnings">${r.warnings.map((w) => html`<li>${w}</li>`)}</ul>` : ''}
      ${cardA()}
      ${cardB()}
      ${cardC()}
      ${cardD()}
      ${criterionCard('E', 'Análisis cuantitativo', '', [], { badge: 'Fuera de alcance',
        pending: 'BioDex no evalúa E: haría falta un análisis de viabilidad poblacional (PVA) con series largas, fuera del alcance de la app. Si tienes uno, cítalo en la justificación.' })}
      ${regionalHtml()}
      ${gbifHtml()}
      ${saveHtml()}
      <section class="iucn-snapshots" aria-labelledby="iucnSnaps"><h5 id="iucnSnaps">Evaluaciones guardadas</h5><div class="snap-box"></div></section>
    </div>`);
    // Cada repintado deja la caja nueva: la lista de instantáneas se vuelve a montar en ella.
    if (snapshots) snapshots.destroy();
    snapshots = mountAssessments($('.snap-box', el), { species, catalog, hideEvaluate: true });
    if (gbifData && !gbifData.error) {
      if (gbifMap) gbifMap.destroy();
      gbifMap = mountGbifMap($('.gbif-map', el), gbifData);
    }
    const loc = $('.loc-form', el);
    if (loc) updateLocCount(loc);
    if (editingFact) { const f = $(`.fact-form[data-key="${editingFact}"] [role="radio"].active, .fact-form[data-key="${editingFact}"] [role="radio"]`, el); if (f) f.focus(); }
  }

  function updateLocCount(form) {
    const { count } = locationGroups(form);
    $('.loc-count', form).textContent = `${count} ${count === 1 ? 'localidad' : 'localidades'}`;
  }

  // --- Acciones --------------------------------------------------------------------------------------
  async function storeParam(k, p) {
    if (!hasDb()) return;
    try { if (p) await put('parameters', { ...p, catalogId: catalog.id }); else await del('parameters', [catalog.id, species.taxonID, k]); } catch (e) { /* llegará con el pull */ }
  }

  async function afterChange(message, focusSel) {
    status = message;
    await refresh();
    const f = focusSel && $(focusSel, el);
    if (f) f.focus();
  }

  el.addEventListener('click', async (ev) => {
    const gp = ev.target.closest('[data-goto-param]');
    if (gp) { if (goToParam) goToParam(gp.dataset.gotoParam); return; }
    const gf = ev.target.closest('[data-goto-fact]');
    if (gf) {
      const k = gf.dataset.gotoFact;
      if (k === 'locations') { const box = $('.locations', el); if (box) { box.scrollIntoView({ block: 'center' }); box.focus(); } return; }
      if (canEdit()) { editingFact = k; paint(); return; }
      const row = $(`.fact[data-fact="${k}"]`, el);
      if (row) { row.setAttribute('tabindex', '-1'); row.scrollIntoView({ block: 'center' }); row.focus(); }
      return;
    }
    const ex = ev.target.closest('[data-expert]');
    if (ex) { choices.expertCategory = ex.dataset.expert || null; view = await recompute(data.inputs, choices); paint(); $(`[data-expert="${ex.dataset.expert}"]`, el).focus(); return; }
    const fe = ev.target.closest('[data-fact-edit]');
    if (fe) { editingFact = fe.dataset.factEdit; paint(); return; }
    if (ev.target.closest('[data-fact-cancel]')) { const k = editingFact; editingFact = null; paint(); const b = $(`[data-fact-edit="${k}"]`, el); if (b) b.focus(); return; }
    const fv = ev.target.closest('[data-fact-value]');
    if (fv) {
      const form = fv.closest('form');
      form.elements.namedItem('value').value = fv.dataset.factValue;
      $$('[data-fact-value]', form).forEach((b) => { const on = b === fv; b.classList.toggle('active', on); b.setAttribute('aria-checked', String(on)); });
      return;
    }
    const fc = ev.target.closest('[data-fact-clear]');
    if (fc) {
      try { await apiDeleteJson(`${base}/parameters/${fc.dataset.factClear}`); await storeParam(fc.dataset.factClear, null); editingFact = null;
        await afterChange(`${ALL_FACTS[fc.dataset.factClear]}: sin fijar.`, `[data-fact-edit="${fc.dataset.factClear}"]`); } catch (e) { status = e.message; paint(); }
      return;
    }
    if (ev.target.closest('[data-loc-edit]')) { editingLocations = true; paint(); const s = $('.loc-form select', el); if (s) s.focus(); return; }
    if (ev.target.closest('[data-loc-cancel]')) { editingLocations = false; paint(); const b = $('[data-loc-edit]', el); if (b) b.focus(); return; }
    if (ev.target.closest('[data-loc-clear]')) {
      try { await apiDeleteJson(`${base}/locations`); if (hasDb()) await del('locations', [catalog.id, species.taxonID]).catch(() => {});
        await afterChange('Localidades sin confirmar.', '[data-loc-edit]'); } catch (e) { status = e.message; paint(); }
    }
  });

  el.addEventListener('change', async (ev) => {
    if (ev.target.matches('[data-pe]')) { choices.possiblyExtinct = ev.target.checked; view = await recompute(data.inputs, choices); paint(); const pe = $('[data-pe]', el); if (pe) pe.focus(); return; }
    if (ev.target.matches('[data-gbif]')) {
      gbifOn = ev.target.checked;
      gbifData = null;
      if (!gbifOn && gbifMap) { gbifMap.destroy(); gbifMap = null; }
      paint();
      if (gbifOn) await refresh();
      const g = $('[data-gbif]', el);
      if (g) g.focus();
      return;
    }
    const loc = ev.target.closest('.loc-form');
    if (loc && ev.target.matches('[data-sub]')) updateLocCount(loc);
  });

  el.addEventListener('submit', async (ev) => {
    const form = ev.target;
    ev.preventDefault();
    const err = $('.form-error', form);
    if (err) err.hidden = true;
    const fail = (m) => { if (err) { err.textContent = m; err.hidden = false; } };
    const btn = $('button[type="submit"]', form);
    if (form.matches('.fact-form')) {
      const k = form.dataset.key;
      const value = form.elements.namedItem('value').value;
      if (!value) { fail('Elige sí, no o desconocido.'); return; }
      btn.disabled = true;
      try {
        const res = await apiPutJson(`${base}/parameters/${k}`, { value, quality: form.elements.namedItem('quality').value || null,
          justification: form.elements.namedItem('justification').value, source: form.elements.namedItem('source').value });
        await storeParam(k, res.parameter);
        editingFact = null;
        await afterChange(`${ALL_FACTS[k]}: ${FACT_LABELS[value]}.`, `[data-fact-edit="${k}"]`);
      } catch (e) { fail(e.message); btn.disabled = false; }
      return;
    }
    if (form.matches('.loc-form')) {
      const { count, groups } = locationGroups(form);
      const justification = form.elements.namedItem('justification').value.trim();
      if (!justification) { fail('Escribe por qué estas son las localidades (obligatorio).'); form.elements.namedItem('justification').focus(); return; }
      btn.disabled = true;
      try {
        const res = await apiPutJson(`${base}/locations`, { count, groups, justification, quality: form.elements.namedItem('quality').value || null,
          source: form.elements.namedItem('source').value, separationKm: (data.subpopulations || {}).separationKm || null });
        if (hasDb() && res.locations) await put('locations', { ...res.locations, catalogId: catalog.id }).catch(() => {});
        editingLocations = false;
        await afterChange(`${count} ${count === 1 ? 'localidad confirmada' : 'localidades confirmadas'}.`, '[data-loc-edit]');
      } catch (e) { fail(e.message); btn.disabled = false; }
      return;
    }
    if (form.matches('.iucn-save')) {
      btn.disabled = true;
      const r = view.result;
      try {
        const regionalJust = r.regionalV4 && r.regional && r.regional.applied ? r.regionalV4.justification : '';
        const just = form.elements.namedItem('justification').value.trim() || regionalJust;
        await saveAssessment({ catalogId: catalog.id, taxonID: species.taxonID, inputs: view.inputs,
          result: { ...r, quantifiers: quantifierSummary(data), computedBy: source === 'server' ? 'server' : 'device' },
          justification: just, final: form.elements.namedItem('final').checked });
        requestSync();
        status = 'Evaluación guardada.';
        form.reset();
        if (snapshots) snapshots.refresh();
        btn.disabled = false;
        const h = $('#iucnSnaps', el);
        if (h) { h.setAttribute('tabindex', '-1'); h.focus(); }
      } catch (e) { fail(e.message); btn.disabled = false; }
    }
  });

  refresh();
  return { refresh, destroy() { alive = false; if (snapshots) snapshots.destroy(); if (gbifMap) gbifMap.destroy(); } };
}
