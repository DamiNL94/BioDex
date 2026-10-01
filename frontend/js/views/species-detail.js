// ================================================================================================
// species-detail.js — ficha de especie (GET /api/species/{taxonID}): panel lateral en escritorio y
// hoja a pantalla completa en el móvil, registrada en BioDexNav para el «atrás».
// ------------------------------------------------------------------------------------------------
// Galería con atribución, descripción, clasificación, evaluaciones UICN, estado legal (nacional y
// Andalucía) y el NIVEL DE SENSIBILIDAD dentro del catálogo activo: todos lo ven; solo el owner lo
// cambia. Bajar respecto al sugerido exige motivo: lo valida el servidor (422) y aquí se muestra.
// Sin servidor, la ficha y el nivel salen del catálogo descargado (IndexedDB) y las fotos, de Cache
// Storage (las sirve el SW). Debajo del nivel, las marcas de la especie en el catálogo activo (F5) con
// su resumen, y las notas de cuaderno de la especie (F8a). Tras las evaluaciones UICN oficiales de EIDOS,
// aparte y rotulada «Evaluación BioDex», la del asistente del equipo (F8b; no las sustituye). Tras las
// marcas, «Distribución»: EOO y AOO de la especie en el catálogo (F9).
// F11b/F11d: sin fotos de la Fototeca, la foto de respaldo aceptada de iNaturalist, GBIF o Wikimedia Commons
// (con su crédito y enlace) o la silueta del grupo. Una foto de respaldo que no carga se cambia por la
// silueta, nunca una imagen rota.
// ================================================================================================

import { apiGetJson, apiPutJson } from '../api.js';
import { state } from '../state.js';
import { html, render, $, sciName, sciNameText } from '../util.js';
import { openOverlay } from '../components/overlay.js';
import { iucnBadge, iucnClass, silhouette, silhouetteData, swapToSilhouette, PHOTO_SOURCES } from '../components/species-card.js';
import { mountMarks } from '../components/marks.js';
import { mountNotes } from '../components/species-notes.js';
import { mountAssessments } from '../components/species-assessments.js';
import { mountDistribution } from '../components/species-distribution.js';
import { remoteOrLocal, localSpecies, localCatalogData, sensitivityLevels, localNote } from '../offline.js';

// --- Estado legal en versalitas: «CEEA · VULNERABLE», «DIR. HÁBITATS · ANEXO II» -------------------
const NORMS = [
  [/139\/2011/, null],                                   // el literal ya dice CEEA: … o LESRPE: …
  [/92\/43/, 'Dir. Hábitats'],
  [/2009\/147/, 'Dir. Aves'],
  [/42\/2007/, 'Ley 42/2007'],
  [/630\/2013/, 'Cat. exóticas invasoras'],
  [/1143\/2014/, 'Reg. UE 1143/2014'],
  [/Comercio Internacional de Especies Amenazadas/i, 'CITES'],
  [/338\/97|2023\/966/, 'Reg. CE 338/97'],
  [/Especies Migratorias/i, 'Conv. Bonn'],
  [/vida silvestre y del medio natural en Europa/i, 'Conv. Berna'],
  [/2023\/2806/, 'Dec. UE 2023/2806'],
];

function legalLabel(r) {
  const text = String(r.estadolegal || '').trim();
  for (const [re, short] of NORMS) {
    if (!re.test(r.norma || '')) continue;
    if (short === null) {
      // «CEEA: En peligro de extinción» → CEEA · EN PELIGRO DE EXTINCIÓN; «LESRPE: En régimen…» → LESRPE
      const m = text.match(/^([A-ZÁÉÍÓÚ]+):\s*(.*)$/);
      if (!m) return text;
      return m[1] === 'LESRPE' ? 'LESRPE' : `${m[1]} · ${m[2]}`;
    }
    return text ? `${short} · ${text}` : short;
  }
  const norm = String(r.norma || '').split(/[,.]/)[0].slice(0, 60);
  return text ? `${norm} · ${text}` : norm;
}

function legalList(rows, prefix = '') {
  const seen = new Set();
  const out = [];
  for (const r of rows || []) {
    const label = prefix ? `${prefix} · ${r.estadolegal}` : legalLabel(r);
    if (seen.has(label)) continue;
    seen.add(label);
    out.push(html`<li><span class="legal-tag" title="${r.norma}">${label}</span>
      ${r.observacionespoblacion ? html`<span class="muted legal-obs">${r.observacionespoblacion}</span>` : ''}</li>`);
  }
  return out.length ? html`<ul class="legal-list">${out}</ul>` : html`<p class="muted">Sin normas vigentes.</p>`;
}

