// ================================================================================================
// app.js — arranque de la PWA, armazón (cabecera de cartel + navegación) y enrutado por hash.
// ------------------------------------------------------------------------------------------------
// Rutas: #/mapa · #/catalogos · #/especies · #/ajustes. Cambiar de sección NO apila historial
// (replaceState): en el móvil «atrás» deshace el último panel abierto, no el último toque de pestaña.
// Ajustes sí se abre como nivel de BioDexNav, para que «atrás» devuelva a la sección de la que se vino.
// F5: al entrar se abre la base local del usuario (db.js) y arranca el motor de sincronización
// (sync.js); la cabecera muestra «n pendientes» junto al indicador de conexión mientras haya cola.
// F6: #/unirse/<código> abre la invitación (join.js) sobre Catálogos; sin sesión, el código se guarda,
// se pasa por la pantalla de acceso y se abre al entrar.
// ================================================================================================

import { state, subscribe, setConnection, setNotice, APP_VERSION, CONNECTION_LABELS } from './state.js';
import { checkHealth, clearLegacyCopies } from './api.js';
import { bootSession, renderAuth, logout, confirmLogout, sessionExpiredText } from './auth.js';
import { html, render, $, $$, plural } from './util.js';
import { openUserDb } from './db.js';
import { startSync, stopSync } from './sync.js';
import * as mapView from './views/map.js';
import * as catalogsView from './views/catalogs.js';
import * as speciesView from './views/species.js';
import * as settingsView from './views/settings.js';
import { codeFromHash, pendingJoin, openJoin } from './views/join.js';

const Nav = window.BioDexNav;
const root = document.getElementById('app');

const ICONS = {
  map: html`<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2Zm0 0v14m6-12v14"/></svg>`,
  catalogs: html`<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16M4 12h16M4 19h10"/></svg>`,
  species: html`<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19C5 10 10 5 20 4c-1 10-6 15-15 15Zm0 0 8-8"/></svg>`,
  settings: html`<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3M4.9 4.9l2.1 2.1m10 10 2.1 2.1M4.9 19.1 7 17m10-10 2.1-2.1"/></svg>`,
};

const ROUTES = {
  mapa:      { label: 'Mapa', icon: ICONS.map, view: mapView },
  catalogos: { label: 'Catálogos', icon: ICONS.catalogs, view: catalogsView },
  especies:  { label: 'Especies', icon: ICONS.species, view: speciesView },
  ajustes:   { label: 'Ajustes', icon: ICONS.settings, view: settingsView },
};

let current = null;       // ruta pintada
let cleanup = null;       // desmontaje de la vista actual
let settingsReturn = 'mapa';
let closingSilently = false;
let unsubscribeShell = null;
let banner = null;        // aviso de una sola vez bajo la cabecera (p. ej. «revisa tu correo»):
                          // {kind, text, action?: {label, run}}

// --- Pantalla de acceso ---------------------------------------------------------------------------

function showAuth() {
  stopSync();
  if (unsubscribeShell) { unsubscribeShell(); unsubscribeShell = null; }
  if (cleanup) { cleanup(); cleanup = null; }
  current = null;
  Nav.reset();
  Nav.setExitGuard(false);
  document.body.classList.remove('in-app');
  if (pendingJoin.get() && !state.notice) {
    setNotice({ kind: 'info', text: 'Te han invitado a un catálogo: entra o crea tu cuenta y lo verás al terminar.' });
  }
  renderAuth(root, ({ registered, verificationSent }) => {
    if (registered) {
      const local = ['localhost', '127.0.0.1'].includes(location.hostname);
      banner = {
        kind: 'info',
        text: verificationSent
          ? `Cuenta creada. Revisa tu correo (${state.user.email}) para verificarla.` +
            (local ? ' En local llega a Mailpit: http://localhost:8025' : '')
          : 'Cuenta creada. No se pudo enviar el correo de verificación; puedes pedirlo más tarde.',
      };
    }
    showApp();
  });
}

// --- Armazón --------------------------------------------------------------------------------------

