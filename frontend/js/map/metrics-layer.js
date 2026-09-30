// ================================================================================================
// map/metrics-layer.js — capa «EOO / AOO» de la especie elegida en el mapa (F9).
// ------------------------------------------------------------------------------------------------
// Dibuja lo que manda el servidor según la confianza (js/metrics.js, Lib/metrics.php):
//   full         polígono convexo mínimo (EOO) y celdas UTM de la AOO (en grados no son rectángulos:
//                se dibujan con sus 4 esquinas, fromUtm)
//   generalized  celdas de 0,1° o 0,01° que contienen registros (las del nivel de generalización)
//   none         nada (nivel 1: solo cifras)
// No es interactiva y queda por debajo de las marcas.
// ================================================================================================

import { utmCellCorners } from '../metrics.js';

export function createMetricsLayer(map) {
  const Lf = window.L;
  const group = Lf.layerGroup();
  let shown = null;   // 'full' | 'generalized' | 'none' | null

  function clear() {
    group.clearLayers();
    if (map.hasLayer(group)) group.remove();
    shown = null;
  }

  /** Pinta la geometría de una respuesta de métricas. Devuelve lo pintado (para la leyenda). */
  function set(geometry) {
    group.clearLayers();
    shown = geometry ? geometry.access : null;
    if (!geometry) return null;
    if (geometry.access === 'full') {
      for (const c of geometry.cells || []) {
        Lf.polygon(utmCellCorners(c, geometry.cellSizeKm), { className: 'aoo-cell', interactive: false }).addTo(group);
      }
      if (geometry.hull && geometry.hull.length >= 3) {
        Lf.polygon(geometry.hull, { className: 'eoo-hull', interactive: false }).addTo(group);
      }
    } else if (geometry.access === 'generalized') {
      const g = geometry.gridDeg;
      for (const [lat, lon] of geometry.generalizedCells || []) {
        Lf.rectangle([[lat, lon], [lat + g, lon + g]], { className: 'metric-gen-cell', interactive: false }).addTo(group);
      }
    }
    if (!map.hasLayer(group)) group.addTo(map);
    return { access: shown, cellSizeKm: geometry.cellSizeKm, gridDeg: geometry.gridDeg, hasHull: !!(geometry.hull && geometry.hull.length >= 3) };
  }

  /** Encuadra lo pintado (si hay algo). */
  function fitTo() {
    const layers = group.getLayers();
    if (!layers.length) return;
    map.fitBounds(Lf.featureGroup(layers).getBounds(), { padding: [24, 24], maxZoom: 14 });
  }

  return { set, clear, fitTo, shown: () => shown };
}
