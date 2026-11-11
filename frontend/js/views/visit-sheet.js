// ================================================================================================
// views/visit-sheet.js — empezar y cerrar una visita en el campo (F11h; lógica en js/visits.js).
// ------------------------------------------------------------------------------------------------
// Empezar («Empezar visita», botón grande del Mapa):
//   · Sitio: los del catálogo por cercanía (el más cercano primero, «dentro» si estás en él), uno NUEVO aquí con
//     punto + radio, «Grabar transecto» (la traza GPS se graba mientras dura la visita) o sin sitio (oportunista).
//   · Protocolo (censo de especies objetivo · conteo en parcela · transecto con banda · búsqueda por tiempo) y especies
//     objetivo: las dos se recuerdan del sitio.
//   · Observadores (tú, por defecto) y condiciones.
// Cerrar (desde la barra de visita): resumen de lo contado; las objetivo sin contar se proponen como AUSENTES (0), y
// se puede quitar alguna; esfuerzo en minutos (inicio–fin, editable) y condiciones. Sin marcas, se puede descartar.
// Todo sin red: IndexedDB + cola; sync.js lo sube (sitios y visitas primero).
// ================================================================================================

import { state } from '../state.js';
import { html, render, $, $$, sciName, sciNameText, plural } from '../util.js';
import { apiGetJson } from '../api.js';
import { openOverlay, confirmDialog } from '../components/overlay.js';
import { requestSync } from '../sync.js';
import { remoteOrLocal, localCatalogData } from '../offline.js';
import { get } from '../db.js';
import { MARK_ROLES } from '../marks.js';
import { PROTOCOLS, SITE_TYPES, SITE_RADIUS_DEFAULT, BAND_DEFAULT, defaultProtocol, nearestSites, rememberedTargets, startVisit,
  closeVisit, discardVisit, visitTally, minutesBetween, trackOf } from '../visits.js';
import { formatArea, formatLength, lineWkt, siteMetrics } from '../site-geom.js';
import { toUtm } from '../geo/utm.js';

const GPS_TIMEOUT_MS = 30000;
const NEAR_LIST = 6;

const fmtDistance = (d) => (d === null ? '' : d === 0 ? 'estás dentro' : d < 1000 ? `a ${Math.round(d)} m` : `a ${(d / 1000).toLocaleString('es-ES', { maximumFractionDigits: 1 })} km`);

/** Especies del catálogo (API o dispositivo). */
async function catalogSpecies(catalog) {
  const res = await remoteOrLocal(() => apiGetJson('/catalogs/' + encodeURIComponent(catalog.id)), () => localCatalogData(catalog.id));
  return res.data.species || [];
}

/** Lista de especies con casillas (objetivo). `selected`: Set. Filtro por nombre. */
function targetPicker(box, species, selected, onChange) {
  let q = '';
  const fold = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  function paint() {
    const f = fold(q);
    const rows = species.filter((sp) => !f || fold(sp.vernacularName).includes(f) || fold(sp.canonicalName || sp.scientificName).includes(f))
      .sort((a, b) => (selected.has(b.taxonID) - selected.has(a.taxonID))
        || String(a.canonicalName || a.scientificName).localeCompare(String(b.canonicalName || b.scientificName), 'es'));
    render($('.vt-list', box), html`${rows.slice(0, 80).map((sp) => html`<li><label class="check vt-row">
      <input type="checkbox" data-target="${sp.taxonID}" ${selected.has(sp.taxonID) ? html`checked` : ''}>
      <span>${sp.vernacularName ? html`<strong>${sp.vernacularName}</strong> · ` : ''}${sciName(sp)}</span></label></li>`)}
      ${rows.length > 80 ? html`<li class="hint">y ${rows.length - 80} más: escribe para afinar.</li>` : ''}`);
    $('.vt-count', box).textContent = selected.size ? plural(selected.size, 'especie objetivo', 'especies objetivo') : 'Ninguna especie objetivo';
  }
  render(box, html`<p class="vt-count" role="status" aria-live="polite"></p>
    <label class="field"><span class="sr-only">Buscar especie objetivo</span>
      <input type="search" class="vt-q" placeholder="Buscar especie…" autocomplete="off"></label>
    <ul class="vt-list" role="list"></ul>`);
  $('.vt-q', box).addEventListener('input', (ev) => { q = ev.target.value; paint(); });
  box.addEventListener('change', (ev) => {
    const cb = ev.target.closest('[data-target]');
    if (!cb) return;
    if (cb.checked) selected.add(cb.dataset.target); else selected.delete(cb.dataset.target);
    $('.vt-count', box).textContent = selected.size ? plural(selected.size, 'especie objetivo', 'especies objetivo') : 'Ninguna especie objetivo';
    onChange();
  });
  paint();
  return { repaint: paint };
}

