// ================================================================================================
// components/fab.js — botones flotantes del modo campo (F11f): «Marcar» siempre visible en Mapa y
// Especies, a la altura del pulgar (abajo a la derecha, encima de la barra de pestañas), y el hueco
// de «Impacto» para F11g, oculto tras el flag `impact` (js/flags.js).
// En estudio (escritorio) el de Mapa sigue flotando sobre el mapa y Especies no lo lleva (allí cada fila
// tiene su «Marcar aquí»).
// ================================================================================================

import { html } from '../util.js';
import { FLAGS } from '../flags.js';

const PIN = html`<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.5-7-12a7 7 0 0 1 14 0c0 5.5-7 12-7 12Z"/><circle cx="12" cy="9" r="2.5"/></svg>`;
const WARN = html`<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 20h20L12 3Zm0 6v5m0 3v.5"/></svg>`;

/** Pila de botones flotantes: `data-mark` y, con el flag, `data-impact` (sin función hasta F11g). */
export function fabStack({ label = 'Marcar aquí' } = {}) {
  return html`<div class="fab-stack">
    ${FLAGS.impact ? html`<button type="button" class="btn-secondary impact-fab" data-impact disabled
      title="Registrar un impacto (llega en F11g)">${WARN}<span>Impacto</span></button>` : ''}
    <button type="button" class="btn-primary mark-fab" data-mark>${PIN}<span>${label}</span></button>
  </div>`;
}
