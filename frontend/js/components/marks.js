// ================================================================================================
// components/marks.js — «Marcar aquí» y la lista de marcas de una especie (mínimo de F5; F8 completa).
// ------------------------------------------------------------------------------------------------
// «Marcar aquí» pide la posición (alta precisión, 30 s) y solo con ella deja guardar: nunca una marca
// sin coordenadas. Sin GPS o sin permiso, aviso de guarda claro. La marca va a IndexedDB en
// `pending` y sync.js la sube cuando haya red.
// Lista: «Mis marcas» (fecha, ±m, estado, borrar) y, debajo, «Marcas del equipo» (F6: autor, fecha,
// precisión y el aviso de generalización cuando el servidor no manda la coordenada exacta).
// ================================================================================================

import { state, subscribe } from '../state.js';
import { html, render, $, sciName } from '../util.js';
import { openOverlay, confirmDialog } from './overlay.js';
import { createMark, deleteMark, marksFor, MARK_ROLES } from '../marks.js';
import { requestSync } from '../sync.js';

const GPS_TIMEOUT_MS = 30000;
const ROLE_LABELS = { viewer: 'Lector' };
const STATUS = {
  pending: ['Pendiente', 'warn'],
  synced: ['Sincronizada', 'ok'],
  rejected: ['Rechazada', 'danger'],
};

export const canMark = (catalog) => !!catalog && MARK_ROLES.includes(catalog.role);

const fmtWhen = (iso) => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};
const fmtCoord = (o) => (o.decimalLatitude === null || o.decimalLatitude === undefined ? null
  : `${Number(o.decimalLatitude).toFixed(5)}, ${Number(o.decimalLongitude).toFixed(5)}`);

function gpsError(err) {
  if (!err) return 'Este dispositivo no ofrece ubicación. No se puede marcar sin coordenadas.';
  if (err.code === 1) return 'Sin permiso de ubicación. Actívalo para BioDex en el navegador: sin coordenadas no se guarda ninguna marca.';
  if (err.code === 3) return 'El GPS no ha dado posición en 30 s. Busca cielo abierto y vuelve a intentarlo: sin coordenadas no se guarda la marca.';
  return 'No se ha podido obtener la posición. Comprueba que la ubicación del dispositivo está activada y vuelve a intentarlo.';
}

/**
 * Diálogo «Marcar aquí» para `species` (ficha o tarjeta) en `catalog` ({id, name, role}).
 * `onSaved(occ)` tras guardar.
 */
export function openMarkHere(species, catalog, { onSaved } = {}) {
  const ov = openOverlay({ key: 'marcar-aqui', title: 'Marcar aquí', variant: 'dialog', className: 'mark-dialog' });
  if (!canMark(catalog)) {
    render(ov.body, html`<p class="guard-band warn">${catalog
      ? `Tu rol en «${catalog.name}» (${ROLE_LABELS[catalog.role] || catalog.role}) no permite marcar.`
      : 'Elige un catálogo activo para marcar.'}</p>
      <div class="form-actions dialog-actions"><button type="button" class="btn-primary" data-close>Entendido</button></div>`);
    $('[data-close]', ov.body).addEventListener('click', () => ov.close());
    $('[data-close]', ov.body).focus();
    return ov;
  }

  let pos = null;
  render(ov.body, html`
    <p class="mark-species">${sciName(species)}<span class="muted"> · ${catalog.name}</span></p>
    <p class="mark-gps" role="status" aria-live="polite"></p>
    <form class="form" novalidate>
      <label class="field"><span>Nota <em>(opcional)</em></span>
        <textarea name="note" rows="2" maxlength="4000" placeholder="Nº de individuos, hábitat, fenología…"></textarea></label>
      <p class="form-error" role="alert" hidden></p>
      <div class="form-actions dialog-actions">
        <button type="submit" class="btn-primary" disabled>Guardar marca</button>
        <button type="button" class="link-btn" data-retry hidden>Reintentar</button>
        <button type="button" class="link-btn" data-cancel>Cancelar</button>
      </div>
    </form>`);
  const gps = $('.mark-gps', ov.body);
  const form = $('form', ov.body);
  const save = $('button[type="submit"]', form);
  const retry = $('[data-retry]', form);
  const err = $('.form-error', form);

  function locate() {
    pos = null;
    save.disabled = true;
    retry.hidden = true;
    err.hidden = true;
    render(gps, html`<span class="gps-wait">Buscando tu posición…</span>`);
    if (!navigator.geolocation) { fail(null); return; }
    navigator.geolocation.getCurrentPosition((p) => {
      if (!ov.isOpen()) return;
      pos = p;
      render(gps, html`<span class="badge ok">Posición</span> ${fmtCoord({ decimalLatitude: p.coords.latitude, decimalLongitude: p.coords.longitude })}
        · <strong>±${Math.round(p.coords.accuracy)} m</strong>`);
      save.disabled = false;
      save.focus();
    }, (e) => { if (ov.isOpen()) fail(e); }, { enableHighAccuracy: true, timeout: GPS_TIMEOUT_MS, maximumAge: 0 });
  }

  function fail(e) {
    render(gps, html`<span class="guard-band warn">${gpsError(e)}</span>`);
    retry.hidden = !navigator.geolocation;
    if (!retry.hidden) retry.focus();
  }

  retry.addEventListener('click', locate);
  $('[data-cancel]', form).addEventListener('click', () => ov.close());
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (!pos) return;
    save.disabled = true;
    try {
      const occ = await createMark({ catalogId: catalog.id, taxonID: species.taxonID, pos, note: form.note.value });
      requestSync();
      ov.close(true);
      if (onSaved) onSaved(occ);
    } catch (e) {
      err.textContent = e.message;
      err.hidden = false;
      save.disabled = false;
    }
  });
  locate();
  return ov;
}

