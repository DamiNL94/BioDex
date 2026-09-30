// ================================================================================================
// iucn-wizard.js — asistente de evaluación UICN de una especie en el catálogo activo (F8b).
// ------------------------------------------------------------------------------------------------
// Pasos A → B → C → D → E → Resultado; cada criterio se puede saltar («Sin datos para este criterio»).
// En cada paso: la tabla de umbrales CR/EN/VU de ese criterio y el resultado parcial en vivo. El motor
// es shared/iucn-engine.js y los umbrales, shared/iucn-criteria.json (los dos precacheados por el SW):
// funciona SIN RED y guarda en IndexedDB + cola (marks.js › saveAssessment), como las marcas.
// BioDex PROPONE y explica; la decisión es del investigador (aviso fijo arriba).
// Datos que BioDex sugiere (siempre editables, con su origen): maduros desde el resumen de las marcas
// (F8a, con el aviso de doble conteo); EOO/AOO desde las marcas (F9, js/metrics.js: el definitivo del
// servidor o, sin red, el provisional del dispositivo), con su tamaño de celda.

// Todo el HTML pasa por html`` (la justificación y los nombres vienen de usuarios).
// ================================================================================================

import { evaluateIucn, categoryLabel, criterionSummary } from '../../shared/iucn-engine.js';
import { html, render, $, $$, sciName } from '../util.js';
import { openOverlay, confirmDialog } from '../components/overlay.js';
import { saveAssessment, marksFor, JUSTIFICATION_MAX } from '../marks.js';
import { speciesSummary } from '../components/marks.js';
import { requestSync } from '../sync.js';
import { speciesMetrics, PROVISIONAL_TEXT, fmtMetricsDate } from '../metrics.js';
import { CELL_1KM_WARNING } from '../../shared/geo-metrics.js';

export const DISCLAIMER = 'Propuesta orientativa. Una evaluación formal requiere las Directrices de uso de las ' +
  'Categorías y Criterios de la Lista Roja de la UICN.';
const CRITERIA_URL = '/shared/iucn-criteria.json';
const STEPS = ['A', 'B', 'C', 'D', 'E', 'R'];
const STEP_TITLES = { R: 'Resultado' };
const MATURE_SOURCES = {
  'marks-sum': 'suma de maduros de las marcas del catálogo',
  'marks-maxday': 'máximo de individuos en un día de las marcas del catálogo',
};

let criteriaPromise = null;
/** Umbrales UICN (una vez por sesión; sin red los sirve el SW desde su caché). */
export function loadIucnCriteria() {
  if (!criteriaPromise) {
    criteriaPromise = fetch(CRITERIA_URL).then((r) => {
      if (!r.ok) throw new Error(`HTTP ${r.status}`);
      return r.json();
    }).catch((e) => { criteriaPromise = null; throw e; });
  }
  return criteriaPromise;
}

export function blankInputs() {
  return {
    generationLength: null,
    A: { skip: false, reductionPct: null, subcriterion: null, basis: [] },
    B: { skip: false, eoo: null, aoo: null, aooCellKm: null, metricsOrigin: null, eooEdited: false, aooEdited: false,
      severelyFragmented: false, locations: null, decline: [], fluctuation: [] },
    C: { skip: false, mature: null, matureSource: null, c1DeclinePct: null, c1Years: null, continuingDecline: false,
      maxPerSubpop: null, pctInOneSubpop: null, extremeFluctuation: false },
    D: { skip: false, mature: null, matureSource: null, plausibleThreat: false, aoo: null, locations: null },
    E: { skip: false, probabilityPct: null, horizonYears: null },
    dataDeficient: false,
    expertCategory: null,
    regional: { direction: null },
  };
}

function mergeInputs(saved) {
  const base = blankInputs();
  if (!saved || typeof saved !== 'object') return base;
  const out = { ...base, ...saved };
  for (const k of ['A', 'B', 'C', 'D', 'E', 'regional']) out[k] = { ...base[k], ...(saved[k] || {}) };
  return out;
}

// --- Formato ---------------------------------------------------------------------------------------
const fmtN = (n) => (typeof n === 'number' ? n.toLocaleString('es-ES') : n);
/** «(i) EOO; (ii) AOO; …» → {i: 'EOO', ii: 'AOO', …} (textos de shared/iucn-criteria.json). */
function romanItems(text) {
  const out = {};
  for (const m of String(text).matchAll(/\((i{1,3}|iv|v)\)\s*([^;]+)/g)) out[m[1]] = m[2].trim();
  return out;
}
/** Valor de un campo numérico: '' → null; número si se puede; si no, el texto (el motor avisa). */
function parseNum(v) {
  const s = String(v ?? '').trim();
  if (!s) return null;
  const n = Number(s.replace(',', '.'));
  return Number.isFinite(n) ? n : s;
}
const valueOf = (v) => (v === null || v === undefined ? '' : String(v));

