// ================================================================================================
// app.js — arranque de la PWA, armazón y enrutado por hash.
// ------------------------------------------------------------------------------------------------
// Rutas: #/mapa · #/catalogos · #/especies · #/ajustes. Cambiar de sección NO apila historial
// (replaceState): en el móvil «atrás» deshace el último panel abierto, no el último toque de pestaña.
// F5: al entrar se abre la base local del usuario (db.js) y arranca el motor de sincronización
// (sync.js); la cabecera muestra «n pendientes» junto al indicador de conexión mientras haya cola.
// F6: #/unirse/<código> abre la invitación (join.js) sobre Catálogos; sin sesión, el código se guarda,
// se pasa por la pantalla de acceso y se abre al entrar.
// F11c: dos modos de disposición según el ancho (body.is-field < 900 px ≤ body.is-studio):
//   campo   barra superior fina (selector de catálogo, pendientes, conexión) + 4 pestañas abajo
//           (Mapa · Especies · Catálogo · Ajustes). Sin ruta, entra en el Mapa.
//   estudio barra lateral (marca, selector, navegación, estado y «Sincronizar ahora»). Sin ruta,
//           entra en Catálogos. Entre 900 y 1199 px la barra se pliega a iconos (solo CSS).
// Al cruzar el umbral se vuelve a montar la vista si cambia de estructura (Catálogos, Mapa).
// go(route, { returnTo: {label, run} }) — salto entre secciones con camino de vuelta: pinta
// «← Volver a <label>» encima de la vista y abre el nivel «retorno» de BioDexNav, así el «atrás» del
// sistema hace lo mismo. Ir a otra sección a mano lo descarta.
// ================================================================================================

import { state, subscribe, setConnection, setNotice, APP_VERSION, CONNECTION_LABELS } from './state.js';
import { checkHealth, clearLegacyCopies } from './api.js';
import { bootSession, renderAuth, logout, confirmLogout, sessionExpiredText } from './auth.js';
import { html, render, $, $$, plural, studioQuery, isStudio } from './util.js';
import { openUserDb } from './db.js';
import { startSync, stopSync, syncNow } from './sync.js';
import { openCatalogSwitcher } from './components/catalog-switcher.js';
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
  chevron: html`<svg viewBox="0 0 24 24" aria-hidden="true" class="chev"><path d="m6 9 6 6 6-6"/></svg>`,
  back: html`<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6"/></svg>`,
  sync: html`<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 0 0-14.7-4.4M4 4v3h3m-3 6a8 8 0 0 0 14.7 4.4M20 20v-3h-3"/></svg>`,
};

// `field`/`studio`: rótulo en cada modo (en el móvil la pestaña es el catálogo ACTIVO, en singular).
const ROUTES = {
  mapa:      { label: 'Mapa', icon: ICONS.map, view: mapView },
  catalogos: { label: 'Catálogos', field: 'Catálogo', icon: ICONS.catalogs, view: catalogsView },
  especies:  { label: 'Especies', icon: ICONS.species, view: speciesView },
  ajustes:   { label: 'Ajustes', icon: ICONS.settings, view: settingsView },
};
// Orden de las pestañas: campo (abajo) y estudio (barra lateral; Ajustes va al pie por CSS).
const FIELD_ORDER = ['mapa', 'especies', 'catalogos', 'ajustes'];
// Vistas cuya estructura cambia entre campo y estudio: al cruzar el umbral se vuelven a montar.
const MODE_SENSITIVE = new Set(['catalogos', 'mapa', 'especies']);

let current = null;       // ruta pintada
let cleanup = null;       // desmontaje de la vista actual
let unsubscribeShell = null;
let banner = null;        // aviso de una sola vez bajo la cabecera (p. ej. «revisa tu correo»):
                          // {kind, text, action?: {label, run}}
let returnTo = null;      // {label, run}: camino de vuelta tras un salto entre secciones
let droppingReturn = false;

function applyMode() {
  document.body.classList.toggle('is-studio', studioQuery.matches);
  document.body.classList.toggle('is-field', !studioQuery.matches);
}
applyMode();

// --- Pantalla de acceso ---------------------------------------------------------------------------

