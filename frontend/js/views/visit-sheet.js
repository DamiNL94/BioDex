// ================================================================================================
// views/visit-sheet.js — empezar y cerrar una visita en el campo (lógica en js/visits.js).
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
import { get, byIndex, hasDb } from '../db.js';
import { MARK_ROLES } from '../marks.js';
import { PROTOCOLS, SITE_TYPES, SITE_RADIUS_DEFAULT, BAND_DEFAULT, defaultProtocol, nearestSites, rememberedTargets, startVisit,
  closeVisit, discardVisit, visitTally, minutesBetween, EFFORT_PROTOCOLS, EFFORT_UNITS, EFFORT_DEFAULT_TARGET, VISIT_KINDS,
  defaultEffortProtocol, effortDefaults, visitProgress } from '../visits.js';

const DESIGN_LABELS = { exploratory: 'exploratorio', repeated: 'sitios repetidos', mixed: 'mixto' };
const EFFORT_TARGET_LABEL = { timed: 'Minutos de búsqueda activa (objetivo)', transect: 'Metros de transecto (objetivo)' };
const EFFORT_HINTS = {
  timed: 'Búsqueda con tiempo fijo: recorre la zona el tiempo fijado. Las pausas no cuentan. Se graba tu recorrido GPS.',
  transect: 'Transecto: camina la longitud fijada. Se graba tu recorrido GPS y la distancia sale de él.',
  free: 'Libre: sin objetivo de esfuerzo ni recorrido. La visita no cuenta para la completitud del muestreo (Chao2).',
};
import { formatArea, formatLength, lineWkt, siteMetrics } from '../site-geom.js';
import { toUtm } from '../geo/utm.js';
import { fold } from '../species-search.js';

const GPS_TIMEOUT_MS = 30000;
const NEAR_LIST = 6;

const fmtDistance = (d) => (d === null ? '' : d === 0 ? 'estás dentro' : d < 1000 ? `a ${Math.round(d)} m` : `a ${(d / 1000).toLocaleString('es-ES', { maximumFractionDigits: 1 })} km`);

/** Especies del catálogo (API o dispositivo). */
async function catalogSpecies(catalog) {
  const res = await remoteOrLocal(() => apiGetJson('/catalogs/' + encodeURIComponent(catalog.id)), () => localCatalogData(catalog.id));
  return res.data.species || [];
}

