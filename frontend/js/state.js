// ================================================================================================
// state.js — estado en memoria de la app, usuario cacheado y preferencias.
// ------------------------------------------------------------------------------------------------
// Lo que tiene que sobrevivir a un cierre de la app va a localStorage con prefijo `biodex.`:
//   biodex.user           último `user` de la API: permite entrar sin red (días en el campo)
//   biodex.activeCatalog  {id, name, role} del catálogo con el que se trabaja
//   biodex.theme          'system' | 'light' | 'dark'
// Las observaciones y el resto de datos de trabajo NO van aquí: van a IndexedDB en F5.
// ================================================================================================

import { store } from './util.js';

export const API_BASE = '/api';
export const APP_VERSION = '0.4.0 · F4';

const listeners = new Set();

export const state = {
  user: store.get('biodex.user'),
  activeCatalog: store.get('biodex.activeCatalog'),
  theme: store.get('biodex.theme', 'system'),
  online: navigator.onLine,
  // Aviso de sesión caducada / correo enviado que la pantalla siguiente tiene que mostrar.
  notice: null,
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

export function setOnline(online) {
  if (state.online === online) return;
  state.online = online;
  emit('online');
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