/**
 * Pinta en `el` las marcas de `taxonID` en `catalog`. `levels`: niveles de sensibilidad (para «~10 km»);
 * `sensitivity`: nivel de la especie en el catálogo. Devuelve {refresh, destroy}.
 */
export function mountMarks(el, { species, catalog, levels = [], sensitivity = null }) {
  let alive = true;
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
    const { own, others } = marks;
    render(el, html`
      <div class="marks-head">
        ${canMark(catalog) ? html`<button type="button" class="btn-primary" data-mark>Marcar aquí</button>` : ''}
      </div>
      <h5>Mis marcas</h5>
      ${own.length ? html`<ul class="marks">${own.map((o) => {
        const [label, kind] = STATUS[o.status] || STATUS.pending;
        return html`<li class="mark">
          <div class="mark-main">
            <span class="mark-when">${fmtWhen(o.eventDate)}</span>
            <span class="badge">±${o.coordinateUncertaintyInMeters ?? '?'} m</span>
            <span class="badge ${kind}">${label}</span>
          </div>
          ${fmtCoord(o) ? html`<span class="mark-coord">${fmtCoord(o)}</span>` : ''}
          ${o.occurrenceRemarks ? html`<p class="mark-note">${o.occurrenceRemarks}</p>` : ''}
          ${o.status === 'rejected' && o.reason ? html`<p class="hint mark-reason">${o.reason}</p>` : ''}
          <button type="button" class="link-btn mark-del" data-del="${o.occurrenceID}">Borrar<span class="sr-only"> la marca del ${fmtWhen(o.eventDate)}</span></button>
        </li>`;
      })}</ul>` : html`<p class="muted">Aún no has marcado esta especie en «${catalog.name}».</p>`}
      <h5>Marcas del equipo</h5>
      ${others.length ? html`<ul class="marks">${others.map((o) => html`<li class="mark">
          <div class="mark-main">
            <span class="mark-author">${o.recordedBy || 'Otro miembro'}</span>
            <span class="mark-when">${fmtWhen(o.eventDate)}</span>
            ${o.coordinateUncertaintyInMeters !== null && o.coordinateUncertaintyInMeters !== undefined
              ? html`<span class="badge" title="Precisión">±${o.coordinateUncertaintyInMeters} m</span>` : ''}
            ${o.generalized ? '' : html`<span class="badge ok">Exacta</span>`}
          </div>
          ${o.generalized ? html`<p class="mark-generalized">${generalizedText(o)}</p>` : ''}
          ${fmtCoord(o) ? html`<span class="mark-coord">${fmtCoord(o)}</span>` : ''}
          ${o.occurrenceRemarks ? html`<p class="mark-note">${o.occurrenceRemarks}</p>` : ''}
        </li>`)}</ul>` : html`<p class="muted">Nadie más del equipo ha marcado esta especie en «${catalog.name}» (o aún no se ha sincronizado).</p>`}`);
  }

  el.addEventListener('click', async (ev) => {
    if (ev.target.closest('[data-mark]')) { openMarkHere(species, catalog, { onSaved: refresh }); return; }
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

