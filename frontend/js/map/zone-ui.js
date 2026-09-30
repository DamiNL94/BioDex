// ================================================================================================
// map/zone-ui.js — «Descargar esta zona» (rectángulo editable + capas y zooms + estimación) y la lista
// de zonas guardadas (progreso, pausa, reanudar, borrar).
// ------------------------------------------------------------------------------------------------
// El editor es un panel sobre el mapa, no un diálogo modal: el mapa sigue vivo para mover y ajustar el
// rectángulo por sus esquinas. Antes de empezar se ve el nº de teselas y los MB estimados (medias
// medidas de map/layers.js); más de MAX_ZONE_TILES o más de lo que cabe en la cuota → no deja empezar.
// ================================================================================================

import { html, render, $, $$, plural } from '../util.js';
import { openOverlay, confirmDialog } from '../components/overlay.js';
import { formatBytes, shortDate } from '../offline.js';
import { LAYERS, layerByKey, estimateZone } from './layers.js';
import { MAX_ZONE_TILES, createZone, quotaCheck, listZones, onZones, startZone, pauseZone, deleteZone, isRunning } from './zones.js';

const L = () => window.L;
// «8.000»: toLocaleString('es-ES') no agrupa los números de 4 cifras (norma RAE), y aquí se leen mejor con punto.
const fmtInt = (n) => String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
const DEFAULT_ON = ['base', 'pnoa'];

export const STATUS_LABELS = {
  downloading: 'Descargando', paused: 'En pausa', complete: 'Descargada', incomplete: 'Incompleta', error: 'Error',
};

/** «IGN Base z12–17 · PNOA z12–16». */
export const layersText = (zone) => zone.layers
  .map((l) => `${layerByKey(l.key).short} z${l.minZoom}–${l.maxZoom}`).join(' · ');

export const boundsOf = (b) => [[b.south, b.west], [b.north, b.east]];

/**
 * Editor de zona sobre `map`; el panel se pinta en `host`. `onClose(zone | null)` al terminar.
 * Devuelve {close()}.
 */
