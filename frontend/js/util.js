// ================================================================================================
// util.js — plantilla html`` con escapado automático y utilidades mínimas del DOM.
// ------------------------------------------------------------------------------------------------
// REGLA: todo HTML dinámico de la app pasa por html``. Los nombres de especie, las descripciones de
// catálogo y, en F8, las notas de campo llegan de usuarios y de EIDOS: nunca se concatenan a mano en
// un innerHTML. Las partes literales de la plantilla son de confianza (las escribimos nosotros); cada
// valor interpolado se escapa, salvo que sea a su vez el resultado de otra html`` (composición).
// ================================================================================================

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' };

/** Fragmento de HTML ya seguro. Solo lo construye html``; no hay forma pública de «confiar» en texto. */
class SafeHtml {
  constructor(str) { this.str = str; }
  toString() { return this.str; }
}

export function escapeHtml(value) {
  return String(value).replace(/[&<>"'`]/g, (c) => ESCAPES[c]);
}

function interpolate(value) {
  if (value === null || value === undefined || value === false) return '';
  if (value instanceof SafeHtml) return value.str;
  if (Array.isArray(value)) return value.map(interpolate).join('');
  return escapeHtml(value);
}

/** Etiqueta de plantilla: html`<p>${nombre}</p>` escapa `nombre`; los arrays de html`` se unen. */
export function html(strings, ...values) {
  let out = strings[0];
  for (let i = 0; i < values.length; i++) out += interpolate(values[i]) + strings[i + 1];
  return new SafeHtml(out);
}

/** Pinta un fragmento html`` en un elemento. Rechaza texto suelto para que nadie se salte el escapado. */
export function render(el, fragment) {
  if (!(fragment instanceof SafeHtml)) throw new TypeError('render() solo acepta html``');
  el.innerHTML = fragment.str;
}

// F11c: modo de disposición. Estudio (≥ 900 px: escritorio, tablet apaisada) o campo (móvil).
export const studioQuery = window.matchMedia('(min-width: 900px)');
export const isStudio = () => studioQuery.matches;

export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

/** «1 especie» / «3 especies». */
export function plural(n, one, many) {
  return `${n} ${n === 1 ? one : many}`;
}

/** Lectura/escritura de localStorage que no revienta en modo privado o con el almacenamiento lleno. */
export const store = {
  get(key, fallback = null) {
    try {
      const raw = localStorage.getItem(key);
      return raw === null ? fallback : JSON.parse(raw);
    } catch (e) { return fallback; }
  },
  set(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* sin almacenamiento */ }
  },
  remove(key) {
    try { localStorage.removeItem(key); } catch (e) { /* sin almacenamiento */ }
  },
};

// --- Nombres científicos -----------------------------------------------------------------------
// Convención botánica y zoológica: en cursiva solo el nombre (género, epíteto e infraespecífico); el
// autor y las marcas de rango (subsp., var., f.) van en redonda. La API da `canonicalName` (sin autor)
// y `scientificNameAuthorship`; si faltan, se deducen de `scientificName`.
const RANK_MARKERS = new Set(['subsp.', 'ssp.', 'var.', 'subvar.', 'f.', 'fo.', 'forma', 'nothosubsp.', 'cv.']);

function nameParts(sp) {
  const full = String(sp.scientificName || '').trim();
  let canonical = String(sp.canonicalName || '').trim();
  let author = String(sp.scientificNameAuthorship || '').trim();
  if (!canonical) {
    canonical = author && full.endsWith(author) ? full.slice(0, -author.length).trim() : full;
    if (canonical === full) author = '';
  } else if (!author && full.startsWith(canonical + ' ')) {
    author = full.slice(canonical.length).trim();
  }
  return { canonical, author };
}

/** Nombre científico listo para pintar: html`` con el nombre en cursiva y el autor en redonda. */
export function sciName(sp) {
  const { canonical, author } = nameParts(sp);
  // Tramos en cursiva separados por las marcas de rango, que van en redonda.
  const runs = [];
  for (const word of canonical.split(/\s+/).filter(Boolean)) {
    const roman = RANK_MARKERS.has(word);
    const last = runs[runs.length - 1];
    if (last && last.roman === roman) last.words.push(word); else runs.push({ roman, words: [word] });
  }
  const name = runs.map((r, i) => html`${i ? ' ' : ''}${r.roman
    ? html`<span class="sci-rank">${r.words.join(' ')}</span>`
    : html`<em class="sci">${r.words.join(' ')}</em>`}`);
  return html`<span class="sci-name">${name}${author ? html` <span class="sci-author">${author}</span>` : ''}</span>`;
}

/** Nombre sin autor en texto plano (alt de imágenes, títulos de diálogo). */
export function sciNameText(sp) {
  return nameParts(sp).canonical;
}

/**
 * ORCID válido (0000-0000-0000-000X con dígito de control ISO 7064 11,2) → forma canónica, o null.
 * Admite la URL https://orcid.org/…. El servidor lo vuelve a comprobar (Lib/teams.php).
 */
export function normalizeOrcid(raw) {
  let v = String(raw || '').trim().toUpperCase().replace(/^HTTPS?:\/\/ORCID\.ORG\//, '');
  if (!/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/.test(v)) return null;
  const digits = v.replace(/-/g, '');
  let total = 0;
  for (let i = 0; i < 15; i++) total = (total + Number(digits[i])) * 2;
  const check = (12 - (total % 11)) % 11;
  return digits[15] === (check === 10 ? 'X' : String(check)) ? v : null;
}

/** Función que retrasa `fn` hasta `ms` sin llamadas; `.cancel()` la anula. */
export function debounce(fn, ms) {
  let t = null;
  const d = (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
  d.cancel = () => clearTimeout(t);
  return d;
}
