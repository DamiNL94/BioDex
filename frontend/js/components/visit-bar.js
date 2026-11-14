// ================================================================================================
// components/visit-bar.js — barra fija de la visita en curso, en todas las pestañas, bajo los avisos.
// ------------------------------------------------------------------------------------------------
// Sitio, tiempo transcurrido y especies objetivo contadas o pendientes; «Cerrar visita» abre el resumen
// (views/visit-sheet.js). Se repinta al guardar marcas (clave 'sync' del estado), al cambiar la visita y cada 30 s.
//
// Con protocolo de búsqueda (tiempo fijo o transecto) o con «Grabar transecto», aquí vive el GPS que graba el
// RECORRIDO (js/visits.js › appendTrack, filtrado por shared/track.js):
//   · Progreso hacia el esfuerzo objetivo (minutos de búsqueda activa o metros) con una barra.
//   · «Pausar» / «Reanudar»: descansos y desplazamientos no cuentan como búsqueda ni graban recorrido.
//   · MODO BÚSQUEDA: distintivo y «Salir del modo búsqueda» (con confirmación; es irreversible y la visita deja de
//     contar para Chao2).
//   · Pantalla: con la pantalla apagada o la app en segundo plano el navegador detiene el GPS. Se pide un Wake Lock
//     (si existe) para que la pantalla no se apague, se avisa al usuario y los huecos quedan a la vista
//     (docs/esfuerzo.md, docs/limitaciones.md).
// ================================================================================================

import { state, subscribe } from '../state.js';
import { html, render, $, plural } from '../util.js';
import { visitTally, appendTrack, checkActiveVisit, PROTOCOLS, EFFORT_PROTOCOLS, EFFORT_UNITS, visitProgress, pauseVisit, resumeVisit,
  leaveBlind } from '../visits.js';
import { get } from '../db.js';
import { openCloseVisit } from '../views/visit-sheet.js';
import { confirmDialog } from './overlay.js';
import { requestSync } from '../sync.js';

const TICK_MS = 30000;