/** Distintivo de categoría con su color de la casa. */
export function categoryBadge(code, extra = '') {
  return html`<span class="badge iucn iucn-${code || 'none'} ${extra}">${code || 'Sin categoría'}</span>`;
}

// --- Tablas de umbrales (todo sale del JSON) -------------------------------------------------------
function thresholdTable(key, crit) {
  const C = crit.criteria;
  const cats = crit.categories;
  const rows = [];
  const row = (label, fn) => rows.push(html`<tr><th scope="row">${label}</th>${cats.map((c) => html`<td>${fn(c)}</td>`)}</tr>`);
  if (key === 'A') {
    row('A1 · reducción', (c) => `≥ ${C.A.thresholdsPct.A1[c]} %`);
    row('A2, A3, A4 · reducción', (c) => `≥ ${C.A.thresholdsPct.A2_A3_A4[c]} %`);
  } else if (key === 'B') {
    row('B1 · EOO', (c) => `< ${fmtN(C.B.thresholdsKm2.B1_EOO[c])} km²`);
    row('B2 · AOO', (c) => `< ${fmtN(C.B.thresholdsKm2.B2_AOO[c])} km²`);
    row('(a) localidades', (c) => (C.B.conditions.a.maxLocations[c] === 1 ? '= 1' : `≤ ${C.B.conditions.a.maxLocations[c]}`));
  } else if (key === 'C') {
    row('Individuos maduros', (c) => `< ${fmtN(C.C.matureIndividualsLessThan[c])}`);
    row('C1 · disminución', (c) => C.C.C1.values[c]);
    row('C2a(i) · máx. por subpoblación', (c) => `≤ ${fmtN(C.C.C2.a_i_maxMaturePerSubpop[c])}`);
    row('C2a(ii) · en una subpoblación', (c) => C.C.C2.a_ii_pctInOneSubpop[c]);
  } else if (key === 'D') {
    row('Individuos maduros', (c) => `< ${fmtN(C.D.matureIndividualsLessThan[c])}`);
    row('D2', (c) => (C.D.D2.categories.includes(c) ? `AOO < ${C.D.D2.aooLessThanKm2} km² o ≤ ${C.D.D2.maxLocations} localidades` : '—'));
  } else if (key === 'E') {
    row('Probabilidad de extinción', (c) => C.E.values[c]);
  }
  return html`<div class="table-scroll wz-thr"><table class="data-table">
    <caption class="sr-only">Umbrales del criterio ${key}</caption>
    <thead><tr><th scope="col">Umbral</th>${cats.map((c) => html`<th scope="col" class="iucn-${c}">${c}</th>`)}</tr></thead>
    <tbody>${rows}</tbody></table></div>`;
}

function thresholdNote(key, crit) {
  const C = crit.criteria;
  if (key === 'A') return `Periodo: el mayor de ${C.A.period.minYears} años y ${C.A.period.generations} generaciones (A3 y A4: como mucho ${C.A.period.maxYears} años).`;
  if (key === 'B') return `Hacen falta B1 y/o B2 y al menos ${C.B.requireAtLeast} de (a), (b), (c) cumplidos con el umbral de la MISMA categoría. (b) y (c) valen igual en las tres; la fragmentación severa cumple (a) en cualquiera.`;
  if (key === 'C') return 'Además de pocos maduros, C1 o C2. C2 exige disminución continua y a(i), a(ii) o (b) fluctuaciones extremas.';
  if (key === 'D') return `D2 solo lleva a VU: ${C.D.D2_VU_only}.`;
  return 'Solo con un análisis cuantitativo (p. ej. un AVP) que dé la probabilidad y su horizonte.';
}

// --- Asistente -------------------------------------------------------------------------------------

/**
 * Abre el asistente. `assessment`: una evaluación propia para editarla (si no, una nueva).
 * `onSaved(rec)` tras guardar.
 */
