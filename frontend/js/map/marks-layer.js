// ================================================================================================
// map/marks-layer.js — las marcas del catálogo activo en el mapa, desde IndexedDB (sin red también).
// ------------------------------------------------------------------------------------------------
// · Propias: marcador sólido verde. Del equipo: color por observador (8 colores, --obs-1…8 de
//   biodex.css), con leyenda. Los puntos se agrupan con markercluster.
// · Generalizadas (niveles 2 y 3 para quien no es de confianza): la CELDA de su generalización como
//   rectángulo, NUNCA un punto. El servidor manda el centro de la celda (floor + medio paso, Lib/
//   sensitivity.php); la celda sale de ese centro y del paso de rejilla. Nivel 1 (retenida): el
//   servidor no manda coordenadas y no se dibuja nada.
// · Pendientes de sincronizar: distintivo ámbar.
// Todo el HTML de marcadores y globos pasa por html`` (los nombres y notas vienen de usuarios).
// ================================================================================================

import { html, sciName, sciNameText } from '../util.js';

const L = () => window.L;
export const OBS_COLORS = 8;

const fmtWhen = (iso) => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? String(iso || '') : d.toLocaleString('es-ES',
    { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const hasCoords = (o) => o.decimalLatitude !== null && o.decimalLatitude !== undefined &&
  Number.isFinite(Number(o.decimalLatitude)) && Number.isFinite(Number(o.decimalLongitude));

/** Paso de rejilla (grados) de una marca generalizada: el que dice el propio registro o el de su nivel. */
export function gridOf(o, level) {
  const m = String(o.dataGeneralizations || '').match(/(\d+[.,]\d+)\s*°/);
  if (m) return Number(m[1].replace(',', '.'));
  if (level && level.gridDeg) return level.gridDeg;
  if (level && level.approxKm === 10) return 0.1;
  if (level && level.approxKm === 1) return 0.01;
  return null;
}

/** Celda [[sur, oeste], [norte, este]] que contiene el centro que manda el servidor. */
export function cellOf(lat, lon, grid) {
  // El centro está a medio paso del borde: floor() cae siempre en la celda buena, sin líos de redondeo.
  const s = Math.floor(lat / grid) * grid;
  const w = Math.floor(lon / grid) * grid;
  return [[s, w], [s + grid, w + grid]];
}

/**
 * Observadores del equipo (sin mí) con su color: Map authorId → {name, color (1–8)}. Orden estable por
 * nombre para que cada uno conserve su color mientras el equipo no cambie.
 */
export function observerColors(marks, me) {
  const names = new Map();
  for (const o of marks) if (o.authorId !== me && !names.has(o.authorId)) names.set(o.authorId, o.recordedBy || 'Otro miembro');
  const sorted = [...names.entries()].sort((a, b) => a[1].localeCompare(b[1], 'es'));
  return new Map(sorted.map(([id, name], i) => [id, { name, color: (i % OBS_COLORS) + 1 }]));
}

/**
 * Filtros: {taxonID, observer ('' | authorId), who ('all' | 'mine' | 'team'), from, to ('AAAA-MM-DD')}.
 */
export function applyFilters(marks, f, me) {
  return marks.filter((o) => {
    if (o.deletedAt) return false;
    if (f.taxonID && o.taxonID !== f.taxonID) return false;
    if (f.who === 'mine' && o.authorId !== me) return false;
    if (f.who === 'team' && o.authorId === me) return false;
    if (f.observer && o.authorId !== f.observer) return false;
    const day = String(o.eventDate || '').slice(0, 10);
    if (f.from && day < f.from) return false;
    if (f.to && day > f.to) return false;
    return true;
  });
}

export const activeFilterCount = (f) => ['taxonID', 'observer', 'from', 'to'].filter((k) => f[k]).length + (f.who !== 'all' ? 1 : 0);

/** Cómo se dibuja una marca: 'point' | 'cell' | 'withheld' (nivel 1) | 'none'. */
export function markShape(o, me) {
  if (!hasCoords(o)) return o.generalized ? 'withheld' : 'none';
  if (o.generalized && o.authorId !== me) return 'cell';
  return 'point';
}

/**
 * Capa de marcas sobre `map`. `ctx()` devuelve {me, speciesById: Map, levelOf(taxonID) → nivel}.
 * `onOpenSpecies(taxonID)` para «Abrir ficha». Devuelve {set(marks), focus(occurrenceID), destroy()}.
 */
export function createMarksLayer(map, { ctx, onOpenSpecies }) {
  const Lf = L();
  const clusters = Lf.markerClusterGroup({
    showCoverageOnHover: false, maxClusterRadius: 50, spiderfyOnMaxZoom: true,
    iconCreateFunction: (c) => Lf.divIcon({ className: 'mk-cluster-wrap',
      html: html`<span class="mk-cluster" aria-hidden="true">${c.getChildCount()}</span>`.str,
      iconSize: [44, 44] }),
  });
  const cells = Lf.featureGroup();
  clusters.addTo(map);
  cells.addTo(map);
  const byId = new Map();   // occurrenceID → capa de Leaflet

  function popupHtml(o, shape, colors, level) {
    const { me, speciesById } = ctx();
    const sp = speciesById.get(o.taxonID);
    const author = o.authorId === me ? 'Tú' : (colors.get(o.authorId) || {}).name || o.recordedBy || 'Otro miembro';
    const grid = shape === 'cell' ? gridOf(o, level) : null;
    const km = level && level.approxKm ? level.approxKm : grid ? Math.round(grid * 111) : null;
    return html`<div class="mk-pop">
      <p class="mk-pop-name">${sp ? sciName(sp) : html`<em class="sci">${o.taxonID}</em>`}</p>
      <p class="mk-pop-meta">${fmtWhen(o.eventDate)} · ${author}
        ${o.coordinateUncertaintyInMeters !== null && o.coordinateUncertaintyInMeters !== undefined ? html` · ±${o.coordinateUncertaintyInMeters} m` : ''}</p>
      ${shape === 'cell' ? html`<p class="mk-pop-gen">Ubicación generalizada${km ? ` (~${km} km)` : ''}</p>` : ''}
      ${o.status === 'pending' ? html`<p class="mk-pop-pending">Pendiente de sincronizar</p>` : ''}
      ${o.status === 'rejected' ? html`<p class="mk-pop-pending">Rechazada por el servidor (ver Ajustes)</p>` : ''}
      <button type="button" class="link-btn mk-pop-open" data-open-species="${o.taxonID}">Abrir ficha</button>
    </div>`.str;
  }

  function markerClass(o, colors) {
    const { me } = ctx();
    const who = o.authorId === me ? 'mk-own' : `mk-obs obs-${(colors.get(o.authorId) || { color: 1 }).color}`;
    return `mk ${who}${o.status === 'pending' ? ' is-pending' : ''}${o.status === 'rejected' ? ' is-rejected' : ''}`;
  }

  function set(marks) {
    const { me, speciesById, levelOf } = ctx();
    clusters.clearLayers();
    cells.clearLayers();
    byId.clear();
    const colors = observerColors(marks, me);
    const points = [];
    for (const o of marks) {
      const shape = markShape(o, me);
      if (shape === 'withheld' || shape === 'none') continue;
      const level = levelOf(o.taxonID);
      const sp = speciesById.get(o.taxonID);
      const label = `${sp ? sciNameText(sp) : o.taxonID}, ${fmtWhen(o.eventDate)}${o.status === 'pending' ? ', pendiente' : ''}`;
      let layer;
      if (shape === 'cell') {
        const grid = gridOf(o, level);
        if (!grid) continue;   // sin paso conocido no se dibuja: nunca un punto en su lugar
        const color = (colors.get(o.authorId) || { color: 1 }).color;
        layer = Lf.rectangle(cellOf(Number(o.decimalLatitude), Number(o.decimalLongitude), grid),
          { className: `gen-cell obs-${color}${o.status === 'pending' ? ' is-pending' : ''}`, weight: 2 });
        cells.addLayer(layer);
      } else {
        layer = Lf.marker([Number(o.decimalLatitude), Number(o.decimalLongitude)], {
          title: label, keyboard: true, riseOnHover: true,
          icon: Lf.divIcon({ className: 'mk-wrap',
            html: html`<span class="${markerClass(o, colors)}"></span><span class="sr-only">${label}</span>`.str,
            iconSize: [44, 44], iconAnchor: [22, 22], popupAnchor: [0, -14] }),   // 44 px de toque, 18 px de punto
        });
        points.push(layer);
      }
      layer.bindPopup(() => popupHtml(o, shape, colors, level), { maxWidth: 280 });
      byId.set(o.occurrenceID, layer);
    }
    clusters.addLayers(points);
    return colors;
  }

  function onPopupOpen(ev) {
    const btn = ev.popup.getElement() && ev.popup.getElement().querySelector('[data-open-species]');
    if (btn) btn.addEventListener('click', () => onOpenSpecies(btn.dataset.openSpecies));
  }
  map.on('popupopen', onPopupOpen);

  /** Centra el mapa en una marca y abre su globo. */
  function focus(occurrenceID) {
    const layer = byId.get(occurrenceID);
    if (!layer) return false;
    if (layer.getBounds) {
      map.fitBounds(layer.getBounds(), { maxZoom: 14, padding: [24, 24] });
      layer.openPopup();
    } else {
      clusters.zoomToShowLayer(layer, () => layer.openPopup());
    }
    return true;
  }

  function destroy() {
    map.off('popupopen', onPopupOpen);
    clusters.remove();
    cells.remove();
  }

  return { set, focus, destroy };
}
