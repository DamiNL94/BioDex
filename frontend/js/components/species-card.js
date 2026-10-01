// ================================================================================================
// species-card.js — tarjeta de especie (buscador, constructor): foto, nombre, UICN de todos los
// ámbitos, descripción recortada y acción Añadir / Añadida ✓ / Quitar. Pulsar la tarjeta abre la ficha.
// ------------------------------------------------------------------------------------------------
// La tarjeta es un <article>; lo pulsable dentro son botones y enlaces de verdad (teclado y lector de
// pantalla): el nombre es el botón que abre la ficha, y el clic en el resto de la tarjeta hace lo mismo.
// Los eventos van delegados en el contenedor (bindSpeciesCards), así una lista repintada no pierde nada.
// F11b/F11d: miniatura = Fototeca > foto de respaldo aceptada (iNaturalist, GBIF o Commons) > silueta del
// grupo. La API decide (thumbnail, photoCredit y silhouette); aquí solo se pinta. Nunca una imagen rota: si
// no carga, silueta.
// ================================================================================================

import { html, render, sciName, sciNameText } from '../util.js';

export const IUCN_CODES = ['EX', 'EW', 'RE', 'CR', 'EN', 'VU', 'NT', 'LC', 'DD', 'NA', 'NE'];

export const iucnClass = (code) => (IUCN_CODES.includes(code) ? code : 'none');

/** Distintivo «CR · ESPAÑA 2007» (las versalitas las pone el CSS). */
export function iucnBadge(a, { primary = false } = {}) {
  if (!a || !a.code) return html`<span class="badge iucn iucn-none">Sin evaluar</span>`;
  return html`<span class="badge iucn iucn-${iucnClass(a.code)} ${primary ? 'is-primary' : ''}"
    >${a.code}${a.scope ? html` · ${a.scope}` : ''}${a.year ? html` ${a.year}` : ''}</span>`;
}

const SILHOUETTES = '/img/silhouettes.svg';
const GENERIC_SILHOUETTE = { id: 'generic', label: 'organismo' };

/** Silueta del grupo (F11b) a partir de `silhouette {id, label}` de la API. */
export function silhouette(s, extraClass = '') {
  const sil = s && s.id ? s : GENERIC_SILHOUETTE;
  return html`<span class="sil-box ${extraClass}"><svg class="silhouette" role="img" aria-label="Sin foto · silueta de ${sil.label}"
    viewBox="0 0 48 48" focusable="false"><use href="${SILHOUETTES}#sil-${sil.id}"></use></svg></span>`;
}

/** Atributos para que una <img> que no carga se cambie por su silueta (swapToSilhouette). */
export function silhouetteData(sp) {
  const sil = sp.silhouette || GENERIC_SILHOUETTE;
  return html`data-sil-id="${sil.id}" data-sil-label="${sil.label}"`;
}

/** Cambia una <img> rota por la silueta guardada en sus data-sil-*; `box` es el contenedor que se repinta. */
export function swapToSilhouette(img, box, extraClass = '') {
  render(box, silhouette({ id: img.dataset.silId, label: img.dataset.silLabel }, extraClass));
}

/** Nombre de la fuente de una foto de respaldo (F11d): largo para la ficha, corto para la tarjeta. */
export const PHOTO_SOURCES = {
  inaturalist: { name: 'iNaturalist', short: 'iNaturalist' },
  gbif: { name: 'GBIF', short: 'GBIF' },
  commons: { name: 'Wikimedia Commons', short: 'Commons' },
};

/** Crédito corto bajo la miniatura de la tarjeta: «GBIF · CC BY 4.0». */
function shortCredit(sp) {
  const c = sp.photoCredit;
  const src = c && PHOTO_SOURCES[c.source];
  if (src) return html`${src.short}${c.license ? html` · ${c.license}` : ''}`;
  return 'Fototeca MITECO · vía EIDOS';
}

/** Todas las evaluaciones UICN, la principal primero (la API ya las ordena). */
export function iucnBadges(sp) {
  const list = sp.iucnAssessments && sp.iucnAssessments.length ? sp.iucnAssessments
    : sp.iucnPrimary ? [sp.iucnPrimary] : [];
  if (!list.length) return iucnBadge(null);
  const primaryScope = sp.iucnPrimary && sp.iucnPrimary.scope;
  return list.map((a) => iucnBadge(a, { primary: a.scope === primaryScope }));
}

/**
 * html`` de una tarjeta.
 *   action  'add' | 'added' | 'busy' | null    estado del botón de la selección
 *   canRemove  muestra «Quitar» cuando está añadida
 */