export function openZoneEditor(map, host, { onClose } = {}) {
  const Lf = L();
  let closed = false;
  const panel = document.createElement('form');
  panel.className = 'zone-panel';
  panel.noValidate = true;
  panel.setAttribute('aria-labelledby', 'zoneTitle');
  const zoomOptions = (max, sel) => Array.from({ length: max - 5 }, (_, i) => i + 6)
    .map((z) => html`<option value="${z}" ${z === sel ? html`selected` : ''}>${z}</option>`);
  render(panel, html`
    <div class="zone-panel-head">
      <h3 id="zoneTitle">Descargar esta zona</h3>
      <button type="button" class="band-close" data-cancel aria-label="Cancelar la descarga de zona">×</button>
    </div>
    <p class="hint">Arrastra las esquinas del rectángulo para ajustar el área.</p>
    <label class="field"><span>Nombre de la zona</span><input name="name" required maxlength="80" autocomplete="off" placeholder="p. ej. Alhambra y Generalife"></label>
    <fieldset class="zone-layers"><legend class="eyebrow">Capas y zoom</legend>
      ${LAYERS.map((l) => html`<div class="zone-layer">
        <label class="check"><input type="checkbox" name="layer" value="${l.key}" ${DEFAULT_ON.includes(l.key) ? html`checked` : ''}> ${l.label}</label>
        <label class="zoom-sel"><span>de</span><select name="min-${l.key}" aria-label="${l.label}: zoom mínimo">${zoomOptions(l.maxZoom, l.defaultZooms[0])}</select></label>
        <label class="zoom-sel"><span>a</span><select name="max-${l.key}" aria-label="${l.label}: zoom máximo">${zoomOptions(l.maxZoom, l.defaultZooms[1])}</select></label>
      </div>`)}
    </fieldset>
    <p class="zone-estimate" role="status" aria-live="polite"></p>
    <p class="form-error" role="alert" hidden></p>
    <p class="hint">Usa áreas razonables: el IGN es un servicio público y cada tesela es una petición a sus
      servidores. Máximo ${fmtInt(MAX_ZONE_TILES)} teselas por zona.</p>
    <div class="form-actions"><button type="submit" class="btn-primary">Descargar</button>
      <button type="button" class="link-btn" data-cancel>Cancelar</button></div>`);
  host.appendChild(panel);
  const out = $('.zone-estimate', panel);
  const err = $('.form-error', panel);
  const submit = $('button[type="submit"]', panel);

  // Rectángulo inicial: la parte del mapa que NO tapa el panel (a la izquierda en escritorio, abajo en
  // el móvil), con margen para que las cuatro esquinas se puedan coger.
  function initialBounds() {
    const size = map.getSize();
    const box = map.getContainer().getBoundingClientRect();
    const p = panel.getBoundingClientRect();
    const m = 36;
    let [x0, y0, x1, y1] = [m, m, size.x - m, size.y - m];
    if (p.bottom >= box.bottom - 2 && p.width >= box.width * 0.9) y1 = Math.max(y0 + 80, p.top - box.top - m);
    else if (p.left <= box.left + 20) x0 = Math.min(x1 - 80, p.right - box.left + m);
    const nw = map.containerPointToLatLng([x0, y0]);
    const se = map.containerPointToLatLng([x1, y1]);
    return { south: se.lat, west: nw.lng, north: nw.lat, east: se.lng };
  }
  let bounds = initialBounds();
  const rect = Lf.rectangle(boundsOf(bounds), { className: 'zone-draft', weight: 2, interactive: false }).addTo(map);
  const corners = ['sw', 'nw', 'ne', 'se'];
  const handles = corners.map(() => Lf.marker([0, 0], {
    draggable: true, keyboard: false, title: 'Arrastra para ajustar la zona',
    icon: Lf.divIcon({ className: 'zone-handle-wrap', html: html`<span class="zone-handle"></span>`.str, iconSize: [44, 44], iconAnchor: [22, 22] }),
  }).addTo(map));

  function cornerLatLng(c) {
    return [c[0] === 's' ? bounds.south : bounds.north, c[1] === 'w' ? bounds.west : bounds.east];
  }
  function placeHandles() { handles.forEach((h, i) => h.setLatLng(cornerLatLng(corners[i]))); }
  handles.forEach((h, i) => {
    h.on('drag', () => {
      const p = h.getLatLng();
      const c = corners[i];
      // La esquina opuesta queda fija; se reordena por si una esquina cruza a la otra.
      const opp = cornerLatLng((c[0] === 's' ? 'n' : 's') + (c[1] === 'w' ? 'e' : 'w'));
      bounds = { south: Math.min(p.lat, opp[0]), north: Math.max(p.lat, opp[0]),
        west: Math.min(p.lng, opp[1]), east: Math.max(p.lng, opp[1]) };
      rect.setBounds(boundsOf(bounds));
      estimate();
    });
    h.on('dragend', placeHandles);
  });
  placeHandles();

  function chosenLayers() {
    return $$('input[name="layer"]', panel).filter((c) => c.checked).map((c) => {
      const a = Number(panel[`min-${c.value}`].value);
      const b = Number(panel[`max-${c.value}`].value);
      return { key: c.value, minZoom: Math.min(a, b), maxZoom: Math.max(a, b) };
    });
  }

  let quotaSeq = 0;
  async function estimate() {
    const layers = chosenLayers();
    const est = estimateZone(bounds, layers);
    const tooMany = est.tiles > MAX_ZONE_TILES;
    render(out, html`<strong>${fmtInt(est.tiles)} teselas</strong> · ~${formatBytes(est.bytes)}
      ${layers.length > 1 ? html`<span class="muted"> (${layers.map((l) => `${layerByKey(l.key).short} ${fmtInt(est.perLayer[l.key].tiles)}`).join(' · ')})</span>` : ''}`);
    let problem = !layers.length ? 'Elige al menos una capa.'
      : tooMany ? `Son más de ${fmtInt(MAX_ZONE_TILES)} teselas: reduce el área o el zoom máximo.` : '';
    const seq = ++quotaSeq;
    if (!problem) {
      const q = await quotaCheck(est.bytes);
      if (seq !== quotaSeq || closed) return;
      if (!q.ok) problem = `No cabe: quedan ~${formatBytes(q.free)} libres en el dispositivo. Borra alguna zona o reduce el área.`;
    }
    out.classList.toggle('is-over', !!problem);
    err.textContent = problem;
    err.hidden = !problem;
    submit.disabled = !!problem;
  }

  function close(zone = null) {
    if (closed) return;
    closed = true;
    rect.remove();
    handles.forEach((h) => h.remove());
    panel.remove();
    if (onClose) onClose(zone);
  }

  panel.addEventListener('change', estimate);
  $$('[data-cancel]', panel).forEach((b) => b.addEventListener('click', () => close()));
  panel.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    submit.disabled = true;
    try {
      const zone = await createZone({ name: panel.name.value, bounds, layers: chosenLayers() });
      close(zone);
    } catch (e) {
      err.textContent = e.message;
      err.hidden = false;
      submit.disabled = false;
      if (!panel.name.value.trim()) panel.name.focus();
    }
  });
  estimate();
  panel.name.focus();
  return { close };
}

/** Línea de progreso de una zona: «1.234 de 3.456 teselas · 12 MB». */
export function progressText(z) {
  const size = z.bytes ? ` · ${formatBytes(z.bytes)}` : '';
  if (z.status === 'complete') return `${fmtInt(z.tileCount)} teselas${size}`;
  return `${fmtInt(z.doneCount)} de ${fmtInt(z.tileCount)} teselas${size}`;
}

