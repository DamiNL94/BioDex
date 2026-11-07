// ================================================================================================
// components/gbif-compare-map.js — mapa de la comparación de fuentes de la pestaña Evaluación UICN (F11g):
// los registros de BioDex y los de GBIF en colores distintos sobre IGN Base (las teselas del Mapa).
// Los de BioDex llegan ya recortados según la confianza (exactos, centro de su celda o ninguno); los de GBIF
// son públicos. Sin interacción salvo el zoom.
// ================================================================================================

import { tileTemplate, attributionFor, layerByKey } from '../map/layers.js';

/** Monta el mapa en `el` con `g.points` {biodex: [[lat, lon]], gbif: [[lat, lon]]}. → {destroy()} */
export function mountGbifMap(el, g) {
  const Lf = window.L;
  if (!Lf || !el) return { destroy() {} };
  const layer = layerByKey('base');
  const map = Lf.map(el, { zoomControl: true, attributionControl: true, scrollWheelZoom: false, keyboard: false, tap: false });
  Lf.tileLayer(tileTemplate(layer.key), { maxZoom: layer.maxZoom, attribution: attributionFor(layer) + ' · GBIF.org' }).addTo(map);
  const group = Lf.featureGroup().addTo(map);
  for (const [lat, lon] of (g.points && g.points.gbif) || []) {
    Lf.circleMarker([lat, lon], { radius: 4, className: 'gbif-pt is-gbif', interactive: false }).addTo(group);
  }
  for (const [lat, lon] of (g.points && g.points.biodex) || []) {
    Lf.circleMarker([lat, lon], { radius: 5, className: 'gbif-pt is-biodex', interactive: false }).addTo(group);
  }
  const b = group.getBounds();
  if (b.isValid()) map.fitBounds(b.pad(0.15), { maxZoom: 13 });
  else if (g.gbif && g.gbif.bbox) map.fitBounds([[g.gbif.bbox[0], g.gbif.bbox[1]], [g.gbif.bbox[2], g.gbif.bbox[3]]]);
  setTimeout(() => map.invalidateSize(), 0);
  return { destroy() { map.remove(); } };
}