export function speciesCard(sp, { action = null, canRemove = false } = {}) {
  const edge = iucnClass(sp.iucnPrimary && sp.iucnPrimary.code);
  const name = sciNameText(sp);
  return html`
    <article class="sp-card iucn-edge-${edge}" data-taxon="${sp.taxonID}">
      <figure class="sp-card-media">
        ${sp.thumbnail
          ? html`<img src="${sp.thumbnail}" loading="lazy" decoding="async" ${silhouetteData(sp)}
                alt="${name}${sp.vernacularName ? ` (${sp.vernacularName})` : ''}">
              <figcaption>${shortCredit(sp)}</figcaption>`
          : silhouette(sp.silhouette)}
      </figure>
      <div class="sp-card-body">
        <h3 class="sp-card-title"><button type="button" class="sp-card-open" data-open>${sciName(sp)}</button></h3>
        ${sp.vernacularName ? html`<p class="sp-card-common">${sp.vernacularName}</p>` : ''}
        <p class="sp-card-meta">
          <span class="badge">${sp.taxonomicGroup || 'Sin grupo'}</span>
          ${sp.source === 'user' ? html`<span class="badge warn">Especie de usuario</span>` : ''}
        </p>
        <p class="sp-card-iucn">${iucnBadges(sp)}</p>
        ${sp.descriptionExcerpt ? html`<p class="sp-card-desc">${sp.descriptionExcerpt}</p>` : ''}
        <div class="sp-card-actions">
          ${sp.eidosUrl ? html`<a class="link-btn" href="${sp.eidosUrl}" target="_blank" rel="noopener noreferrer"
              >Más contenido<span class="sr-only"> sobre ${name} en EIDOS (se abre en otra pestaña)</span></a>` : ''}
          ${cardAction(sp, action, canRemove, name)}
        </div>
      </div>
    </article>`;
}

function cardAction(sp, action, canRemove, name) {
  if (action === 'add') {
    return html`<button type="button" class="btn-secondary sp-add" data-add>Añadir<span class="sr-only"> ${name}</span></button>`;
  }
  if (action === 'busy') return html`<button type="button" class="btn-secondary sp-add" disabled>Añadiendo…</button>`;
  if (action === 'added') {
    return html`<span class="sp-added-wrap"><span class="sp-added" role="status">Añadida ✓</span>
      ${canRemove ? html`<button type="button" class="link-btn" data-remove>Quitar<span class="sr-only"> ${name}</span></button>` : ''}</span>`;
  }
  return '';
}

/**
 * Delegación de eventos sobre un contenedor de tarjetas.
 *   onOpen(taxonID) · onAdd(taxonID) · onRemove(taxonID)
 * Flechas arriba/abajo saltan entre los nombres de las tarjetas (lista navegable con teclado).
 */
export function bindSpeciesCards(container, { onOpen, onAdd, onRemove }) {
  container.addEventListener('click', (ev) => {
    const card = ev.target.closest('.sp-card');
    if (!card || !container.contains(card)) return;
    const id = card.dataset.taxon;
    if (ev.target.closest('[data-add]')) { if (onAdd) onAdd(id); return; }
    if (ev.target.closest('[data-remove]')) { if (onRemove) onRemove(id); return; }
    if (ev.target.closest('a')) return;               // «Más contenido»: el enlace hace lo suyo
    if (ev.target.closest('button')) {
      if (!ev.target.closest('[data-open]')) return;
    }
    if (window.getSelection && String(window.getSelection()).length) return;   // seleccionando texto
    if (onOpen) onOpen(id);
  });
  container.addEventListener('keydown', (ev) => {
    if (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp') return;
    const opens = Array.from(container.querySelectorAll('[data-open]'));
    const at = opens.indexOf(document.activeElement.closest && document.activeElement.closest('.sp-card')
      ? document.activeElement.closest('.sp-card').querySelector('[data-open]') : null);
    if (at === -1) return;
    const next = opens[at + (ev.key === 'ArrowDown' ? 1 : -1)];
    if (next) { ev.preventDefault(); next.focus(); }
  });
  // Foto que no carga (MITECO o iNat caídos, sin red y sin copia): la silueta del grupo en vez del icono
  // de imagen rota. En captura: el evento `error` de <img> no burbujea.
  container.addEventListener('error', (ev) => {
    const img = ev.target;
    if (!(img instanceof HTMLImageElement) || !img.closest('.sp-card-media')) return;
    swapToSilhouette(img, img.closest('.sp-card-media'));
  }, true);
}

/** Esqueleto de carga: `n` tarjetas grises. */
export function cardSkeletons(n = 3) {
  return html`${Array.from({ length: n }, () => html`
    <div class="sp-card is-skeleton" aria-hidden="true">
      <div class="sp-card-media"><div class="sp-card-ph"></div></div>
      <div class="sp-card-body"><span class="sk sk-title"></span><span class="sk"></span><span class="sk sk-short"></span></div>
    </div>`)}`;
}
