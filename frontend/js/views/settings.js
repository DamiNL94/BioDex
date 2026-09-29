// Vista Ajustes: cuenta, tema, versión, estado de conexión y cierre de sesión.

import { state, subscribe, setTheme, APP_VERSION } from '../state.js';
import { html, render, $ } from '../util.js';

const THEMES = [['system', 'Sistema'], ['light', 'Claro'], ['dark', 'Oscuro']];

export function mount(el, { onLogout }) {
  function paint() {
    const u = state.user || {};
    const sw = 'serviceWorker' in navigator && navigator.serviceWorker.controller;
    render(el, html`
      <div class="page-head"><h2>Ajustes</h2></div>
      <div class="settings">
        <section class="panel">
          <h3>Cuenta</h3>
          <dl class="facts">
            <div><dt>Nombre</dt><dd>${u.name || '—'}</dd></div>
            <div><dt>Email</dt><dd>${u.email}
              ${u.emailVerified ? '' : html`<span class="badge warn">Email sin verificar</span>`}</dd></div>
            ${u.orcid ? html`<div><dt>ORCID</dt><dd>${u.orcid}</dd></div>` : ''}
          </dl>
        </section>
        <section class="panel">
          <h3>Apariencia</h3>
          <div class="segmented" role="radiogroup" aria-label="Tema">${THEMES.map(([value, label]) => html`
            <button type="button" role="radio" data-choice="${value}" aria-checked="${state.theme === value}"
                    class="${state.theme === value ? 'active' : ''}">${label}</button>`)}</div>
          <p class="hint">«Sistema» sigue el modo claro u oscuro del dispositivo.</p>
        </section>
        <section class="panel">
          <h3>Aplicación</h3>
          <dl class="facts">
            <div><dt>Versión</dt><dd>${APP_VERSION}</dd></div>
            <div><dt>Conexión</dt><dd>${state.online ? 'En línea' : 'Sin conexión'}</dd></div>
            <div><dt>Uso sin conexión</dt><dd>${sw ? 'Preparado' : 'No disponible en este navegador o aún instalándose'}</dd></div>
          </dl>
        </section>
        <section class="panel">
          <button type="button" class="btn-secondary danger" id="logoutBtn">Cerrar sesión</button>
          <p class="hint">${state.online ? 'Cierra la sesión solo en este dispositivo.'
            : 'Sin conexión: se olvida la sesión en este dispositivo; el servidor la cerrará cuando caduque.'}</p>
        </section>
      </div>`);

    el.querySelectorAll('[data-choice]').forEach((b) => b.addEventListener('click', () => setTheme(b.dataset.choice)));
    $('#logoutBtn', el).addEventListener('click', (ev) => { ev.currentTarget.disabled = true; onLogout(); });
  }

  const unsubscribe = subscribe((key) => { if (key === 'theme' || key === 'online' || key === 'user') paint(); });
  paint();
  return unsubscribe;
}
