// ================================================================================================
// components/marks.js — las marcas de una especie en la ficha: resumen, «Mis marcas» y «Marcas del
// equipo» (F5, F6; F8a: datos completos, editar y resumen).
// ------------------------------------------------------------------------------------------------
// «Marcar aquí» abre la hoja de marca (views/mark-sheet.js); nunca se guarda una marca sin posición.
// «Mis marcas»: fecha, presente/ausente, individuos, maduros, estado vital, sexo, hábitat, precisión,
// estado de sincronización, Editar y Borrar. «Marcas del equipo»: autor y el aviso de generalización
// cuando el servidor no manda la coordenada exacta.
// Resumen (propias y del equipo en este catálogo): marcas, ausencias, máximo de individuos en un mismo
// día, suma de maduros, primera y última fecha, con el aviso de doble conteo.
// Todo el HTML pasa por html`` (notas, hábitat y nombres vienen de usuarios).
// ================================================================================================

import { state, subscribe } from '../state.js';
import { html, render, sciName } from '../util.js';
import { confirmDialog } from './overlay.js';
import { deleteMark, marksFor, GEOREF_MAP } from '../marks.js';
import { requestSync } from '../sync.js';
import { openMarkSheet, canMark, LIFE_STAGE_LABELS, SEX_LABELS } from '../views/mark-sheet.js';

export { canMark };

const STATUS = {
  pending: ['Pendiente', 'warn'],
  synced: ['Sincronizada', 'ok'],
  rejected: ['Rechazada', 'danger'],
};

const fmtWhen = (iso) => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};
const fmtDay = (d) => d.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' });
const fmtCoord = (o) => (o.decimalLatitude === null || o.decimalLatitude === undefined ? null
  : `${Number(o.decimalLatitude).toFixed(5)}, ${Number(o.decimalLongitude).toFixed(5)}`);
const isAbsent = (o) => o.occurrenceStatus === 'absent';

/** Compatibilidad: «Marcar aquí» desde la lista de especies (F5). */
export function openMarkHere(species, catalog, { onSaved } = {}) {
  return openMarkSheet({ catalog, species, onSaved });
}

/**
 * Resumen de las marcas de una especie → {marks, absences, maxDay: {count, day}, matureSum, first, last}.
 * Días locales del dispositivo. Una presencia sin nº de individuos cuenta 1.
 */