// --- Niveles de sensibilidad ------------------------------------------------------------------------
/** «Nivel 2 · Alta · se mostrará a ~10 km a quien no sea de confianza». */
export function levelText(levels, n) {
  const l = (levels || []).find((x) => x.level === n);
  if (!l) return `Nivel ${n}`;
  if (l.display === 'withheld') return `Nivel ${n} · ${l.name} · a quien no sea de confianza solo se le dirá «${l.displayText}»`;
  if (l.approxKm) return `Nivel ${n} · ${l.name} · se mostrará a ~${l.approxKm} km a quien no sea de confianza`;
  return `Nivel ${n} · ${l.name} · coordenada exacta para todo el equipo`;
}

/**
 * «Foto: {autor} · CC BY-SA 4.0 · GBIF», con enlace a la página de la foto en su fuente (iNaturalist, GBIF
 * o Wikimedia Commons). En iNat el autor va tal cual lo da («(c) faluke, some rights reserved…»).
 */
function backupCredit(c) {
  const src = PHOTO_SOURCES[c.source];
  return html`Foto: ${c.text}${c.license ? html` · ${c.license}` : ''} · ${c.url ? html`<a href="${c.url}" target="_blank"
    rel="noopener noreferrer">${src.name}<span class="sr-only"> (se abre en otra pestaña)</span></a>` : src.name}`;
}

/** Silueta del grupo en la cabecera de la ficha. */
function silhouetteBlock(sp) {
  return html`<figure class="detail-photo is-silhouette"><div class="detail-photo-media">${silhouette(sp.silhouette)}</div></figure>`;
}

/** Cabecera de fotos: galería de la Fototeca; si no hay, la foto de respaldo (iNat, GBIF o Commons); si no, la silueta. */
function photoBlock(sp) {
  const images = sp.images || [];
  if (images.length) {
    return html`<div class="gallery" role="list" aria-label="Fotos">${images.map((img, i) => html`
      <figure class="gallery-item" role="listitem">
        <img src="${img.url}" loading="${i ? 'lazy' : 'eager'}" decoding="async"
             alt="${sciNameText(sp)}${img.description ? ` — ${img.description}` : ''}">
        <figcaption>${img.description ? html`${img.description} · ` : ''}<span class="credit">${img.attribution || sp.photoAttribution}</span></figcaption>
      </figure>`)}</div>`;
  }
  const c = sp.photoCredit;
  if (sp.thumbnail && c && PHOTO_SOURCES[c.source]) {
    return html`<figure class="detail-photo">
      <div class="detail-photo-media"><img src="${sp.thumbnail}" decoding="async" ${silhouetteData(sp)}
        alt="${sciNameText(sp)}${sp.vernacularName ? ` (${sp.vernacularName})` : ''}"></div>
      <figcaption class="credit-inat">${backupCredit(c)}</figcaption>
    </figure>`;
  }
  return silhouetteBlock(sp);
}

