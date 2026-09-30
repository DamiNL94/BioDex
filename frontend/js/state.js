// ================================================================================================
// state.js — estado en memoria de la app, usuario cacheado y preferencias.
// ------------------------------------------------------------------------------------------------
// Lo que tiene que sobrevivir a un cierre de la app va a localStorage con prefijo `biodex.`:
//   biodex.user           último `user` de la API: permite entrar sin red (días en el campo)
//   biodex.activeCatalog  {id, name, role} del catálogo con el que se trabaja
//   biodex.theme          'system' | 'light' | 'dark'
// Catálogos, especies, marcas y la cola de envío NO van aquí: van a IndexedDB (db.js), una base por usuario.
// ================================================================================================

import { store } from './util.js';

export const API_BASE = '/api';
export const APP_VERSION = '0.9.0 · F9';

const listeners = new Set();

export const state = {
  user: store.get('biodex.user'),
  activeCatalog: store.get('biodex.activeCatalog'),
  theme: store.get('biodex.theme', 'system'),
  // Conexión real: 'online' · 'server-down' (hay red, pero la API no contesta) · 'offline' (sin red).
  // La actualizan api.js (cada respuesta o fallo) y los eventos online/offline de app.js.
  connection: navigator.onLine ? 'online' : 'offline',
  get online() { return this.connection === 'online'; },
  // Aviso de sesión caducada / correo enviado que la pantalla siguiente tiene que mostrar.
  notice: null,
  // Sincronización (sync.js): pending = en cola sin rechazar; unsynced = todo lo que aún no está en el
  // servidor (incluidas las rechazadas); revoked = catálogos de los que te han sacado con marcas sin subir.
  sync: { pending: 0, rejected: 0, unsynced: 0, running: false, lastSync: null, error: null,
    unauthorized: false, nextRetryAt: null, revoked: [] },
  // Descargas en curso: catalogId → {phase, done, total}.
  downloads: {},
};

/** Suscripción a cambios: fn(clave) cada vez que algo cambia. Devuelve la función para darse de baja. */
export function subscribe(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

function emit(key) {
  listeners.forEach((fn) => { try { fn(key); } catch (e) { console.error(e); } });
}

export function setUser(user) {
  state.user = user;
  if (user) store.set('biodex.user', user); else store.remove('biodex.user');
  emit('user');
}

export function setActiveCatalog(catalog) {
  state.activeCatalog = catalog ? { id: catalog.id, name: catalog.name, role: catalog.role } : null;
  if (state.activeCatalog) store.set('biodex.activeCatalog', state.activeCatalog);
  else store.remove('biodex.activeCatalog');
  emit('activeCatalog');
}

export const CONNECTION_LABELS = { online: 'En línea', 'server-down': 'Servidor no disponible', offline: 'Sin conexión' };

/** Cambia el estado de conexión; avisa con la clave 'online' (la que ya escuchan las vistas). */
export function setConnection(connection) {
  if (!navigator.onLine) connection = 'offline';
  if (state.connection === connection) return;
  state.connection = connection;
  emit('online');
}

/** Actualiza el estado de sincronización; avisa con la clave 'sync'. */
export function setSync(patch) {
  state.sync = { ...state.sync, ...patch };
  emit('sync');
}

/** Progreso de la descarga de un catálogo (null = terminada); avisa con la clave 'download'. */
export function setDownload(catalogId, progress) {
  const next = { ...state.downloads };
  if (progress) next[catalogId] = progress; else delete next[catalogId];
  state.downloads = next;
  emit('download');
}

export function setNotice(notice) {
  state.notice = notice;
}

// --- Tema -----------------------------------------------------------------------------------------
// Los tokens del oscuro cuelgan de :root[data-theme="dark"]; «sistema» sigue a prefers-color-scheme.
const darkQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

export function applyTheme() {
  const dark = state.theme === 'dark' || (state.theme === 'system' && darkQuery && darkQuery.matches);
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  const meta = document.querySelector('meta[name="theme-color"]');
  // El color de la barra del sistema sigue al --leaf-fill del tema.
  if (meta) meta.content = dark ? '#2E6B49' : '#1F4D34';
}

export function setTheme(theme) {
  state.theme = theme;
  store.set('biodex.theme', theme);
  applyTheme();
  emit('theme');
}

if (darkQuery && darkQuery.addEventListener) {
  darkQuery.addEventListener('change', () => { if (state.theme === 'system') applyTheme(); });
}

// Se aplica al importar el módulo, antes del primer pintado de la app.
applyTheme();
