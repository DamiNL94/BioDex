// ================================================================================================
// components/species-picker.js — buscador de especies del modo campo (F11f): la hoja de marcar (elegir
// la especie) y la pestaña Especies en el móvil (abrir la ficha o marcar).
// ------------------------------------------------------------------------------------------------
// · Un solo cuadro: nombre común, científico o abreviatura 3+3 («pinnev»), sin tildes ni mayúsculas
//   (reglas y orden en js/species-search.js). Sin red funciona igual: la lista llega del catálogo de
//   IndexedDB y los recientes y «cerca» salen de las marcas guardadas en el dispositivo.
// · Chips de grupo (las siluetas de shared/species-groups.json que hay en el catálogo): un toque filtra.
// · Filas grandes (56 px de miniatura o silueta, nombre común y científico, cursiva solo en el nombre).
//   Con 8 especies o menos, rejilla de tarjetas en lugar de lista.
// · Orden: recientes de este usuario → con registros a menos de 500 m (si hay GPS) → resto alfabético,
//   con un rótulo en cada tramo.
// `keep` guarda el texto y el grupo entre montajes (la pestaña conserva su estado al volver).
// ================================================================================================

import { state } from '../state.js';
import { html, render, $, $$, sciName, sciNameText, plural } from '../util.js';
import { hasDb, byIndex } from '../db.js';
import { silhouette, silhouetteData, swapToSilhouette } from './species-card.js';
import { searchSpecies, recentFromMarks, nearFromMarks, groupsOf, GRID_MAX, NEAR_M } from '../species-search.js';

const ROW_LIMIT = 80;   // más filas no se leen en una pantalla de móvil; el buscador afina
const TIER_LABELS = { recent: 'Recientes', near: `Cerca de aquí (< ${NEAR_M} m)`, all: 'Todas' };

function media(sp, cls) {
  return sp.thumbnail
    ? html`<span class="${cls}"><img src="${sp.thumbnail}" alt="" loading="lazy" decoding="async" ${silhouetteData(sp)}></span>`
    : html`<span class="${cls}">${silhouette(sp.silhouette)}</span>`;
}

/** Nombre común arriba (si lo hay) y científico debajo; sin común, el científico es el título. */
function names(sp) {
  return sp.vernacularName
    ? html`<span class="pk-common">${sp.vernacularName}</span><span class="pk-sci">${sciName(sp)}</span>`
    : html`<span class="pk-common">${sciName(sp)}</span>`;
}

/**
 * Monta el buscador en `el`.
 *   species     tarjetas del catálogo (API o IndexedDB)
 *   catalogId   para leer las marcas del dispositivo (recientes y «cerca»)
 *   keep        {query, group}: estado que sobrevive al desmontar (opcional)
 *   position    () => {lat, lng} | null — última posición conocida (GPS)
 *   selectedId  taxonID marcado como elegido (hoja de marcar)
 *   onPick(sp)  toque en una especie
 *   onMark(sp)  si se da, cada fila lleva además «Marcar» (pestaña Especies)
 *   label       rótulo del cuadro de búsqueda
 * Devuelve {refresh(), setPosition(), focus(), destroy()}.
 */
