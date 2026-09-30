// ================================================================================================
// auth.js — pantallas de acceso y lógica de sesión.
// ------------------------------------------------------------------------------------------------
// La sesión vive en cookies HttpOnly (el JS no las ve). Lo que sí guarda el cliente es el último
// `user` en localStorage (biodex.user): con él la app arranca sin red, que es lo normal tras varios
// días en el campo. Reglas de arranque (bootSession, F3b):
//   · con usuario guardado → se pinta la app YA, sin esperar a la red; GET /auth/me va en segundo
//     plano (corte de 3 s): 200 actualiza el usuario; 401 → acceso con aviso de sesión caducada (vía
//     biodex:unauthorized); caído o timeout no cambian nada (la cabecera dirá «Servidor no disponible»).
//   · sin usuario guardado → hace falta red: GET /auth/me decide entre la app y la pantalla de acceso.
// Cerrar sesión (F5): con marcas sin sincronizar se BLOQUEA con un diálogo (sincronizar, exportar
// respaldo o cancelar): nunca se borran sin que el usuario lo decida. Al cerrar, se borra la base
// `biodex-<userId>` y las imágenes de sus catálogos (dispositivos compartidos).
// ================================================================================================

import { apiGetJson, apiPostJson, apiPostQuiet } from './api.js';
import { state, setUser, setNotice, setActiveCatalog } from './state.js';
import { html, render, $, plural } from './util.js';
import { hasDb, getAll, deleteUserDb, closeDb } from './db.js';
import { queueCounts, downloadBackup } from './marks.js';
import { syncNow, stopSync, syncIdle } from './sync.js';
import { IMG_CACHE_PREFIX } from './offline.js';
import { openOverlay } from './components/overlay.js';

export const SESSION_EXPIRED = 'Tu sesión ha caducado. Lo que tengas en este dispositivo se conserva.';
const ME_TIMEOUT_MS = 3000;

/** Decide si se entra en la app ('app') o en la pantalla de acceso ('auth'). */
export async function bootSession() {
  if (state.user) {
    validateSessionInBackground();
    return 'app';
  }
  if (!navigator.onLine) {
    setNotice({ kind: 'warn', text: 'Sin conexión. La primera vez necesitas red para entrar.' });
    return 'auth';
  }
  try {
    const { user } = await apiGetJson('/auth/me', { timeoutMs: ME_TIMEOUT_MS });
    setUser(user);
    return 'app';
  } catch (e) {
    if (e.status !== 401) setNotice({ kind: 'warn', text: e.message });
    return 'auth';
  }
}

// Se conserva biodex.user aunque llegue un 401: si el aviso llega en el campo y se pierde la red, se
// puede seguir trabajando en local. Solo cerrar sesión a propósito lo borra.
function validateSessionInBackground() {
  if (!navigator.onLine) return;
  apiGetJson('/auth/me', { timeoutMs: ME_TIMEOUT_MS })
    .then(({ user }) => setUser(user))
    .catch((e) => {
      if (e.status === 401) window.dispatchEvent(new CustomEvent('biodex:unauthorized'));
    });
}

/** Aviso para la pantalla de acceso tras un 401: si hay cola, se dice cuánto espera a sincronizarse. */
export function sessionExpiredText() {
  const n = state.sync.unsynced;
  return n ? `Inicia sesión para sincronizar (${plural(n, 'pendiente', 'pendientes')}). Lo que tienes en este dispositivo se conserva.`
    : SESSION_EXPIRED;
}

const unsyncedNow = async () => (hasDb() ? (await queueCounts()).unsynced : 0);

/**
 * ¿Se puede cerrar sesión? Sin marcas pendientes, sí. Con pendientes, diálogo «Tienes n marcas sin
 * sincronizar» → Sincronizar ahora · Exportar respaldo · Cancelar. Resuelve true solo si el usuario
 * lo decide (todo sincronizado, o respaldo exportado y confirmado).
 */
export async function confirmLogout() {
  if (!(await unsyncedNow())) return true;
  return new Promise((resolve) => {
    let result = false;
    let exported = 0;
    let message = null;
    const ov = openOverlay({ key: 'cerrar-sesion', title: 'Marcas sin sincronizar', variant: 'dialog',
      onClose: () => resolve(result) });

    async function paint() {
      const n = await unsyncedNow();
      if (!ov.isOpen()) return;
      if (!n) { result = true; ov.close(true); return; }
      render(ov.body, html`
        <p class="dialog-text"><strong>Tienes ${plural(n, 'marca', 'marcas')} sin sincronizar.</strong>
          Si cierras sesión se borran de este dispositivo. Sincronízalas o guarda un respaldo antes.</p>
        ${message ? html`<p class="guard-band ${message.kind}" role="status">${message.text}</p>` : ''}
        ${state.online ? '' : html`<p class="hint">Sin conexión: para sincronizar necesitas red. El respaldo se guarda sin ella.</p>`}
        <div class="form-actions dialog-actions">
          <button type="button" class="btn-primary" data-sync ${state.sync.running || !state.online ? html`disabled` : ''}>Sincronizar ahora</button>
          <button type="button" class="btn-secondary" data-export>Exportar respaldo</button>
          <button type="button" class="link-btn" data-cancel>Cancelar</button>
        </div>
        ${exported ? html`<div class="danger-zone">
          <button type="button" class="btn-secondary danger" data-wipe>Cerrar sesión y borrar del dispositivo</button>
          <p class="hint">El respaldo guardado se puede importar después en Ajustes.</p></div>` : ''}`);
      $('[data-cancel]', ov.body).addEventListener('click', () => ov.close(true));
      $('[data-sync]', ov.body).addEventListener('click', async (ev) => {
        ev.currentTarget.disabled = true;
        ev.currentTarget.textContent = 'Sincronizando…';
        await syncNow();
        const left = await unsyncedNow();
        message = left ? { kind: 'warn', text: state.sync.error || 'Quedan marcas que el servidor ha rechazado: revísalas en Ajustes › Sincronización.' } : null;
        paint();
      });
      $('[data-export]', ov.body).addEventListener('click', async () => {
        exported = await downloadBackup();
        message = { kind: 'info', text: `Respaldo descargado (${plural(exported, 'marca', 'marcas')}).` };
        exported = exported || 1;
        paint();
      });
      const wipe = $('[data-wipe]', ov.body);
      if (wipe) wipe.addEventListener('click', () => { result = true; ov.close(true); });
      $(state.online ? '[data-sync]' : '[data-export]', ov.body).focus();
    }
    paint();
  });
}

