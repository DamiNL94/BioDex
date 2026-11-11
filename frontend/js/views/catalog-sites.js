// ================================================================================================
// views/catalog-sites.js — pestaña «Sitios» del catálogo (F11h): lista y mapa de los sitios de muestreo, y crear o
// dibujar sitios desde casa («llegar con los deberes hechos»): punto + radio, polígono (parcela) o línea (transecto con
// banda). Nombre, tipo, especies objetivo, protocolo y, si se conoce, la superficie. Sin red también: va a la cola como
// todo (js/visits.js) y sync.js lo sube. Solo el autor de un sitio lo edita o lo borra.
// Los sitios de especies sensibles llegan GENERALIZADOS a quien no es de confianza (Lib/visits.php): sin geometría y
// con el radio de la celda; aquí se pintan así y se dice.
// ================================================================================================

import { state, subscribe } from '../state.js';
import { html, render, $, $$, plural, formatDateTime } from '../util.js';
import { apiGetJson } from '../api.js';
import { openOverlay, confirmDialog } from '../components/overlay.js';
import { requestSync } from '../sync.js';
import { remoteOrLocal, localCatalogData } from '../offline.js';
import { hasDb } from '../db.js';
import { MARK_ROLES } from '../marks.js';
import { tileTemplate, attributionFor, layerByKey } from '../map/layers.js';
import { PROTOCOLS, SITE_TYPES, SITE_RADIUS_DEFAULT, BAND_DEFAULT, defaultProtocol, sitesFor, visitsFor, createSite, updateSite,
  deleteSite, localSiteMetrics } from '../visits.js';
import { polygonWkt, lineWkt, parseWkt, formatArea, formatLength } from '../site-geom.js';
import { targetPicker } from './visit-sheet.js';

const GRANADA = [[36.7, -4.3], [38.0, -2.2]];
const SITE_STYLE = { className: 'site-shape', color: '#7a4fb5', weight: 2, dashArray: '6 4', fillOpacity: 0.08 };

/** Capa de Leaflet de un sitio (o null). */
function siteShape(Lf, s, style = SITE_STYLE) {
  const g = s.footprintWKT ? parseWkt(s.footprintWKT) : null;
  if (g && g.type === 'POLYGON') return Lf.polygon(g.latlngs, style);
  if (g) return Lf.polyline(g.latlngs, { ...style, fill: false, weight: 4 });
  if (s.decimalLatitude === null || s.decimalLatitude === undefined) return null;
  return Lf.circle([s.decimalLatitude, s.decimalLongitude], { ...style, radius: s.radiusM || SITE_RADIUS_DEFAULT });
}

