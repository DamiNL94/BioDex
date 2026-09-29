// ================================================================================================
// auth.js — pantallas de acceso y lógica de sesión.
// ------------------------------------------------------------------------------------------------
// La sesión vive en cookies HttpOnly (el JS no las ve). Lo que sí guarda el cliente es el último
// `user` en localStorage (biodex.user): con él la app arranca sin red, que es lo normal tras varios
// días en el campo. Reglas de arranque (bootSession):
//   · sin red y con usuario guardado  → entra directo; la cabecera avisa «Sin conexión».
//   · con red → GET /auth/me: 200 actualiza el usuario; 401 → acceso con aviso de sesión caducada.
//     Si el servidor no contesta (caído, timeout) se trata como «sin red».
// ================================================================================================

import { apiGetJson, apiPostJson, apiPostQuiet } from './api.js';
import { state, setUser, setNotice } from './state.js';
import { html, render, $ } from './util.js';

export const SESSION_EXPIRED = 'Tu sesión ha caducado. Lo que tengas en este dispositivo se conserva.';

/** Decide si se entra en la app ('app') o en la pantalla de acceso ('auth'). */
export async function bootSession() {
  if (!navigator.onLine) {
    if (state.user) return 'app';
    setNotice({ kind: 'warn', text: 'Sin conexión. La primera vez necesitas red para entrar.' });
    return 'auth';
  }
  try {
    const { user } = await apiGetJson('/auth/me');
    setUser(user);
    return 'app';
  } catch (e) {
    if (e.status === 401) {
      // Se conserva biodex.user: si el aviso llega en el campo y se pierde la red, se puede seguir
      // trabajando en local. Solo cerrar sesión a propósito lo borra.
      if (state.user) setNotice({ kind: 'warn', text: SESSION_EXPIRED });
      return 'auth';
    }
    if (state.user) return 'app';   // servidor inaccesible: como sin red
    setNotice({ kind: 'warn', text: e.message });
    return 'auth';
  }
}

export async function logout() {
  if (navigator.onLine) await apiPostQuiet('/auth/logout');
  // TODO F5: decidir aquí qué pasa con los datos locales (IndexedDB) al cerrar sesión: las marcas
  // sin sincronizar no se pueden borrar sin avisar.
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