/** Fila de una zona con sus acciones (lista de zonas y Ajustes › Almacenamiento). */
export function zoneRow(z, { showOnMap = false } = {}) {
  const pct = z.tileCount ? Math.floor((100 * z.doneCount) / z.tileCount) : 0;
  const running = isRunning(z.id);
  return html`<li class="zone-row status-${z.status}">
    <div class="zone-row-main">
      <strong>${z.name}</strong>
      <span class="badge ${z.status === 'complete' ? 'ok' : z.status === 'downloading' ? '' : 'warn'}">${STATUS_LABELS[z.status] || z.status}</span>
    </div>
    <p class="hint">${layersText(z)} · ${progressText(z)} · ${shortDate(z.completedAt || z.createdAt)}</p>
    ${z.status !== 'complete' ? html`<progress max="100" value="${pct}" aria-label="Progreso de «${z.name}»">${pct} %</progress>` : ''}
    ${z.error ? html`<p class="hint mark-reason">${z.error}</p>` : ''}
    <div class="form-actions">
      ${showOnMap ? html`<button type="button" class="link-btn" data-zone-show="${z.id}">Ver en el mapa</button>` : ''}
      ${running ? html`<button type="button" class="link-btn" data-zone-pause="${z.id}">Pausar</button>` : ''}
      ${!running && z.status !== 'complete' ? html`<button type="button" class="link-btn" data-zone-resume="${z.id}">Reanudar</button>` : ''}
      <button type="button" class="link-btn danger-link" data-zone-delete="${z.id}">${running ? 'Cancelar y borrar' : 'Borrar'}</button>
    </div>
  </li>`;
}

/** Engancha las acciones de zoneRow() en `el`. `onShow(zone)` para «Ver en el mapa». */
export function bindZoneRows(el, zones, { onShow, onChanged } = {}) {
  const find = (id) => zones().find((z) => z.id === id);
  el.addEventListener('click', async (ev) => {
    const b = ev.target.closest('[data-zone-show],[data-zone-pause],[data-zone-resume],[data-zone-delete]');
    if (!b || !el.contains(b)) return;
    if (b.dataset.zoneShow) { const z = find(b.dataset.zoneShow); if (z && onShow) onShow(z); return; }
    if (b.dataset.zonePause) { pauseZone(b.dataset.zonePause); return; }
    if (b.dataset.zoneResume) {
      if (!navigator.onLine) return;
      startZone(b.dataset.zoneResume);
      if (onChanged) onChanged();
      return;
    }
    const z = find(b.dataset.zoneDelete);
    if (!z) return;
    const ok = await confirmDialog({
      key: 'borrar-zona', title: 'Borrar zona', danger: true, confirmLabel: 'Borrar zona',
      text: html`<p>Se borran del dispositivo las teselas de «${z.name}»${z.bytes ? ` (${formatBytes(z.bytes)})` : ''}.
        Sin red, esa zona del mapa dejará de verse.</p>`,
    });
    if (!ok) return;
    await deleteZone(z.id);
    if (onChanged) onChanged();
  });
}

/** Panel «Zonas sin conexión». `onDownloadHere()` abre el editor; `onShow(zone)` encuadra una zona. */
export function openZonesList({ onDownloadHere, onShow } = {}) {
  let zones = [];
  let alive = true;
  const ov = openOverlay({ key: 'zonas', title: 'Zonas sin conexión', variant: 'side', className: 'zones-overlay',
    onClose: () => { alive = false; unsubscribe(); } });

  async function load() {
    zones = await listZones();
    if (alive) paint();
  }
  function paint() {
    const total = zones.reduce((s, z) => s + (z.bytes || 0), 0);
    render(ov.body, html`
      <p>Las zonas descargadas se ven en el mapa sin cobertura, en todas las capas que elegiste.</p>
      <div class="form-actions"><button type="button" class="btn-primary" data-here>Descargar esta zona</button></div>
      ${!navigator.onLine ? html`<p class="guard-band warn">Sin conexión: no se pueden descargar ni reanudar zonas.</p>` : ''}
      ${zones.length ? html`<h4>${plural(zones.length, 'zona', 'zonas')} · ${formatBytes(total)}</h4>
        <ul class="zone-rows">${zones.map((z) => zoneRow(z, { showOnMap: true }))}</ul>`
        : html`<p class="muted">Aún no hay zonas descargadas.</p>`}`);
    $('[data-here]', ov.body).disabled = !navigator.onLine;
  }
  ov.body.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-here]')) { ov.close(true); if (onDownloadHere) onDownloadHere(); }
  });
  bindZoneRows(ov.body, () => zones, { onShow: (z) => { ov.close(true); if (onShow) onShow(z); }, onChanged: load });
  // Durante una descarga llegan avisos cada 150 ms: repintar tan a menudo se comería los toques.
  let pending = null;
  const unsubscribe = onZones(() => { if (!pending) pending = setTimeout(() => { pending = null; load(); }, 1000); });
  render(ov.body, html`<p class="muted">Cargando zonas…</p>`);
  load();
  return ov;
}