export function mountSpeciesPicker(el, { species = [], catalogId, keep = {}, position = () => null, selectedId = null,
  onPick, onMark = null, label = 'Buscar especie' } = {}) {
  let alive = true;
  let recent = new Map();
  let near = new Map();
  let marks = [];
  keep.query = keep.query || '';
  keep.group = keep.group || '';
  const groups = groupsOf(species);
  if (keep.group && !groups.some((g) => g.id === keep.group)) keep.group = '';
  const grid = species.length <= GRID_MAX;
  const uid = `pk${Math.random().toString(36).slice(2, 8)}`;

  render(el, html`
    <div class="picker${grid ? ' is-grid' : ''}">
      <label class="field pk-search"><span class="sr-only">${label}</span>
        <input type="search" name="pkq" value="${keep.query}" autocomplete="off" autocapitalize="off" spellcheck="false"
          enterkeyhint="search" placeholder="Nombre común, científico o abreviatura (pinnev)" aria-describedby="${uid}-count"></label>
      ${groups.length > 1 ? html`<div class="pk-chips" role="group" aria-label="Filtrar por grupo">
        <button type="button" class="chip pk-chip" data-group="" aria-pressed="${keep.group ? 'false' : 'true'}">Todas</button>
        ${groups.map((g) => html`<button type="button" class="chip pk-chip" data-group="${g.id}"
          aria-pressed="${keep.group === g.id ? 'true' : 'false'}">${g.label} <span class="pk-chip-n">${g.count}</span></button>`)}
      </div>` : ''}
      <p class="search-count pk-count" id="${uid}-count" role="status" aria-live="polite"></p>
      <ul class="pk-list" role="list"></ul>
    </div>`);

  const input = $('input[name="pkq"]', el);
  const list = $('.pk-list', el);

  function paint() {
    if (!alive) return;
    const rows = searchSpecies(species, { query: keep.query, group: keep.group, recent, near });
    const shown = rows.slice(0, ROW_LIMIT);
    const filtered = keep.query.trim() || keep.group;
    $('.pk-count', el).textContent = !species.length ? ''
      : filtered ? (rows.length ? `${rows.length} de ${plural(species.length, 'especie', 'especies')}` : 'Ninguna especie coincide.')
        : plural(species.length, 'especie', 'especies');
    let lastTier = null;
    const showTiers = !keep.query.trim() && (recent.size || near.size);
    const items = [];
    for (const { sp, tier, distance } of shown) {
      if (showTiers && tier !== lastTier) {
        items.push(html`<li class="pk-tier" role="presentation"><span>${TIER_LABELS[tier]}</span></li>`);
        lastTier = tier;
      }
      const chosen = selectedId && sp.taxonID === selectedId;
      const hint = tier === 'near' && Number.isFinite(distance) ? html`<span class="pk-near">a ${Math.round(distance)} m</span>` : '';
      items.push(grid
        ? html`<li class="pk-item"><button type="button" class="pk-card${chosen ? ' is-chosen' : ''}" data-pick="${sp.taxonID}"
            ${chosen ? html`aria-current="true"` : ''}>${media(sp, 'pk-card-media')}<span class="pk-names">${names(sp)}${hint}</span></button>
            ${onMark ? html`<button type="button" class="pk-mark" data-mark="${sp.taxonID}">Marcar<span class="sr-only"> ${sciNameText(sp)}</span></button>` : ''}</li>`
        : html`<li class="pk-item"><button type="button" class="pk-row${chosen ? ' is-chosen' : ''}" data-pick="${sp.taxonID}"
            ${chosen ? html`aria-current="true"` : ''}>${media(sp, 'pk-media')}<span class="pk-names">${names(sp)}${hint}</span></button>
            ${onMark ? html`<button type="button" class="pk-mark" data-mark="${sp.taxonID}">Marcar<span class="sr-only"> ${sciNameText(sp)}</span></button>` : ''}</li>`);
    }
    if (rows.length > shown.length) {
      items.push(html`<li class="pk-more hint" role="presentation">y ${rows.length - shown.length} más: escribe para afinar.</li>`);
    }
    if (!species.length) items.push(html`<li class="pk-empty muted">Este catálogo no tiene especies en el dispositivo.</li>`);
    render(list, html`${items}`);
  }

  /** Recientes y «cerca» desde las marcas del dispositivo (sin red también). */
  async function loadMarks() {
    if (!catalogId || !hasDb()) return;
    try { marks = await byIndex('occurrences', 'catalogId', catalogId); } catch (e) { marks = []; }
    if (!alive) return;
    recent = recentFromMarks(marks, state.user && state.user.id);
    near = nearFromMarks(marks, position());
    paint();
  }

  input.addEventListener('input', () => { keep.query = input.value; paint(); });
  el.addEventListener('click', (ev) => {
    const chip = ev.target.closest('[data-group]');
    if (chip) {
      keep.group = chip.dataset.group;
      $$('.pk-chip', el).forEach((c) => c.setAttribute('aria-pressed', String(c === chip)));
      paint();
      return;
    }
    const mark = ev.target.closest('[data-mark]');
    if (mark && onMark) { const sp = species.find((s) => s.taxonID === mark.dataset.mark); if (sp) onMark(sp); return; }
    const pick = ev.target.closest('[data-pick]');
    if (pick && onPick) { const sp = species.find((s) => s.taxonID === pick.dataset.pick); if (sp) onPick(sp); }
  });
  // Miniatura que no carga (sin red y sin copia): la silueta, nunca el icono roto.
  el.addEventListener('error', (ev) => {
    if (ev.target instanceof HTMLImageElement && ev.target.dataset.silId !== undefined) swapToSilhouette(ev.target, ev.target.parentElement);
  }, true);

  paint();
  loadMarks();

  return {
    refresh: loadMarks,
    setPosition() { near = nearFromMarks(marks, position()); paint(); },
    focus() { input.focus(); },
    destroy() { alive = false; },
  };
}

/**
 * Última posición conocida sin pedir permiso: solo si el usuario ya lo concedió (no salta el aviso del
 * navegador al abrir la pestaña Especies). → Promise<{lat, lng} | null>
 */
export async function quietPosition() {
  if (!navigator.geolocation) return null;
  try {
    if (navigator.permissions && navigator.permissions.query) {
      const p = await navigator.permissions.query({ name: 'geolocation' });
      if (p.state !== 'granted') return null;
    }
  } catch (e) { return null; }
  return new Promise((resolve) => {
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }),
      () => resolve(null),
      { enableHighAccuracy: false, maximumAge: 120000, timeout: 8000 });
  });
}