/** Abre «Empezar visita». `initialPos`: última GeolocationPosition conocida. */
export function openStartVisit({ catalog, initialPos = null, onStarted } = {}) {
  let watchId = null;
  const ov = openOverlay({ key: 'empezar-visita', title: 'Empezar visita', variant: 'side', className: 'visit-sheet',
    onClose: () => { if (watchId !== null) navigator.geolocation.clearWatch(watchId); } });
  if (!catalog || !MARK_ROLES.includes(catalog.role)) {
    render(ov.body, html`<p class="guard-band warn">${catalog ? `Tu rol en «${catalog.name}» no permite hacer visitas.` : 'Elige un catálogo activo para empezar una visita.'}</p>
      <div class="form-actions dialog-actions"><button type="button" class="btn-primary" data-close>Entendido</button></div>`);
    $('[data-close]', ov.body).addEventListener('click', () => ov.close());
    return ov;
  }
  let pos = initialPos ? { lat: initialPos.coords.latitude, lng: initialPos.coords.longitude, accuracy: initialPos.coords.accuracy } : null;
  let near = [];
  let species = [];
  let choice = null;                  // {kind: 'site', site} · {kind: 'new'} · {kind: 'transect'} · {kind: 'none'}
  let protocol = null;
  let protocolTouched = false;
  const selected = new Set();
  let picker = null;

  render(ov.body, html`<form class="form visit-form" novalidate>
    <p class="visit-gps hint" role="status" aria-live="polite"></p>
    <fieldset class="field"><legend>Sitio</legend><div class="visit-sites" role="radiogroup" aria-label="Sitio"></div></fieldset>
    <div class="visit-new" hidden>
      <label class="field"><span>Nombre del sitio</span><input name="siteName" maxlength="120" autocomplete="off" placeholder="Parcela 3 · Barranco"></label>
      <div class="field new-type"><span id="vsType">Tipo</span><div class="chip-pick" role="radiogroup" aria-labelledby="vsType">
        ${['point', 'plot'].map((k) => html`<button type="button" role="radio" data-type="${k}">${SITE_TYPES[k]}</button>`)}</div></div>
      <label class="field new-radius"><span>Radio (m)</span><input name="radius" type="number" inputmode="numeric" min="1" max="100000" value="${SITE_RADIUS_DEFAULT}"></label>
      <label class="field new-band" hidden><span>Ancho total de la banda (m)</span><input name="band" type="number" inputmode="decimal" min="0.1" max="1000" step="any" value="${BAND_DEFAULT}"></label>
      <p class="hint new-hint"></p>
    </div>
    <fieldset class="field"><legend>Protocolo</legend><div class="chip-pick visit-protocols" role="radiogroup" aria-label="Protocolo">
      ${Object.entries(PROTOCOLS).map(([k, label]) => html`<button type="button" role="radio" data-protocol="${k}">${label}</button>`)}</div>
      <label class="field visit-band" hidden><span>Banda del transecto, ancho total (m)</span>
        <input name="visitBand" type="number" inputmode="decimal" min="0.1" max="1000" step="any"></label></fieldset>
    <fieldset class="field"><legend>Especies objetivo <em>(lo que no cuentes quedará como ausente al cerrar)</em></legend>
      <div class="visit-targets"><p class="muted">Cargando especies…</p></div></fieldset>
    <label class="field"><span>Observadores</span><input name="observers" maxlength="500" value="${state.user.name || state.user.email || ''}"></label>
    <label class="field"><span>Condiciones <em>(opcional)</em></span><textarea name="conditions" rows="2" maxlength="2000"
      placeholder="Despejado, 14 °C, viento flojo…"></textarea></label>
    <p class="form-error" role="alert" hidden></p>
    <div class="sheet-actions"><button type="submit" class="btn-primary visit-start">Empezar visita</button>
      <button type="button" class="link-btn" data-cancel>Cancelar</button></div>
  </form>`);
  const form = $('form', ov.body);
  const err = $('.form-error', form);
  let newType = 'point';

  function paintGps() {
    $('.visit-gps', form).textContent = pos ? `Posición ±${Math.round(pos.accuracy || 0)} m` : 'Buscando tu posición… (para un sitio que ya existe no hace falta)';
  }

  function paintSites() {
    const opts = [
      ...near.slice(0, NEAR_LIST).map(({ site, distance }) => ({ key: 'site:' + site.siteID, label: html`<strong>${site.name}</strong>
        <span class="muted"> · ${SITE_TYPES[site.siteType] || site.siteType}${distance !== null ? ` · ${fmtDistance(distance)}` : ''}</span>` })),
      { key: 'new', label: html`<strong>Nuevo sitio aquí</strong><span class="muted"> · punto + radio</span>` },
      { key: 'transect', label: html`<strong>Grabar transecto</strong><span class="muted"> · la traza GPS mientras dura la visita</span>` },
      { key: 'none', label: html`<strong>Sin sitio</strong><span class="muted"> · visita oportunista (no entra en las series)</span>` },
    ];
    const cur = choice ? (choice.kind === 'site' ? 'site:' + choice.site.siteID : choice.kind) : null;
    render($('.visit-sites', form), html`${opts.map((o) => html`<button type="button" role="radio" class="visit-site${cur === o.key ? ' active' : ''}"
      aria-checked="${cur === o.key}" data-site="${o.key}">${o.label}</button>`)}
      ${near.length > NEAR_LIST ? html`<p class="hint">${plural(near.length - NEAR_LIST, 'sitio más', 'sitios más')} en el catálogo, más lejos.</p>` : ''}`);
    const isNew = choice && (choice.kind === 'new' || choice.kind === 'transect');
    $('.visit-new', form).hidden = !isNew;
    if (isNew) {
      const tr = choice.kind === 'transect';
      $('.new-type', form).hidden = tr;
      $('.new-radius', form).hidden = tr;
      $('.new-band', form).hidden = !tr;
      $('.new-hint', form).textContent = tr ? 'Camina el transecto: la línea se guarda al cerrar la visita.'
        : `Centro: tu posición. Superficie: ${formatArea(Math.PI * Number(form.radius.value || 0) ** 2)}.`;
      $$('[data-type]', form).forEach((b) => { const on = b.dataset.type === newType; b.classList.toggle('active', on); b.setAttribute('aria-checked', String(on)); });
    }
  }

  function paintProtocol() {
    $$('[data-protocol]', form).forEach((b) => { const on = b.dataset.protocol === protocol; b.classList.toggle('active', on); b.setAttribute('aria-checked', String(on)); });
    $('.visit-band', form).hidden = protocol !== 'transect';
  }

  async function choose(key) {
    if (key.startsWith('site:')) choice = { kind: 'site', site: near.find((n) => 'site:' + n.site.siteID === key).site };
    else choice = { kind: key };
    const siteType = choice.kind === 'site' ? choice.site.siteType : choice.kind === 'transect' ? 'transect' : choice.kind === 'new' ? newType : null;
    if (!protocolTouched) protocol = (choice.kind === 'site' && choice.site.protocol) || (siteType ? defaultProtocol(siteType) : 'timed_search');
    if (protocol === 'transect' && !form.visitBand.value) {
      form.visitBand.value = String((choice.kind === 'site' && choice.site.bandM) || (choice.kind === 'transect' && form.band.value) || BAND_DEFAULT);
    }
    if (choice.kind === 'site') {
      selected.clear();
      for (const t of await rememberedTargets(choice.site)) selected.add(t);
      if (picker) picker.repaint();
    }
    paintSites();
    paintProtocol();
  }

  form.addEventListener('click', (ev) => {
    const s = ev.target.closest('[data-site]');
    if (s) { choose(s.dataset.site); return; }
    const t = ev.target.closest('[data-type]');
    if (t) { newType = t.dataset.type; if (!protocolTouched) protocol = defaultProtocol(newType); paintSites(); paintProtocol(); return; }
    const p = ev.target.closest('[data-protocol]');
    if (p) { protocol = p.dataset.protocol; protocolTouched = true; paintProtocol(); return; }
    if (ev.target.closest('[data-cancel]')) ov.close();
  });
  form.radius.addEventListener('input', paintSites);

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    err.hidden = true;
    const fail = (m) => { err.textContent = m; err.hidden = false; $('.visit-start', form).focus(); };
    if (!choice) { fail('Elige el sitio (o «Sin sitio»).'); return; }
    if (!protocol) { fail('Elige el protocolo.'); return; }
    const args = { catalog, protocol, species: [...selected], observers: form.observers.value, conditions: form.conditions.value, pos,
      bandM: protocol === 'transect' ? form.visitBand.value : null };
    if (choice.kind === 'site') args.site = choice.site;
    if (choice.kind === 'new') args.newSite = { name: form.siteName.value, siteType: newType, radiusM: form.radius.value };
    if (choice.kind === 'transect') args.transect = { name: form.siteName.value, bandM: form.band.value };
    $('.visit-start', form).disabled = true;
    try {
      const visit = await startVisit(args);
      requestSync();
      ov.close(true);
      if (onStarted) onStarted(visit);
    } catch (e) {
      $('.visit-start', form).disabled = false;
      fail(e.message);
    }
  });

  async function loadSites() {
    near = await nearestSites(catalog.id, pos).catch(() => []);
    if (!ov.isOpen()) return;
    // El más cercano preseleccionado si estás dentro de él.
    if (!choice && near[0] && near[0].distance === 0) await choose('site:' + near[0].site.siteID);
    paintSites();
  }

  paintGps();
  paintSites();
  paintProtocol();
  loadSites();
  catalogSpecies(catalog).then((list) => {
    species = list;
    if (ov.isOpen()) picker = targetPicker($('.visit-targets', form), species, selected, () => {});
  }).catch(() => {
    if (ov.isOpen()) render($('.visit-targets', form), html`<p class="guard-band warn">Sin conexión y sin el catálogo descargado no hay especies que elegir.</p>`);
  });
  if (navigator.geolocation) {
    let lastNear = 0;
    watchId = navigator.geolocation.watchPosition((p) => {
      if (!ov.isOpen()) return;
      pos = { lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy };
      paintGps();
      if (Date.now() - lastNear > 15000) { lastNear = Date.now(); loadSites(); }
    }, () => {}, { enableHighAccuracy: true, timeout: GPS_TIMEOUT_MS, maximumAge: 5000 });
  }
  return ov;
}