export function openIucnWizard({ catalog, species, assessment = null, onSaved } = {}) {
  let crit = null;
  let step = 0;
  let dirty = false;
  let summary = null;          // resumen de marcas (F8a) para las sugerencias de C y D
  let calcState = null;        // cálculo de EOO/AOO en curso o fallido (F9)
  const inputs = mergeInputs(assessment && assessment.inputs);
  let justification = assessment ? assessment.justification || '' : '';

  const ov = openOverlay({
    key: 'asistente-uicn', title: 'Evaluación UICN', variant: 'side', className: 'iucn-wizard',
    isDirty: () => dirty,
    confirmClose: () => confirmDialog({ key: 'descartar-evaluacion', title: 'Descartar la evaluación', danger: true,
      confirmLabel: 'Descartar', text: html`<p>Hay datos sin guardar. Guárdala como borrador si quieres seguir otro día.</p>` }),
  });
  render(ov.body, html`<p class="muted">Cargando los criterios UICN…</p>`);

  Promise.all([
    loadIucnCriteria(),
    marksFor(catalog.id, species.taxonID).then((m) => speciesSummary([...m.own, ...m.others])).catch(() => null),
  ]).then(([c, s]) => {
    if (!ov.isOpen()) return;
    crit = c;
    summary = s;
    paintShell();
  }).catch(() => {
    if (!ov.isOpen()) return;
    render(ov.body, html`<p class="guard-band warn">No se han podido cargar los umbrales UICN. Abre BioDex una vez con red
      para que queden guardados en el dispositivo.</p>`);
  });

  const evaluate = () => evaluateIucn({ ...inputs, regional: { ...inputs.regional, justification } }, crit);

  function paintShell() {
    render(ov.body, html`
      <p class="wz-notice" role="note">${DISCLAIMER}</p>
      <p class="wz-species">${sciName(species)}<span class="muted"> · ${catalog.name}</span></p>
      <nav class="wz-steps" aria-label="Pasos de la evaluación"><ol></ol></nav>
      <form class="form wz-form" novalidate>
        <div class="wz-step"></div>
        <div class="sheet-actions wz-actions"></div>
      </form>`);
    const form = $('.wz-form', ov.body);
    form.addEventListener('input', onEdit);
    form.addEventListener('change', onEdit);
    form.addEventListener('click', onClick);
    form.addEventListener('submit', (ev) => ev.preventDefault());
    $('.wz-steps', ov.body).addEventListener('click', (ev) => {
      const b = ev.target.closest('[data-goto]');
      if (b) go(Number(b.dataset.goto));
    });
    paintStep();
  }

  function paintNav() {
    const r = evaluate();
    render($('.wz-steps ol', ov.body), html`${STEPS.map((k, i) => {
      const c = k === 'R' ? null : r.byCriterion[k];
      const state_ = !c ? '' : c.status === 'skipped' ? 'is-skipped' : c.category ? 'is-met' : 'is-data';
      return html`<li><button type="button" class="wz-step-btn ${state_}" data-goto="${i}"
        ${i === step ? html`aria-current="step"` : ''}>${k === 'R' ? 'Resultado' : k}<span class="sr-only">${
          !c ? '' : c.status === 'skipped' ? ' (sin datos)' : c.category ? ` (${c.category})` : ''}</span></button></li>`;
    })}`);
  }

  function go(i) {
    readStep();
    step = Math.max(0, Math.min(STEPS.length - 1, i));
    paintStep();
    const h = $('.wz-step h3', ov.body);
    if (h) { h.setAttribute('tabindex', '-1'); h.focus(); }
  }

  function paintStep() {
    const key = STEPS[step];
    const box = $('.wz-step', ov.body);
    paintNav();
    if (key === 'R') paintResult(box);
    else {
      const c = crit.criteria[key];
      const skip = inputs[key].skip;
      render(box, html`
        <h3>${key} · ${c.name}</h3>
        ${thresholdTable(key, crit)}
        <p class="hint wz-thr-note">${thresholdNote(key, crit)}</p>
        <label class="check big-check wz-skip"><input type="checkbox" name="${key}.skip" ${skip ? html`checked` : ''}>
          <span>Sin datos para este criterio</span></label>
        <fieldset class="wz-fields" ${skip ? html`disabled` : ''}><legend class="sr-only">Datos del criterio ${key}</legend>
          ${FIELDS[key]()}</fieldset>
        <section class="wz-live" aria-live="polite" aria-label="Resultado parcial"></section>`);
      paintLive();
    }
    paintActions();
  }

  function paintActions() {
    const last = step === STEPS.length - 1;
    const bar = $('.wz-actions', ov.body);
    if (last) {
      render(bar, html`<button type="button" class="btn-primary" data-save="final">Guardar como final</button>
        <button type="button" class="btn-secondary" data-save="draft">Guardar borrador</button>
        <button type="button" class="link-btn" data-prev>Anterior</button>`);
    } else {
      render(bar, html`<button type="button" class="btn-primary" data-next>${step === STEPS.length - 2 ? 'Ver resultado' : `Siguiente: ${STEPS[step + 1]}`}</button>
        ${step > 0 ? html`<button type="button" class="link-btn" data-prev>Anterior</button>` : ''}`);
    }
  }

  // --- Campos de cada paso -------------------------------------------------------------------------
  const num = (name, label, value, { unit = '', hint = '', integer = false, placeholder = '' } = {}) => html`
    <label class="field wz-num"><span>${label}${unit ? html` <em>(${unit})</em>` : ''}</span>
      <input type="text" name="${name}" inputmode="${integer ? 'numeric' : 'decimal'}" autocomplete="off"
        value="${valueOf(value)}" placeholder="${placeholder}">
      ${hint ? html`<small class="hint">${hint}</small>` : ''}</label>`;
  const box = (name, label, checked, value = null) => html`<label class="check">
    <input type="checkbox" name="${name}" ${value !== null ? html`value="${value}"` : ''} ${checked ? html`checked` : ''}>
    <span>${label}</span></label>`;

  const FIELDS = {
    A: () => {
      const A = crit.criteria.A;
      const a = inputs.A;
      const banned = (A.basisNotAllowed && A.basisNotAllowed[a.subcriterion]) || [];
      return html`
        ${num('generationLength', 'Duración de una generación', inputs.generationLength, { unit: 'años, opcional',
          hint: 'La usan A (periodo), C1 y E. Sin ella solo cuentan los años.' })}
        <fieldset class="wz-group"><legend>Subcriterio</legend>
          ${Object.entries(A.subcriteria).map(([k, text]) => html`<label class="check wz-radio">
            <input type="radio" name="A.subcriterion" value="${k}" ${a.subcriterion === k ? html`checked` : ''}>
            <span><strong>${k}</strong> · ${text}</span></label>`)}
        </fieldset>
        ${num('A.reductionPct', 'Reducción de la población', a.reductionPct, { unit: '%' })}
        <fieldset class="wz-group"><legend>Basada en (al menos una)</legend>
          ${Object.entries(A.basis).map(([k, text]) => html`<label class="check">
            <input type="checkbox" name="A.basis" value="${k}" ${a.basis.includes(k) ? html`checked` : ''}
              ${banned.includes(k) ? html`disabled` : ''}>
            <span>(${k}) ${text}${banned.includes(k) ? html` <em class="muted">— ${a.subcriterion} no la admite</em>` : ''}</span></label>`)}
        </fieldset>`;
    },
    B: () => {
      const B = crit.criteria.B;
      const b = inputs.B;
      const dec = romanItems(B.conditions.b.text);
      const flu = romanItems(B.conditions.c.text);
      return html`
        <div class="wz-suggest">
          <p><strong>Calcular EOO y AOO desde las marcas de «${catalog.name}»</strong></p>
          <div class="form-actions">
            ${B.metrics.aooCellSizeKmOptions.map((k) => html`<button type="button" class="btn-secondary" data-calc-b="${k}">${
              k === B.metrics.aooCellSizeKmDefault ? `Calcular (celdas de ${k} km)` : `Con celdas de ${k} km`}</button>`)}
          </div>
          <div class="wz-calc">${calcOriginHtml()}</div>
          <p class="hint">La UICN mide la AOO en celdas de ${B.metrics.aooCellSizeKmDefault} × ${B.metrics.aooCellSizeKmDefault} km: los
            umbrales de B2 están calibrados para esa escala. Se guarda el tamaño de celda usado.</p>
          <p class="hint">El nº de localidades no se calcula
: depende de las amenazas y lo introduces tú, abajo.</p>
        </div>
        <div class="wz-pair">
          ${num('B.eoo', 'EOO · extensión de presencia', b.eoo, { unit: 'km²' })}
          ${num('B.aoo', 'AOO · área de ocupación', b.aoo, { unit: 'km²', hint: `Celdas de ${b.aooCellKm || B.metrics.aooCellSizeKmDefault}×${b.aooCellKm || B.metrics.aooCellSizeKmDefault} km.` })}
        </div>
        <fieldset class="wz-group"><legend>(a) ${B.conditions.a.text}</legend>
          ${box('B.severelyFragmented', 'Severamente fragmentada', b.severelyFragmented)}
          ${num('B.locations', 'Nº de localidades', b.locations, { integer: true, hint: 'Localidad: área donde una sola amenaza puede afectar rápidamente a todos los individuos.' })}
        </fieldset>
        <fieldset class="wz-group"><legend>(b) Disminución continua (observada, estimada, inferida o proyectada) en</legend>
          ${B.conditions.b.items.map((i) => box('B.decline', `(${i}) ${dec[i] || ''}`, b.decline.includes(i), i))}
        </fieldset>
        <fieldset class="wz-group"><legend>(c) Fluctuaciones extremas en</legend>
          ${B.conditions.c.items.map((i) => box('B.fluctuation', `(${i}) ${flu[i] || ''}`, b.fluctuation.includes(i), i))}
        </fieldset>`;
    },
    C: () => {
      const c = inputs.C;
      return html`
        ${matureField('C')}
        <fieldset class="wz-group"><legend>C1 · ${crit.criteria.C.C1.text}</legend>
          <div class="wz-pair">
            ${num('C.c1DeclinePct', 'Disminución continua estimada', c.c1DeclinePct, { unit: '%' })}
            ${num('C.c1Years', 'En un plazo de', c.c1Years, { unit: 'años' })}
          </div>
        </fieldset>
        <fieldset class="wz-group"><legend>C2 · ${crit.criteria.C.C2.text}</legend>
          ${box('C.continuingDecline', 'Disminución continua de los individuos maduros', c.continuingDecline)}
          ${num('C.maxPerSubpop', 'a(i) Máx. de maduros en una subpoblación', c.maxPerSubpop, { integer: true })}
          ${num('C.pctInOneSubpop', 'a(ii) Maduros en una sola subpoblación', c.pctInOneSubpop, { unit: '%' })}
          ${box('C.extremeFluctuation', '(b) Fluctuaciones extremas en el nº de maduros', c.extremeFluctuation)}
        </fieldset>`;
    },
    D: () => {
      const d = inputs.D;
      const b = inputs.B.skip ? {} : inputs.B;
      return html`
        ${matureField('D')}
        <fieldset class="wz-group"><legend>D2 · solo VU</legend>
          ${box('D.plausibleThreat', 'Hay una amenaza futura plausible que podría llevarla a CR o EX en muy poco tiempo', d.plausibleThreat)}
          <div class="wz-pair">
            ${num('D.aoo', 'AOO', d.aoo, { unit: 'km²', placeholder: b.aoo !== null && b.aoo !== undefined ? `de B: ${b.aoo}` : '' })}
            ${num('D.locations', 'Nº de localidades', d.locations, { integer: true,
              placeholder: b.locations !== null && b.locations !== undefined ? `de B: ${b.locations}` : '' })}
          </div>
          <p class="hint">Vacíos: se usan los del paso B.</p>
        </fieldset>`;
    },
    E: () => {
      const e = inputs.E;
      return html`<div class="wz-pair">
          ${num('E.probabilityPct', 'Probabilidad de extinción', e.probabilityPct, { unit: '%' })}
          ${num('E.horizonYears', 'En un horizonte de', e.horizonYears, { unit: 'años' })}
        </div>`;
    },
  };

  /** Origen de EOO/AOO calculados desde las marcas (F9): servidor con fecha o provisional; editables. */
  function calcOriginHtml() {
    const b = inputs.B;
    const o = b.metricsOrigin;
    if (calcState && calcState.loading) return html`<p class="hint">Calculando…</p>`;
    if (calcState && calcState.error) return html`<p class="guard-band warn">${calcState.error}</p>`;
    if (!o) return '';
    const edited = [b.eooEdited ? 'EOO' : null, b.aooEdited ? 'AOO' : null].filter(Boolean);
    return html`<p class="hint wz-origin">Origen: ${o.source === 'server'
      ? `servidor, ${fmtMetricsDate(o.computedAt)}` : PROVISIONAL_TEXT(o.nExact)} · celdas de ${o.cellSizeKm} km ·
      ${o.nRecords} ${o.nRecords === 1 ? 'registro' : 'registros'}${o.nExcluded ? ` (${o.nExcluded} excluidos)` : ''}${
      edited.length ? ` · ${edited.join(' y ')} ${edited.length > 1 ? 'cambiadas' : 'cambiada'} a mano` : ''}.</p>
      ${o.cellSizeKm !== crit.criteria.B.metrics.aooCellSizeKmDefault ? html`<ul class="wz-warnings"><li>${CELL_1KM_WARNING}</li></ul>` : ''}
      ${o.eooKm2 === null ? html`<p class="hint">Sin EOO: menos de 3 puntos no colineales.</p>` : ''}`;
  }

  async function calcB(cellKm) {
    readStep();
    calcState = { loading: true };
    const box = $('.wz-calc', ov.body);
    if (box) render(box, calcOriginHtml());
    try {
      const res = await speciesMetrics(catalog.id, species.taxonID, { scope: 'team', cellKm });
      const m = res.data;
      Object.assign(inputs.B, { eoo: m.eooKm2, aoo: m.aooKm2, aooCellKm: m.cellSizeKm, eooEdited: false, aooEdited: false,
        metricsOrigin: { source: res.source, computedAt: m.computedAt, cellSizeKm: m.cellSizeKm, nRecords: m.nRecords,
          nExcluded: m.nExcluded, nExact: m.nExact ?? null, eooKm2: m.eooKm2 } });
      calcState = null;
      dirty = true;
    } catch (e) {
      calcState = { error: e.message };
    }
    if (ov.isOpen() && STEPS[step] === 'B') paintStep();
  }

  /** Maduros (C y D) con lo que BioDex puede sugerir desde las marcas, siempre editable y con su origen. */
  function matureField(key) {
    const x = inputs[key];
    const s = summary;
    const other = key === 'D' ? inputs.C : inputs.D;
    return html`
      ${num(`${key}.mature`, 'Individuos maduros', x.mature, { integer: true })}
      ${x.matureSource && MATURE_SOURCES[x.matureSource] ? html`<p class="hint wz-origin">Origen: ${MATURE_SOURCES[x.matureSource]}
        (sugerido por BioDex; puedes cambiarlo).</p>` : ''}
      <div class="wz-suggest">
        <p><strong>Desde las marcas de «${catalog.name}»</strong></p>
        ${s && (s.marks || s.absences) ? html`
          <div class="form-actions">
            <button type="button" class="btn-secondary" data-suggest="${key}:marks-sum:${s.matureSum}">Suma de maduros: ${s.matureSum}</button>
            ${s.maxDay ? html`<button type="button" class="btn-secondary" data-suggest="${key}:marks-maxday:${s.maxDay.count}">Máx. en un día: ${s.maxDay.count}</button>` : ''}
          </div>
          <p class="hint">Suma de registros, puede contar varias veces a los mismos individuos. El máximo en un día
            cuenta individuos, no solo maduros.</p>`
          : html`<p class="hint">Aún no hay marcas de esta especie en el catálogo (o no se han sincronizado).</p>`}
        ${other.mature !== null && other.mature !== undefined && other.mature !== x.mature
          ? html`<button type="button" class="link-btn" data-copy-mature="${key}">Usar el de ${key === 'D' ? 'C' : 'D'}: ${other.mature}</button>` : ''}
      </div>`;
  }

  // --- Lectura del formulario y resultado en vivo --------------------------------------------------
  function readStep() {
    const form = $('.wz-form', ov.body);
    if (!form) return;
    const lists = new Map();
    for (const el of $$('input, select, textarea', $('.wz-step', form))) {
      if (!el.name) continue;
      if (el.name === 'justification') { justification = el.value; continue; }
      // Solo «X.campo» y generationLength; los radios del resultado (nothreat, regional) van en onEdit.
      if (!/^([A-E]\.\w+|generationLength)$/.test(el.name)) continue;
      const [k, f] = el.name.includes('.') ? el.name.split('.') : [null, el.name];
      const target = k ? inputs[k] : inputs;
      if (!target) continue;
      if (el.type === 'checkbox' && el.value !== 'on') {
        if (!lists.has(el.name)) lists.set(el.name, { target, f, values: [] });
        if (el.checked) lists.get(el.name).values.push(el.value);
      } else if (el.type === 'checkbox') target[f] = el.checked;
      else if (el.type === 'radio') { if (el.checked) target[f] = el.value; }
      else target[f] = parseNum(el.value);
    }
    for (const { target, f, values } of lists.values()) target[f] = values;
  }

  function onEdit(ev) {
    const el = ev.target;
    if (!el.name) return;
    dirty = true;
    const key = STEPS[step];
    // Un maduro escrito a mano deja de ser «sugerido».
    if (/^[CD]\.mature$/.test(el.name) && ev.type === 'input') inputs[el.name[0]].matureSource = null;
    // EOO/AOO calculados y luego cambiados a mano: se dice en el origen.
    if (el.name === 'B.eoo' && ev.type === 'input' && inputs.B.metricsOrigin) inputs.B.eooEdited = true;
    if (el.name === 'B.aoo' && ev.type === 'input' && inputs.B.metricsOrigin) inputs.B.aooEdited = true;
    readStep();
    if (el.name === `${key}.skip`) { const fs = $('.wz-fields', ov.body); if (fs) fs.disabled = el.checked; }
    // A3 no admite (a): se repinta para deshabilitarla.
    if (el.name === 'A.subcriterion' && ev.type === 'change') {
      const banned = (crit.criteria.A.basisNotAllowed || {})[inputs.A.subcriterion] || [];
      inputs.A.basis = inputs.A.basis.filter((b) => !banned.includes(b));
      paintStep();
      const again = $(`input[name="A.subcriterion"][value="${inputs.A.subcriterion}"]`, ov.body);
      if (again) again.focus();
      return;
    }
    if (el.name === 'nothreat') {
      const o = crit.outcomes;
      inputs.dataDeficient = el.value === o.dataDeficient;
      inputs.expertCategory = o.expertJudgement.includes(el.value) ? el.value : null;
    }
    if (el.name === 'regional') inputs.regional.direction = el.value || null;
    if (key === 'R') paintFinalSummary();
    else paintLive();
    paintNav();
  }

  function onClick(ev) {
    const t = ev.target;
    if (t.closest('[data-next]')) { go(step + 1); return; }
    if (t.closest('[data-prev]')) { go(step - 1); return; }
    const calc = t.closest('[data-calc-b]');
    if (calc) { calcB(Number(calc.dataset.calcB)); return; }
    const sug = t.closest('[data-suggest]');
    if (sug) {
      const [key, source, value] = sug.dataset.suggest.split(':');
      inputs[key].mature = Number(value);
      inputs[key].matureSource = source;
      dirty = true;
      paintStep();
      const input = $(`input[name="${key}.mature"]`, ov.body);
      if (input) input.focus();
      return;
    }
    const copy = t.closest('[data-copy-mature]');
    if (copy) {
      const key = copy.dataset.copyMature;
      const from = key === 'D' ? inputs.C : inputs.D;
      inputs[key].mature = from.mature;
      inputs[key].matureSource = from.matureSource;
      dirty = true;
      paintStep();
      return;
    }
    const save = t.closest('[data-save]');
    if (save) doSave(save.dataset.save === 'final', save);
  }

  function levelRows(res) {
    return crit.categories.map((c) => {
      const lv = res.levels[c];
      if (!lv.reasons.length) return '';
      return html`<li class="${lv.met ? 'is-met' : ''}"><span class="badge iucn iucn-${c}">${c}</span>
        <div><span class="wz-verdict">${lv.met ? `Sí · ${lv.notation}` : 'No'}</span>
          <span class="wz-reasons">${lv.reasons.join(' · ')}</span></div></li>`;
    });
  }

  function paintLive() {
    const key = STEPS[step];
    const live = $('.wz-live', ov.body);
    if (!live) return;
    const r = evaluate();
    const c = r.byCriterion[key];
    const warn = r.warnings.filter((w) => w.startsWith(key));  // «B: …», «C1: …», «C2a(i): …»
    render(live, html`
      <p class="wz-live-head"><span class="eyebrow">Resultado de ${key}</span>
        <strong class="iucn-${c.category || 'none'}">${criterionSummary(c)}</strong></p>
      ${c.status !== 'skipped' ? html`<ul class="wz-levels">${levelRows(c)}</ul>` : ''}
      ${c.notes.length ? html`<ul class="wz-notes">${c.notes.map((n) => html`<li>${n}</li>`)}</ul>` : ''}
      ${warn.length ? html`<ul class="wz-warnings">${warn.map((w) => html`<li>${w}</li>`)}</ul>` : ''}
      <p class="wz-live-total">Con todo lo introducido: <strong class="iucn-${r.baseCategory || 'none'}">${r.criteriaString || r.message || '—'}</strong></p>`);
  }

  // --- Resultado ------------------------------------------------------------------------------------
  function paintResult(el) {
    const o = crit.outcomes;
    const adj = o.regionalAdjustment.directions;
    render(el, html`
      <h3>Resultado</h3>
      <div class="wz-final"></div>
      <fieldset class="wz-group wz-nothreat"><legend>Si no alcanza umbrales de amenaza</legend>
        ${[...o.expertJudgement.map((k) => [k, `${k} · ${categoryLabel(crit, k)} (juicio experto)`]),
          [o.dataDeficient, `${o.dataDeficient} · ${categoryLabel(crit, o.dataDeficient)}: faltan datos para evaluarla`],
          ['', 'Aún no lo decido']].map(([k, label]) => html`<label class="check wz-radio">
          <input type="radio" name="nothreat" value="${k}" ${currentNoThreat() === k ? html`checked` : ''}><span>${label}</span></label>`)}
      </fieldset>
      <fieldset class="wz-group"><legend>Ajuste regional <em class="muted">(Directrices regionales de la UICN)</em></legend>
        ${[['', 'Sin ajuste'], ['down', adj.down], ['up', adj.up]].map(([k, label]) => html`<label class="check wz-radio">
          <input type="radio" name="regional" value="${k}" ${(inputs.regional.direction || '') === k ? html`checked` : ''}><span>${label}</span></label>`)}
      </fieldset>
      <label class="field"><span>Justificación <em>(obligatoria con ajuste regional)</em></span>
        <textarea name="justification" rows="4" maxlength="${JUSTIFICATION_MAX}"
          placeholder="Fuentes de los datos, supuestos, por qué este criterio, motivo del ajuste…">${justification}</textarea></label>
      <p class="form-error" role="alert" hidden></p>`);
    paintFinalSummary();
  }

  function currentNoThreat() {
    if (inputs.dataDeficient) return crit.outcomes.dataDeficient;
    return inputs.expertCategory || '';
  }

  function paintFinalSummary() {
    const el = $('.wz-final', ov.body);
    if (!el) return;
    const r = evaluate();
    const threatened = r.basis === 'criteria';
    const nothreat = $('.wz-nothreat', ov.body);
    if (nothreat) nothreat.hidden = threatened;
    const dirLabel = r.regional.direction === 'down' ? 'rebaja' : 'subida';
    render(el, html`
      <div class="wz-cat-box iucn-edge-${r.category || 'none'}">
        <p class="eyebrow">Categoría propuesta</p>
        <p class="wz-cat iucn-${r.category || 'none'}">${r.category || '—'}</p>
        <p class="wz-cat-label">${r.category ? categoryLabel(crit, r.category) : 'Sin categoría'}</p>
        ${r.criteriaString ? html`<p class="wz-criteria">${r.criteriaString}</p>` : ''}
        ${r.regional.applied ? html`<p class="wz-adjust">Antes del ajuste regional: <strong class="iucn-${r.baseCategory}">${r.baseCategory}</strong>
          → después: <strong class="iucn-${r.category}">${r.category}</strong> (${dirLabel} de un escalón)</p>` : ''}
        ${threatened ? html`<p class="wz-determines">La determina: ${r.determining.map((k, i) => html`${i ? ', ' : ''}<strong>${k}</strong>
          (${r.byCriterion[k].levels[r.baseCategory].notation})`)}</p>`
          : r.basis === 'expert' ? html`<p class="wz-determines">Por juicio experto: no alcanza umbrales de amenaza.</p>`
            : r.basis === 'dd' ? html`<p class="wz-determines">Datos insuficientes, elegido por ti.</p>` : ''}
        ${r.message && r.basis === 'none' ? html`<p class="guard-band warn">${r.message}</p>` : ''}
      </div>
      ${r.warnings.length ? html`<ul class="wz-warnings" aria-label="Avisos">${r.warnings.map((w) => html`<li>${w}</li>`)}</ul>` : ''}
      <table class="data-table wz-by">
        <caption class="sr-only">Resultado por criterio</caption>
        <thead><tr><th scope="col">Criterio</th><th scope="col">Resultado</th></tr></thead>
        <tbody>${Object.entries(r.byCriterion).map(([k, c]) => html`<tr class="${r.determining.includes(k) ? 'is-determining' : ''}">
          <th scope="row">${k}</th><td class="iucn-${c.category || 'none'}">${criterionSummary(c)}</td></tr>`)}</tbody>
      </table>
      <p class="hint">${DISCLAIMER}</p>`);
  }

  async function doSave(final, button) {
    readStep();
    const err = $('.form-error', ov.body);
    const r = evaluate();
    if (inputs.regional.direction && !r.regional.applied && !String(justification).trim()) {
      err.textContent = 'El ajuste regional exige una justificación.';
      err.hidden = false;
      $('textarea[name="justification"]', ov.body).focus();
      return;
    }
    button.disabled = true;
    try {
      const rec = await saveAssessment({
        assessmentID: assessment ? assessment.assessmentID : null, catalogId: catalog.id, taxonID: species.taxonID,
        inputs: JSON.parse(JSON.stringify(inputs)), result: r, justification, final,
      });
      dirty = false;
      requestSync();
      ov.close(true);
      if (onSaved) onSaved(rec);
    } catch (e) {
      err.textContent = e.message;
      err.hidden = false;
      button.disabled = false;
    }
  }

  return ov;
}

/** Texto corto de una evaluación guardada: «EN B1ab(iii)+2ab(iii)» o «VU (ajuste regional desde EN …)». */
export function assessmentHeadline(a) {
  const r = a.result || {};
  if (r.regional && r.regional.applied) return `${r.category} (ajuste regional desde ${r.criteriaString || r.baseCategory})`;
  return r.criteriaString || r.category || 'Sin categoría';
}

