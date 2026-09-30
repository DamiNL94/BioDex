// ================================================================================================
// Vista Mapa (F7): capas del IGN por /tiles/, posición GPS, marcas del catálogo activo y zonas para
// usar sin conexión.
// ------------------------------------------------------------------------------------------------
// · Capas: IGN Base · PNOA (ortofoto) · MTN (map/layers.js). Vista inicial: provincia de Granada;
//   después, la última vista (localStorage `biodex.mapView`).
// · Posición: watchPosition SOLO mientras la pestaña Mapa está montada y visible (batería). Punto azul
//   con círculo de precisión, «Centrar en mí» y modo seguir (se suelta al arrastrar el mapa). Pie con
//   lat/lon, UTM 30N ETRS89 (geo/utm.js) y ±precisión.
// · «Marcar aquí»: createMark (marks.js) con la ÚLTIMA posición del GPS; peor de 50 m → confirmación.
// · Marcas: IndexedDB (map/marks-layer.js), filtros y «Ver como lista». Se repintan al terminar cada
//   sincronización.
// · Zonas: map/zone-ui.js (editor y lista) sobre map/zones.js (descarga).
// ================================================================================================

import { state, subscribe } from '../state.js';
import { html, render, $, $$, plural, store, sciName, sciNameText } from '../util.js';
import { apiGetJson } from '../api.js';
import { hasDb, byIndex, get } from '../db.js';
import { createMark } from '../marks.js';
import { requestSync } from '../sync.js';
import { remoteOrLocal, localCatalogData, localCatalog, localSpeciesOf, sensitivityLevels } from '../offline.js';
import { openOverlay, confirmDialog } from '../components/overlay.js';
import { canMark } from '../components/marks.js';
import { openSpeciesDetail } from './species-detail.js';
import { toUtm, formatUtm } from '../geo/utm.js';
import { LAYERS, tileTemplate, attributionFor, MAP_MAX_ZOOM } from '../map/layers.js';
import { createMarksLayer, applyFilters, activeFilterCount, markShape, observerColors } from '../map/marks-layer.js';
import { listZones, onZones, pauseZone, deleteZone } from '../map/zones.js';
import { openZoneEditor, openZonesList, boundsOf, progressText } from '../map/zone-ui.js';

const GRANADA = [[36.7, -4.3], [38.0, -2.2]];
const VIEW_KEY = 'biodex.mapView';
const LOW_ACCURACY_M = 50;
const ROLE_LABELS = { viewer: 'Lector' };

// Filtros: sobreviven a cambiar de pestaña (no a recargar).
let filters = { taxonID: '', observer: '', who: 'all', from: '', to: '' };
let filtersCatalog = null;

const ICON = {
  locate: html`<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3"/></svg>`,
  follow: html`<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 5 21l7-4 7 4-7-19Z"/></svg>`,
  pin: html`<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.5-7-12a7 7 0 0 1 14 0c0 5.5-7 12-7 12Z"/><circle cx="12" cy="9" r="2.5"/></svg>`,
};