async function showApp() {
  let dbError = false;
  try {
    await openUserDb(state.user.id);
  } catch (e) {
    dbError = true;
    console.info('IndexedDB no disponible:', e && e.message);
  }
  if (dbError) {
    banner = { kind: 'warn', text: 'Este navegador no deja guardar datos en el dispositivo (¿modo privado?): sin red no podrás trabajar ni marcar.' };
  }
  document.body.classList.add('in-app');
  const tabs = ['mapa', 'catalogos', 'especies', 'ajustes'].map((key) => html`
    <a class="tab ${key === 'ajustes' ? 'tab-settings' : ''}" href="#/${key}" data-route="${key}">
      ${ROUTES[key].icon}<span>${ROUTES[key].label}</span></a>`);

  render(root, html`
    <div class="shell">
      <header class="sign app-sign">
        <div class="sign-main">
          <p class="eyebrow">BioDex · Catálogo activo</p>
          <p class="sign-title" id="signCatalog"></p>
        </div>
        <div class="sign-tools">
          <a class="pending-count" id="pendingCount" href="#/ajustes" data-route="ajustes" hidden></a>
          <span class="conn" id="conn" role="status"></span>
          <a class="icon-btn gear" href="#/ajustes" data-route="ajustes" aria-label="Ajustes">${ICONS.settings}</a>
        </div>
      </header>
      <nav class="tabs" aria-label="Secciones">${tabs}</nav>
      <div id="bands"></div>
      <main class="view" id="view" tabindex="-1"></main>
    </div>`);

  $$('[data-route]', root).forEach((a) => a.addEventListener('click', (ev) => {
    ev.preventDefault();
    go(a.dataset.route);
  }));

  updateShell();
  unsubscribeShell = subscribe((key) => {
    if (key === 'online' || key === 'activeCatalog' || key === 'user' || key === 'sync') updateShell();
  });
  Nav.setExitGuard(true);
  current = null;
  go(routeFromHash());
  if (!dbError) startSync();
  openPendingJoin();
}

/** Invitación guardada (enlace abierto sin sesión, o sin red): se abre sobre Catálogos. */
function openPendingJoin() {
  const code = pendingJoin.get();
  if (!code) return;
  go('catalogos');
  openJoin(code);
}

const CONNECTION_BANDS = {
  'server-down': 'Servidor no disponible: trabajas con lo guardado en este dispositivo.',
  offline: 'Sin conexión: trabajas en local.',
};

function updateShell() {
  const cat = state.activeCatalog;
  const title = $('#signCatalog', root);
  if (!title) return;
  title.textContent = cat ? cat.name : 'Sin catálogo activo';
  title.classList.toggle('is-empty', !cat);

  render($('#conn', root), html`<span class="conn-dot ${state.online ? '' : 'off'}"></span>
    <span class="conn-label">${CONNECTION_LABELS[state.connection]}</span>`);

  // «n pendientes»: lo que aún no está en el servidor (incluidas las rechazadas, que esperan decisión).
  const pending = $('#pendingCount', root);
  const n = state.sync.unsynced;
  pending.hidden = !n;
  pending.textContent = n ? plural(n, 'pendiente', 'pendientes') : '';
  pending.classList.toggle('is-syncing', state.sync.running);
  pending.setAttribute('aria-label', n ? `${plural(n, 'marca pendiente', 'marcas pendientes')} de sincronizar. Ver en Ajustes` : '');

  const band = CONNECTION_BANDS[state.connection];
  const revoked = state.sync.revoked || [];
  render($('#bands', root), html`
    ${band ? html`<p class="guard-band warn" role="status">${band}</p>` : ''}
    ${revoked.map((c) => html`<p class="guard-band warn" role="status"><span>Ya no eres miembro de «${c.name}».
      Tus marcas sin sincronizar de ese catálogo siguen aquí: expórtalas en Ajustes › Sincronización.</span></p>`)}
    ${banner ? html`<p class="guard-band ${banner.kind}" role="status">
      <span>${banner.text}</span>
      ${banner.action ? html`<button type="button" class="link-btn band-action">${banner.action.label}</button>` : ''}
      <button type="button" class="band-close" aria-label="Cerrar aviso">×</button></p>` : ''}`);
  const close = $('.band-close', root);
  if (close) close.addEventListener('click', () => { banner = null; updateShell(); });
  const action = $('.band-action', root);
  if (action) action.addEventListener('click', banner.action.run);
}