export function speciesSummary(marks) {
  const present = marks.filter((o) => !isAbsent(o));
  const byDay = new Map();
  let matureSum = 0;
  let first = null;
  let last = null;
  for (const o of present) {
    const d = new Date(o.eventDate);
    if (Number.isNaN(d.getTime())) continue;
    const key = `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
    const n = Number.isInteger(o.individualCount) && o.individualCount > 0 ? o.individualCount : 1;
    const day = byDay.get(key) || { count: 0, day: d };
    day.count += n;
    byDay.set(key, day);
    if (Number.isInteger(o.mature_count)) matureSum += o.mature_count;
    if (!first || d < first) first = d;
    if (!last || d > last) last = d;
  }
  let maxDay = null;
  for (const v of byDay.values()) if (!maxDay || v.count > maxDay.count) maxDay = v;
  return { marks: present.length, absences: marks.length - present.length, maxDay, matureSum, first, last };
}

function summaryHtml(s) {
  if (!s.marks && !s.absences) return html``;
  return html`<section class="species-summary" aria-label="Resumen de la especie en el catálogo">
    <dl class="counts summary-counts">
      <div><dt>Marcas</dt><dd>${s.marks}</dd></div>
      <div><dt>Ausencias</dt><dd>${s.absences}</dd></div>
      <div><dt>Máx. en un día</dt><dd>${s.maxDay ? s.maxDay.count : '—'}${s.maxDay ? html`<span class="dd-sub">${fmtDay(s.maxDay.day)}</span>` : ''}</dd></div>
      <div><dt>Maduros</dt><dd>${s.matureSum}</dd></div>
      <div><dt>Primera</dt><dd class="dd-date">${s.first ? fmtDay(s.first) : '—'}</dd></div>
      <div><dt>Última</dt><dd class="dd-date">${s.last ? fmtDay(s.last) : '—'}</dd></div>
    </dl>
    <p class="hint">Suma de registros, puede contar varias veces a los mismos individuos. Tuyas y del equipo en este catálogo.</p>
  </section>`;
}

/** Línea de datos de una marca: presente/ausente, individuos, maduros, estado vital, sexo. */
function factsHtml(o) {
  if (isAbsent(o)) return html`<span class="badge danger">Ausencia</span>`;
  const bits = [];
  if (Number.isInteger(o.individualCount)) bits.push(`${o.individualCount} ${o.individualCount === 1 ? 'individuo' : 'individuos'}`);
  if (Number.isInteger(o.mature_count) && o.mature_count > 0) bits.push(`${o.mature_count} ${o.mature_count === 1 ? 'maduro' : 'maduros'}`);
  if (o.lifeStage) bits.push(LIFE_STAGE_LABELS[o.lifeStage] || o.lifeStage);
  if (o.sex) bits.push(SEX_LABELS[o.sex] || o.sex);
  return bits.length ? html`<span class="mark-facts">${bits.join(' · ')}</span>` : html``;
}

function detailsHtml(o) {
  return html`${o.habitat ? html`<p class="mark-note"><span class="mark-label">Hábitat</span> ${o.habitat}</p>` : ''}
    ${o.georeferenceProtocol === GEOREF_MAP ? html`<p class="mark-note"><span class="mark-label">Punto del mapa</span>
      ${o.georeferenceRemarks || 'posición marcada a mano, con la precisión estimada'}</p>` : ''}
    ${o.occurrenceRemarks ? html`<p class="mark-note">${o.occurrenceRemarks}</p>` : ''}`;
}

/**
 * Pinta en `el` las marcas de `taxonID` en `catalog`. `levels`: niveles de sensibilidad (para «~10 km»);
 * `sensitivity`: nivel de la especie en el catálogo. Devuelve {refresh, destroy}.
 */
export function mountMarks(el, { species, catalog, levels = [], sensitivity = null }) {
  let alive = true;
  let own = [];
  const level = sensitivity && levels.find((l) => l.level === sensitivity.level);

  function generalizedText(o) {
    if (o.decimalLatitude === null || o.decimalLatitude === undefined) {
      return `Ubicación reservada${level && level.displayText ? ` · ${level.displayText}` : ''}`;
    }
    return `Ubicación generalizada${level && level.approxKm ? ` (~${level.approxKm} km)` : ''}`;
  }

  async function refresh() {
    let marks;
    try { marks = await marksFor(catalog.id, species.taxonID); } catch (e) { return; }
    if (!alive) return;
    own = marks.own;
    const { others } = marks;
    render(el, html`
      <div class="marks-head">
        ${canMark(catalog) ? html`<button type="button" class="btn-primary" data-mark>Marcar aquí</button>` : ''}
      </div>
      ${summaryHtml(speciesSummary([...own, ...others]))}
      <h5>Mis marcas</h5>
      ${own.length ? html`<ul class="marks">${own.map((o) => {
        const [label, kind] = STATUS[o.status] || STATUS.pending;
        return html`<li class="mark${isAbsent(o) ? ' is-absent' : ''}">
          <div class="mark-main">
            <span class="mark-when">${fmtWhen(o.eventDate)}</span>
            <span class="badge">±${o.coordinateUncertaintyInMeters ?? '?'} m</span>
            <span class="badge ${kind}">${label}</span>
          </div>
          <div class="mark-main">${factsHtml(o)}</div>
          ${fmtCoord(o) ? html`<span class="mark-coord">${fmtCoord(o)}</span>` : ''}
          ${detailsHtml(o)}
          ${o.status === 'rejected' && o.reason ? html`<p class="hint mark-reason">${o.reason}</p>` : ''}
          <div class="form-actions mark-actions">
            <button type="button" class="link-btn" data-edit="${o.occurrenceID}">Editar<span class="sr-only"> la marca del ${fmtWhen(o.eventDate)}</span></button>
            <button type="button" class="link-btn mark-del" data-del="${o.occurrenceID}">Borrar<span class="sr-only"> la marca del ${fmtWhen(o.eventDate)}</span></button>
          </div>
        </li>`;
      })}</ul>` : html`<p class="muted">Aún no has marcado esta especie en «${catalog.name}».</p>`}
      <h5>Marcas del equipo</h5>
      ${others.length ? html`<ul class="marks">${others.map((o) => html`<li class="mark${isAbsent(o) ? ' is-absent' : ''}">
          <div class="mark-main">
            <span class="mark-author">${o.recordedBy || 'Otro miembro'}</span>
            <span class="mark-when">${fmtWhen(o.eventDate)}</span>
            ${o.coordinateUncertaintyInMeters !== null && o.coordinateUncertaintyInMeters !== undefined
              ? html`<span class="badge" title="Precisión">±${o.coordinateUncertaintyInMeters} m</span>` : ''}
            ${o.generalized ? '' : html`<span class="badge ok">Exacta</span>`}
          </div>
          <div class="mark-main">${factsHtml(o)}</div>
          ${o.generalized ? html`<p class="mark-generalized">${generalizedText(o)}</p>` : ''}
          ${fmtCoord(o) ? html`<span class="mark-coord">${fmtCoord(o)}</span>` : ''}
          ${detailsHtml(o)}
        </li>`)}</ul>` : html`<p class="muted">Nadie más del equipo ha marcado esta especie en «${catalog.name}» (o aún no se ha sincronizado).</p>`}`);
  }

  el.addEventListener('click', async (ev) => {
    if (ev.target.closest('[data-mark]')) { openMarkSheet({ catalog, species, onSaved: refresh }); return; }
    const edit = ev.target.closest('[data-edit]');
    if (edit) {
      const occ = own.find((o) => o.occurrenceID === edit.dataset.edit);
      if (occ) openMarkSheet({ catalog, species, occurrence: occ, onSaved: refresh });
      return;
    }
    const del = ev.target.closest('[data-del]');
    if (!del) return;
    const ok = await confirmDialog({
      key: 'borrar-marca', title: 'Borrar marca', danger: true, confirmLabel: 'Borrar marca',
      text: html`<p>Se borra esta marca de ${sciName(species)} para todo el equipo en cuanto se sincronice.</p>`,
    });
    if (!ok) return;
    await deleteMark(del.dataset.del);
    requestSync();
    refresh();
  });

  const unsubscribe = subscribe((key) => { if (key === 'sync' && !state.sync.running) refresh(); });
  refresh();
  return { refresh, destroy: () => { alive = false; unsubscribe(); } };
}