/** Monta la pestaña en `el`. → función para desmontarla. */
export function mountSites(el, catalog) {
  const Lf = window.L;
  let alive = true;
  let map = null;
  let group = null;
  let sites = [];
  let visitsBySite = new Map();
  const canCreate = MARK_ROLES.includes(catalog.role);
  const me = state.user && state.user.id;

  render(el, html`<section class="sites-tab" aria-label="Sitios de muestreo">
    <p class="hint">Un sitio es un lugar que se muestrea una y otra vez (parcela, transecto o punto de conteo). Sus visitas
      dan las series temporales de la ficha de cada especie (pestaña Registros) y los criterios A y C1.</p>
    ${canCreate ? html`<div class="sites-actions"><button type="button" class="btn-primary" data-new-site>Nuevo sitio</button></div>` : ''}
    <div class="sites-map" role="img" aria-label="Mapa de los sitios de muestreo"></div>
    <div class="sites-list" aria-live="polite"></div>
  </section>`);

  if (Lf) {
    const layer = layerByKey('base');
    map = Lf.map($('.sites-map', el), { zoomControl: true, scrollWheelZoom: false, tap: false });
    Lf.tileLayer(tileTemplate(layer.key), { maxZoom: layer.maxZoom, attribution: attributionFor(layer) }).addTo(map);
    map.fitBounds(GRANADA);
    group = Lf.featureGroup().addTo(map);
  }

  async function load() {
    if (!hasDb()) { render($('.sites-list', el), html`<p class="muted">Este navegador no guarda datos en el dispositivo.</p>`); return; }
    sites = await sitesFor(catalog.id).catch(() => []);
    const visits = await visitsFor(catalog.id).catch(() => []);
    visitsBySite = new Map();
    for (const v of visits) {
      if (!v.siteID) continue;
      if (!visitsBySite.has(v.siteID)) visitsBySite.set(v.siteID, []);
      visitsBySite.get(v.siteID).push(v);
    }
    if (!alive) return;
    paint();
  }

  function paint() {
    if (group) {
      group.clearLayers();
      for (const s of sites) {
        const shape = siteShape(Lf, s);
        if (shape) shape.bindTooltip(s.name).addTo(group);
      }
      if (sites.length && group.getBounds().isValid()) map.fitBounds(group.getBounds().pad(0.2), { maxZoom: 16 });
    }
    render($('.sites-list', el), sites.length ? html`<table class="data-table sites-table">
      <thead><tr><th>Sitio</th><th>Tipo</th><th>Superficie</th><th>Visitas</th><th>Última</th><th>Objetivo</th><th></th></tr></thead>
      <tbody>${sites.map((s) => {
        const vs = visitsBySite.get(s.siteID) || [];
        const own = s.authorId === me;
        return html`<tr>
          <td><strong>${s.name}</strong>${s.generalized ? html` <span class="badge warn" title="Especies sensibles: lo ves generalizado">generalizado</span>` : ''}
            ${s.status === 'pending' ? html` <span class="badge">sin sincronizar</span>` : ''}
            <span class="muted"> · ${s.recordedBy || ''}</span></td>
          <td>${SITE_TYPES[s.siteType] || s.siteType}${s.lengthM ? html`<span class="muted"> · ${formatLength(s.lengthM)}${s.bandM ? ` × ${s.bandM} m` : ''}</span>` : ''}</td>
          <td>${formatArea(s.areaM2)}${s.areaGiven ? html` <span class="muted">(dada)</span>` : ''}</td>
          <td>${vs.length}</td>
          <td>${vs[0] ? formatDateTime(vs[0].eventStart) : '—'}</td>
          <td>${(s.species || []).length}</td>
          <td>${own ? html`<button type="button" class="link-btn" data-edit="${s.siteID}">Editar</button>
            <button type="button" class="link-btn danger" data-del="${s.siteID}">Borrar</button>` : ''}</td>
        </tr>`; })}</tbody></table>`
      : html`<p class="muted">Todavía no hay sitios en este catálogo.${canCreate ? ' Crea uno con «Nuevo sitio» o empieza una visita en el Mapa.' : ''}</p>`);
  }

  el.addEventListener('click', async (ev) => {
    if (ev.target.closest('[data-new-site]')) { openSiteEditor({ catalog, onSaved: load }); return; }
    const e = ev.target.closest('[data-edit]');
    if (e) { openSiteEditor({ catalog, site: sites.find((s) => s.siteID === e.dataset.edit), onSaved: load }); return; }
    const d = ev.target.closest('[data-del]');
    if (d) {
      const s = sites.find((x) => x.siteID === d.dataset.del);
      const n = (visitsBySite.get(s.siteID) || []).length;
      const ok = await confirmDialog({ key: 'borrar-sitio', title: 'Borrar el sitio', danger: true, confirmLabel: 'Borrar',
        text: html`<p>Se borra «${s.name}» para todo el equipo.${n ? ` Sus ${plural(n, 'visita', 'visitas')} y sus marcas se quedan, pero dejan de contar en las series.` : ''}</p>` });
      if (!ok) return;
      await deleteSite(s.siteID);
      requestSync();
      load();
    }
  });

  const unsubscribe = subscribe((key) => { if ((key === 'sync' && !state.sync.running) || key === 'download') load(); });
  load();
  return () => { alive = false; unsubscribe(); if (map) map.remove(); };
}

/** Panel a pantalla completa con la pestaña (modo campo: Catálogos › Gestionar › Sitios). */
export function openSites(catalog) {
  let stop = null;
  const ov = openOverlay({ key: 'sitios', title: `Sitios · ${catalog.name}`, variant: 'full', className: 'sites-panel',
    onClose: () => { if (stop) stop(); } });
  stop = mountSites(ov.body, catalog);
  return ov;
}

/**
 * Crear o editar un sitio dibujándolo en un mapa. Herramientas: punto + radio (un toque), polígono (toques en los
 * vértices) o línea de transecto (toques en los puntos). `site`: el propio a editar.
 */
