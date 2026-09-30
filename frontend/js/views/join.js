// ================================================================================================
// join.js — unirse a un catálogo con una invitación (F6): ruta #/unirse/<código> y «Tengo un código».
// ------------------------------------------------------------------------------------------------
// Antes de aceptar se enseña qué es: nombre del catálogo, propietario y rol (POST /invites/preview).
// El código viaja siempre en el cuerpo, nunca en una URL de la API. Sin sesión, app.js guarda el
// código (pendingJoin), pasa por la pantalla de acceso y lo abre aquí al entrar.
// ================================================================================================

import { apiPostJson } from '../api.js';
import { state, setActiveCatalog } from '../state.js';
import { html, render, $, store } from '../util.js';
import { openOverlay } from '../components/overlay.js';
import { ROLE_LABELS } from './catalog-team.js';

const PENDING_KEY = 'biodex.pendingJoin';
const ROLE_TEXT = {
  editor: 'podrás marcar en el campo y añadir especies; las marcas ajenas de especies sensibles las verás generalizadas',
  viewer: 'podrás consultar el catálogo y sus marcas (las de especies sensibles, generalizadas); no podrás marcar',
};

/** Código de un enlace #/unirse/<código> (o null). */
export function codeFromHash(hash = location.hash) {
  const m = String(hash).match(/^#\/unirse\/([^/?#]+)/);
  return m ? decodeURIComponent(m[1]) : null;
}

/** Invitación pendiente de abrir tras entrar (sobrevive a la pantalla de acceso y a una recarga). */
export const pendingJoin = {
  get: () => store.get(PENDING_KEY),
  set: (code) => store.set(PENDING_KEY, code),
  clear: () => store.remove(PENDING_KEY),
};

function errorText(e) {
  if (e.offline || e.status === 0) return 'Necesitas conexión para unirte. El enlace sigue valiendo: ábrelo de nuevo con red.';
  if (e.status === 404) return 'Ese código no existe. Revisa que esté completo (26 letras y números).';
  return e.message;   // 403 otro email, 410 caducada/revocada/usada, 429 demasiados intentos: el servidor lo explica
}

/**
 * Diálogo de unirse con `code` (código o enlace entero). `onJoined(catalog)` tras aceptar (o si ya eras
 * miembro). El catálogo al que te unes pasa a ser el activo.
 */
export function openJoin(code, { onJoined } = {}) {
  pendingJoin.clear();
  const ov = openOverlay({ key: 'unirse', title: 'Invitación a un catálogo', variant: 'dialog', className: 'join-dialog' });
  render(ov.body, html`<p class="muted">Comprobando la invitación…</p>`);

  const done = (catalog, role) => {
    setActiveCatalog({ id: catalog.id, name: catalog.name, role });
    window.dispatchEvent(new CustomEvent('biodex:catalogs-changed'));
    if (onJoined) onJoined(catalog);
  };

  function fail(e) {
    if (!ov.isOpen()) return;
    render(ov.body, html`<p class="guard-band warn" role="alert">${errorText(e)}</p>
      <div class="form-actions dialog-actions"><button type="button" class="btn-primary" data-close>Entendido</button></div>`);
    $('[data-close]', ov.body).addEventListener('click', () => ov.close(true));
    $('[data-close]', ov.body).focus();
    if (e.offline || e.status === 0) pendingJoin.set(code);
  }

  if (!state.online) { fail({ offline: true }); return ov; }

  apiPostJson('/invites/preview', { code }).then((res) => {
    if (!ov.isOpen()) return;
    const c = res.catalog;
    if (res.alreadyMember) {
      render(ov.body, html`<p class="dialog-text">Ya eres miembro de <strong>«${c.name}»</strong>
        (<span class="badge">${ROLE_LABELS[res.role] || res.role}</span>).</p>
        <div class="form-actions dialog-actions"><button type="button" class="btn-primary" data-open>Abrir el catálogo</button></div>`);
      $('[data-open]', ov.body).addEventListener('click', () => { ov.close(true); done(c, res.role); });
      $('[data-open]', ov.body).focus();
      return;
    }
    render(ov.body, html`
      <p class="eyebrow">Te invitan a</p>
      <h3 class="join-name">${c.name}</h3>
      ${c.description ? html`<p class="join-desc">${c.description}</p>` : ''}
      <dl class="facts">
        <div><dt>Propietario</dt><dd>${c.ownerName}</dd></div>
        <div><dt>Entrarás como</dt><dd><span class="badge role-badge">${ROLE_LABELS[res.role] || res.role}</span></dd></div>
      </dl>
      <p class="hint">Como ${(ROLE_LABELS[res.role] || res.role).toLowerCase()} ${ROLE_TEXT[res.role] || ''}.</p>
      <p class="form-error" role="alert" hidden></p>
      <div class="form-actions dialog-actions">
        <button type="button" class="btn-primary" data-accept>Unirme al catálogo</button>
        <button type="button" class="link-btn" data-cancel>Ahora no</button>
      </div>`);
    const accept = $('[data-accept]', ov.body);
    $('[data-cancel]', ov.body).addEventListener('click', () => ov.close(true));
    accept.addEventListener('click', async () => {
      accept.disabled = true;
      try {
        const r = await apiPostJson('/invites/accept', { code });
        ov.close(true);
        done(r.catalog, r.role);
      } catch (e) {
        if (e.status === 0 || e.status === 403 || e.status === 410 || e.status === 429) { fail(e); return; }
        const err = $('.form-error', ov.body);
        err.textContent = errorText(e);
        err.hidden = false;
        accept.disabled = false;
      }
    });
    accept.focus();
  }).catch(fail);
  return ov;
}
