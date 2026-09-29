// ================================================================================================
// overlay.js — paneles y diálogos propios (nunca confirm()/alert()), apilados en BioDexNav.
// ------------------------------------------------------------------------------------------------
// Tres formas, el mismo mecanismo:
//   'side'    panel lateral en escritorio, hoja a pantalla completa en el móvil (ficha de especie)
//   'full'    pantalla completa en todos los tamaños (constructor de catálogos)
//   'dialog'  diálogo centrado (confirmaciones, alta de especie)
// Cada uno es un nivel de BioDexNav: «atrás» del móvil, Escape y el botón de cerrar hacen lo mismo.
// Accesibilidad: role="dialog" + aria-modal, foco atrapado dentro mientras está abierto y devuelto al
// elemento que lo abrió al cerrar.
// Se montan dentro de #app: al cerrar sesión la app se repinta entera y se van con ella.
// ================================================================================================

import { html, render, $ } from '../util.js';

const Nav = () => window.BioDexNav;
const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),' +
  'textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
let seq = 0;
let openCount = 0;

/**
 * Abre un panel. Devuelve { body, head, close(force), setTitle, el }.
 *   key         nivel de BioDexNav (único mientras esté abierto)
 *   title       texto del encabezado (o html``)
 *   variant     'side' | 'full' | 'dialog'
 *   onClose       se llama una vez, al quitarse del DOM
 *   isDirty       () => boolean, SIN efectos: ¿hay algo que se perdería al cerrar?
 *   confirmClose  () => Promise<boolean>: con isDirty() true, pregunta; solo con true se cierra.
 *                 close(true) se salta las dos.
 */
export function openOverlay({ key, title = '', variant = 'side', className = '', onClose, isDirty, confirmClose }) {
  const id = `ov${++seq}`;
  const returnFocus = document.activeElement;
  const host = document.getElementById('app') || document.body;
  const wrap = document.createElement('div');
  wrap.className = `overlay overlay-${variant} ${className}`;
  render(wrap, html`
    <div class="overlay-backdrop" data-dismiss></div>
    <section class="sheet" role="dialog" aria-modal="true" aria-labelledby="${id}-title" tabindex="-1">
      <header class="sheet-head">
        <h2 class="sheet-title" id="${id}-title"></h2>
        <div class="sheet-tools"></div>
        <button type="button" class="sheet-close" aria-label="Cerrar" data-dismiss>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
      </header>
      <div class="sheet-body"></div>
    </section>`);
  host.appendChild(wrap);
  openCount++;
  document.body.classList.add('has-overlay');

  const sheet = $('.sheet', wrap);
  const titleEl = $('.sheet-title', wrap);
  let closed = false;
  let forced = false;
  let asking = false;

  function setTitle(t) {
    if (t && typeof t === 'object' && 'str' in t) render(titleEl, t); else titleEl.textContent = t || '';
  }
  setTitle(title);

  function teardown() {
    if (closed) return;
    closed = true;
    wrap.remove();
    openCount = Math.max(0, openCount - 1);
    if (!openCount) document.body.classList.remove('has-overlay');
    document.removeEventListener('keydown', trap, true);
    if (onClose) { try { onClose(); } catch (e) { console.error(e); } }
    if (returnFocus && returnFocus.isConnected && typeof returnFocus.focus === 'function') returnFocus.focus();
  }

  // Cierre pedido por la pila (atrás, Escape, botón). Si hay algo que perder, se vuelve a apilar el
  // nivel y se pregunta; solo con un «sí» se cierra de verdad.
  // OJO: esto se ejecuta DENTRO del bucle de desapilado de BioDexNav (unwindTo). Reapilar ahí mismo
  // hacía que el bucle volviese a sacar el nivel, y otra vez, y otra: la página se colgaba. Por eso
  // la comprobación síncrona no puede tener efectos, y reapilar y preguntar van en una microtarea,
  // cuando el desapilado ya ha terminado.
  function onNavClose() {
    if (closed) return;
    if (!forced && isDirty && isDirty()) {
      Promise.resolve().then(() => {
        if (closed) return;
        Nav().open(key, onNavClose);
        if (asking) return;
        asking = true;
        Promise.resolve(confirmClose ? confirmClose() : false).then((ok) => {
          asking = false;
          if (ok) close(true);
        });
      });
      return;
    }
    teardown();
  }

  function close(force = false) {
    if (closed) return;
    if (force) forced = true;
    if (!Nav().close(key)) onNavClose();
  }

  // Foco atrapado: Tab y Mayús+Tab dan la vuelta dentro del panel de arriba.
  function trap(ev) {
    if (ev.key !== 'Tab' || closed) return;
    const top = document.querySelectorAll('.overlay');
    if (top[top.length - 1] !== wrap) return;
    const items = Array.from(sheet.querySelectorAll(FOCUSABLE)).filter((n) => n.offsetParent !== null || n === document.activeElement);
    if (!items.length) { ev.preventDefault(); sheet.focus(); return; }
    const first = items[0];
    const last = items[items.length - 1];
    if (!sheet.contains(document.activeElement)) { ev.preventDefault(); first.focus(); }
    else if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
    else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
  }
  document.addEventListener('keydown', trap, true);
  wrap.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-dismiss]')) close();
  });

  Nav().open(key, onNavClose);
  sheet.focus();

  return {
    el: wrap,
    sheet,
    head: $('.sheet-tools', wrap),
    body: $('.sheet-body', wrap),
    close,
    setTitle,
    isOpen: () => !closed,
  };
}

/**
 * Diálogo de confirmación. Resuelve true (confirmar) o false (cancelar, atrás, Escape).
 *   requireText  si se da, el botón de confirmar solo se activa al escribir ese texto exacto
 */
export function confirmDialog({ title, text, confirmLabel = 'Confirmar', cancelLabel = 'Cancelar', danger = false,
  requireText = null, key = 'confirmar' } = {}) {
  return new Promise((resolve) => {
    let result = false;
    const ov = openOverlay({ key, title, variant: 'dialog', onClose: () => resolve(result) });
    render(ov.body, html`
      <div class="dialog-text">${text}</div>
      ${requireText ? html`<label class="field"><span>Escribe «${requireText}» para confirmar</span>
        <input name="confirmText" autocomplete="off" spellcheck="false"></label>` : ''}
      <div class="form-actions dialog-actions">
        <button type="button" class="${danger ? 'btn-secondary danger' : 'btn-primary'}" data-ok
                ${requireText ? html`disabled` : ''}>${confirmLabel}</button>
        <button type="button" class="link-btn" data-cancel>${cancelLabel}</button>
      </div>`);
    const ok = $('[data-ok]', ov.body);
    const input = $('input[name="confirmText"]', ov.body);
    if (input) {
      input.addEventListener('input', () => { ok.disabled = input.value.trim() !== requireText; });
      input.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' && !ok.disabled) ok.click(); });
    }
    ok.addEventListener('click', () => { result = true; ov.close(true); });
    $('[data-cancel]', ov.body).addEventListener('click', () => ov.close(true));
    (input || ok).focus();
  });
}
