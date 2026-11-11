// ================================================================================================
// components/fab.js — botones flotantes del modo campo (F11f): «Marcar» siempre visible en Mapa y
// Especies, a la altura del pulgar (abajo a la derecha, encima de la barra de pestañas), y encima
// «Impacto» (F11g: views/impact-sheet.js) y, en el Mapa, «Empezar visita» (F11h: views/visit-sheet.js).
// En estudio (escritorio) el de Mapa sigue flotando sobre el mapa y Especies no lo lleva (allí cada fila
// tiene su «Marcar aquí»).
// ================================================================================================

import { html } from '../util.js';

const PIN = html`<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.5-7-12a7 7 0 0 1 14 0c0 5.5-7 12-7 12Z"/><circle cx="12" cy="9" r="2.5"/></svg>`;
const WARN = html`<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 20h20L12 3Zm0 6v5m0 3v.5"/></svg>`;

const VISIT = html`<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/></svg>`;

/**
 * Pila de botones flotantes: `data-visit` (F11h: empezar una visita, solo en el Mapa), `data-impact` (registrar un
 * impacto) y `data-mark`.
 */
export function fabStack({ label = 'Marcar aquí', visit = false } = {}) {
  return html`<div class="fab-stack">
    ${visit ? html`<button type="button" class="btn-primary visit-fab" data-visit title="Empezar una visita a un sitio (muestreo repetible)">${VISIT}<span>Empezar visita</span></button>` : ''}
    <button type="button" class="btn-secondary impact-fab" data-impact title="Registrar un impacto (pisoteo, residuos, ganado…)">${WARN}<span>Impacto</span></button>
    <button type="button" class="btn-primary mark-fab" data-mark>${PIN}<span>${label}</span></button>
  </div>`;
}
