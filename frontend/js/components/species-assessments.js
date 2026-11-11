// ================================================================================================
// components/species-assessments.js — «Evaluación BioDex» de una especie en el catálogo activo.
// ------------------------------------------------------------------------------------------------
// Aparte de las evaluaciones oficiales de EIDOS (no las sustituye): la evaluación de referencia del
// catálogo, si la hay, y las del equipo con autor, fecha, estado y detalle. «Evaluar (UICN)» abre el
// asistente (views/iucn-wizard.js), también sin red. Cada autor edita y borra las suyas; solo el owner,
// con red, marca una FINAL como «Evaluación de referencia del catálogo» (el servidor lo registra).
// Todo el HTML pasa por html`` (la justificación y los nombres vienen de usuarios).
// La pestaña Evaluación UICN (components/species-iucn.js) la monta con `hideEvaluate` como lista de
// INSTANTÁNEAS: cada una con su versión (v1, v2… por fecha), su origen (servidor o dispositivo) y «Comparar»
// (dos marcadas → tabla con las diferencias). Las instantáneas del motor 2 no se editan (se guarda otra); el
// asistente solo queda para editar las evaluaciones antiguas.
// ================================================================================================

import { apiPutJson } from '../api.js';
import { state, subscribe } from '../state.js';
import { html, render, formatDateTime, SYNC_BADGES } from '../util.js';
import { confirmDialog } from './overlay.js';
import { assessmentsFor, deleteAssessment, MARK_ROLES } from '../marks.js';
import { tx } from '../db.js';
import { requestSync } from '../sync.js';
import { openIucnWizard, loadIucnCriteria, assessmentHeadline, DISCLAIMER } from '../views/iucn-wizard.js';
import { categoryLabel, criterionSummary } from '../../shared/iucn-engine.js';

/** Pinta en `el` las evaluaciones de `species` en `catalog` ({id, name, role}). Devuelve {refresh, destroy}. */
/** La de referencia se editó después de marcarla (la fecha de referencia es del servidor; 1 min de margen de reloj). */
export function editedAfterReference(a) {
  if (!a.isReference || !a.referenceSetAt || !a.clientUpdatedAt) return false;
  return Date.parse(a.clientUpdatedAt) - Date.parse(a.referenceSetAt) > 60_000;
}