/** Cierra la sesión y borra los datos de este usuario del dispositivo (llamar tras confirmLogout). */
export async function logout() {
  stopSync();
  await syncIdle();
  // Con el servidor caído no se espera al corte: el token caduca solo en el servidor.
  if (state.online) await apiPostQuiet('/auth/logout');
  const userId = state.user && state.user.id;
  if (userId && hasDb()) {
    const ids = (await getAll('catalogs').catch(() => [])).map((c) => c.id);
    if ('caches' in window) await Promise.all(ids.map((id) => caches.delete(IMG_CACHE_PREFIX + id).catch(() => {})));
    await deleteUserDb(userId);
  } else {
    closeDb();
  }
  setActiveCatalog(null);
  setUser(null);
}

// --- Pantalla de acceso ---------------------------------------------------------------------------

function errorMessage(e) {
  if (e.status === 429 && e.retryAfter) {
    const min = Math.max(1, Math.ceil(e.retryAfter / 60));
    return `Demasiados intentos fallidos. Vuelve a probar dentro de ${min} min.`;
  }
  if (e.status === 409) return 'Ya hay una cuenta con ese email. Entra con ella o usa otro.';
  return e.message;
}

/**
 * Pinta la pantalla de acceso en `root`. `onDone({registered})` se llama con la sesión ya abierta.
 */
export function renderAuth(root, onDone, mode = 'login') {
  const notice = state.notice;
  setNotice(null);
  const isLogin = mode === 'login';
  const lastEmail = state.user ? state.user.email : '';

  render(root, html`
    <main class="auth-screen">
      <header class="sign auth-sign">
        <p class="eyebrow">Cuaderno de campo</p>
        <h1 class="sign-title">BioDex</h1>
        <p class="sign-sub">Catálogos de especies, observaciones sin conexión y evaluación UICN.</p>
      </header>
      ${notice ? html`<p class="guard-band ${notice.kind}" role="status">${notice.text}</p>` : ''}
      <section class="auth-card">
        <div class="auth-tabs" role="tablist">
          <button type="button" role="tab" class="auth-tab ${isLogin ? 'active' : ''}" data-mode="login"
                  aria-selected="${isLogin}">Entrar</button>
          <button type="button" role="tab" class="auth-tab ${isLogin ? '' : 'active'}" data-mode="register"
                  aria-selected="${!isLogin}">Crear cuenta</button>
        </div>
        <form class="form" novalidate>
          ${isLogin ? '' : html`
            <label class="field"><span>Nombre</span>
              <input name="name" autocomplete="name" maxlength="120"></label>`}
          <label class="field"><span>Email</span>
            <input name="email" type="email" autocomplete="email" required value="${lastEmail}"></label>
          <label class="field"><span>Contraseña</span>
            <input name="password" type="password" required minlength="${isLogin ? 1 : 10}"
                   autocomplete="${isLogin ? 'current-password' : 'new-password'}"></label>
          ${isLogin ? '' : html`<p class="hint">Al menos 10 caracteres.</p>`}
          <p class="form-error" role="alert" hidden></p>
          <button class="btn-primary" type="submit">${isLogin ? 'Entrar' : 'Crear cuenta'}</button>
        </form>
      </section>
    </main>`);

  root.querySelectorAll('.auth-tab').forEach((tab) => {
    tab.addEventListener('click', () => {
      if (tab.dataset.mode !== mode) renderAuth(root, onDone, tab.dataset.mode);
    });
  });

  const form = $('form', root);
  const errorEl = $('.form-error', root);
  const showError = (text) => { errorEl.textContent = text; errorEl.hidden = !text; };

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const data = Object.fromEntries(new FormData(form));
    data.email = String(data.email || '').trim();
    if (!data.email || !data.password) return showError('Escribe el email y la contraseña.');
    if (!isLogin && data.password.length < 10) return showError('La contraseña necesita al menos 10 caracteres.');
    if (!navigator.onLine) return showError('Sin conexión: para entrar necesitas red.');

    const button = $('button[type="submit"]', form);
    button.disabled = true;
    showError('');
    try {
      const res = await apiPostJson(isLogin ? '/auth/login' : '/auth/register', data);
      setUser(res.user);
      onDone({ registered: !isLogin, verificationSent: !!res.verificationSent });
    } catch (e) {
      showError(errorMessage(e));
      button.disabled = false;
    }
  });

  const first = isLogin && lastEmail ? form.password : (form.name || form.email);
  if (first) first.focus();
}
