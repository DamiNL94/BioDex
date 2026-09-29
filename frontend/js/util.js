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
