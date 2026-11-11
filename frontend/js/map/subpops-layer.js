// ================================================================================================
// map/subpops-layer.js — capa «Subpoblaciones» del mapa de estudio: las de la especie elegida en
// Filtros, con un color por subpoblación, y sus impactos (punto + radio o perímetro, discontinuos).
// La geometría llega ya recortada según la confianza (Lib/assessment.php, la regla de applyVisibility):
//   full         casco de cada subpoblación (o un círculo pequeño si tiene menos de 3 puntos) y sus celdas de 2 km
//   generalized  un círculo en el centro generalizado de cada una, del tamaño de la celda de su nivel
//   none         nada (nivel 1: solo cifras, en la pestaña Evaluación UICN)
// Los impactos ajenos de especies sensibles llegan generalizados por el pull (sin perímetro). No es interactiva.
// ================================================================================================

import { utmCellCorners } from '../metrics.js';
import { html } from '../util.js';

const COLORS = 8;

export function createSubpopsLayer(map) {
  const Lf = window.L;
  const group = Lf.layerGroup();

  function clear() {
    group.clearLayers();
    if (map.hasLayer(group)) group.remove();
  }

  /** Pinta las subpoblaciones de una evaluación (y los impactos dados). → {count, access} */
  function set(assessment, impacts = []) {
    group.clearLayers();
    const access = assessment ? assessment.geometry : 'none';
    const list = (assessment && assessment.subpopulations && assessment.subpopulations.subpops) || [];
    list.forEach((s, i) => {
      const cls = `subpop-shape sp-c${i % COLORS}`;
      const label = html`${s.label} · ${s.nRecords} ${s.nRecords === 1 ? 'registro' : 'registros'}`.str;
      let shape = null;
      if (access === 'full') {
        for (const c of s.cells || []) Lf.polygon(utmCellCorners(c, 2), { className: `${cls} is-cell`, interactive: false }).addTo(group);
        shape = s.hull && s.hull.length >= 3 ? Lf.polygon(s.hull, { className: cls, interactive: false })
          : s.centroid ? Lf.circle(s.centroid, { radius: 150, className: cls, interactive: false }) : null;
      } else if (access === 'generalized' && s.centroid && s.centroid[0] !== null) {
        shape = Lf.circle(s.centroid, { radius: s.radiusM || 1000, className: `${cls} is-generalized`, interactive: false });
      }
      if (shape) shape.bindTooltip(label, { permanent: true, direction: 'center', className: 'subpop-label' }).addTo(group);
    });
    for (const imp of impacts) {
      if (imp.decimalLatitude === null || imp.decimalLatitude === undefined) continue;
      const cls = `impact-shape is-${imp.timing}${imp.generalized ? ' is-generalized' : ''}`;
      if (imp.polygon && imp.polygon.length >= 3) Lf.polygon(imp.polygon, { className: cls, interactive: false }).addTo(group);
      else Lf.circle([imp.decimalLatitude, imp.decimalLongitude], { radius: imp.radiusM || 25, className: cls, interactive: false }).addTo(group);
    }
    if (!map.hasLayer(group)) group.addTo(map);
    return { count: list.length, access };
  }

  function fitTo() {
    const layers = group.getLayers();
    if (!layers.length) return;
    map.fitBounds(Lf.featureGroup(layers).getBounds(), { padding: [24, 24], maxZoom: 14 });
  }

  return { set, clear, fitTo };
}
