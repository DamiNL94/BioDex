// ================================================================================================
// components/species-minimap.js — minimapa de la pestaña «Registros» de la ficha (F11f): las marcas de
// la especie en el catálogo activo sobre IGN Base (las mismas teselas que el Mapa, por /tiles/; sin red
// salen de las zonas descargadas o de la caché de uso).
// Solo marcas con coordenada exacta: las generalizadas (celda de 1–10 km o sin punto) no se dibujan como un punto que
// no es; se cuentan aparte y su celda está en la pestaña Mapa.
// Las propias, cuadradas y sólidas; las del equipo, redondas; las ausencias, con aspa (las clases del Mapa).
// Sin interacción salvo el zoom: es para situarse; para trabajar está la pestaña Mapa.
// ================================================================================================

import { html, render, plural } from '../util.js';
import { tileTemplate, attributionFor, layerByKey } from '../map/layers.js';
import { marksFor } from '../marks.js';

/** Monta el minimapa en `el`. → {refresh(), resize(), destroy()} */
export function mountMinimap(el, { species, catalog }) {
  const Lf = window.L;
  let map = null;
  let group = null;
  let alive = true;

  async function refresh() {
    let marks;
    try { marks = await marksFor(catalog.id, species.taxonID); } catch (e) { marks = { own: [], others: [] }; }
    if (!alive) return;
    const me = new Set(marks.own.map((o) => o.occurrenceID));
    const all = [...marks.own, ...marks.others];
    const located = all.filter((o) => !o.generalized && o.decimalLatitude !== null && o.decimalLatitude !== undefined);
    const hidden = all.length - located.length;
    if (!Lf || !located.length) {
      if (map) { map.remove(); map = null; }
      render(el, html`<p class="muted minimap-empty">${all.length
        ? `Ninguna de las ${all.length} marcas tiene coordenada exacta que puedas ver (generalizadas o reservadas; su celda, en Mapa).`
        : 'Sin marcas con posición en este catálogo.'}</p>`);
      return;
    }
    if (!map) {
      render(el, html`<div class="minimap" role="img" aria-label="Mapa de las marcas de la especie"></div>
        <p class="hint minimap-note"></p>`);
      const layer = layerByKey('base');
      map = Lf.map(el.querySelector('.minimap'), { zoomControl: true, attributionControl: true, dragging: true,
        scrollWheelZoom: false, keyboard: false, tap: false });
      Lf.tileLayer(tileTemplate(layer.key), { maxZoom: layer.maxZoom, attribution: attributionFor(layer) }).addTo(map);
      group = Lf.featureGroup().addTo(map);
    }
    group.clearLayers();
    for (const o of located) {
      const cls = `mk ${me.has(o.occurrenceID) ? 'mk-own' : ''} ${o.occurrenceStatus === 'absent' ? 'is-absent' : ''}`;
      Lf.marker([o.decimalLatitude, o.decimalLongitude], { interactive: false, keyboard: false,
        icon: Lf.divIcon({ className: 'mk-wrap', html: html`<span class="${cls}"></span>`.str, iconSize: [24, 24], iconAnchor: [12, 12] }) })
        .addTo(group);
    }
    const note = el.querySelector('.minimap-note');
    if (note) note.textContent = `${plural(located.length, 'marca', 'marcas')} en el mapa`
      + (hidden ? ` · ${hidden} generalizadas o reservadas (sin punto; su celda, en Mapa)` : '') + '.';
    resize();
  }

  function resize() {
    if (!map || !group) return;
    map.invalidateSize();
    const b = group.getBounds();
    if (b.isValid()) map.fitBounds(b.pad(0.25), { maxZoom: 14 });
  }

  refresh();
  return { refresh, resize, destroy() { alive = false; if (map) { map.remove(); map = null; } } };
}
