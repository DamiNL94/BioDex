// ================================================================================================
// views/mark-sheet.js — hoja de marca (F8a): crear una marca desde la ficha, la lista o el mapa, y
// editar las propias desde «Mis marcas».
// ------------------------------------------------------------------------------------------------
// Pensada para una mano, con guantes o al sol: objetivos de 48 px y «Guardar» siempre visible (pie
// fijo). Todo es opcional salvo la posición: guardar sin tocar nada más es lo que se hace con prisa.
//   · Posición: GPS (watchPosition mientras la hoja está abierta; peor de 50 m → confirmación) o un
//     PUNTO DEL MAPA (mantener pulsado en Mapa: individuo visto a distancia) con la precisión estimada
//     por el usuario, obligatoria, y comentario de georreferencia. Al editar, la posición no se toca.
//   · Presente / Ausente (una ausencia en un punto prospectado también es dato: 0 individuos).
//   · Nº de individuos (− / +), «Individuo maduro» (con varios: «¿cuántos maduros?», el total por
//     defecto) → lifeStage adult + mature_count.
//   · Estado vital según el reino (fauna y flora tienen valores distintos) y sexo (solo fauna).
//   · Hábitat (se recuerda el último de esta sesión) y observaciones.
// Vocabularios: los de GBIF que valida el servidor (Lib/sync.php); aquí solo sus etiquetas.
// ================================================================================================

import { state } from '../state.js';
import { html, render, $, $$, sciName, sciNameText } from '../util.js';
import { apiGetJson } from '../api.js';
import { openOverlay, confirmDialog } from '../components/overlay.js';
import { createMark, updateMark, markFields, MARK_ROLES, GEOREF_MAP } from '../marks.js';
import { requestSync } from '../sync.js';
import { remoteOrLocal, localCatalogData, localSpecies } from '../offline.js';
import { toUtm, formatUtm } from '../geo/utm.js';

const LOW_ACCURACY_M = 50;
const GPS_TIMEOUT_MS = 30000;
const HABITAT_KEY = 'biodex.lastHabitat';
const ROLE_LABELS = { viewer: 'Lector' };

export const LIFE_STAGE_LABELS = {
  adult: 'Adulto', subadult: 'Subadulto', juvenile: 'Juvenil', larva: 'Larva', egg: 'Huevo',
  seedling: 'Plántula', vegetative: 'Vegetativo', flowering: 'En flor', fruiting: 'En fruto', undetermined: 'Indeterminado',
};
export const SEX_LABELS = { male: 'Macho', female: 'Hembra', hermaphrodite: 'Hermafrodita', undetermined: 'Indeterminado' };
const STAGES_BY_KINGDOM = {
  Animalia: ['adult', 'subadult', 'juvenile', 'larva', 'egg', 'undetermined'],
  Plantae: ['seedling', 'vegetative', 'flowering', 'fruiting', 'adult', 'undetermined'],
  other: ['adult', 'juvenile', 'undetermined'],
};

export const canMark = (catalog) => !!catalog && MARK_ROLES.includes(catalog.role);

const kingdomOf = (sp) => (sp && ((sp.classification && sp.classification.kingdom) || sp.kingdom)) || null;

function sessionGet(key) {
  try { return sessionStorage.getItem(key) || ''; } catch (e) { return ''; }
}
function sessionSet(key, value) {
  try { if (value) sessionStorage.setItem(key, value); } catch (e) { /* sin almacenamiento */ }
}

function gpsError(err) {
  if (!err) return 'Este dispositivo no ofrece ubicación. Sin coordenadas no se guarda la marca (en Mapa puedes marcar un punto a mano).';
  if (err.code === 1) return 'Sin permiso de ubicación. Actívalo para BioDex en el navegador, o marca el punto a mano en Mapa (mantener pulsado).';
  if (err.code === 3) return 'El GPS no da posición todavía. Busca cielo abierto: la hoja sigue esperando.';
  return 'No se puede obtener la posición. Comprueba que la ubicación del dispositivo está activada.';
}

