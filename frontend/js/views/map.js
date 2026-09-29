// Vista Mapa: de momento solo el marcador de posición. El mapa real (Leaflet + WMTS del IGN) es F7.

import { html, render } from '../util.js';

export function mount(el) {
  render(el, html`
    <section class="placeholder">
      <p class="eyebrow">Mapa</p>
      <h2>El mapa llega en F7</h2>
      <p>Aquí irán las capas del IGN (base, PNOA y MTN), la descarga de zonas para trabajar sin
        cobertura y las observaciones del catálogo activo.</p>
    </section>`);
}
