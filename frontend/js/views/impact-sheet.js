// ================================================================================================
// views/impact-sheet.js — hoja de impacto del modo campo (F11g): botón «Impacto» junto a «Marcar».
// ------------------------------------------------------------------------------------------------
// Pensada para una mano, como la hoja de marca: botones grandes con los impactos más habituales (pisoteo,
// residuos, ganado o sobrepastoreo, recolección, obras o pistas, incendio, especies invasoras) y «Otro» →
// árbol completo del esquema de amenazas de la UICN v3.3 (shared/iucn-threats.json, precacheado por el SW).
// Después: sitio (GPS + radio, o PERÍMETRO recorrido a pie añadiendo vértices con el GPS), intensidad (baja ·
// media · alta), alcance (% afectado estimado), temporalidad (pasado · en curso · futuro), fecha, nota y
// especies afectadas: por defecto, las del catálogo con marcas en el dispositivo dentro del radio.
// Funciona sin conexión: se guarda en IndexedDB + cola (marks.js › createImpact) y sync.js lo sube.
// Todo el HTML pasa por html`` (la nota y los nombres vienen de usuarios).
// ================================================================================================

import { html, render, $, $$, sciNameText } from '../util.js';
import { apiGetJson } from '../api.js';
import { openOverlay, confirmDialog } from '../components/overlay.js';
import { createImpact, updateImpact, MARK_ROLES, IMPACT_INTENSITIES, IMPACT_TIMINGS, IMPACT_RADIUS_DEFAULT, isoNow } from '../marks.js';
import { byIndex } from '../db.js';
import { requestSync } from '../sync.js';
import { remoteOrLocal, localCatalogData } from '../offline.js';

const THREATS_URL = '/shared/iucn-threats.json';
const GPS_TIMEOUT_MS = 30000;
const LOW_ACCURACY_M = 50;
let threatsPromise = null;

/** Esquema de amenazas (una vez por sesión; sin red lo sirve el SW). */
export function loadThreats() {
  if (!threatsPromise) {
    threatsPromise = fetch(THREATS_URL).then((r) => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
      .catch((e) => { threatsPromise = null; throw e; });
  }
  return threatsPromise;
}

/** Código → «2.3 Ganadería y pastoreo». */
export function threatLabel(threats, code) {
  if (!threats || !code) return code || '';
  for (const t of threats.threats) {
    if (t.code === code) return `${code} ${t.label}`;
    for (const c of t.children || []) if (c.code === code) return `${code} ${c.label}`;
  }
  return code;
}

/** Distancia en metros entre dos puntos (haversine). */
export function metersBetween(a, b) {
  const R = 6371000;
  const toRad = (d) => (d * Math.PI) / 180;
  const dLat = toRad(b.lat - a.lat);
  const dLon = toRad(b.lng - a.lng);
  const s = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLon / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(s));
}

/** ¿Está el punto dentro del polígono [[lat, lon]…]? (rayo; vale a escala de campo). */
function inPolygon(p, poly) {
  let c = false;
  for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
    const [ai, bi] = poly[i];
    const [aj, bj] = poly[j];
    if ((ai > p.lat) !== (aj > p.lat) && p.lng < ((bj - bi) * (p.lat - ai)) / (aj - ai) + bi) c = !c;
  }
  return c;
}

/** taxonIDs del catálogo con marcas (presencias) del dispositivo dentro del radio o del polígono. */
async function speciesInside(catalogId, where) {
  const marks = (await byIndex('occurrences', 'catalogId', catalogId)).filter((o) => !o.deletedAt && o.occurrenceStatus !== 'absent'
    && Number.isFinite(o.decimalLatitude) && Number.isFinite(o.decimalLongitude));
  const ids = new Set();
  for (const o of marks) {
    const p = { lat: o.decimalLatitude, lng: o.decimalLongitude };
    const inside = where.polygon ? inPolygon(p, where.polygon) : metersBetween(p, where.center) <= where.radiusM;
    if (inside) ids.add(o.taxonID);
  }
  return ids;
}

const canRecord = (catalog) => !!catalog && MARK_ROLES.includes(catalog.role);