export function openSiteEditor({ catalog, site = null, onSaved } = {}) {
  const Lf = window.L;
  const editing = !!site;
  const ov = openOverlay({ key: 'editar-sitio', title: editing ? `Editar «${site.name}»` : 'Nuevo sitio', variant: 'full', className: 'site-editor',
    onClose: () => { if (map) map.remove(); } });
  const g0 = site && site.footprintWKT ? parseWkt(site.footprintWKT) : null;
  let tool = g0 ? (g0.type === 'POLYGON' ? 'polygon' : 'line') : 'point';
  let center = site && !g0 && site.decimalLatitude !== null ? [site.decimalLatitude, site.decimalLongitude] : null;
  let vertices = g0 ? g0.latlngs.slice() : [];
  let siteType = site ? site.siteType : 'point';
  const selected = new Set(site ? site.species || [] : []);
  let map = null;
  let drawn = null;

  render(ov.body, html`<form class="form site-form" novalidate>
    <label class="field"><span>Nombre</span><input name="name" maxlength="120" required value="${site ? site.name : ''}" placeholder="Parcela 3 · Barranco del Lobo"></label>
    <div class="sites-draw-tools" role="radiogroup" aria-label="Forma del sitio">
      <button type="button" role="radio" data-tool="point">Punto + radio</button>
      <button type="button" role="radio" data-tool="polygon">Polígono (parcela)</button>
      <button type="button" role="radio" data-tool="line">Línea (transecto)</button>
      <button type="button" class="btn-secondary" data-undo>Quitar el último punto</button>
    </div>
    <p class="hint draw-hint" role="status" aria-live="polite"></p>
    <div class="sites-map editor-map" role="application" aria-label="Mapa para dibujar el sitio"></div>
    <label class="field f-radius"><span>Radio (m)</span><input name="radius" type="number" inputmode="numeric" min="1" max="100000"
      value="${site && site.radiusM ? site.radiusM : SITE_RADIUS_DEFAULT}"></label>
    <label class="field f-band"><span>Ancho total de la banda (m)</span><input name="band" type="number" inputmode="decimal" min="0.1" max="1000" step="any"
      value="${site && site.bandM ? site.bandM : BAND_DEFAULT}"></label>
    <div class="field f-type"><span id="seType">Tipo</span><div class="chip-pick" role="radiogroup" aria-labelledby="seType">
      ${['point', 'plot'].map((k) => html`<button type="button" role="radio" data-type="${k}">${SITE_TYPES[k]}</button>`)}</div></div>
    <label class="field"><span>Superficie conocida (m²) <em>(opcional: si no, se calcula de la forma)</em></span>
      <input name="area" type="number" inputmode="decimal" min="0.01" step="any" value="${site && site.areaGiven ? site.areaM2 : ''}"></label>
    <p class="hint metrics-hint" role="status" aria-live="polite"></p>
    <fieldset class="field"><legend>Protocolo habitual</legend><select name="protocol">
      ${Object.entries(PROTOCOLS).map(([k, label]) => html`<option value="${k}" ${site && site.protocol === k ? html`selected` : ''}>${label}</option>`)}</select></fieldset>
    <fieldset class="field"><legend>Especies objetivo (se proponen en cada visita)</legend><div class="site-targets"><p class="muted">Cargando especies…</p></div></fieldset>
    <p class="form-error" role="alert" hidden></p>
    <div class="sheet-actions"><button type="submit" class="btn-primary">${editing ? 'Guardar cambios' : 'Crear sitio'}</button>
      <button type="button" class="link-btn" data-cancel>Cancelar</button></div>
  </form>`);
  const form = $('form', ov.body);
  const err = $('.form-error', form);

  function current() {
    if (tool === 'point') return center ? { center: { lat: center[0], lng: center[1] }, radiusM: form.radius.value } : null;
    if (tool === 'polygon') return vertices.length >= 3 ? { wkt: polygonWkt(vertices) } : null;
    return vertices.length >= 2 ? { wkt: lineWkt(vertices) } : null;
  }

  function paint() {
    $$('[data-tool]', form).forEach((b) => { const on = b.dataset.tool === tool; b.classList.toggle('active', on); b.setAttribute('aria-checked', String(on)); });
    $$('[data-type]', form).forEach((b) => { const on = b.dataset.type === siteType; b.classList.toggle('active', on); b.setAttribute('aria-checked', String(on)); });
    $('.f-radius', form).hidden = tool !== 'point';
    $('.f-band', form).hidden = tool !== 'line';
    $('.f-type', form).hidden = tool !== 'point';
    $('[data-undo]', form).hidden = tool === 'point';
    $('.draw-hint', form).textContent = tool === 'point' ? (center ? 'Toca el mapa para mover el centro.' : 'Toca el mapa en el centro del sitio.')
      : tool === 'polygon' ? `Toca los vértices de la parcela (${plural(vertices.length, 'vértice', 'vértices')}; al menos 3).`
        : `Toca los puntos del transecto en orden (${plural(vertices.length, 'punto', 'puntos')}; al menos 2).`;
    const g = current();
    const m = g ? localSiteMetrics({ radiusM: g.radiusM ? Number(g.radiusM) : null, footprintWKT: g.wkt || null,
      bandM: tool === 'line' ? Number(form.band.value) || null : null, decimalLatitude: center && center[0], decimalLongitude: center && center[1] }) : null;
    $('.metrics-hint', form).textContent = m ? `Superficie calculada: ${formatArea(m.areaM2)}${m.lengthM ? ` · longitud ${formatLength(m.lengthM)}` : ''}.` : '';
    if (drawn) { drawn.remove(); drawn = null; }
    if (map && g) {
      drawn = siteShape(Lf, { footprintWKT: g.wkt || null, radiusM: g.radiusM ? Number(g.radiusM) : null,
        decimalLatitude: center && center[0], decimalLongitude: center && center[1] }, { ...SITE_STYLE, dashArray: null }).addTo(map);
    } else if (map && vertices.length) {
      drawn = Lf.polyline(vertices, { ...SITE_STYLE, dashArray: '2 4' }).addTo(map);
    }
  }

  if (Lf) {
    const layer = layerByKey('base');
    map = Lf.map($('.editor-map', form), { zoomControl: true, scrollWheelZoom: true, tap: false, doubleClickZoom: false });
    Lf.tileLayer(tileTemplate(layer.key), { maxZoom: layer.maxZoom, attribution: attributionFor(layer) }).addTo(map);
    const startShape = site ? siteShape(Lf, site) : null;
    if (startShape) map.fitBounds(Lf.featureGroup([startShape]).getBounds().pad(0.5), { maxZoom: 17 });
    else map.fitBounds(GRANADA);
    map.on('click', (ev) => {
      if (tool === 'point') center = [ev.latlng.lat, ev.latlng.lng];
      else vertices.push([ev.latlng.lat, ev.latlng.lng]);
      paint();
    });
    setTimeout(() => map.invalidateSize(), 50);
  }

  form.addEventListener('click', (ev) => {
    const t = ev.target.closest('[data-tool]');
    if (t) { tool = t.dataset.tool; if (tool !== 'point') vertices = []; paint(); return; }
    const ty = ev.target.closest('[data-type]');
    if (ty) { siteType = ty.dataset.type; paint(); return; }
    if (ev.target.closest('[data-undo]')) { vertices.pop(); paint(); return; }
    if (ev.target.closest('[data-cancel]')) ov.close();
  });
  form.radius.addEventListener('input', paint);
  form.band.addEventListener('input', paint);

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    err.hidden = true;
    const g = current();
    const fail = (m) => { err.textContent = m; err.hidden = false; };
    if (!g) { fail(tool === 'point' ? 'Toca el mapa para poner el centro del sitio.' : 'Dibuja la forma del sitio en el mapa.'); return; }
    const type = tool === 'line' ? 'transect' : tool === 'polygon' ? 'plot' : siteType;
    const fields = { name: form.name.value, siteType: type, protocol: form.protocol.value || defaultProtocol(type), species: [...selected],
      bandM: tool === 'line' ? form.band.value : null, areaM2: form.area.value === '' ? null : form.area.value };
    try {
      if (editing) {
        const patch = { ...fields, ...(g.wkt ? { footprintWKT: g.wkt, radiusM: null } : { radiusM: Math.round(Number(g.radiusM)), footprintWKT: null,
          decimalLatitude: g.center.lat, decimalLongitude: g.center.lng }) };
        if (patch.areaM2 !== null) patch.areaM2 = Number(String(patch.areaM2).replace(',', '.'));
        if (patch.bandM !== null) patch.bandM = Number(String(patch.bandM).replace(',', '.'));
        if (!(await updateSite(site.siteID, patch))) throw new Error('Ese sitio ya no se puede editar (¿se borró?).');
      } else {
        await createSite({ catalogId: catalog.id, ...fields, ...(g.wkt ? { wkt: g.wkt } : { center: g.center, radiusM: g.radiusM }) });
      }
      requestSync();
      ov.close(true);
      if (onSaved) onSaved();
    } catch (e) {
      fail(e.message);
    }
  });

  remoteOrLocal(() => apiGetJson('/catalogs/' + encodeURIComponent(catalog.id)), () => localCatalogData(catalog.id))
    .then((res) => { if (ov.isOpen()) targetPicker($('.site-targets', form), res.data.species || [], selected, () => {}); })
    .catch(() => { if (ov.isOpen()) render($('.site-targets', form), html`<p class="guard-band warn">Sin conexión y sin el catálogo descargado no hay especies que elegir.</p>`); });
  paint();
  form.name.focus();
  return ov;
}
