// ================================================================================================
// species-new.js — alta de una especie que no está en EIDOS (POST /api/species).
// ------------------------------------------------------------------------------------------------
// Se abre desde «Sin resultados» del buscador (con el texto precargado) y desde el constructor.
// 201 → clasificación obtenida y SU FUENTE (género en EIDOS o GBIF) con aviso de guarda.
// 409 → «Ya existe» con enlace a su ficha: nunca se crean duplicados.
// ================================================================================================

import { apiPostJson } from '../api.js';
import { state } from '../state.js';
import { html, render, $, sciName } from '../util.js';
import { openOverlay } from '../components/overlay.js';
import { openSpeciesDetail } from './species-detail.js';

const RANKS = [['Species', 'Especie'], ['Subspecies', 'Subespecie'], ['Variety', 'Variedad'], ['Form', 'Forma']];
const SPECIES_RE = /^\p{Lu}\p{Ll}+ \p{Ll}[\p{Ll}-]+$/u;
const INFRA_RE = /^\p{Lu}\p{Ll}+ \p{Ll}[\p{Ll}-]+ (?:(?:subsp|var|f)\. )?\p{Ll}[\p{Ll}-]+$/u;

const SOURCE_TEXT = {
  eidos: 'Clasificación del género en EIDOS',
  gbif: 'Clasificación de GBIF',
};

/** «inventadus  falsus» → «Inventadus falsus»: se normaliza lo que viene del buscador. */
function tidy(name) {
  const s = String(name || '').trim().replace(/\s+/g, ' ');
  return s ? s[0].toUpperCase() + s.slice(1).toLowerCase() : '';
}

/**
 * Abre el formulario.
 *   initialName  texto buscado
 *   onAdd(sp)    si se da, «Añadir a la selección» con la especie recién creada (tarjeta)
 */