/** Lista de especies con casillas (objetivo). `selected`: Set. Filtro por nombre. */
export function targetPicker(box, species, selected, onChange) {
  let q = '';
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
  let effort = null;                  // protocolo de búsqueda: timed · transect · free
  let effortTouched = false;
  let blindTouched = false;
  let kind = null;                    // exploratory · repeat
  let kindTouched = false;
  const designs = new Map();          // taxonID → diseño de muestreo (parámetro sampling_design)

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
    <fieldset class="field visit-effort-set"><legend>Búsqueda y esfuerzo</legend>
      <div class="chip-pick visit-efforts" role="radiogroup" aria-label="Cómo vas a buscar">
        ${Object.entries(EFFORT_PROTOCOLS).map(([k, label]) => html`<button type="button" role="radio" data-effort="${k}">${label}</button>`)}</div>
      <label class="field visit-effort-target" hidden><span class="vet-label"></span>
        <input name="effortTarget" type="number" inputmode="numeric" min="1" max="100000"></label>
      <p class="hint visit-effort-hint"></p>
      <label class="check visit-blind-check"><input type="checkbox" name="blind">
        <span><strong>Modo búsqueda</strong>: oculta las marcas anteriores de las especies objetivo mientras dura la visita, para que
          cada hallazgo sea independiente. Solo estas visitas cuentan para la completitud (Chao2).</span></label>
    </fieldset>
    <fieldset class="field visit-kind-set"><legend>Tipo de visita</legend>
      <div class="chip-pick visit-kinds" role="radiogroup" aria-label="Tipo de visita">
        ${Object.entries(VISIT_KINDS).map(([k, label]) => html`<button type="button" role="radio" data-kind="${k}">${label}</button>`)}</div>
      <p class="hint visit-kind-hint"></p>
    </fieldset>
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

  // --- Búsqueda y esfuerzo (F11j) ----------------------------------------------------------------
  function setEffort(next, target = null) {
    const changed = next !== effort;
    effort = next;
    if (effort !== 'free' && (changed || target !== null)) form.effortTarget.value = String(target || EFFORT_DEFAULT_TARGET[effort]);
    // Con protocolo, modo búsqueda por defecto; «libre» no puede ser ciega.
    if (!blindTouched || effort === 'free') form.blind.checked = effort !== 'free';
    paintEffort();
  }

  function paintEffort() {
    $$('[data-effort]', form).forEach((b) => { const on = b.dataset.effort === effort; b.classList.toggle('active', on); b.setAttribute('aria-checked', String(on)); });
    const free = !effort || effort === 'free';
    $('.visit-effort-target', form).hidden = free;
    if (!free) $('.vet-label', form).textContent = EFFORT_TARGET_LABEL[effort];
    $('.visit-effort-hint', form).textContent = effort ? EFFORT_HINTS[effort] : '';
    form.blind.disabled = free;
    $('.visit-blind-check', form).classList.toggle('is-disabled', free);
  }

  /** Búsqueda por defecto: la del sitio o la última del catálogo; si no, la que corresponde al protocolo de conteo. */
  async function defaultEffort() {
    if (effortTouched) return;
    const d = await effortDefaults(catalog.id, choice && choice.kind === 'site' ? choice.site : null).catch(() => null);
    if (effortTouched || !ov.isOpen()) return;
    if (d && EFFORT_PROTOCOLS[d.effortProtocol]) setEffort(d.effortProtocol, d.effortTarget);
    else setEffort(defaultEffortProtocol(protocol));
  }

  /**
   * Tipo de visita según el diseño de muestreo de las especies objetivo (parámetro `sampling_design`, lo fija quien
   * configura el catálogo): exploratorio → exploratoria; sitios repetidos → repetición; mixto o sin definir → se elige
   * aquí (por defecto, repetición si se visita un sitio que ya existe).
   */
  function paintKind() {
    const set = new Set([...selected].map((t) => designs.get(String(t))).filter(Boolean));
    const fixed = set.size === 1 && !set.has('mixed') ? (set.has('exploratory') ? 'exploratory' : 'repeat') : null;
    if (fixed) kind = fixed;
    else if (!kindTouched) kind = choice && choice.kind === 'site' ? 'repeat' : 'exploratory';
    $$('[data-kind]', form).forEach((b) => {
      const on = b.dataset.kind === kind;
      b.classList.toggle('active', on);
      b.setAttribute('aria-checked', String(on));
      b.disabled = !!fixed && !on;
    });
    $('.visit-kind-hint', form).textContent = !set.size ? 'Sin diseño de muestreo definido para las especies objetivo (se fija en Parámetros de la especie).'
      : fixed ? `Diseño de muestreo de las especies objetivo: ${DESIGN_LABELS[[...set][0]]}.`
        : `Diseño de muestreo: ${[...set].map((d) => DESIGN_LABELS[d]).join(' y ')}. Elige qué es esta visita.`;
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
    paintKind();
    await defaultEffort();
  }

  form.addEventListener('click', (ev) => {
    const s = ev.target.closest('[data-site]');
    if (s) { choose(s.dataset.site); return; }
    const t = ev.target.closest('[data-type]');
    if (t) { newType = t.dataset.type; if (!protocolTouched) protocol = defaultProtocol(newType); paintSites(); paintProtocol(); return; }
    const p = ev.target.closest('[data-protocol]');
    if (p) { protocol = p.dataset.protocol; protocolTouched = true; paintProtocol(); if (!effortTouched) setEffort(defaultEffortProtocol(protocol)); return; }
    const e = ev.target.closest('[data-effort]');
    if (e) { effortTouched = true; setEffort(e.dataset.effort); return; }
    const k = ev.target.closest('[data-kind]');
    if (k && !k.disabled) { kind = k.dataset.kind; kindTouched = true; paintKind(); return; }
    if (ev.target.closest('[data-cancel]')) ov.close();
  });
  form.radius.addEventListener('input', paintSites);
  form.blind.addEventListener('change', () => { blindTouched = true; });

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    err.hidden = true;
    const fail = (m) => { err.textContent = m; err.hidden = false; $('.visit-start', form).focus(); };
    if (!choice) { fail('Elige el sitio (o «Sin sitio»).'); return; }
    if (!protocol) { fail('Elige el protocolo.'); return; }
    if (!effort) { fail('Elige cómo vas a buscar: tiempo fijo, transecto o libre.'); return; }
    const args = { catalog, protocol, species: [...selected], observers: form.observers.value, conditions: form.conditions.value, pos,
      bandM: protocol === 'transect' ? form.visitBand.value : null,
      effortProtocol: effort, effortTarget: effort === 'free' ? null : form.effortTarget.value, blind: effort !== 'free' && form.blind.checked,
      visitKind: kind };
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
  paintEffort();
  paintKind();
  loadSites();
  // Diseño de muestreo de cada especie (parámetros del catálogo en el dispositivo).
  if (hasDb()) {
    byIndex('parameters', 'catalogId', catalog.id).then((list) => {
      for (const p of list) if (p.key === 'sampling_design' && p.value) designs.set(String(p.taxonID), p.value);
      if (ov.isOpen()) paintKind();
    }).catch(() => {});
  }
  catalogSpecies(catalog).then((list) => {
    species = list;
    if (ov.isOpen()) picker = targetPicker($('.visit-targets', form), species, selected, paintKind);
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
  const hasEffort = (visit.effortProtocol || 'free') !== 'free';
  const progress = recording || hasEffort ? await visitProgress(visit).catch(() => null) : null;
  const track = progress ? progress.track : [];
  const trackLen = track.length >= 2 ? siteMetrics({ wkt: lineWkt(track) }, toUtm).lengthM : null;
  const absences = new Set(pending);
  const ov = openOverlay({ key: 'cerrar-visita', title: 'Cerrar visita', variant: 'side', className: 'visit-sheet' });
  const counted = [...byTaxon.entries()];
  const effort0 = hasEffort && progress && progress.effort.activeSeconds !== null ? Math.round(progress.effort.activeSeconds / 60)
    : minutesBetween(visit.eventStart, new Date().toISOString());

  /** Resumen del esfuerzo y si la visita contará como ocasión de Chao2 (mismos criterios que shared/iucn-sufficiency.js). */
  function effortSummary() {
    if (!hasEffort || !progress) return '';
    const { effort: e, analysis: a } = progress;
    const unit = EFFORT_UNITS[visit.effortProtocol];
    const why = [
      visit.blind === true ? '' : 'no se hizo entera en modo búsqueda',
      e.targetMet ? '' : `no alcanza el esfuerzo objetivo (${visit.effortTarget} ${unit})`,
      e.trackComplete ? '' : 'el recorrido GPS está incompleto',
    ].filter(Boolean);
    const num = (n) => Number(n).toLocaleString('es-ES', { maximumFractionDigits: 1 });
    return html`<section class="visit-effort-summary" aria-label="Esfuerzo de la visita"><h3 class="visit-h">Esfuerzo</h3>
      <dl class="counts visit-effort-counts">
        <div><dt>Búsqueda</dt><dd>${EFFORT_PROTOCOLS[visit.effortProtocol]}<span class="dd-sub">objetivo: ${visit.effortTarget} ${unit}</span></dd></div>
        <div><dt>Tiempo activo</dt><dd>${num((e.activeSeconds || 0) / 60)} min</dd></div>
        <div><dt>Distancia</dt><dd>${num(a.distanceM)} m</dd></div>
        <div><dt>Celdas de 2 km</dt><dd>${a.cells.length}</dd></div>
        <div><dt>Recorrido</dt><dd>${e.trackComplete ? 'Completo' : 'Incompleto'}<span class="dd-sub">${Math.round(e.coverage * 100)} % del tiempo con GPS${
          a.gaps ? ` · ${plural(a.gaps, 'hueco', 'huecos')}` : ''}</span></dd></div>
        <div><dt>Modo búsqueda</dt><dd>${visit.blind === true ? 'Sí' : 'No'}</dd></div>
      </dl>
      ${why.length ? html`<p class="guard-band warn visit-occasion">No contará para la completitud del muestreo (Chao2): ${why.join('; ')}.
          Las marcas se guardan igual y cuentan para la distribución.</p>`
        : html`<p class="guard-band info visit-occasion">Cuenta como ocasión de muestreo para la completitud (Chao2), si su esfuerzo es comparable con el de las demás.</p>`}
    </section>`;
  }
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
    ${effortSummary()}
    <label class="field"><span>Esfuerzo (minutos)</span><input name="effort" type="number" inputmode="numeric" min="0" max="100000" value="${effort0}">
      <span class="hint">${hasEffort ? 'Tiempo activo de la búsqueda, sin las pausas. Para Chao2 cuenta el que sale del recorrido, no este campo.'
        : 'Calculado entre el inicio y ahora; corrígelo si paraste.'}</span></label>
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