function showAuth() {
  stopSync();
  if (unsubscribeShell) { unsubscribeShell(); unsubscribeShell = null; }
  if (cleanup) { cleanup(); cleanup = null; }
  current = null;
  returnTo = null;
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
  const tabs = FIELD_ORDER.map((key) => html`
    <a class="tab tab-${key}" href="#/${key}" data-route="${key}" title="${ROUTES[key].label}">
      ${ROUTES[key].icon}<span class="tab-label">${ROUTES[key].field
        ? html`<span class="lbl-field">${ROUTES[key].field}</span><span class="lbl-studio">${ROUTES[key].label}</span>`
        : ROUTES[key].label}</span></a>`);
  const switcher = (where) => html`<button type="button" class="cat-switch cat-switch-${where}" data-switch aria-haspopup="dialog">
      <span class="cat-switch-text"><span class="cat-switch-label">Catálogo activo</span>
        <span class="cat-switch-name" data-cat-name></span></span>${ICONS.chevron}</button>`;

  render(root, html`
    <div class="shell">
      <header class="topbar">
        ${switcher('top')}
        <div class="top-tools">
          <a class="pending-count" data-pending href="#/ajustes" data-route="ajustes" hidden></a>
          <span class="conn" data-conn role="status"></span>
        </div>
      </header>
      <aside class="sidebar" aria-label="BioDex">
        <a class="brand" href="#/catalogos" data-route="catalogos"><img src="img/icon.svg" alt="" width="28" height="28">
          <span class="brand-name">BioDex</span></a>
        ${switcher('side')}
        <nav class="tabs" aria-label="Secciones">${tabs}</nav>
        <div class="side-status">
          <span class="conn" data-conn role="status"></span>
          <a class="pending-count" data-pending href="#/ajustes" data-route="ajustes" hidden></a>
          <button type="button" class="btn-quiet side-sync" data-sync-now title="Sincronizar ahora">${ICONS.sync}
            <span class="side-sync-text">Sincronizar ahora</span></button>
        </div>
      </aside>
      <div class="main">
        <div id="bands"></div>
        <div id="backSlot"></div>
        <main class="view" id="view" tabindex="-1"></main>
      </div>
    </div>`);
  // La barra de pestañas del móvil es la misma <nav>: en campo se fija abajo por CSS.

  $$('[data-route]', root).forEach((a) => a.addEventListener('click', (ev) => {
    ev.preventDefault();
    go(a.dataset.route);
  }));
  $$('[data-switch]', root).forEach((b) => b.addEventListener('click', () => openCatalogSwitcher({
    anchor: b,
    onManage: () => go('catalogos'),
  })));
  $('[data-sync-now]', root).addEventListener('click', () => { syncNow(); });

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
  if (!$('.shell', root)) return;
  const cat = state.activeCatalog;
  $$('[data-cat-name]', root).forEach((n) => {
    n.textContent = cat ? cat.name : 'Elige un catálogo';
    n.classList.toggle('is-empty', !cat);
  });
  $$('[data-switch]', root).forEach((b) => b.setAttribute('aria-label',
    `Catálogo activo: ${cat ? cat.name : 'ninguno'}. Cambiar de catálogo`));

  $$('[data-conn]', root).forEach((c) => render(c, html`<span class="conn-dot ${state.online ? '' : 'off'}"></span>
    <span class="conn-label">${CONNECTION_LABELS[state.connection]}</span>`));

  // «n pendientes»: lo que aún no está en el servidor (incluidas las rechazadas, que esperan decisión).
  const n = state.sync.unsynced;
  $$('[data-pending]', root).forEach((pending) => {
    pending.hidden = !n;
    pending.textContent = n ? plural(n, 'pendiente', 'pendientes') : '';
    pending.classList.toggle('is-syncing', state.sync.running);
    pending.setAttribute('aria-label', n ? `${plural(n, 'marca pendiente', 'marcas pendientes')} de sincronizar. Ver en Ajustes` : '');
  });
  const syncBtn = $('[data-sync-now]', root);
  syncBtn.disabled = !state.online || state.sync.running;
  syncBtn.classList.toggle('is-syncing', state.sync.running);

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

// --- Camino de vuelta («← Volver a …») -------------------------------------------------------------

function paintBack() {
  const slot = $('#backSlot', root);
  if (!slot) return;
  if (!returnTo) { render(slot, html``); return; }
  render(slot, html`<button type="button" class="back-link">${ICONS.back}<span>Volver a ${returnTo.label}</span></button>`);
  $('.back-link', slot).addEventListener('click', () => Nav.close('retorno'));
}

function setReturn(rt) {
  returnTo = rt;
  Nav.open('retorno', () => {
    const r = returnTo;
    returnTo = null;
    paintBack();
    if (!droppingReturn && r) r.run();
  });
  paintBack();
}

/** Quita el camino de vuelta sin seguirlo (se ha ido a otra sección a mano). */
function dropReturn() {
  if (!returnTo && !Nav.has('retorno')) return;
  droppingReturn = true;
  if (!Nav.close('retorno')) { returnTo = null; paintBack(); }
  droppingReturn = false;
}

// --- Enrutado -------------------------------------------------------------------------------------

function defaultRoute() {
  return studioQuery.matches ? 'catalogos' : 'mapa';
}

function routeFromHash() {
  if (codeFromHash()) return 'catalogos';
  const key = (location.hash.match(/^#\/([a-z]+)/) || [])[1];
  return ROUTES[key] ? key : defaultRoute();
}

/**
 * Cambia de sección. `returnTo` ({label, run}) deja un «← Volver a <label>» que deshace el salto.
 * Sin él, cualquier camino de vuelta anterior se descarta.
 */
function go(route, { returnTo: rt = null } = {}) {
  if (!ROUTES[route]) route = defaultRoute();
  if (route === current && !rt) return;
  dropReturn();
  show(route);
  if (rt) setReturn(rt);
}

function show(route, force = false) {
  if (route === current && !force) return;
  if (cleanup) { cleanup(); cleanup = null; }
  current = route;
  try { history.replaceState(history.state, '', '#/' + route); } catch (e) { /* file:// */ }
  $$('.tab', root).forEach((t) => {
    const on = t.dataset.route === route;
    t.classList.toggle('active', on);
    if (on) t.setAttribute('aria-current', 'page'); else t.removeAttribute('aria-current');
  });
  const view = $('#view', root);
  view.className = 'view view-' + route;
  document.body.dataset.route = route;
  cleanup = ROUTES[route].view.mount(view, { go, onLogout, isStudio }) || null;
}

/** Cerrar sesión desde Ajustes. Devuelve false si el usuario lo cancela (marcas sin sincronizar). */
async function onLogout() {
  if (!(await confirmLogout())) return false;
  await logout();
  showAuth();
  return true;
}

// Cruzar los 900 px: cambia la piel por CSS y, si la vista cambia de estructura, se vuelve a montar.
// No se toca nada con un panel abierto (podría tener cambios sin guardar): se remonta al cerrarlo… o
// al siguiente cambio de sección, que es lo mismo que pasaba antes de F11c.
studioQuery.addEventListener('change', () => {
  applyMode();
  if (current && MODE_SENSITIVE.has(current) && !document.querySelector('.overlay')) show(current, true);
});

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