export function mount(el) {
  const Lf = window.L;
  if (!Lf || !Lf.markerClusterGroup) {
    render(el, html`<section class="placeholder"><p class="eyebrow">Mapa</p><h2>No se ha podido cargar el mapa</h2>
      <p>Falta la librería del mapa en este dispositivo. Recarga la app con conexión.</p></section>`);
    return null;
  }
  let alive = true;
  const saved = store.get(VIEW_KEY);
  let layerKey = saved && LAYERS.some((l) => l.key === saved.layer) ? saved.layer : 'base';
  let follow = !!store.get('biodex.mapFollow', false);
  let pos = null;            // última GeolocationPosition
  let posError = null;
  let watchId = null;
  let marks = [];            // todas las del catálogo (sin borradas)
  let visible = [];          // tras filtros
  let speciesById = new Map();
  let levels = [];
  let catalogSensitivity = new Map();   // taxonID → nivel
  let catalogLocal = null;
  let colors = new Map();
  let zones = [];
  let editor = null;
  const posListeners = new Set();

  render(el, html`
    <section class="map-view" aria-label="Mapa">
      <div class="map-bar">
        <div class="segmented map-layers" role="radiogroup" aria-label="Capa del mapa">
          ${LAYERS.map((l) => html`<button type="button" role="radio" data-layer="${l.key}">${l.label}</button>`)}
        </div>
        <div class="map-bar-tools">
          <button type="button" class="btn-secondary map-btn" data-filters aria-expanded="false" aria-controls="mapFilters">Filtros</button>
          <button type="button" class="btn-secondary map-btn" data-list>Ver como lista</button>
          <button type="button" class="btn-secondary map-btn" data-zones>Zonas</button>
        </div>
      </div>
      <form class="map-filters panel" id="mapFilters" hidden novalidate></form>
      <div class="map-note" id="mapNote"></div>
      <div class="map-wrap">
        <div class="map" id="leafletMap"></div>
        <div class="map-fabs">
          <button type="button" class="map-fab" data-locate aria-label="Centrar en mí">${ICON.locate}</button>
          <button type="button" class="map-fab" data-follow aria-pressed="false" aria-label="Seguir mi posición">${ICON.follow}</button>
        </div>
        <button type="button" class="btn-primary mark-fab" data-mark>${ICON.pin}<span>Marcar aquí</span></button>
        <div class="zone-strip" id="zoneStrip" hidden></div>
      </div>
      <p class="map-foot" id="mapFoot" role="status" aria-live="off"></p>
      <div class="map-legend" id="mapLegend"></div>
    </section>`);

  const root = $('.map-view', el);
  const mapEl = $('#leafletMap', el);
  const wrap = $('.map-wrap', el);

  // Alto del mapa: lo que queda de pantalla (en el móvil, sin la barra inferior). Se fija ANTES de
  // crear el mapa: Leaflet encuadra Granada con el tamaño que tenga el contenedor en ese momento.
  function sizeWrap() {
    const tabs = document.querySelector('.tabs');
    const bottom = tabs && getComputedStyle(tabs).position === 'fixed' ? tabs.offsetHeight : 0;
    const top = wrap.getBoundingClientRect().top + window.scrollY;
    const gap = parseFloat(getComputedStyle(root).rowGap) || 0;   // huecos del flex: pie y leyenda
    const below = $('#mapFoot', el).offsetHeight + $('#mapLegend', el).offsetHeight + 2 * gap;
    wrap.style.height = `${Math.max(280, Math.floor(window.innerHeight - top - bottom - below))}px`;
  }
  sizeWrap();

  // --- Mapa y capas ----------------------------------------------------------------------------
  const map = Lf.map(mapEl, { maxZoom: MAP_MAX_ZOOM, minZoom: 5, zoomControl: true, attributionControl: true });
  map.attributionControl.setPrefix('<a href="https://leafletjs.com" rel="noopener" target="_blank">Leaflet</a>');
  Lf.control.scale({ imperial: false, metric: true, position: 'bottomleft' }).addTo(map);
  const tileLayers = new Map(LAYERS.map((l) => [l.key, Lf.tileLayer(tileTemplate(l.key), {
    maxNativeZoom: l.maxZoom, maxZoom: MAP_MAX_ZOOM, minZoom: 5, attribution: attributionFor(l), keepBuffer: 3,
    className: `tiles-${l.key}`,
  })]));
  if (saved && Number.isFinite(saved.lat) && Number.isFinite(saved.lng) && Number.isFinite(saved.zoom)) {
    map.setView([saved.lat, saved.lng], saved.zoom);
  } else {
    map.fitBounds(GRANADA);
  }
  setLayer(layerKey);

  function saveView() {
    const c = map.getCenter();
    store.set(VIEW_KEY, { lat: +c.lat.toFixed(6), lng: +c.lng.toFixed(6), zoom: map.getZoom(), layer: layerKey });
  }
  map.on('moveend', saveView);

  function setLayer(key) {
    layerKey = key;
    for (const [k, layer] of tileLayers) {
      if (k === key) { if (!map.hasLayer(layer)) layer.addTo(map); } else if (map.hasLayer(layer)) layer.remove();
    }
    $$('[data-layer]', el).forEach((b) => {
      const on = b.dataset.layer === key;
      b.classList.toggle('active', on);
      b.setAttribute('aria-checked', String(on));
    });
    root.dataset.layer = key;
    saveView();
  }
  $$('[data-layer]', el).forEach((b) => b.addEventListener('click', () => setLayer(b.dataset.layer)));

  function fit() {
    sizeWrap();
    map.invalidateSize();
  }
  const ro = window.ResizeObserver ? new ResizeObserver(() => fit()) : null;
  if (ro) ['.app-sign', '#bands', '.map-bar', '#mapFilters', '#mapNote', '#mapFoot', '#mapLegend']
    .forEach((s) => { const n = document.querySelector(s); if (n) ro.observe(n); });
  window.addEventListener('resize', fit);

  // --- Posición --------------------------------------------------------------------------------
  const meAccuracy = Lf.circle([0, 0], { radius: 1, className: 'me-accuracy', interactive: false });
  const meDot = Lf.circleMarker([0, 0], { radius: 8, className: 'me-dot', interactive: false });

  function startWatch() {
    if (watchId !== null || !navigator.geolocation || document.visibilityState === 'hidden') return;
    watchId = navigator.geolocation.watchPosition(onPosition, onPositionError,
      { enableHighAccuracy: true, maximumAge: 5000, timeout: 30000 });
  }
  function stopWatch() {
    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    watchId = null;
  }
  function onVisibility() { if (document.visibilityState === 'hidden') stopWatch(); else startWatch(); }
  document.addEventListener('visibilitychange', onVisibility);

  function onPosition(p) {
    if (!alive) return;
    pos = p;
    posError = null;
    const ll = [p.coords.latitude, p.coords.longitude];
    meAccuracy.setLatLng(ll).setRadius(Math.max(1, p.coords.accuracy || 1));
    meDot.setLatLng(ll);
    if (!map.hasLayer(meDot)) { meAccuracy.addTo(map); meDot.addTo(map); }
    if (follow) map.panTo(ll, { animate: true });
    paintFoot();
    posListeners.forEach((fn) => fn());
  }
  function onPositionError(e) {
    if (!alive) return;
    posError = e.code === 1 ? 'Sin permiso de ubicación: actívalo para BioDex en el navegador.'
      : e.code === 3 ? 'El GPS no da posición todavía (busca cielo abierto).'
        : 'No se puede obtener la posición.';
    paintFoot();
    posListeners.forEach((fn) => fn());
  }

  function paintFoot() {
    const foot = $('#mapFoot', el);
    if (!pos) {
      render(foot, html`<span class="foot-label">Tu posición</span> ${!navigator.geolocation
        ? 'Este dispositivo no ofrece ubicación.' : posError || 'Buscando…'}`);
      return;
    }
    const { latitude: lat, longitude: lon, accuracy } = pos.coords;
    let utm = null;
    try { utm = toUtm(lat, lon); } catch (e) { /* fuera de rango */ }
    render(foot, html`<span class="foot-label">Tu posición</span>
      <span class="foot-item">${lat.toFixed(6)}, ${lon.toFixed(6)}</span>
      ${utm ? html`<span class="foot-item"><abbr title="UTM huso 30 norte, ETRS89 (EPSG:25830)">UTM 30N ETRS89</abbr> ${formatUtm(utm)}</span>` : ''}
      <span class="foot-item ${accuracy > LOW_ACCURACY_M ? 'is-poor' : ''}">±${Math.round(accuracy)} m</span>`);
  }

  function setFollow(on) {
    follow = on;
    store.set('biodex.mapFollow', on);
    const b = $('[data-follow]', el);
    b.setAttribute('aria-pressed', String(on));
    b.classList.toggle('is-active', on);
    if (on && pos) map.panTo([pos.coords.latitude, pos.coords.longitude]);
  }
  map.on('dragstart', () => { if (follow) setFollow(false); });
  $('[data-follow]', el).addEventListener('click', () => setFollow(!follow));
  $('[data-locate]', el).addEventListener('click', () => {
    if (!pos) { paintFoot(); $('#mapFoot', el).classList.add('is-flash'); setTimeout(() => $('#mapFoot', el).classList.remove('is-flash'), 900); return; }
    map.setView([pos.coords.latitude, pos.coords.longitude], Math.max(map.getZoom(), 16));
  });

  // --- Marcas ----------------------------------------------------------------------------------
  const layer = createMarksLayer(map, {
    ctx: () => ({
      me: state.user && state.user.id,
      speciesById,
      levelOf: (taxonID) => levels.find((l) => l.level === catalogSensitivity.get(taxonID)) || null,
    }),
    onOpenSpecies: (taxonID) => openSpeciesDetail(taxonID),
  });

  async function loadMarks() {
    const cat = state.activeCatalog;
    if (filtersCatalog !== (cat && cat.id)) {
      filters = { taxonID: '', observer: '', who: 'all', from: '', to: '' };
      filtersCatalog = cat && cat.id;
    }
    if (!cat || !hasDb()) {
      marks = [];
      catalogLocal = null;
      if (alive) repaintMarks();
      return;
    }
    try {
      const [occ, species, local] = await Promise.all([
        byIndex('occurrences', 'catalogId', cat.id), localSpeciesOf(cat.id), localCatalog(cat.id),
      ]);
      marks = occ.filter((o) => !o.deletedAt);
      catalogLocal = local || null;
      speciesById = new Map(species.map((s) => [s.taxonID, s]));
      catalogSensitivity = new Map(species.map((s) => [s.taxonID, s.sensitivity && s.sensitivity.level]));
      for (const t of new Set(marks.map((o) => o.taxonID))) {
        if (!speciesById.has(t)) { const sp = await get('species', t).catch(() => null); if (sp) speciesById.set(t, sp); }
      }
    } catch (e) {
      marks = [];
    }
    if (alive) repaintMarks();
  }

  function repaintMarks() {
    const me = state.user && state.user.id;
    visible = applyFilters(marks, filters, me);
    colors = layer.set(visible);
    paintNote();
    paintLegend();
    paintFilters();
  }

  function paintNote() {
    const cat = state.activeCatalog;
    const note = $('#mapNote', el);
    const me = state.user && state.user.id;
    if (!cat) {
      render(note, html`<p class="hint">Sin catálogo activo: elige uno en Catálogos para ver y hacer marcas.</p>`);
      return;
    }
    const shapes = visible.map((o) => markShape(o, me));
    const cells = shapes.filter((s) => s === 'cell').length;
    const withheld = shapes.filter((s) => s === 'withheld').length;
    const n = activeFilterCount(filters);
    render(note, html`
      ${catalogLocal && catalogLocal.downloadedAt ? '' : html`<p class="guard-band info">«${cat.name}» no está descargado:
        el mapa solo muestra las marcas hechas en este dispositivo. Descárgalo en Catálogos para ver las del equipo sin conexión.</p>`}
      <p class="hint map-count">${plural(visible.length - withheld, 'marca', 'marcas')} en el mapa${n ? ` (${plural(n, 'filtro', 'filtros')})` : ''}
        ${cells ? html` · ${plural(cells, 'ubicación generalizada', 'ubicaciones generalizadas')}, dibujadas como su celda` : ''}
        ${withheld ? html` · ${plural(withheld, 'retenida', 'retenidas')} (nivel 1, sin ubicación)` : ''}</p>`);
  }

  function paintLegend() {
    const me = state.user && state.user.id;
    const hasOwn = visible.some((o) => o.authorId === me);
    const hasPending = visible.some((o) => o.status === 'pending');
    const hasCells = visible.some((o) => markShape(o, me) === 'cell');
    const legend = $('#mapLegend', el);
    if (!visible.length) { render(legend, html``); return; }
    render(legend, html`<ul class="legend" aria-label="Leyenda">
      ${hasOwn ? html`<li><span class="mk mk-own" aria-hidden="true"></span>Mis marcas</li>` : ''}
      ${[...colors.values()].map((c) => html`<li><span class="mk mk-obs obs-${c.color}" aria-hidden="true"></span>${c.name}</li>`)}
      ${hasCells ? html`<li><span class="legend-cell" aria-hidden="true"></span>Ubicación generalizada (celda)</li>` : ''}
      ${hasPending ? html`<li><span class="mk mk-own is-pending" aria-hidden="true"></span>Pendiente de sincronizar</li>` : ''}
    </ul>`);
  }

  // --- Filtros ---------------------------------------------------------------------------------
  function paintFilters() {
    const form = $('#mapFilters', el);
    const me = state.user && state.user.id;
    const taxa = [...new Set(marks.map((o) => o.taxonID))]
      .map((t) => ({ t, name: speciesById.has(t) ? sciNameText(speciesById.get(t)) : t }))
      .sort((a, b) => a.name.localeCompare(b.name, 'es'));
    const observers = observerColors(marks, me);
    const n = activeFilterCount(filters);
    const btn = $('[data-filters]', el);
    btn.textContent = n ? `Filtros (${n})` : 'Filtros';
    if (form.contains(document.activeElement)) {   // no se repinta bajo los dedos; solo «Quitar filtros»
      const clear = $('[data-clear]', form);
      if (clear) clear.disabled = !n;
      return;
    }
    render(form, html`
      <label class="field"><span>Especie</span><select name="taxonID"><option value="">Todas</option>
        ${taxa.map(({ t, name }) => html`<option value="${t}" ${filters.taxonID === t ? html`selected` : ''}>${name}</option>`)}</select></label>
      <div class="field"><span id="whoLabel">Marcas</span>
        <div class="segmented" role="radiogroup" aria-labelledby="whoLabel">
          ${[['all', 'Todas'], ['mine', 'Mías'], ['team', 'Equipo']].map(([v, label]) => html`<button type="button" role="radio"
            data-who="${v}" aria-checked="${filters.who === v}" class="${filters.who === v ? 'active' : ''}">${label}</button>`)}
        </div></div>
      <label class="field"><span>Observador</span><select name="observer"><option value="">Cualquiera</option>
        ${me && marks.some((o) => o.authorId === me) ? html`<option value="${me}" ${filters.observer === me ? html`selected` : ''}>Yo</option>` : ''}
        ${[...observers.entries()].map(([id, o]) => html`<option value="${id}" ${filters.observer === id ? html`selected` : ''}>${o.name}</option>`)}</select></label>
      <label class="field"><span>Desde</span><input type="date" name="from" value="${filters.from}"></label>
      <label class="field"><span>Hasta</span><input type="date" name="to" value="${filters.to}"></label>
      <div class="form-actions"><button type="button" class="link-btn" data-clear ${n ? '' : html`disabled`}>Quitar filtros</button></div>`);
  }
  const filtersForm = $('#mapFilters', el);
  filtersForm.addEventListener('change', (ev) => {
    const t = ev.target;
    if (t.name in filters) { filters = { ...filters, [t.name]: t.value }; repaintMarks(); }
  });
  filtersForm.addEventListener('click', (ev) => {
    const who = ev.target.closest('[data-who]');
    if (who) { filters = { ...filters, who: who.dataset.who }; t0(); return; }
    if (ev.target.closest('[data-clear]')) { filters = { taxonID: '', observer: '', who: 'all', from: '', to: '' }; t0(); }
    function t0() { document.activeElement.blur(); repaintMarks(); }
  });
  $('[data-filters]', el).addEventListener('click', (ev) => {
    const open = filtersForm.hidden;
    filtersForm.hidden = !open;
    ev.currentTarget.setAttribute('aria-expanded', String(open));
    fit();
  });

  // --- Lista ------------------------------------------------------------------------------------
  $('[data-list]', el).addEventListener('click', () => openMarksList());

  function openMarksList() {
    const me = state.user && state.user.id;
    const ov = openOverlay({ key: 'marcas-lista', title: 'Marcas del mapa', variant: 'side' });
    const rows = [...visible].sort((a, b) => String(b.eventDate).localeCompare(String(a.eventDate)));
    render(ov.body, html`
      <p class="hint">${plural(rows.length, 'marca', 'marcas')} de «${state.activeCatalog ? state.activeCatalog.name : '—'}» con los filtros del mapa.</p>
      ${rows.length ? html`<ul class="marks map-list">${rows.map((o) => {
        const shape = markShape(o, me);
        const sp = speciesById.get(o.taxonID);
        const who = o.authorId === me ? 'Tú' : (colors.get(o.authorId) || {}).name || o.recordedBy || 'Otro miembro';
        return html`<li class="mark">
          <div class="mark-main">
            <span>${sp ? sciName(sp) : o.taxonID}</span>
          </div>
          <div class="mark-main">
            <span class="mark-when">${new Date(o.eventDate).toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</span>
            <span class="badge">${who}</span>
            ${o.coordinateUncertaintyInMeters !== null && o.coordinateUncertaintyInMeters !== undefined ? html`<span class="badge">±${o.coordinateUncertaintyInMeters} m</span>` : ''}
            ${o.status === 'pending' ? html`<span class="badge warn">Pendiente</span>` : ''}
          </div>
          ${shape === 'cell' ? html`<p class="mark-generalized">Ubicación generalizada</p>` : ''}
          ${shape === 'withheld' ? html`<p class="mark-generalized">Ubicación reservada (no se muestra en el mapa)</p>` : ''}
          <div class="form-actions">
            ${shape === 'point' || shape === 'cell' ? html`<button type="button" class="link-btn" data-focus="${o.occurrenceID}">Ver en el mapa</button>` : ''}
            <button type="button" class="link-btn" data-open="${o.taxonID}">Abrir ficha</button>
          </div>
        </li>`;
      })}</ul>` : html`<p class="muted">No hay marcas con estos filtros.</p>`}`);
    ov.body.addEventListener('click', (ev) => {
      const f = ev.target.closest('[data-focus]');
      if (f) { ov.close(true); layer.focus(f.dataset.focus); return; }
      const o = ev.target.closest('[data-open]');
      if (o) openSpeciesDetail(o.dataset.open);
    });
  }

  // --- Marcar aquí ------------------------------------------------------------------------------
  $('[data-mark]', el).addEventListener('click', () => openMapMark());

  function openMapMark() {
    const catalog = state.activeCatalog;
    const ov = openOverlay({ key: 'marcar-mapa', title: 'Marcar aquí', variant: 'dialog', className: 'mark-dialog',
      onClose: () => posListeners.delete(paintPos) });
    if (!canMark(catalog)) {
      render(ov.body, html`<p class="guard-band warn">${catalog
        ? `Tu rol en «${catalog.name}» (${ROLE_LABELS[catalog.role] || catalog.role}) no permite marcar.`
        : 'Elige un catálogo activo en Catálogos para marcar.'}</p>
        <div class="form-actions dialog-actions"><button type="button" class="btn-primary" data-close>Entendido</button></div>`);
      $('[data-close]', ov.body).addEventListener('click', () => ov.close());
      $('[data-close]', ov.body).focus();
      return;
    }
    let species = [];
    let chosen = filters.taxonID || '';
    render(ov.body, html`
      <p class="mark-gps" role="status" aria-live="polite"></p>
      <form class="form" novalidate>
        <div class="field"><span>Especie</span>
          <input type="search" name="q" placeholder="Buscar en «${catalog.name}»…" autocomplete="off" aria-label="Buscar especie">
          <div class="pick-list" role="radiogroup" aria-label="Especie"><p class="muted">Cargando especies…</p></div></div>
        <label class="field"><span>Nota <em>(opcional)</em></span>
          <textarea name="note" rows="2" maxlength="4000" placeholder="Nº de individuos, hábitat, fenología…"></textarea></label>
        <p class="form-error" role="alert" hidden></p>
        <div class="form-actions dialog-actions">
          <button type="submit" class="btn-primary" disabled>Guardar marca</button>
          <button type="button" class="link-btn" data-cancel>Cancelar</button>
        </div>
      </form>`);
    const form = $('form', ov.body);
    const save = $('button[type="submit"]', form);
    const err = $('.form-error', form);
    const list = $('.pick-list', form);

    function paintPos() {
      const gps = $('.mark-gps', ov.body);
      if (!gps) return;
      if (!pos) {
        render(gps, html`<span class="${posError ? 'guard-band warn' : 'gps-wait'}">${posError || 'Esperando al GPS…'}</span>`);
      } else {
        const acc = Math.round(pos.coords.accuracy);
        render(gps, html`<span class="badge ${acc > LOW_ACCURACY_M ? 'warn' : 'ok'}">Posición</span>
          ${pos.coords.latitude.toFixed(6)}, ${pos.coords.longitude.toFixed(6)} · <strong>±${acc} m</strong>
          ${acc > LOW_ACCURACY_M ? html`<span class="hint"> · precisión baja: se pedirá confirmación</span>` : ''}`);
      }
      save.disabled = !pos || !chosen;
    }
    posListeners.add(paintPos);

    function paintList() {
      const q = form.q.value.trim().toLowerCase();
      const match = species.filter((s) => !q || sciNameText(s).toLowerCase().includes(q) ||
        String(s.vernacularName || '').toLowerCase().includes(q));
      const shown = match.slice(0, 60);
      render(list, shown.length ? html`${shown.map((s) => html`<label class="check pick-row">
          <input type="radio" name="taxon" value="${s.taxonID}" ${s.taxonID === chosen ? html`checked` : ''}>
          <span>${sciName(s)}${s.vernacularName ? html`<span class="muted"> · ${s.vernacularName}</span>` : ''}</span></label>`)}
        ${match.length > shown.length ? html`<p class="hint">y ${match.length - shown.length} más: afina la búsqueda.</p>` : ''}`
        : html`<p class="muted">${species.length ? 'Ninguna especie coincide.' : 'Este catálogo no tiene especies en el dispositivo.'}</p>`);
    }
    remoteOrLocal(() => apiGetJson('/catalogs/' + encodeURIComponent(catalog.id)), () => localCatalogData(catalog.id))
      .then((res) => {
        species = [...res.data.species].sort((a, b) => sciNameText(a).localeCompare(sciNameText(b), 'es'));
        if (chosen && !species.some((s) => s.taxonID === chosen)) chosen = '';
        if (ov.isOpen()) { paintList(); paintPos(); }
      })
      .catch(() => { if (ov.isOpen()) render(list, html`<p class="guard-band warn">Sin conexión y sin el catálogo descargado no hay especies que elegir.</p>`); });

    form.q.addEventListener('input', paintList);
    list.addEventListener('change', (ev) => { if (ev.target.name === 'taxon') { chosen = ev.target.value; paintPos(); } });
    $('[data-cancel]', form).addEventListener('click', () => ov.close());
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!pos || !chosen) return;
      const usePos = pos;   // la posición del momento de pulsar, aunque el GPS siga llegando
      const acc = Math.round(usePos.coords.accuracy);
      if (acc > LOW_ACCURACY_M) {
        const ok = await confirmDialog({ key: 'precision-baja', title: 'Precisión baja', confirmLabel: 'Guardar igualmente',
          text: html`<p>El GPS da ahora <strong>±${acc} m</strong>, peor que ${LOW_ACCURACY_M} m. La marca quedará con esa
            incertidumbre. Si puedes, espera unos segundos a cielo abierto.</p>` });
        if (!ok || !ov.isOpen()) return;
      }
      save.disabled = true;
      try {
        await createMark({ catalogId: catalog.id, taxonID: chosen, pos: usePos, note: form.note.value });
        requestSync();
        ov.close(true);
        loadMarks();
      } catch (e) {
        err.textContent = e.message;
        err.hidden = false;
        save.disabled = false;
      }
    });
    paintPos();
    form.q.focus();
  }

  // --- Zonas -----------------------------------------------------------------------------------
  const zoneOutlines = Lf.featureGroup().addTo(map);
  let outlineIds = '';

  async function loadZones() {
    zones = await listZones();
    if (!alive) return;
    const ids = zones.map((z) => z.id).join();
    if (ids !== outlineIds) {
      outlineIds = ids;
      zoneOutlines.clearLayers();
      for (const z of zones) {
        Lf.rectangle(boundsOf(z.bounds), { className: 'zone-outline', weight: 1.5, fill: false, interactive: false }).addTo(zoneOutlines);
      }
    }
    paintStrip();
  }

  function paintStrip() {
    const strip = $('#zoneStrip', el);
    const active = zones.filter((z) => z.status === 'downloading');
    strip.hidden = !active.length;
    if (!active.length) return;
    const z = active[0];
    const pct = z.tileCount ? Math.floor((100 * z.doneCount) / z.tileCount) : 0;
    const key = `${z.id}`;
    if (strip.dataset.zone !== key) {
      strip.dataset.zone = key;
      render(strip, html`<div class="zone-strip-text"><strong>Descargando «${z.name}»</strong> <span class="zs-progress"></span></div>
        <progress max="100" aria-label="Progreso de «${z.name}»"></progress>
        <div class="form-actions"><button type="button" class="link-btn" data-zs-pause>Pausar</button>
          <button type="button" class="link-btn danger-link" data-zs-cancel>Cancelar</button></div>`);
    }
    $('.zs-progress', strip).textContent = `${pct} % · ${progressText(z)}`;
    $('progress', strip).value = pct;
  }
  $('#zoneStrip', el).addEventListener('click', async (ev) => {
    const id = $('#zoneStrip', el).dataset.zone;
    if (ev.target.closest('[data-zs-pause]')) { pauseZone(id); return; }
    if (ev.target.closest('[data-zs-cancel]')) {
      const z = zones.find((x) => x.id === id);
      const ok = await confirmDialog({ key: 'cancelar-zona', title: 'Cancelar descarga', danger: true, confirmLabel: 'Cancelar y borrar',
        cancelLabel: 'Seguir descargando', text: html`<p>Se para la descarga de «${z ? z.name : 'la zona'}» y se borra lo ya bajado.</p>` });
      if (ok) deleteZone(id);
    }
  });

  function startZoneEditor() {
    if (editor) return;
    if (!navigator.onLine) {
      confirmDialog({ key: 'zona-sin-red', title: 'Sin conexión', confirmLabel: 'Entendido', cancelLabel: 'Cerrar',
        text: html`<p>Para descargar una zona hace falta conexión.</p>` });
      return;
    }
    root.classList.add('is-editing-zone');
    editor = openZoneEditor(map, wrap, { onClose: () => { editor = null; root.classList.remove('is-editing-zone'); loadZones(); } });
  }
  $('[data-zones]', el).addEventListener('click', () => openZonesList({
    onDownloadHere: startZoneEditor,
    onShow: (z) => map.fitBounds(boundsOf(z.bounds), { padding: [16, 16] }),
  }));

  // --- Suscripciones y arranque --------------------------------------------------------------
  let zonesTimer = null;
  const offZones = onZones(() => {
    // Progreso: la tira se actualiza a menudo; la lista de contornos solo si cambian las zonas.
    if (!zonesTimer) zonesTimer = setTimeout(() => { zonesTimer = null; loadZones(); }, 250);
  });
  const unsubscribe = subscribe((key) => {
    if (key === 'sync' && !state.sync.running) loadMarks();
    // Descarga del catálogo terminada: ya hay fichas y marcas del equipo en el dispositivo.
    if (key === 'activeCatalog' || key === 'user' || key === 'download') loadMarks();
  });

  sensitivityLevels().then((l) => { levels = l || []; if (alive) repaintMarks(); }).catch(() => {});
  paintFoot();
  startWatch();
  loadMarks();
  loadZones();
  requestAnimationFrame(fit);

  return () => {
    alive = false;
    stopWatch();
    document.removeEventListener('visibilitychange', onVisibility);
    window.removeEventListener('resize', fit);
    if (ro) ro.disconnect();
    clearTimeout(zonesTimer);
    offZones();
    unsubscribe();
    if (editor) editor.close();
    posListeners.clear();
    layer.destroy();
    map.remove();
  };
}