/** «2026-11-07T10:30» para un <input type="datetime-local"> con la hora del dispositivo. */
function localInputValue(iso) {
  const d = iso ? new Date(iso) : new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

/**
 * Abre la hoja.
 *   catalog   {id, name, role}
 *   species   especie de partida (desde su ficha), o null
 *   impact    impacto propio a editar (sin sitio editable)
 *   point     {lat, lng}: punto del mapa en vez del GPS
 *   initialPos última GeolocationPosition conocida
 *   onSaved(impact)
 */
export function openImpactSheet({ catalog, species = null, impact = null, point = null, initialPos = null, onSaved } = {}) {
  const editing = !!impact;
  let watchId = null;
  const ov = openOverlay({ key: editing ? 'editar-impacto' : 'hoja-impacto', title: editing ? 'Editar impacto' : 'Registrar un impacto',
    variant: 'side', className: 'impact-sheet',
    onClose: () => { if (watchId !== null) navigator.geolocation.clearWatch(watchId); } });

  if (!canRecord(catalog) && !editing) {
    render(ov.body, html`<p class="guard-band warn">${catalog ? `Tu rol en «${catalog.name}» no permite registrar impactos.`
      : 'Elige un catálogo activo en Catálogos para registrar impactos.'}</p>
      <div class="form-actions dialog-actions"><button type="button" class="btn-primary" data-close>Entendido</button></div>`);
    $('[data-close]', ov.body).addEventListener('click', () => ov.close());
    $('[data-close]', ov.body).focus();
    return ov;
  }

  const i0 = impact || {};
  const f = {
    threatCode: i0.threatCode || null, quickKey: i0.quickKey || null,
    intensity: i0.intensity || 'medium', timing: i0.timing || 'ongoing',
    scopePct: i0.scopePct ?? '', note: i0.note || '',
    mode: i0.polygon ? 'polygon' : 'radius', radiusM: i0.radiusM || IMPACT_RADIUS_DEFAULT,
    vertices: [], species: new Set(i0.species || (species ? [species.taxonID] : [])), speciesTouched: editing,
  };
  let threats = null;
  let pos = initialPos;
  let posError = null;
  let catalogSpecies = [];
  let showTree = false;

  render(ov.body, html`<form class="form impact-form" novalidate>
    <section class="impact-threat" aria-label="Amenaza"></section>
    <div class="impact-rest" hidden>
      <section class="impact-where" aria-label="Sitio"></section>
      <div class="field"><span id="isInt">Intensidad</span>
        <div class="status-toggle three" role="radiogroup" aria-labelledby="isInt">
          ${Object.entries(IMPACT_INTENSITIES).map(([k, l]) => html`<button type="button" role="radio" data-intensity="${k}">${l}</button>`)}
        </div></div>
      <div class="field"><span id="isTim">Temporalidad</span>
        <div class="status-toggle three" role="radiogroup" aria-labelledby="isTim">
          ${Object.entries(IMPACT_TIMINGS).map(([k, l]) => html`<button type="button" role="radio" data-timing="${k}">${l}</button>`)}
        </div></div>
      <label class="field"><span>Alcance: fracción afectada (%) <em>(estimada, opcional)</em></span>
        <input type="number" name="scope" inputmode="decimal" min="0" max="100" step="any" value="${f.scopePct}" placeholder="p. ej. 30"></label>
      <label class="field"><span>Fecha y hora</span>
        <input type="datetime-local" name="when" value="${localInputValue(i0.eventDate)}" ${editing ? html`disabled` : ''}></label>
      <fieldset class="field impact-species"><legend>Especies afectadas</legend><div class="impact-species-list"><p class="muted">Cargando…</p></div></fieldset>
      <label class="field"><span>Nota <em>(opcional)</em></span>
        <textarea name="note" rows="3" maxlength="4000" placeholder="Qué has visto, a qué afecta…">${f.note}</textarea></label>
    </div>
    <p class="form-error" role="alert" hidden></p>
    <div class="sheet-actions">
      <button type="submit" class="btn-primary save-impact" disabled>${editing ? 'Guardar cambios' : 'Guardar impacto'}</button>
      <button type="button" class="link-btn" data-cancel>Cancelar</button>
    </div>
  </form>`);
  const form = $('form', ov.body);
  const save = $('.save-impact', form);
  const err = $('.form-error', form);

  // --- Amenaza: botones rápidos y árbol -------------------------------------------------------
  function paintThreat() {
    const box = $('.impact-threat', form);
    if (!threats) { render(box, html`<p class="muted">Cargando amenazas…</p>`); return; }
    if (f.threatCode && !showTree) {
      render(box, html`<div class="impact-chosen"><p class="mark-species"><strong>${threatLabel(threats, f.threatCode)}</strong></p>
        <button type="button" class="btn-secondary" data-change-threat>Cambiar<span class="sr-only"> de amenaza</span></button></div>`);
      $('.impact-rest', form).hidden = false;
      paintSave();
      return;
    }
    $('.impact-rest', form).hidden = true;
    render(box, html`<p class="field-label" id="isQuick">¿Qué impacto ves?</p>
      <div class="impact-quick" role="group" aria-labelledby="isQuick">
        ${threats.quick.map((q) => html`<button type="button" class="impact-quick-btn${f.quickKey === q.key ? ' active' : ''}"
          data-quick="${q.key}">${q.label}${q.code ? html`<span class="muted"> ${q.code}</span>` : ''}</button>`)}
      </div>
      ${showTree ? html`<div class="impact-tree" role="group" aria-label="Esquema de amenazas de la UICN">
        ${threats.threats.map((t) => html`<details class="more"><summary>${t.code} ${t.label}</summary>
          <div class="impact-tree-items">${(t.children || []).map((c) => html`<button type="button" class="link-btn"
            data-threat="${c.code}">${c.code} ${c.label}</button>`)}</div></details>`)}
      </div>` : ''}`);
    paintSave();
  }

  // --- Sitio ---------------------------------------------------------------------------------
  const center = () => (point ? { lat: point.lat, lng: point.lng } : pos ? { lat: pos.coords.latitude, lng: pos.coords.longitude } : null);
  function paintWhere() {
    const box = $('.impact-where', form);
    if (editing) {
      render(box, html`<p class="mark-gps"><span class="badge">${i0.polygon ? `Perímetro · ${i0.polygon.length} vértices` : `Radio ${i0.radiusM} m`}</span>
        ${i0.decimalLatitude !== null && i0.decimalLatitude !== undefined ? `${Number(i0.decimalLatitude).toFixed(5)}, ${Number(i0.decimalLongitude).toFixed(5)}` : ''}</p>
        <p class="hint">El sitio y la fecha de un impacto no se cambian: si está mal, bórralo y regístralo otra vez.</p>`);
      return;
    }
    const c = center();
    const acc = pos && !point ? Math.round(pos.coords.accuracy) : null;
    render(box, html`
      <div class="status-toggle" role="radiogroup" aria-label="Forma del sitio">
        <button type="button" role="radio" data-mode="radius" aria-checked="${f.mode === 'radius'}" class="${f.mode === 'radius' ? 'active' : ''}">Punto + radio</button>
        <button type="button" role="radio" data-mode="polygon" aria-checked="${f.mode === 'polygon'}" class="${f.mode === 'polygon' ? 'active' : ''}"
          ${point ? html`disabled title="Desde un punto del mapa solo hay radio"` : ''}>Perímetro a pie</button>
      </div>
      <p class="mark-gps" role="status" aria-live="polite">${c ? html`<span class="badge ${acc !== null && acc > LOW_ACCURACY_M ? 'warn' : 'ok'}">${point ? 'Punto del mapa' : 'Posición'}</span>
        ${c.lat.toFixed(5)}, ${c.lng.toFixed(5)}${acc !== null ? html` · <strong>±${acc} m</strong>` : ''}`
        : html`<span class="${posError ? 'guard-band warn' : 'gps-wait'}">${posError || 'Buscando tu posición…'}</span>`}</p>
      ${f.mode === 'radius' ? html`<div class="field"><span id="isRad">Radio afectado (m)</span>
          <div class="stepper" role="group" aria-labelledby="isRad">
            <button type="button" class="step-btn" data-radius="-10" aria-label="10 m menos">−</button>
            <input type="number" name="radius" inputmode="numeric" min="1" max="100000" step="1" value="${f.radiusM}" aria-labelledby="isRad">
            <button type="button" class="step-btn" data-radius="10" aria-label="10 m más">+</button>
          </div></div>`
        : html`<div class="field"><p class="hint">Recorre el borde de la zona afectada y pulsa «Añadir vértice» en cada esquina (mínimo 3).</p>
          <div class="form-actions"><button type="button" class="btn-secondary" data-vertex ${c ? '' : html`disabled`}>Añadir vértice aquí</button>
            <button type="button" class="link-btn" data-vertex-undo ${f.vertices.length ? '' : html`disabled`}>Quitar el último</button></div>
          <p class="hint" role="status">${f.vertices.length} ${f.vertices.length === 1 ? 'vértice' : 'vértices'}${f.vertices.length >= 3 ? ' · perímetro listo' : ''}</p></div>`}`);
  }

  function watchGps() {
    if (editing || point) return;
    if (!navigator.geolocation) { posError = 'Este dispositivo no ofrece ubicación.'; paintWhere(); return; }
    watchId = navigator.geolocation.watchPosition((p) => {
      if (!ov.isOpen()) return;
      const first = !pos;
      pos = p;
      posError = null;
      paintWhere();
      paintSave();
      if (first) refreshSpecies();
    }, (e) => {
      if (!ov.isOpen()) return;
      posError = e && e.code === 1 ? 'Sin permiso de ubicación: actívalo para BioDex o registra el impacto desde un punto del mapa.'
        : 'El GPS no da posición todavía. Busca cielo abierto.';
      paintWhere();
    }, { enableHighAccuracy: true, timeout: GPS_TIMEOUT_MS, maximumAge: 5000 });
  }

  // --- Especies afectadas --------------------------------------------------------------------
  function where() {
    if (f.mode === 'polygon' && f.vertices.length >= 3) return { polygon: f.vertices };
    const c = center();
    return c ? { center: c, radiusM: Number(form.radius ? form.radius.value : f.radiusM) || f.radiusM } : null;
  }

  async function refreshSpecies() {
    if (!f.speciesTouched && !editing) {
      const w = where();
      if (w) {
        const inside = await speciesInside(catalog.id, w).catch(() => new Set());
        f.species = new Set([...(species ? [species.taxonID] : []), ...inside]);
      }
    }
    paintSpecies();
  }

  function paintSpecies() {
    const box = $('.impact-species-list', form);
    if (!box) return;
    if (!catalogSpecies.length) { render(box, html`<p class="muted">Sin especies del catálogo en el dispositivo.</p>`); return; }
    const sorted = [...catalogSpecies].sort((a, b) => (f.species.has(b.taxonID) - f.species.has(a.taxonID))
      || sciNameText(a).localeCompare(sciNameText(b)));
    render(box, html`<p class="hint">${f.speciesTouched ? 'Elegidas a mano.' : 'Por defecto, las del catálogo con marcas dentro del sitio.'}
      ${f.species.size} ${f.species.size === 1 ? 'elegida' : 'elegidas'}.</p>
      <div class="impact-species-scroll">${sorted.map((s) => html`<label class="check"><input type="checkbox" data-sp="${s.taxonID}"
        ${f.species.has(s.taxonID) ? html`checked` : ''}><span>${s.vernacularName ? html`<strong>${s.vernacularName}</strong> · ` : ''}<em>${sciNameText(s)}</em></span></label>`)}</div>`);
  }

  // --- Guardar --------------------------------------------------------------------------------
  function paintChoices() {
    $$('[data-intensity]', form).forEach((b) => { const on = b.dataset.intensity === f.intensity; b.classList.toggle('active', on); b.setAttribute('aria-checked', String(on)); });
    $$('[data-timing]', form).forEach((b) => { const on = b.dataset.timing === f.timing; b.classList.toggle('active', on); b.setAttribute('aria-checked', String(on)); });
  }

  function paintSave() {
    const placed = editing || (f.mode === 'polygon' ? f.vertices.length >= 3 : !!center());
    save.disabled = !f.threatCode || !placed;
  }

  function showError(message, focusEl) {
    err.textContent = message;
    err.hidden = false;
    (focusEl || save).focus();
  }

  form.addEventListener('click', (ev) => {
    const q = ev.target.closest('[data-quick]');
    if (q) {
      const item = threats.quick.find((x) => x.key === q.dataset.quick);
      if (!item.code) { showTree = true; f.quickKey = item.key; paintThreat(); const d = $('.impact-tree details', form); if (d) d.querySelector('summary').focus(); return; }
      f.threatCode = item.code;
      f.quickKey = item.key;
      showTree = false;
      paintThreat();
      const firstChoice = $('[data-intensity].active', form);
      if (firstChoice) firstChoice.focus();
      return;
    }
    const t = ev.target.closest('[data-threat]');
    if (t) { f.threatCode = t.dataset.threat; showTree = false; paintThreat(); return; }
    if (ev.target.closest('[data-change-threat]')) { showTree = false; f.threatCode = null; paintThreat(); return; }
    const int = ev.target.closest('[data-intensity]');
    if (int) { f.intensity = int.dataset.intensity; paintChoices(); return; }
    const tim = ev.target.closest('[data-timing]');
    if (tim) { f.timing = tim.dataset.timing; paintChoices(); return; }
    const mode = ev.target.closest('[data-mode]');
    if (mode) { f.mode = mode.dataset.mode; paintWhere(); paintSave(); refreshSpecies(); return; }
    const rad = ev.target.closest('[data-radius]');
    if (rad) {
      f.radiusM = Math.max(1, Math.min(100000, (Number(form.radius.value) || f.radiusM) + Number(rad.dataset.radius)));
      form.radius.value = String(f.radiusM);
      refreshSpecies();
      return;
    }
    if (ev.target.closest('[data-vertex]')) {
      const c = center();
      if (c) { f.vertices.push([Number(c.lat.toFixed(6)), Number(c.lng.toFixed(6))]); paintWhere(); paintSave(); refreshSpecies(); }
      return;
    }
    if (ev.target.closest('[data-vertex-undo]')) { f.vertices.pop(); paintWhere(); paintSave(); refreshSpecies(); return; }
    if (ev.target.closest('[data-cancel]')) ov.close();
  });
  form.addEventListener('change', (ev) => {
    const sp = ev.target.closest('[data-sp]');
    if (sp) {
      f.speciesTouched = true;
      if (sp.checked) f.species.add(sp.dataset.sp); else f.species.delete(sp.dataset.sp);
      paintSpecies();
      const again = $(`[data-sp="${CSS.escape(sp.dataset.sp)}"]`, form);
      if (again) again.focus();
      return;
    }
    if (ev.target.name === 'radius') { f.radiusM = Math.max(1, Math.min(100000, Number(ev.target.value) || IMPACT_RADIUS_DEFAULT)); refreshSpecies(); }
  });

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    err.hidden = true;
    const fields = { threatCode: f.threatCode, quickKey: f.quickKey && threats.quick.find((q) => q.key === f.quickKey && q.code === f.threatCode) ? f.quickKey : null,
      intensity: f.intensity, timing: f.timing, scopePct: form.scope.value, note: form.note.value, species: [...f.species] };
    if (!editing && form.when.value) {
      const d = new Date(form.when.value);
      if (Number.isNaN(d.getTime()) || d.getTime() > Date.now() + 86400000) { showError('La fecha no vale (como mucho, mañana).', form.when); return; }
      fields.eventDate = isoNow(d);
    }
    if (!editing && !f.species.size) {
      const ok = await confirmDialog({ key: 'impacto-sin-especies', title: 'Impacto sin especies', confirmLabel: 'Guardar igualmente',
        text: html`<p>No has marcado ninguna especie afectada: el impacto no contará en la evaluación de ninguna.</p>` });
      if (!ok || !ov.isOpen()) return;
    }
    save.disabled = true;
    try {
      const saved = editing ? await updateImpact(i0.impactID, fields)
        : await createImpact({ catalogId: catalog.id, ...(f.mode === 'polygon' ? { polygon: f.vertices } : { point: center(), radiusM: f.radiusM }), fields });
      if (!saved) throw new Error('Este impacto ya no se puede editar (¿se borró?).');
      requestSync();
      ov.close(true);
      if (onSaved) onSaved(saved);
    } catch (e) {
      showError(e.message);
      paintSave();
    }
  });

  loadThreats().then((t) => { threats = t; if (ov.isOpen()) paintThreat(); })
    .catch(() => { if (ov.isOpen()) render($('.impact-threat', form), html`<p class="guard-band warn">No se pudo cargar el esquema de amenazas.</p>`); });
  remoteOrLocal(() => apiGetJson('/catalogs/' + encodeURIComponent(catalog.id)), () => localCatalogData(catalog.id))
    .then((res) => { catalogSpecies = res.data.species || []; if (ov.isOpen()) refreshSpecies(); })
    .catch(() => { if (ov.isOpen()) paintSpecies(); });
  paintThreat();
  paintWhere();
  paintChoices();
  watchGps();
  return ov;
}