// --- Enrutado -------------------------------------------------------------------------------------

function routeFromHash() {
  if (codeFromHash()) return 'catalogos';
  const key = (location.hash.match(/^#\/([a-z]+)/) || [])[1];
  return ROUTES[key] ? key : 'mapa';
}

function go(route) {
  if (!ROUTES[route]) route = 'mapa';
  if (route === current) return;
  if (route === 'ajustes') {
    settingsReturn = current && current !== 'ajustes' ? current : 'mapa';
    Nav.open('ajustes', () => { if (!closingSilently) show(settingsReturn); });
  } else if (Nav.has('ajustes')) {
    // Se sale de Ajustes por una pestaña: se cierra su nivel sin que su cierre nos lleve atrás.
    closingSilently = true;
    Nav.close('ajustes');
    closingSilently = false;
  }
  show(route);
}

function show(route) {
  if (route === current) return;
  if (cleanup) { cleanup(); cleanup = null; }
  current = route;
  try { history.replaceState(history.state, '', '#/' + route); } catch (e) { /* file:// */ }
  $$('.tab', root).forEach((t) => {
    const on = t.dataset.route === route;
    t.classList.toggle('active', on);
    if (on) t.setAttribute('aria-current', 'page'); else t.removeAttribute('aria-current');
  });
  $('.gear', root).classList.toggle('is-active', route === 'ajustes');
  const view = $('#view', root);
  view.className = 'view view-' + route;
  cleanup = ROUTES[route].view.mount(view, { go, onLogout }) || null;
}

/** Cerrar sesión desde Ajustes. Devuelve false si el usuario lo cancela (marcas sin sincronizar). */
async function onLogout() {
  if (!(await confirmLogout())) return false;
  await logout();
  showAuth();
  return true;
}

// --- Arranque -------------------------------------------------------------------------------------

// Volver a tener red no dice que el servidor conteste: se comprueba con /health.
window.addEventListener('online', () => checkHealth());
window.addEventListener('offline', () => setConnection('offline'));
// El hash solo se lee al arrancar. Después lo escribe show(), y un cambio que llegue desde fuera es
// casi siempre el history.go() de BioDexNav aterrizando en una entrada con el hash de antes: se
// reescribe con la sección que se está viendo en vez de navegar (si no, cerrar Ajustes lo reabría).
window.addEventListener('hashchange', () => {
  // Un enlace de invitación abierto con la app ya en marcha.
  const code = codeFromHash();
  if (code) {
    pendingJoin.set(code);
    if (current) {
      try { history.replaceState(history.state, '', '#/' + current); } catch (e) { /* file:// */ }
      openPendingJoin();
    }
    return;
  }
  if (current && routeFromHash() !== current) {
    try { history.replaceState(history.state, '', '#/' + current); } catch (e) { /* file:// */ }
  }
});
window.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') Nav.back(); });
window.addEventListener('biodex:unauthorized', () => {
  if (!current) return;
  setNotice({ kind: 'warn', text: sessionExpiredText() });
  showAuth();
});

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js').catch((e) => console.info('SW no registrado:', e.message));
  // El armazón sale de la caché (cache-first): una versión nueva se instala en segundo plano y toma el
  // control, pero esta página sigue con el código viejo. Se avisa en vez de recargar sola (podría haber
  // un formulario a medias). La primera instalación no avisa: no había versión anterior.
  const hadController = !!navigator.serviceWorker.controller;
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (!hadController) return;
    banner = { kind: 'info', text: 'Hay una versión nueva.', action: { label: 'Recargar', run: () => location.reload() } };
    updateShell();
  });
}

clearLegacyCopies();
{ const code = codeFromHash(); if (code) pendingJoin.set(code); }
Nav.init();
document.documentElement.dataset.version = APP_VERSION;
bootSession().then((where) => (where === 'app' ? showApp() : showAuth()));