export function mountAssessments(el, { species, catalog, hideEvaluate = false }) {
  let alive = true;
  let list = [];
  let crit = null;
  const compare = new Set();
  const canWrite = MARK_ROLES.includes(catalog.role);
  const isOwner = catalog.role === 'owner';
  const me = () => state.user && state.user.id;
  loadIucnCriteria().then((c) => { crit = c; if (alive) refresh(); }).catch(() => {});

  function detailHtml(a) {
    const r = a.result || {};
    return html`<details class="more assess-detail"><summary>Detalle</summary>
      ${r.byCriterion ? html`<table class="data-table"><caption class="sr-only">Resultado por criterio</caption>
        <tbody>${Object.entries(r.byCriterion).map(([k, c]) => html`<tr class="${(r.determining || []).includes(k) ? 'is-determining' : ''}">
          <th scope="row">${k}</th><td class="iucn-${c.category || 'none'}">${criterionSummary(c)}</td></tr>`)}</tbody></table>` : ''}
      ${r.regional && r.regional.applied ? html`<p class="hint">Ajuste regional: ${r.baseCategory} → ${r.category}.</p>` : ''}
      ${r.warnings && r.warnings.length ? html`<ul class="wz-warnings">${r.warnings.map((w) => html`<li>${w}</li>`)}</ul>` : ''}
      ${a.justification ? html`<p class="assess-just"><span class="mark-label">Justificación</span> ${a.justification}</p>` : ''}
    </details>`;
  }

  const isSnapshot = (a) => !!(a.inputs && a.inputs.engine === 2);
  /** v1, v2… por fecha (la más antigua, v1), entre todas las de la especie en el catálogo. */
  const versionOf = (a) => [...list].sort((x, y) => Date.parse(x.clientUpdatedAt) - Date.parse(y.clientUpdatedAt)).indexOf(a) + 1;

  function compareHtml() {
    if (compare.size !== 2) return compare.size === 1 ? html`<p class="hint">Marca otra para comparar.</p>` : '';
    const [a, b] = [...compare].map((id) => list.find((x) => x.assessmentID === id)).filter(Boolean)
      .sort((x, y) => Date.parse(x.clientUpdatedAt) - Date.parse(y.clientUpdatedAt));
    if (!a || !b) return '';
    const q = (x) => (x.result && x.result.quantifiers) || {};
    const fmt = (v) => (v === null || v === undefined ? '—' : typeof v === 'number' ? v.toLocaleString('es-ES', { maximumFractionDigits: 2 }) : String(v));
    const rows = [
      ['Categoría', (x) => (x.result && (x.result.displayCategory || x.result.category)) || '—'],
      ['Cadena', (x) => (x.result && x.result.criteriaString) || '—'],
      ['Rango', (x) => (x.result && x.result.range ? `${x.result.range.low || 'no amenazada'}–${x.result.range.high || 'no amenazada'}` : '—')],
      ['EOO (km²)', (x) => fmt(q(x).metrics && q(x).metrics.eooKm2)],
      ['AOO (km²)', (x) => fmt(q(x).metrics && q(x).metrics.aooKm2)],
      ['Maduros (mejor)', (x) => fmt(q(x).mature && q(x).mature.best)],
      ['Subpoblaciones', (x) => fmt(q(x).subpopulations && q(x).subpopulations.list ? q(x).subpopulations.list.length : null)],
      ['Localidades', (x) => (q(x).locations ? `${fmt(q(x).locations.count)}${q(x).locations.confirmed ? '' : ' (propuesta)'}` : '—')],
      ['Fragmentación grave', (x) => (q(x).fragmentation ? (q(x).fragmentation.status === 'not_evaluable' ? 'no evaluable' : q(x).fragmentation.severe ? 'sí' : 'no') : '—')],
    ];
    return html`<div class="table-scroll"><table class="data-table snap-compare"><caption>Comparación: v${versionOf(a)} → v${versionOf(b)}</caption>
      <thead><tr><th scope="col"></th><th scope="col">v${versionOf(a)} · ${formatDateTime(a.clientUpdatedAt)}</th>
        <th scope="col">v${versionOf(b)} · ${formatDateTime(b.clientUpdatedAt)}</th></tr></thead>
      <tbody>${rows.map(([label, fn]) => { const va = fn(a); const vb = fn(b); return html`<tr class="${va !== vb ? 'is-changed' : ''}">
        <th scope="row">${label}</th><td>${va}</td><td>${vb}${va !== vb ? html`<span class="sr-only"> (cambia)</span>` : ''}</td></tr>`; })}</tbody>
    </table></div>`;
  }

  function itemHtml(a) {
    const mine = a.authorId === me();
    const [label, kind] = SYNC_BADGES[a.status] || [];
    const final = a.assessmentStatus === 'final';
    const synced = !!a.serverSeq && a.status === 'synced';
    const snap = isSnapshot(a);
    return html`<li class="assess${a.isReference ? ' is-reference' : ''} iucn-edge-${a.category || 'none'}">
      ${a.isReference ? html`<p class="eyebrow assess-ref">Evaluación de referencia del catálogo</p>` : ''}
      <div class="assess-head">
        <span class="assess-cat iucn-${a.category || 'none'}">${a.category || '—'}</span>
        <span class="assess-string">${assessmentHeadline(a)}${crit && a.category
          ? html`<span class="sr-only"> (${categoryLabel(crit, a.category)})</span>` : ''}</span>
      </div>
      <div class="note-head">
        <span class="mark-author">${mine ? 'Tú' : a.recordedBy || 'Otro miembro'}</span>
        <time class="mark-when" datetime="${a.clientUpdatedAt}">${formatDateTime(a.clientUpdatedAt)}</time>
        <span class="badge ${final ? 'ok' : ''}">${final ? 'Final' : 'Borrador'}</span>
        <span class="badge">v${versionOf(a)}${snap ? (a.result && a.result.computedBy === 'server' ? ' · servidor' : ' · dispositivo') : ' · asistente'}</span>
        ${label ? html`<span class="badge ${kind}">${label}</span>` : ''}
      </div>
      ${a.isReference && a.referenceSetBy ? html`<p class="hint">Marcada por ${a.referenceSetBy}${a.referenceSetAt
        ? html`, ${formatDateTime(a.referenceSetAt)}` : ''}.</p>` : ''}
      ${editedAfterReference(a) ? html`<p class="hint mark-reason">Editada después de marcarla como referencia: revísala${isOwner
        ? ' y confírmala si sigue valiendo' : ''}.</p>` : ''}
      ${a.status === 'rejected' && a.reason ? html`<p class="hint mark-reason">${a.reason}</p>` : ''}
      ${detailHtml(a)}
      <div class="form-actions note-actions">
        ${mine && !snap ? html`<button type="button" class="link-btn" data-edit-assess="${a.assessmentID}">Editar</button>` : ''}
        ${mine ? html`<button type="button" class="link-btn danger-link" data-del-assess="${a.assessmentID}">Borrar</button>` : ''}
        ${hideEvaluate ? html`<label class="check snap-check"><input type="checkbox" data-compare="${a.assessmentID}"
          ${compare.has(a.assessmentID) ? html`checked` : ''}><span>Comparar</span></label>` : ''}
        ${isOwner && final && !a.isReference ? html`<button type="button" class="link-btn" data-ref="${a.assessmentID}"
          ${synced && state.online ? '' : html`disabled title="${synced ? 'Necesita conexión' : 'Sincronízala primero'}"`}>Marcar como referencia</button>` : ''}
        ${isOwner && editedAfterReference(a) ? html`<button type="button" class="link-btn" data-ref="${a.assessmentID}"
          ${synced && state.online ? '' : html`disabled title="${synced ? 'Necesita conexión' : 'Sincronízala primero'}"`}>Confirmar referencia</button>` : ''}
        ${isOwner && a.isReference ? html`<button type="button" class="link-btn" data-unref="${a.assessmentID}"
          ${state.online ? '' : html`disabled title="Necesita conexión"`}>Quitar referencia</button>` : ''}
      </div>
    </li>`;
  }

  async function refresh() {
    try { list = await assessmentsFor(catalog.id, species.taxonID); } catch (e) { return; }
    if (!alive) return;
    const ref = list.find((a) => a.isReference);
    const others = list.filter((a) => a !== ref);
    for (const id of [...compare]) if (!list.some((a) => a.assessmentID === id)) compare.delete(id);
    render(el, html`
      ${hideEvaluate ? html`<p class="hint">No sustituyen a las evaluaciones oficiales de EIDOS (pestaña Resumen).</p>` : html`
      <p class="hint">Propuesta del equipo con el asistente de BioDex. No sustituye a las evaluaciones oficiales de EIDOS
        (arriba). ${DISCLAIMER}</p>
      ${canWrite ? html`<div class="marks-head"><button type="button" class="btn-primary" data-evaluate>Evaluar (UICN)</button></div>`
        : html`<p class="hint">Tu rol en «${catalog.name}» solo permite leer las evaluaciones.</p>`}`}
      ${hideEvaluate ? compareHtml() : ''}
      ${ref ? html`<ul class="assessments">${itemHtml(ref)}</ul>`
        : list.length ? html`<p class="muted">Sin evaluación de referencia del catálogo${isOwner ? ': marca una final como referencia.' : '.'}</p>` : ''}
      ${others.length ? html`<h5>Evaluaciones del equipo</h5><ul class="assessments">${others.map(itemHtml)}</ul>`
        : !ref ? html`<p class="muted">Nadie ha evaluado aún esta especie en «${catalog.name}».</p>` : ''}
      <p class="form-error" role="alert" hidden></p>`);
  }

  function fail(message) {
    const err = el.querySelector('.form-error');
    if (!err) return;
    err.textContent = message;
    err.hidden = false;
  }

  async function setReference(assessmentID) {
    try {
      const res = await apiPutJson(`/catalogs/${encodeURIComponent(catalog.id)}/species/${encodeURIComponent(species.taxonID)}/reference-assessment`,
        { assessmentID });
      // Mientras llega el pull: la referencia nueva aquí también (el servidor ya subió sus server_seq).
      const refId = res.reference ? res.reference.assessmentID : null;
      await tx('assessments', 'readwrite', async (t) => {
        for (const a of await t.byIndex('assessments', 'taxonID', species.taxonID)) {
          if (a.catalogId !== catalog.id) continue;
          const isRef = a.assessmentID === refId;
          if (!!a.isReference !== isRef || (isRef && a.referenceSetAt !== res.reference.referenceSetAt)) {
            await t.put('assessments', { ...a, isReference: isRef, referenceSetBy: isRef ? res.reference.referenceSetBy : null,
              referenceSetAt: isRef ? res.reference.referenceSetAt : null });
          }
        }
      });
      requestSync();
      refresh();
    } catch (e) {
      fail(e.status === 422 ? 'Solo una evaluación final puede ser la de referencia.' : e.message);
    }
  }

  el.addEventListener('change', (ev) => {
    const c = ev.target.closest('[data-compare]');
    if (!c) return;
    if (c.checked) {
      if (compare.size >= 2) compare.delete([...compare][0]);
      compare.add(c.dataset.compare);
    } else compare.delete(c.dataset.compare);
    refresh().then(() => { const again = el.querySelector(`[data-compare="${c.dataset.compare}"]`); if (again) again.focus(); });
  });

  el.addEventListener('click', async (ev) => {
    if (ev.target.closest('[data-evaluate]')) { openIucnWizard({ catalog, species, onSaved: refresh }); return; }
    const edit = ev.target.closest('[data-edit-assess]');
    if (edit) {
      const a = list.find((x) => x.assessmentID === edit.dataset.editAssess);
      if (a) openIucnWizard({ catalog, species, assessment: a, onSaved: refresh });
      return;
    }
    const ref = ev.target.closest('[data-ref]');
    if (ref) { ref.disabled = true; await setReference(ref.dataset.ref); return; }
    const unref = ev.target.closest('[data-unref]');
    if (unref) {
      const ok = await confirmDialog({ key: 'quitar-referencia', title: 'Quitar la evaluación de referencia', confirmLabel: 'Quitar referencia',
        text: html`<p>La evaluación sigue en el catálogo; solo deja de ser la de referencia. Queda en el registro.</p>` });
      if (ok) await setReference(null);
      return;
    }
    const del = ev.target.closest('[data-del-assess]');
    if (!del) return;
    const a = list.find((x) => x.assessmentID === del.dataset.delAssess);
    const ok = await confirmDialog({ key: 'borrar-evaluacion', title: 'Borrar evaluación', danger: true, confirmLabel: 'Borrar evaluación',
      text: html`<p>Se borra para todo el equipo en cuanto se sincronice.${a && a.isReference
        ? ' Es la evaluación de referencia del catálogo: dejará de serlo.' : ''}</p>` });
    if (!ok) return;
    await deleteAssessment(del.dataset.delAssess);
    requestSync();
    refresh();
  });

  // Tras cada sincronización llegan las del equipo (y la referencia); con red cambia «Marcar como referencia».
  const unsubscribe = subscribe((key) => {
    if ((key === 'sync' && !state.sync.running) || key === 'online') refresh();
  });
  refresh();
  return { refresh, destroy: () => { alive = false; unsubscribe(); } };
}