const fmtMin = (m) => (m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')} min`);
const elapsed = (iso) => fmtMin(Math.max(0, Math.round((Date.now() - Date.parse(iso)) / 60000)));
const fmtNum = (n) => Number(n).toLocaleString('es-ES', { maximumFractionDigits: 0 });

/** Paso 0–10 de la barra de progreso (clases vp-0…vp-10: la CSP no admite style=""). */
const stepOf = (value, target) => Math.max(0, Math.min(10, Math.floor((10 * value) / target)));

/** Monta la barra en `el`. → función para desmontarla. */
export function mountVisitBar(el) {
  let timer = null;
  let watchId = null;
  let wakeLock = null;
  let lastReason = null;
  let painting = false;
  let repaint = false;   // llegó otro aviso mientras se pintaba: se vuelve a pintar al terminar

  /** Línea del esfuerzo: «12 de 30 min · 340 m · 2 celdas» y la barra. */
  function effortHtml(v, p) {
    const { effort, analysis } = p;
    const unit = EFFORT_UNITS[v.effortProtocol];
    const value = effort.value === null ? 0 : effort.value;
    const shown = v.effortProtocol === 'timed' ? Math.floor(value) : Math.round(value);
    const extra = v.effortProtocol === 'timed' ? `${fmtNum(analysis.distanceM)} m` : fmtMin(Math.floor((effort.activeSeconds || 0) / 60));
    const pct = Math.min(100, Math.round((100 * value) / v.effortTarget));
    return html`<div class="visit-effort${effort.targetMet ? ' is-met' : ''}">
      <p class="visit-effort-text"><strong>${fmtNum(shown)} de ${fmtNum(v.effortTarget)} ${unit}</strong>
        <span class="visit-bar-meta"> · ${EFFORT_PROTOCOLS[v.effortProtocol]} · ${extra} · ${plural(analysis.cells.length, 'celda', 'celdas')}</span>
        ${effort.targetMet ? html` <span class="badge ok">Objetivo alcanzado</span>` : ''}</p>
      <div class="visit-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct}"
        aria-label="Esfuerzo: ${pct} % del objetivo"><span class="visit-progress-fill vp-${stepOf(value, v.effortTarget)}"></span></div>
    </div>`;
  }

  /** Estado del recorrido: grabando, en pausa, sin GPS o con huecos. */
  function trackHtml(v, p) {
    if (v.pausedAt) return html`<p class="visit-bar-track is-paused">⏸ En pausa: el tiempo no cuenta y el recorrido no se graba.</p>`;
    const n = p ? p.analysis.nPoints : 0;
    const gaps = p ? p.analysis.gaps : 0;
    const noGps = !navigator.geolocation;
    const weak = lastReason === 'accuracy';
    return html`<p class="visit-bar-track${gaps || noGps ? ' is-warn' : ''}">${noGps ? 'Este dispositivo no da posición: el recorrido no se graba.'
      : html`● Grabando el recorrido${n ? ` · ${plural(n, 'punto', 'puntos')}` : ''}${weak ? ' · señal GPS débil' : ''}${
        gaps ? html` · <strong>${plural(gaps, 'hueco', 'huecos')}</strong> (no suman distancia ni celdas)` : ''}`}
      <span class="visit-screen-hint"> Mantén la pantalla encendida y la app abierta${wakeLock ? ' (bloqueo de pantalla evitado)' : ''}.</span></p>`;
  }

  async function paint() {
    const v = state.visit;
    const mine = v && state.user && v.userId === state.user.id;
    if (!mine) { render(el, html``); el.hidden = true; stopTrack(); return; }
    if (painting) { repaint = true; return; }
    painting = true;
    repaint = false;
    try {
      const { counted, pending } = await visitTally(v.visitID, v.species || []);
      const hasEffort = v.effortProtocol && v.effortProtocol !== 'free';
      const tracking = hasEffort || v.recording;
      const rec = tracking ? await get('visits', v.visitID).catch(() => null) : null;
      const p = rec ? await visitProgress(rec).catch(() => null) : null;
      const other = state.activeCatalog && state.activeCatalog.id !== v.catalogId;
      el.hidden = false;
      render(el, html`<div class="visit-bar${v.blind ? ' is-blind' : ''}" role="region" aria-label="Visita en curso">
        <div class="visit-bar-main">
          <span class="visit-bar-dot" aria-hidden="true"></span>
          <span class="visit-bar-text"><strong>${v.siteName || 'Visita sin sitio'}</strong>
            <span class="visit-bar-meta"> · ${elapsed(v.startedAt)}${other ? ` · en «${v.catalogName || 'otro catálogo'}»` : ''}</span></span>
          <button type="button" class="btn-secondary visit-bar-close" data-close-visit>Cerrar visita</button>
        </div>
        ${v.blind ? html`<p class="visit-blind"><span class="badge visit-blind-badge">Modo búsqueda</span>
          <span> Las marcas anteriores ${(v.species || []).length ? 'de las especies objetivo' : 'del catálogo'} están ocultas.</span>
          <button type="button" class="link-btn" data-leave-blind>Salir del modo búsqueda</button></p>`
          : hasEffort ? html`<p class="visit-blind is-off"><span class="badge">Búsqueda no ciega</span>
            <span> Esta visita no contará para la completitud (Chao2).</span></p>` : ''}
        ${hasEffort && p ? effortHtml(v, p) : ''}
        ${(v.species || []).length ? html`<p class="visit-bar-targets">
          <span class="visit-bar-ok">${counted.length} de ${(v.species || []).length} objetivo contadas</span>
          ${pending.length ? html`<span class="visit-bar-pending"> · ${plural(pending.length, 'pendiente', 'pendientes')}</span>` : ''}</p>`
          : html`<p class="visit-bar-targets muted">${PROTOCOLS[v.protocol] || ''} · sin especies objetivo</p>`}
        ${tracking ? html`<div class="visit-bar-trackrow">${trackHtml(v, p)}
          ${hasEffort ? html`<button type="button" class="btn-secondary visit-pause" data-pause-visit aria-pressed="${v.pausedAt ? 'true' : 'false'}">${v.pausedAt ? 'Reanudar' : 'Pausar'}</button>` : ''}</div>` : ''}
      </div>`);
      $('[data-close-visit]', el).addEventListener('click', () => openCloseVisit({ visitID: v.visitID }));
      const leave = $('[data-leave-blind]', el);
      if (leave) leave.addEventListener('click', () => askLeaveBlind(v.visitID));
      const pause = $('[data-pause-visit]', el);
      if (pause) pause.addEventListener('click', () => (state.visit && state.visit.pausedAt ? resumeVisit(v.visitID) : pauseVisit(v.visitID)).catch(() => {}));
      if (tracking && !v.pausedAt) startTrack(v.visitID); else stopTrack();
    } catch (e) {
      console.info('Barra de visita:', e && e.message);
    } finally {
      painting = false;
      if (repaint) paint();
    }
  }

  async function askLeaveBlind(visitID) {
    const ok = await confirmDialog({ key: 'salir-modo-busqueda', title: 'Salir del modo búsqueda', danger: true, confirmLabel: 'Salir y ver las marcas',
      text: html`<p>Volverás a ver las marcas anteriores en el mapa y en las fichas.</p>
        <p><strong>No se puede deshacer:</strong> la visita quedará como <strong>búsqueda no ciega</strong> y dejará de contar para la
        completitud del muestreo (Chao2). Chao2 supone que cada búsqueda es independiente: si vas a una planta que ya está
        marcada, encontrarla no dice nada de lo fácil que es detectarla.</p>
        <p>Tus marcas de esta visita se guardan igual y siguen contando para la distribución (EOO y AOO).</p>` });
    if (!ok) return;
    await leaveBlind(visitID).catch(() => {});
    requestSync();
  }

  async function holdScreen() {
    if (wakeLock || !navigator.wakeLock || document.visibilityState !== 'visible') return;
    try {
      wakeLock = await navigator.wakeLock.request('screen');
      wakeLock.addEventListener('release', () => { wakeLock = null; });
    } catch (e) { wakeLock = null; }   // batería baja, política del navegador…: se sigue sin él
  }
  function releaseScreen() {
    if (wakeLock) { wakeLock.release().catch(() => {}); wakeLock = null; }
  }

  function startTrack(visitID) {
    holdScreen();
    if (watchId !== null || !navigator.geolocation || document.visibilityState === 'hidden') return;
    watchId = navigator.geolocation.watchPosition((pos) => {
      appendTrack(visitID, { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy,
        t: Math.round((pos.timestamp || Date.now()) / 1000) }).then((r) => {
        if (!r) return;
        const changed = r.reason !== lastReason;
        lastReason = r.reason;
        if (r.reason === 'added' || changed) paint();
      }).catch(() => {});
    }, () => {}, { enableHighAccuracy: true, maximumAge: 2000, timeout: 30000 });
  }
  function stopTrack() {
    if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
    releaseScreen();
  }
  // Página oculta: el navegador para el GPS igualmente; al volver se retoma (y el hueco, si lo hubo, queda registrado).
  const onVisibility = () => { if (document.visibilityState === 'hidden') stopTrack(); else paint(); };

  const unsubscribe = subscribe((key) => {
    if (key === 'visit') { lastReason = null; paint(); }
    if (key === 'sync' || key === 'activeCatalog') paint();
  });
  document.addEventListener('visibilitychange', onVisibility);
  timer = setInterval(paint, TICK_MS);
  checkActiveVisit().catch(() => {}).finally(paint);
  return () => { unsubscribe(); clearInterval(timer); stopTrack(); document.removeEventListener('visibilitychange', onVisibility); };
}