/** Abre «Cerrar visita» para la visita en curso. */
export async function openCloseVisit({ visitID, onClosed } = {}) {
  const visit = await get('visits', visitID);
  if (!visit) return null;
  const site = visit.siteID ? await get('sites', visit.siteID) : null;
  const targets = visit.species || [];
  const { byTaxon, pending } = await visitTally(visitID, targets);
  const catalog = (await get('catalogs', visit.catalogId)) || { id: visit.catalogId, name: '' };
  const species = await catalogSpecies(catalog).catch(() => []);
  const name = (t) => { const sp = species.find((s) => s.taxonID === t); return sp ? html`${sp.vernacularName ? html`<strong>${sp.vernacularName}</strong> · ` : ''}${sciName(sp)}` : t; };
  const nameText = (t) => { const sp = species.find((s) => s.taxonID === t); return sp ? (sp.vernacularName || sciNameText(sp)) : t; };
  const recording = state.visit && state.visit.visitID === visitID && state.visit.recording;
  const track = recording ? await trackOf(visitID) : [];
  const trackLen = track.length >= 2 ? siteMetrics({ wkt: lineWkt(track) }, toUtm).lengthM : null;
  const absences = new Set(pending);
  const ov = openOverlay({ key: 'cerrar-visita', title: 'Cerrar visita', variant: 'side', className: 'visit-sheet' });
  const counted = [...byTaxon.entries()];
  const effort0 = minutesBetween(visit.eventStart, new Date().toISOString());
  render(ov.body, html`<form class="form visit-form" novalidate>
    <p class="visit-summary-head"><strong>${site ? site.name : 'Visita sin sitio'}</strong>
      <span class="muted"> · ${PROTOCOLS[visit.protocol]}${site && site.areaM2 ? ` · ${formatArea(site.areaM2)}` : ''}</span></p>
    ${recording ? html`<p class="hint">Traza del transecto: ${plural(track.length, 'punto', 'puntos')}${trackLen ? ` · ${formatLength(trackLen)}` : ''}.
      ${track.length < 2 ? 'Con menos de dos puntos, el sitio se queda como punto + radio.' : 'Se guarda como la línea del sitio.'}</p>` : ''}
    <section aria-label="Lo contado"><h3 class="visit-h">Contado en la visita</h3>
      ${counted.length ? html`<table class="data-table visit-tally"><thead><tr><th>Especie</th><th>Individuos</th><th>Maduros</th></tr></thead>
        <tbody>${counted.map(([t, a]) => html`<tr><td>${name(t)}${targets.includes(t) ? html` <span class="badge">objetivo</span>` : ''}</td>
          <td>${a.present ? a.individuals : a.absent ? 'ausente' : '—'}</td><td>${a.present ? a.mature : '—'}</td></tr>`)}</tbody></table>`
        : html`<p class="muted">Todavía no hay marcas en esta visita.</p>`}
    </section>
    ${pending.length ? html`<fieldset class="field"><legend>Especies objetivo sin contar: se guardan como <strong>ausentes (0)</strong></legend>
      <p class="hint">Quita la marca de las que no buscaste de verdad: una ausencia dice que la buscaste y no estaba.</p>
      <ul class="vt-list" role="list">${pending.map((t) => html`<li><label class="check vt-row"><input type="checkbox" data-absent="${t}" checked>
        <span>${name(t)}</span></label></li>`)}</ul></fieldset>` : targets.length ? html`<p class="hint">Todas las especies objetivo están contadas.</p>` : ''}
    <label class="field"><span>Esfuerzo (minutos)</span><input name="effort" type="number" inputmode="numeric" min="0" max="100000" value="${effort0}">
      <span class="hint">Calculado entre el inicio y ahora; corrígelo si paraste.</span></label>
    <label class="field"><span>Condiciones <em>(opcional)</em></span><textarea name="conditions" rows="2" maxlength="2000">${visit.conditions || ''}</textarea></label>
    <p class="form-error" role="alert" hidden></p>
    <div class="sheet-actions"><button type="submit" class="btn-primary visit-close">Cerrar visita</button>
      ${!byTaxon.size ? html`<button type="button" class="btn-secondary" data-discard>Descartar visita</button>` : ''}
      <button type="button" class="link-btn" data-cancel>Seguir en la visita</button></div>
  </form>`);
  const form = $('form', ov.body);
  const err = $('.form-error', form);
  const label = () => { $('.visit-close', form).textContent = absences.size ? `Cerrar visita (${plural(absences.size, 'ausencia', 'ausencias')})` : 'Cerrar visita'; };
  label();
  form.addEventListener('change', (ev) => {
    const cb = ev.target.closest('[data-absent]');
    if (!cb) return;
    if (cb.checked) absences.add(cb.dataset.absent); else absences.delete(cb.dataset.absent);
    label();
  });
  form.addEventListener('click', async (ev) => {
    if (ev.target.closest('[data-cancel]')) { ov.close(); return; }
    if (ev.target.closest('[data-discard]')) {
      const ok = await confirmDialog({ key: 'descartar-visita', title: 'Descartar la visita', danger: true, confirmLabel: 'Descartar',
        text: html`<p>La visita no tiene marcas. Se borra${site && !site.serverSeq ? ', y también el sitio nuevo' : ''}.</p>` });
      if (!ok) return;
      try { await discardVisit(visitID); requestSync(); ov.close(true); if (onClosed) onClosed(null); } catch (e) { err.textContent = e.message; err.hidden = false; }
    }
  });
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    err.hidden = true;
    $('.visit-close', form).disabled = true;
    try {
      const res = await closeVisit({ visitID, absences: [...absences], effortMinutes: form.effort.value, conditions: form.conditions.value });
      requestSync();
      ov.close(true);
      if (onClosed) onClosed(res, [...absences].map(nameText));
    } catch (e) {
      $('.visit-close', form).disabled = false;
      err.textContent = e.message;
      err.hidden = false;
    }
  });
  $('.visit-close', form).focus();
  return ov;
}