const fmtLatLon = (lat, lon) => `${Number(lat).toFixed(6)}, ${Number(lon).toFixed(6)}`;
function utmText(lat, lon) {
  try { return `UTM 30N ${formatUtm(toUtm(Number(lat), Number(lon)))}`; } catch (e) { return ''; }
}

/**
 * Abre la hoja.
 *   catalog     {id, name, role}
 *   species     ficha (o null: se elige en la hoja, p. ej. desde el mapa)
 *   occurrence  marca propia a editar (sin posición editable)
 *   point       {lat, lng}: punto marcado en el mapa (precisión estimada obligatoria)
 *   initialPos  última GeolocationPosition conocida (el mapa ya la tiene)
 *   taxonHint   taxonID preseleccionado en el selector
 *   onSaved(occ), onClose()
 */
export function openMarkSheet({ catalog, species = null, occurrence = null, point = null, initialPos = null,
  taxonHint = '', onSaved, onClose } = {}) {
  const editing = !!occurrence;
  const title = editing ? 'Editar marca' : point ? 'Marcar en este punto' : 'Marcar aquí';
  let watchId = null;
  const ov = openOverlay({ key: editing ? 'editar-marca' : 'hoja-marca', title, variant: 'side', className: 'mark-sheet',
    onClose: () => { if (watchId !== null) navigator.geolocation.clearWatch(watchId); if (onClose) onClose(); } });

  if (!editing && !canMark(catalog)) {
    render(ov.body, html`<p class="guard-band warn">${catalog
      ? `Tu rol en «${catalog.name}» (${ROLE_LABELS[catalog.role] || catalog.role}) no permite marcar.`
      : 'Elige un catálogo activo en Catálogos para marcar.'}</p>
      <div class="form-actions dialog-actions"><button type="button" class="btn-primary" data-close>Entendido</button></div>`);
    $('[data-close]', ov.body).addEventListener('click', () => ov.close());
    $('[data-close]', ov.body).focus();
    return ov;
  }

  // --- Estado de la hoja ----------------------------------------------------------------------
  const o = occurrence || {};
  let chosen = species;
  let speciesList = [];
  let pos = initialPos;
  let posError = null;
  const f = {
    status: o.occurrenceStatus === 'absent' ? 'absent' : 'present',
    count: Number.isInteger(o.individualCount) && o.individualCount > 0 ? o.individualCount : 1,
    mature: Number.isInteger(o.mature_count) && o.mature_count > 0,
    matureCount: Number.isInteger(o.mature_count) && o.mature_count > 0 ? o.mature_count : null,
    matureTouched: editing && Number.isInteger(o.mature_count),
    lifeStage: o.lifeStage || '',
    sex: o.sex || '',
  };
  const habitat0 = editing ? (o.habitat || '') : sessionGet(HABITAT_KEY);

  render(ov.body, html`
    <form class="form mark-form" novalidate>
      <div class="mark-sp"></div>
      <section class="mark-pos" aria-label="Posición"></section>

      <div class="field"><span id="msStatus">Registro</span>
        <div class="status-toggle" role="radiogroup" aria-labelledby="msStatus">
          <button type="button" role="radio" data-status="present">Presente</button>
          <button type="button" role="radio" data-status="absent">Ausente</button>
        </div>
        <p class="hint absent-hint" hidden>Ausencia: has prospectado aquí y no la has encontrado. Se guarda con 0 individuos.</p>
      </div>

      <div class="present-only">
        <div class="field"><span id="msCount">Nº de individuos</span>
          <div class="stepper" role="group" aria-labelledby="msCount">
            <button type="button" class="step-btn" data-step="count:-1" aria-label="Uno menos">−</button>
            <input type="number" name="count" inputmode="numeric" min="1" max="100000" step="1" aria-labelledby="msCount">
            <button type="button" class="step-btn" data-step="count:1" aria-label="Uno más">+</button>
          </div>
        </div>
        <label class="check big-check"><input type="checkbox" name="mature"><span>Individuo maduro</span></label>
        <div class="field mature-field" hidden><span id="msMature">¿Cuántos maduros?</span>
          <div class="stepper" role="group" aria-labelledby="msMature">
            <button type="button" class="step-btn" data-step="matureCount:-1" aria-label="Un maduro menos">−</button>
            <input type="number" name="matureCount" inputmode="numeric" min="0" step="1" aria-labelledby="msMature">
            <button type="button" class="step-btn" data-step="matureCount:1" aria-label="Un maduro más">+</button>
          </div>
        </div>
        <div class="field stage-field"><span id="msStage">Estado vital</span>
          <div class="chip-pick stage-pick" role="radiogroup" aria-labelledby="msStage"></div></div>
        <div class="field sex-field" hidden><span id="msSex">Sexo</span>
          <div class="chip-pick sex-pick" role="radiogroup" aria-labelledby="msSex">
            ${Object.entries(SEX_LABELS).map(([v, label]) => html`<button type="button" role="radio" data-sex="${v}">${label}</button>`)}
          </div></div>
      </div>

      <label class="field"><span>Hábitat <em>(opcional)</em></span>
        <input name="habitat" maxlength="500" autocomplete="off" value="${habitat0}" placeholder="Encinar, roquedo calizo, borde de arroyo…"></label>
      <label class="field"><span>Observaciones <em>(opcional)</em></span>
        <textarea name="remarks" rows="3" maxlength="4000" placeholder="Comportamiento, rastros, fenología…">${o.occurrenceRemarks || ''}</textarea></label>
      <p class="form-error" role="alert" hidden></p>
      <div class="sheet-actions">
        <button type="submit" class="btn-primary save-mark">${editing ? 'Guardar cambios' : 'Guardar'}</button>
        <button type="button" class="link-btn" data-cancel>Cancelar</button>
      </div>
    </form>`);

  const form = $('form', ov.body);
  const save = $('.save-mark', form);
  const err = $('.form-error', form);
  form.count.value = String(f.count);

  // --- Especie -----------------------------------------------------------------------------------
  function paintSpecies() {
    const box = $('.mark-sp', form);
    if (species) {
      render(box, html`<p class="mark-species">${sciName(species)}<span class="muted"> · ${catalog.name}</span></p>`);
      return;
    }
    render(box, html`<div class="field"><span>Especie</span>
      <input type="search" name="q" placeholder="Buscar en «${catalog.name}»…" autocomplete="off" aria-label="Buscar especie">
      <div class="pick-list" role="radiogroup" aria-label="Especie"><p class="muted">Cargando especies…</p></div></div>`);
    form.q.addEventListener('input', paintPickList);
    $('.pick-list', box).addEventListener('change', (ev) => {
      if (ev.target.name !== 'taxon') return;
      chosen = speciesList.find((s) => s.taxonID === ev.target.value) || null;
      paintKingdom();
      paintSave();
    });
    remoteOrLocal(() => apiGetJson('/catalogs/' + encodeURIComponent(catalog.id)), () => localCatalogData(catalog.id))
      .then((res) => {
        speciesList = [...res.data.species].sort((a, b) => sciNameText(a).localeCompare(sciNameText(b), 'es'));
        if (taxonHint) chosen = speciesList.find((s) => s.taxonID === taxonHint) || null;
        if (ov.isOpen()) { paintPickList(); paintKingdom(); paintSave(); }
      })
      .catch(() => {
        const list = $('.pick-list', box);
        if (ov.isOpen() && list) render(list, html`<p class="guard-band warn">Sin conexión y sin el catálogo descargado no hay especies que elegir.</p>`);
      });
  }

  function paintPickList() {
    const list = $('.pick-list', form);
    if (!list) return;
    const q = form.q.value.trim().toLowerCase();
    const match = speciesList.filter((s) => !q || sciNameText(s).toLowerCase().includes(q) ||
      String(s.vernacularName || '').toLowerCase().includes(q));
    const shown = match.slice(0, 60);
    if (chosen && !shown.includes(chosen) && match.includes(chosen)) shown.unshift(chosen);
    render(list, shown.length ? html`${shown.map((s) => html`<label class="check pick-row">
        <input type="radio" name="taxon" value="${s.taxonID}" ${chosen && s.taxonID === chosen.taxonID ? html`checked` : ''}>
        <span>${sciName(s)}${s.vernacularName ? html`<span class="muted"> · ${s.vernacularName}</span>` : ''}</span></label>`)}
      ${match.length > shown.length ? html`<p class="hint">y ${match.length - shown.length} más: afina la búsqueda.</p>` : ''}`
      : html`<p class="muted">${speciesList.length ? 'Ninguna especie coincide.' : 'Este catálogo no tiene especies en el dispositivo.'}</p>`);
  }

  // --- Posición --------------------------------------------------------------------------------
  function paintPosition() {
    const box = $('.mark-pos', form);
    if (editing) {
      const map = o.georeferenceProtocol === GEOREF_MAP;
      render(box, html`<p class="mark-gps"><span class="badge">${map ? 'Punto del mapa' : 'GPS'}</span>
        ${fmtLatLon(o.decimalLatitude, o.decimalLongitude)} · <strong>±${o.coordinateUncertaintyInMeters ?? '?'} m</strong></p>
        <p class="hint">La posición y la fecha de una marca no se cambian: si está mal, bórrala y márcala otra vez.</p>
        ${map ? html`<label class="field"><span>Comentario de la georreferencia <em>(opcional)</em></span>
          <input name="georefRemarks" maxlength="255" value="${o.georeferenceRemarks || ''}"></label>` : ''}`);
      return;
    }
    if (point) {
      render(box, html`<p class="mark-gps"><span class="badge warn">Punto del mapa</span> ${fmtLatLon(point.lat, point.lng)}
        <span class="muted">· ${utmText(point.lat, point.lng)}</span></p>
        <div class="field"><span id="msUnc">Precisión estimada (m) <em>(obligatoria)</em></span>
          <div class="stepper" role="group" aria-labelledby="msUnc">
            <button type="button" class="step-btn" data-step="uncertainty:-10" aria-label="10 m menos">−</button>
            <input type="number" name="uncertainty" inputmode="numeric" min="1" max="100000" step="1" required aria-labelledby="msUnc"
              placeholder="p. ej. 150">
            <button type="button" class="step-btn" data-step="uncertainty:10" aria-label="10 m más">+</button>
          </div>
          <p class="hint">Cuánto puede distar el individuo del punto que has marcado.</p></div>
        <label class="field"><span>Comentario de la georreferencia <em>(opcional)</em></span>
          <input name="georefRemarks" maxlength="255" placeholder="Visto con prismáticos desde el mirador, a unos 300 m…"></label>`);
      return;
    }
    let body;
    if (!pos) {
      body = html`<span class="${posError ? 'guard-band warn' : 'gps-wait'}">${posError || 'Buscando tu posición…'}</span>`;
    } else {
      const acc = Math.round(pos.coords.accuracy);
      body = html`<span class="badge ${acc > LOW_ACCURACY_M ? 'warn' : 'ok'}">Posición</span>
        ${fmtLatLon(pos.coords.latitude, pos.coords.longitude)} · <strong>±${acc} m</strong>
        ${acc > LOW_ACCURACY_M ? html`<span class="hint"> · precisión baja: se pedirá confirmación</span>` : ''}`;
    }
    render(box, html`<p class="mark-gps" role="status" aria-live="polite">${body}</p>`);
  }

  function watchGps() {
    if (editing || point) return;
    if (!navigator.geolocation) { posError = gpsError(null); paintPosition(); return; }
    watchId = navigator.geolocation.watchPosition((p) => {
      if (!ov.isOpen()) return;
      pos = p;
      posError = null;
      paintPosition();
      paintSave();
    }, (e) => {
      if (!ov.isOpen()) return;
      posError = gpsError(e);
      paintPosition();
      paintSave();
    }, { enableHighAccuracy: true, timeout: GPS_TIMEOUT_MS, maximumAge: 5000 });
  }

  // --- Campos ----------------------------------------------------------------------------------
  // Las tarjetas de la lista y del selector no traen la clasificación: el reino sale de la ficha
  // guardada en el dispositivo (paquete offline) y, si no está, de la API.
  const kingdoms = new Map();
  function resolveKingdom(sp) {
    if (!sp || kingdomOf(sp) || kingdoms.has(sp.taxonID)) return;
    kingdoms.set(sp.taxonID, null);
    localSpecies(sp.taxonID).catch(() => null)
      .then((full) => full || (state.online ? apiGetJson('/species/' + encodeURIComponent(sp.taxonID)).then((r) => r.species) : null))
      .catch(() => null)
      .then((full) => {
        kingdoms.set(sp.taxonID, kingdomOf(full));
        if (ov.isOpen() && (chosen || species) === sp) paintKingdom();
      });
  }

  function paintKingdom() {
    const sp = chosen || (editing ? species : null);
    resolveKingdom(sp);
    const kingdom = kingdomOf(sp) || (sp && kingdoms.get(sp.taxonID)) || null;
    const stages = [...(STAGES_BY_KINGDOM[kingdom] || STAGES_BY_KINGDOM.other)];
    // Un valor que no es de este reino: al editar se respeta (ya estaba guardado); al cambiar de especie, fuera.
    if (f.lifeStage && !stages.includes(f.lifeStage)) { if (editing) stages.push(f.lifeStage); else f.lifeStage = ''; }
    render($('.stage-pick', form), html`${stages.map((v) => html`<button type="button" role="radio" data-stage="${v}"
      aria-checked="${f.lifeStage === v}" class="${f.lifeStage === v ? 'active' : ''}">${LIFE_STAGE_LABELS[v] || v}</button>`)}`);
    const fauna = kingdom === 'Animalia';
    $('.sex-field', form).hidden = !fauna;
    if (!fauna) f.sex = '';
    paintChoices();
  }

  function paintChoices() {
    $$('[data-status]', form).forEach((b) => {
      const on = b.dataset.status === f.status;
      b.classList.toggle('active', on);
      b.setAttribute('aria-checked', String(on));
    });
    const absent = f.status === 'absent';
    $('.present-only', form).hidden = absent;
    $('.absent-hint', form).hidden = !absent;
    form.mature.checked = f.mature;
    const many = f.mature && f.count > 1;
    $('.mature-field', form).hidden = !many;
    if (many && document.activeElement !== form.matureCount) form.matureCount.value = String(f.matureCount ?? f.count);
    form.matureCount.max = String(f.count);
    $$('[data-stage]', form).forEach((b) => {
      const on = b.dataset.stage === f.lifeStage;
      b.classList.toggle('active', on);
      b.setAttribute('aria-checked', String(on));
    });
    $$('[data-sex]', form).forEach((b) => {
      const on = b.dataset.sex === f.sex;
      b.classList.toggle('active', on);
      b.setAttribute('aria-checked', String(on));
    });
  }

  function paintSave() {
    const hasWhere = editing || !!point || !!pos;
    save.disabled = !hasWhere || !(species || chosen);
  }

  const toInt = (v) => { const n = parseInt(v, 10); return Number.isFinite(n) ? n : null; };
  function setCount(n) {
    f.count = Math.max(1, Math.min(100000, n || 1));
    form.count.value = String(f.count);
    if (f.mature && (!f.matureTouched || f.matureCount > f.count)) f.matureCount = f.count;
    paintChoices();
  }
  function setMatureCount(n) {
    f.matureCount = Math.max(0, Math.min(f.count, n ?? f.count));
    f.matureTouched = true;
    form.matureCount.value = String(f.matureCount);
  }

  form.addEventListener('click', (ev) => {
    const s = ev.target.closest('[data-status]');
    if (s) { f.status = s.dataset.status; paintChoices(); return; }
    const st = ev.target.closest('[data-stage]');
    if (st) { f.lifeStage = f.lifeStage === st.dataset.stage ? '' : st.dataset.stage; paintChoices(); return; }
    const sx = ev.target.closest('[data-sex]');
    if (sx) { f.sex = f.sex === sx.dataset.sex ? '' : sx.dataset.sex; paintChoices(); return; }
    const step = ev.target.closest('[data-step]');
    if (step) {
      const [field, delta] = step.dataset.step.split(':');
      const d = Number(delta);
      if (field === 'count') setCount(f.count + d);
      else if (field === 'matureCount') { setMatureCount((f.matureCount ?? f.count) + d); paintChoices(); }
      else if (field === 'uncertainty') {
        const cur = toInt(form.uncertainty.value) || 0;
        form.uncertainty.value = String(Math.max(1, cur + d));
      }
      return;
    }
    if (ev.target.closest('[data-cancel]')) ov.close();
  });
  form.count.addEventListener('change', () => setCount(toInt(form.count.value)));
  form.matureCount.addEventListener('change', () => { setMatureCount(toInt(form.matureCount.value)); paintChoices(); });
  form.mature.addEventListener('change', () => {
    f.mature = form.mature.checked;
    if (f.mature) {
      if (!f.matureTouched) f.matureCount = f.count;
      // «Individuo maduro» → lifeStage adult, si el usuario no ha elegido otro estado.
      if (!f.lifeStage) f.lifeStage = 'adult';
    }
    paintChoices();
  });

  // --- Guardar -----------------------------------------------------------------------------------
  function collect() {
    const present = f.status === 'present';
    const fields = {
      occurrenceStatus: f.status,
      individualCount: present ? f.count : 0,
      mature_count: present && f.mature ? (f.count > 1 ? (f.matureCount ?? f.count) : 1) : null,
      lifeStage: f.lifeStage || null,
      sex: f.sex || null,
      habitat: form.habitat.value,
      occurrenceRemarks: form.remarks.value,
      georeferenceRemarks: form.georefRemarks ? form.georefRemarks.value : null,
    };
    return markFields(fields);
  }

  function showError(message, focusEl) {
    err.textContent = message;
    err.hidden = false;
    (focusEl || save).focus();
  }

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    err.hidden = true;
    const sp = species || chosen;
    if (!sp) { showError('Elige la especie.', form.q); return; }
    const fields = collect();
    let target = null;
    if (!editing && point) {
      const unc = toInt(form.uncertainty.value);
      if (!unc || unc < 1) { showError('Indica la precisión estimada del punto, en metros (p. ej. 150).', form.uncertainty); return; }
      target = { point: { lat: point.lat, lng: point.lng, uncertainty: unc } };
    } else if (!editing) {
      if (!pos) { showError('Todavía no hay posición del GPS.', save); return; }
      const usePos = pos;   // la del momento de pulsar, aunque sigan llegando
      const acc = Math.round(usePos.coords.accuracy);
      if (acc > LOW_ACCURACY_M) {
        const ok = await confirmDialog({ key: 'precision-baja', title: 'Precisión baja', confirmLabel: 'Guardar igualmente',
          text: html`<p>El GPS da ahora <strong>±${acc} m</strong>, peor que ${LOW_ACCURACY_M} m. La marca quedará con esa
            incertidumbre. Si puedes, espera unos segundos a cielo abierto.</p>` });
        if (!ok || !ov.isOpen()) return;
      }
      target = { pos: usePos };
    }
    save.disabled = true;
    try {
      const occ = editing
        ? await updateMark(o.occurrenceID, fields)
        : await createMark({ catalogId: catalog.id, taxonID: sp.taxonID, ...target, fields });
      if (!occ) throw new Error('Esta marca ya no se puede editar (¿se borró?).');
      sessionSet(HABITAT_KEY, (fields.habitat || '').trim());
      requestSync();
      ov.close(true);
      if (onSaved) onSaved(occ);
    } catch (e) {
      showError(e.message, save);
      paintSave();
    }
  });

  paintSpecies();
  paintPosition();
  paintKingdom();
  paintSave();
  watchGps();
  if (point && form.uncertainty) form.uncertainty.focus();
  else if (!species && form.q) form.q.focus();
  else save.focus();
  return ov;
}