export function openSpeciesNew({ initialName = '', onAdd } = {}) {
  const ov = openOverlay({ key: 'nueva-especie', title: 'Crear especie', variant: 'dialog', className: 'species-new' });

  function paintForm(values = {}) {
    render(ov.body, html`
      <p class="hint">Solo para especies que no están en EIDOS. Se guardan como especie de usuario y se
        pueden usar en tus catálogos como cualquier otra.</p>
      <form class="form" novalidate>
        <label class="field"><span>Nombre científico <em>(sin autor)</em></span>
          <input name="scientificName" required autocomplete="off" spellcheck="false" value="${values.scientificName || tidy(initialName)}"
                 placeholder="Genus epithet"></label>
        <label class="field"><span>Autoría</span>
          <input name="scientificNameAuthorship" required autocomplete="off" maxlength="200" value="${values.scientificNameAuthorship || ''}"
                 placeholder="(Linnaeus, 1758)"></label>
        <label class="field"><span>Rango</span>
          <select name="taxonRank">${RANKS.map(([v, l]) => html`<option value="${v}" ${values.taxonRank === v ? html`selected` : ''}>${l}</option>`)}</select></label>
        <label class="field"><span>Nombre común <em>(opcional)</em></span>
          <input name="vernacularName" autocomplete="off" maxlength="255" value="${values.vernacularName || ''}"></label>
        <p class="form-error" role="alert" hidden></p>
        <div class="form-actions">
          <button type="submit" class="btn-primary">Crear especie</button>
          <button type="button" class="link-btn" data-cancel>Cancelar</button>
        </div>
      </form>`);
    const form = $('form', ov.body);
    const err = $('.form-error', form);
    const fail = (text, field) => { err.textContent = text; err.hidden = false; if (field) form[field].focus(); };
    $('[data-cancel]', form).addEventListener('click', () => ov.close());

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const body = {
        scientificName: form.scientificName.value.trim().replace(/\s+/g, ' '),
        scientificNameAuthorship: form.scientificNameAuthorship.value.trim(),
        taxonRank: form.taxonRank.value,
        vernacularName: form.vernacularName.value.trim() || null,
      };
      err.hidden = true;
      const re = body.taxonRank === 'Species' ? SPECIES_RE : INFRA_RE;
      if (!re.test(body.scientificName)) {
        return fail(body.taxonRank === 'Species'
          ? 'Escribe el binomio sin autor: género con mayúscula y epíteto en minúscula («Genus epithet»).'
          : 'Escribe el trinomio sin autor: «Genus epithet subsp. infra» (o var. / f.).', 'scientificName');
      }
      if (!body.scientificNameAuthorship) return fail('Indica la autoría («Autor, año»).', 'scientificNameAuthorship');
      if (!state.online) return fail('Sin conexión: crear una especie necesita red.');
      const button = $('button[type="submit"]', form);
      button.disabled = true;
      button.textContent = 'Consultando EIDOS y GBIF…';
      try {
        const { species } = await apiPostJson('/species', body, { timeoutMs: 30000 });
        if (ov.isOpen()) paintCreated(species);
      } catch (e) {
        if (!ov.isOpen()) return;
        if (e.status === 409 && e.data && e.data.taxonID) paintExisting(body, e.data);
        else { fail(e.message); button.disabled = false; button.textContent = 'Crear especie'; }
      }
    });
    form.scientificName.focus();
  }

  function paintCreated(sp) {
    const cls = sp.classification || {};
    const ranks = [cls.kingdom, cls.phylum, cls.class, cls.order, cls.family].filter(Boolean);
    ov.setTitle('Especie creada');
    render(ov.body, html`
      <p class="detail-name">${sciName(sp)}</p>
      ${sp.vernacularName ? html`<p class="detail-common">${sp.vernacularName}</p>` : ''}
      <p class="guard-band warn">Esta especie no está en EIDOS; revisa la clasificación antes de usarla.</p>
      <h4>Clasificación</h4>
      ${ranks.length ? html`<ol class="crumbs">${ranks.map((r) => html`<li>${r}</li>`)}</ol>`
        : html`<p class="muted">Sin clasificación: ni EIDOS ni GBIF conocen el género.</p>`}
      <p class="badge ${cls.source ? 'ok' : 'warn'}">Fuente: ${SOURCE_TEXT[cls.source] || 'ninguna'}</p>
      <div class="form-actions dialog-actions">
        ${onAdd ? html`<button type="button" class="btn-primary" data-add>Añadir a la selección</button>` : ''}
        <button type="button" class="btn-secondary" data-open>Ver ficha</button>
        <button type="button" class="link-btn" data-close>Cerrar</button>
      </div>`);
    const add = $('[data-add]', ov.body);
    if (add) {
      add.addEventListener('click', async () => {
        add.disabled = true;
        try { await onAdd(sp); ov.close(); } catch (e) { add.disabled = false; }
      });
      add.focus();
    }
    $('[data-open]', ov.body).addEventListener('click', () => { ov.close(); openSpeciesDetail(sp.taxonID); });
    $('[data-close]', ov.body).addEventListener('click', () => ov.close());
    if (!add) $('[data-open]', ov.body).focus();
  }

  function paintExisting(values, data) {
    ov.setTitle('Ya existe');
    render(ov.body, html`
      <p class="guard-band info">Ya existe: ${sciName({ canonicalName: values.scientificName })}</p>
      <p>${data.error} No se crean duplicados: usa la existente.</p>
      <div class="form-actions dialog-actions">
        <button type="button" class="btn-primary" data-open>Abrir su ficha</button>
        <button type="button" class="link-btn" data-back>Corregir el nombre</button>
      </div>`);
    $('[data-open]', ov.body).addEventListener('click', () => { ov.close(); openSpeciesDetail(data.taxonID); });
    $('[data-back]', ov.body).addEventListener('click', () => { ov.setTitle('Crear especie'); paintForm(values); });
    $('[data-open]', ov.body).focus();
  }

  paintForm();
  return ov;
}
