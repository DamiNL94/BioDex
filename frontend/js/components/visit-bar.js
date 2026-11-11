// ================================================================================================
// components/visit-bar.js — barra fija de la visita en curso, en todas las pestañas, bajo los avisos.
// ------------------------------------------------------------------------------------------------
// Sitio, tiempo transcurrido y especies objetivo contadas o pendientes; «Cerrar visita» abre el resumen
// (views/visit-sheet.js). Con «Grabar transecto», aquí vive el GPS que graba la traza (solo con la página visible:
// batería) y la barra dice cuántos puntos lleva. Se repinta al guardar marcas (clave 'sync' del estado), al cambiar
// la visita y cada 30 s.
// ================================================================================================

import { state, subscribe } from '../state.js';
import { html, render, $, plural } from '../util.js';
import { visitTally, appendTrack, checkActiveVisit, PROTOCOLS } from '../visits.js';
import { openCloseVisit } from '../views/visit-sheet.js';

const TICK_MS = 30000;

const elapsed = (iso) => {
  const m = Math.max(0, Math.round((Date.now() - Date.parse(iso)) / 60000));
  return m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')} min`;
};

/** Monta la barra en `el`. → función para desmontarla. */
export function mountVisitBar(el) {
  let timer = null;
  let watchId = null;
  let points = null;
  let painting = false;
  let repaint = false;   // llegó otro aviso mientras se pintaba: se vuelve a pintar al terminar

  async function paint() {
    const v = state.visit;
    const mine = v && state.user && v.userId === state.user.id;
    if (!mine) { render(el, html``); el.hidden = true; stopTrack(); return; }
    if (painting) { repaint = true; return; }
    painting = true;
    repaint = false;
    try {
      const { counted, pending } = await visitTally(v.visitID, v.species || []);
      const other = state.activeCatalog && state.activeCatalog.id !== v.catalogId;
      el.hidden = false;
      render(el, html`<div class="visit-bar" role="region" aria-label="Visita en curso">
        <div class="visit-bar-main">
          <span class="visit-bar-dot" aria-hidden="true"></span>
          <span class="visit-bar-text"><strong>${v.siteName || 'Visita sin sitio'}</strong>
            <span class="visit-bar-meta"> · ${elapsed(v.startedAt)}${other ? ` · en «${v.catalogName || 'otro catálogo'}»` : ''}</span></span>
          <button type="button" class="btn-secondary visit-bar-close" data-close-visit>Cerrar visita</button>
        </div>
        ${(v.species || []).length ? html`<p class="visit-bar-targets">
          <span class="visit-bar-ok">${counted.length} de ${(v.species || []).length} objetivo contadas</span>
          ${pending.length ? html`<span class="visit-bar-pending"> · ${plural(pending.length, 'pendiente', 'pendientes')}</span>` : ''}</p>`
          : html`<p class="visit-bar-targets muted">${PROTOCOLS[v.protocol] || ''} · sin especies objetivo</p>`}
        ${v.recording ? html`<p class="visit-bar-track">● Grabando el transecto${points !== null ? ` · ${plural(points, 'punto', 'puntos')}` : ''}</p>` : ''}
      </div>`);
      $('[data-close-visit]', el).addEventListener('click', () => openCloseVisit({ visitID: v.visitID }));
      if (v.recording) startTrack(v.visitID); else stopTrack();
    } catch (e) {
      console.info('Barra de visita:', e && e.message);
    } finally {
      painting = false;
      if (repaint) paint();
    }
  }

  function startTrack(visitID) {
    if (watchId !== null || !navigator.geolocation || document.visibilityState === 'hidden') return;
    watchId = navigator.geolocation.watchPosition((p) => {
      appendTrack(visitID, { lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }).then((n) => {
        if (n !== null && n !== points) { points = n; const t = $('.visit-bar-track', el); if (t) t.textContent = `● Grabando el transecto · ${plural(n, 'punto', 'puntos')}`; }
      }).catch(() => {});
    }, () => {}, { enableHighAccuracy: true, maximumAge: 2000, timeout: 30000 });
  }
  function stopTrack() {
    if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
  }
  const onVisibility = () => { if (document.visibilityState === 'hidden') stopTrack(); else paint(); };

  const unsubscribe = subscribe((key) => {
    if (key === 'visit') { points = null; paint(); }
    if (key === 'sync' || key === 'activeCatalog') paint();
  });
  document.addEventListener('visibilitychange', onVisibility);
  timer = setInterval(paint, TICK_MS);
  checkActiveVisit().catch(() => {}).finally(paint);
  return () => { unsubscribe(); clearInterval(timer); stopTrack(); document.removeEventListener('visibilitychange', onVisibility); };
}