function fmtDate(s) {
  if (!s) return '';
  const d = new Date(String(s).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? s : d.toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * Abre la ficha. `onChanged()` se llama si se cambia el nivel (para refrescar la lista de Especies).
 * F11c: `step(dir)` (dir = -1 | 1) devuelve el taxonID de la especie anterior/siguiente de la lista que la
 * abrió, o null. Con él, la cabecera lleva «Anterior/Siguiente» y ↑/↓ pasan de ficha sin apilar niveles.
 */
export function openSpeciesDetail(taxonID, { onChanged, step, onOpen } = {}) {
  let alive = true;
  let marks = null;
  let notes = null;
  let assessments = null;
  let distribution = null;
  let fromDevice = false;   // sin servidor: el nivel no se puede cambiar
  const ov = openOverlay({ key: 'ficha-especie', title: 'Ficha de especie', variant: 'side', className: 'species-detail',
    onClose: () => { alive = false; for (const m of [marks, notes, assessments, distribution]) if (m) m.destroy(); } });
  render(ov.body, html`<p class="muted">Cargando ficha…</p>`);

  if (onOpen) onOpen(taxonID);
  if (step) {
    const go = (dir) => {
      const next = step(dir);
      if (!next) return;
      // Se cierra esta y se abre la otra en el mismo turno: BioDexNav salda el historial a cero.
      ov.close(true);
      openSpeciesDetail(next, { onChanged, step, onOpen });
    };
    render(ov.head, html`<div class="detail-step">
      <button type="button" class="sheet-close" data-step="-1" aria-label="Especie anterior (↑)" title="Anterior (↑)" ${step(-1) ? '' : html`disabled`}>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg></button>
      <button type="button" class="sheet-close" data-step="1" aria-label="Especie siguiente (↓)" title="Siguiente (↓)" ${step(1) ? '' : html`disabled`}>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button></div>`);
    ov.head.addEventListener('click', (ev) => {
      const b = ev.target.closest('[data-step]');
      if (b) go(Number(b.dataset.step));
    });
    ov.el.addEventListener('keydown', (ev) => {
      if (ev.key !== 'ArrowUp' && ev.key !== 'ArrowDown') return;
      if (ev.target.closest('input,select,textarea,[contenteditable]')) return;
      if (document.querySelectorAll('.overlay')[document.querySelectorAll('.overlay').length - 1] !== ov.el) return;
      ev.preventDefault();
      go(ev.key === 'ArrowUp' ? -1 : 1);
    });
  }

  const active = state.activeCatalog;
  const localCard = async () => { const sp = await localSpecies(taxonID); return sp ? { species: sp } : null; };
  Promise.all([
    remoteOrLocal(() => apiGetJson('/species/' + encodeURIComponent(taxonID), { timeoutMs: state.online ? 30000 : 3000 }), localCard),
    active ? remoteOrLocal(() => apiGetJson('/catalogs/' + encodeURIComponent(active.id)), () => localCatalogData(active.id))
      .catch(() => null) : Promise.resolve(null),
    sensitivityLevels(),
  ]).then(([one, cat, levels]) => {
    if (!alive) return;
    const species = one.data.species;
    const catalogData = cat ? cat.data : null;
    const inCatalog = catalogData && catalogData.species.find((s) => s.taxonID === species.taxonID);
    paint(species, catalogData, inCatalog, levels, one.fromDevice ? (catalogData && catalogData.local) || {} : null);
  }).catch((e) => {
    if (!alive) return;
    render(ov.body, html`<p class="guard-band warn">${e.offline
      ? 'Esta ficha no está en el dispositivo. Lleva su catálogo al dispositivo con red («Llevar al campo», en Catálogo) para verla sin cobertura.'
      : e.message}</p>`);
  });

  function paint(sp, catalogData, inCatalog, levels, device) {
    fromDevice = !!device;
    ov.setTitle(sciNameText(sp));
    const cls = sp.classification || {};
    const ranks = [['Reino', cls.kingdom], ['Filo', cls.phylum], ['Clase', cls.class], ['Orden', cls.order], ['Familia', cls.family]];
    const legal = sp.legalStatus;
    render(ov.body, html`
      <article class="detail">
        ${device ? html`<p class="copy-note" role="status">${localNote(device)}</p>` : ''}
        ${photoBlock(sp)}

        <header class="detail-head iucn-edge-${iucnClass(sp.iucnPrimary && sp.iucnPrimary.code)}">
          <h3 class="detail-name">${sciName(sp)}</h3>
          ${sp.vernacularName ? html`<p class="detail-common">${sp.vernacularName}</p>` : ''}
          <p class="row-meta"><span class="badge">${sp.taxonomicGroup || 'Sin grupo'}</span>
            ${sp.taxonRank ? html`<span class="badge">${sp.taxonRank}</span>` : ''}
            ${sp.source === 'user' ? html`<span class="badge warn">Especie de usuario</span>` : ''}</p>
        </header>
        ${sp.source === 'user' ? html`<p class="guard-band warn">Esta especie no está en EIDOS; revisa la clasificación antes de usarla.</p>` : ''}

        <section class="detail-sec" aria-labelledby="d-sens"><h4 id="d-sens">Nivel de sensibilidad</h4>
          <div class="sens-box"></div></section>

        ${inCatalog ? html`<section class="detail-sec" aria-labelledby="d-marks"><h4 id="d-marks">Marcas en «${catalogData.catalog.name}»</h4>
          <div class="marks-box"></div></section>
        <section class="detail-sec dist-sec" aria-labelledby="d-dist"><h4 id="d-dist">Distribución</h4>
          <div class="dist-box"></div></section>
        <section class="detail-sec" aria-labelledby="d-notes"><h4 id="d-notes">Notas de la especie</h4>
          <div class="notes-box"></div></section>` : ''}

        <section class="detail-sec" aria-labelledby="d-cls"><h4 id="d-cls">Clasificación</h4>
          <ol class="crumbs">${ranks.map(([r, v]) => html`<li><span class="crumb-rank">${r}</span> ${v || '—'}</li>`)}</ol>
          ${cls.source === 'gbif' ? html`<p class="hint">Clasificación de GBIF.</p>`
            : sp.source === 'user' && cls.source === 'eidos' ? html`<p class="hint">Clasificación del género en EIDOS.</p>` : ''}
          ${sp.synonyms && sp.synonyms.length ? html`<p class="hint">Sinónimos: ${sp.synonyms.join(' · ')}</p>` : ''}
        </section>

        ${sp.description ? html`<section class="detail-sec" aria-labelledby="d-desc"><h4 id="d-desc">Descripción</h4>
          <p class="detail-desc">${sp.description}</p></section>` : ''}

        <section class="detail-sec" aria-labelledby="d-iucn"><h4 id="d-iucn">Evaluaciones UICN</h4>
          ${sp.iucnAssessments && sp.iucnAssessments.length ? html`
          <table class="data-table">
            <thead><tr><th scope="col">Categoría</th><th scope="col">Ámbito</th><th scope="col">Año</th><th scope="col">Criterios</th></tr></thead>
            <tbody>${sp.iucnAssessments.map((a) => html`<tr>
              <td>${iucnBadge({ code: a.code }, { primary: sp.iucnPrimary && a.scope === sp.iucnPrimary.scope })}
                ${a.label ? html`<span class="sr-only">${a.label}</span>` : ''}</td>
              <td>${a.scope}${sp.iucnPrimary && a.scope === sp.iucnPrimary.scope ? html` <span class="badge ok">Principal</span>` : ''}</td>
              <td>${a.year || '—'}</td><td>${a.criteria || '—'}</td></tr>`)}</tbody>
          </table>` : html`<p class="muted">Sin evaluaciones UICN vigentes en EIDOS.</p>`}
        </section>

        ${inCatalog ? html`<section class="detail-sec assess-sec" aria-labelledby="d-biodex"><h4 id="d-biodex">Evaluación BioDex</h4>
          <div class="assess-box"></div></section>` : ''}

        <section class="detail-sec" aria-labelledby="d-legal"><h4 id="d-legal">Estado legal</h4>
          ${legal ? html`
            <h5>Nacional</h5>${legalList(legal.national)}
            <h5>Andalucía</h5>${legalList(legal.andalucia, 'Andalucía')}
            ${legal.other && legal.other.length ? html`<details class="more"><summary>Europeo e internacional (${legal.other.length})</summary>
              ${legalList(legal.other)}</details>` : ''}`
          : html`<p class="muted">${sp.source === 'user' ? 'Especie de usuario: sin estado legal en EIDOS.' : 'Sin datos.'}</p>`}
        </section>

        ${sp.eidosUrl ? html`<p class="detail-sec"><a class="btn-secondary" href="${sp.eidosUrl}" target="_blank"
          rel="noopener noreferrer">Ver en EIDOS</a></p>` : ''}
      </article>`);
    paintSensitivity(sp, catalogData, inCatalog, levels);
    // Fotos que no están guardadas en el dispositivo: fuera, en vez del icono de imagen rota. La de
    // respaldo se cambia por la silueta (y su crédito ya no aplica). Si fallan TODAS las de la galería,
    // la silueta en su lugar (antes la cabecera se quedaba vacía).
    ov.body.addEventListener('error', (ev) => {
      if (!(ev.target instanceof HTMLImageElement)) return;
      const fig = ev.target.closest('.gallery-item');
      if (fig) {
        const gallery = fig.closest('.gallery');
        fig.remove();
        if (gallery && !gallery.querySelector('.gallery-item')) {
          const box = document.createElement('div');
          render(box, silhouetteBlock(sp));
          gallery.replaceWith(box.firstElementChild);
        }
        return;
      }
      const photo = ev.target.closest('.detail-photo');
      if (!photo) return;
      photo.classList.add('is-silhouette');
      const cap = photo.querySelector('figcaption');
      if (cap) cap.remove();
      swapToSilhouette(ev.target, photo.querySelector('.detail-photo-media'));
    }, true);
    if (inCatalog) {
      for (const m of [marks, notes, assessments, distribution]) if (m) m.destroy();
      const catalog = { ...catalogData.catalog, role: catalogData.catalog.role };
      marks = mountMarks($('.marks-box', ov.body), { species: sp, catalog, levels, sensitivity: inCatalog.sensitivity });
      notes = mountNotes($('.notes-box', ov.body), { species: sp, catalog });
      assessments = mountAssessments($('.assess-box', ov.body), { species: sp, catalog });
      distribution = mountDistribution($('.dist-box', ov.body), { species: sp, catalog });
    }
  }

  function paintSensitivity(sp, catalogData, inCatalog, levels) {
    const box = $('.sens-box', ov.body);
    const suggested = sp.suggestedSensitivity || {};
    if (!catalogData) {
      render(box, html`<p>${suggested.level ? levelText(levels, suggested.level) : '—'} <span class="badge">sugerido</span></p>
        <p class="hint">${suggested.reason || ''}</p>
        <p class="hint">El nivel se fija dentro de un catálogo. Elige un catálogo activo para verlo.</p>`);
      return;
    }
    const cat = catalogData.catalog;
    if (!inCatalog) {
      render(box, html`<p>${suggested.level ? levelText(levels, suggested.level) : '—'} <span class="badge">sugerido</span></p>
        <p class="hint">${suggested.reason || ''}</p>
        <p class="hint">No está en «${cat.name}»: al añadirla tendrá este nivel.</p>`);
      return;
    }
    const s = inCatalog.sensitivity;
    const isOwner = cat.role === 'owner' && !fromDevice;
    const levelOptions = [1, 2, 3, 4].map((n) => html`<option value="${n}" ${n === s.level ? html`selected` : ''}>${levelText(levels, n)}</option>`);
    render(box, html`
      <p class="sens-current level-${s.level}"><strong>${levelText(levels, s.level)}</strong></p>
      <p class="row-meta">
        ${s.confirmed ? html`<span class="badge ok">Confirmado por el propietario</span>` : html`<span class="badge">Sugerido por BioDex</span>`}
        ${s.suggestedLevel ? html`<span class="badge">Sugerido: ${s.suggestedLevel}</span>` : ''}</p>
      ${s.confirmed ? html`<p class="hint">Último cambio: ${s.setBy || '—'}${s.setAt ? html`, ${fmtDate(s.setAt)}` : ''}${s.reason
        ? html` · Motivo: «${s.reason}»` : ''}</p>`
        : s.reason ? html`<p class="hint">${s.reason}</p>` : ''}
      ${isOwner ? html`
        <form class="form sens-form" novalidate>
          <label class="field"><span>Cambiar nivel</span><select name="level">${levelOptions}</select></label>
          <label class="field"><span>Motivo <em>(obligatorio si lo bajas respecto al sugerido)</em></span>
            <textarea name="reason" rows="2" maxlength="255"></textarea></label>
          <p class="form-error" role="alert" hidden></p>
          <div class="form-actions"><button type="submit" class="btn-primary">Guardar nivel</button></div>
        </form>` : html`<p class="hint">${fromDevice ? 'Sin conexión: el nivel se cambia con red.'
          : 'Solo el propietario del catálogo puede cambiar el nivel.'}</p>`}`);
    if (!isOwner) return;

    const form = $('.sens-form', box);
    const err = $('.form-error', form);
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const level = parseInt(form.level.value, 10);
      const reason = form.reason.value.trim();
      const button = $('button[type="submit"]', form);
      button.disabled = true;
      err.hidden = true;
      try {
        const res = await apiPutJson(`/catalogs/${encodeURIComponent(cat.id)}/species/${encodeURIComponent(sp.taxonID)}/sensitivity`,
          { level, reason });
        if (!alive) return;
        inCatalog.sensitivity = res.sensitivity;
        paintSensitivity(sp, catalogData, inCatalog, levels);
        const again = $('.sens-current', box);
        if (again) { again.setAttribute('tabindex', '-1'); again.focus(); }
        if (onChanged) onChanged();
      } catch (e) {
        if (!alive) return;
        err.textContent = e.status === 422
          ? `Para bajar el nivel respecto al sugerido (${s.suggestedLevel}) hay que indicar el motivo.` : e.message;
        err.hidden = false;
        (e.status === 422 ? form.reason : button).focus();
      } finally {
        button.disabled = false;
      }
    });
  }

  return ov;
}
