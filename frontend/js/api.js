// ================================================================================================
// api.js — el único sitio del frontend que habla con /api (portado de NaturDex).
// ------------------------------------------------------------------------------------------------
// La app es offline-first: ninguna llamada puede dejar la interfaz colgada esperando a la red, así
// que todas llevan corte por tiempo. `AbortSignal.timeout` no existe en navegadores antiguos; ahí
// la petición va sin límite.
//
// Diferencias con NaturDex:
//   · Los errores son `ApiError` con `status` (0 = sin red o sin respuesta) y `retryAfter` (s), para
//     que quien llama distinga un 401 de un 429 de un fallo de red sin leer el texto.
//   · Un 401 fuera de /auth/ avisa a la app con el evento `biodex:unauthorized` (sesión caducada).
//   · `signal` propio (AbortController) para cancelar una petición que ya no interesa (buscador):
//     el ApiError lleva entonces `aborted = true` y quien llama lo ignora.
//   · Cada llamada actualiza `state.connection` (F3b): la cabecera refleja si el servidor contesta,
//     no solo si el dispositivo tiene red.
// ================================================================================================

import { API_BASE, state, setConnection } from './state.js';
import { store } from './util.js';

export const API_TIMEOUT_MS = 8000;
export const HEALTH_INTERVAL_MS = 30000;
const SHORT_TIMEOUT_MS = 3000;       // /health y GET con copia guardada

// --- Estado de conexión ---------------------------------------------------------------------------
// Cada respuesta de la API dice «en línea»; cada fallo de red o timeout, «servidor no disponible»
// (setConnection lo convierte en «sin conexión» si el navegador no tiene red). Mientras no se esté en
// línea, GET /health cada 30 s devuelve la cabecera a «En línea» sin recargar.
let healthTimer = null;

function reportConnection(connection) {
  setConnection(connection);
  if (state.connection === 'online') {
    clearTimeout(healthTimer);
    healthTimer = null;
  } else if (!healthTimer) {
    healthTimer = setTimeout(checkHealth, HEALTH_INTERVAL_MS);
  }
}

/** Comprueba ya si el servidor contesta (al arrancar, al volver el evento `online`, y cada 30 s). */
export async function checkHealth() {
  clearTimeout(healthTimer);
  healthTimer = null;
  if (!navigator.onLine) { reportConnection('offline'); return; }
  try { await apiFetch('/health', { timeoutMs: SHORT_TIMEOUT_MS }); } catch (e) { /* ya lo anota apiFetch */ }
}

export class ApiError extends Error {
  constructor(message, status = 0, retryAfter = null, data = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.retryAfter = retryAfter;
    this.data = data;        // cuerpo JSON del error (p. ej. el taxonID de un 409)
    this.aborted = false;
  }
}

export function apiSignal(ms = API_TIMEOUT_MS, signal = undefined) {
  const timeout = (typeof AbortSignal !== 'undefined' && AbortSignal.timeout) ? AbortSignal.timeout(ms) : undefined;
  if (!signal) return timeout;
  if (!timeout) return signal;
  return AbortSignal.any ? AbortSignal.any([signal, timeout]) : signal;
}

export const apiUrl = (path) => API_BASE + path;

/** `fetch` con corte por tiempo. Devuelve la Response cruda; un fallo de red lanza ApiError(status 0). */
export async function apiFetch(path, { method = 'GET', body, timeoutMs = API_TIMEOUT_MS, signal } = {}) {
  const opts = { method, signal: apiSignal(timeoutMs, signal), credentials: 'same-origin', headers: { Accept: 'application/json' } };
  if (body !== undefined) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }
  let res;
  try {
    res = await fetch(apiUrl(path), opts);
  } catch (e) {
    if (signal && signal.aborted) {
      const err = new ApiError('Petición cancelada.', 0);
      err.aborted = true;
      throw err;
    }
    reportConnection('server-down');
    const timeout = e && (e.name === 'TimeoutError' || e.name === 'AbortError');
    throw new ApiError(timeout ? 'El servidor no responde. Inténtalo de nuevo en un momento.'
                               : 'Sin conexión con el servidor.', 0);
  }
  // 502/503/504 los da nginx con PHP caído: para la app es igual que un servidor que no contesta.
  reportConnection([502, 503, 504].includes(res.status) ? 'server-down' : 'online');
  if (res.status === 401 && !path.startsWith('/auth/')) {
    window.dispatchEvent(new CustomEvent('biodex:unauthorized'));
  }
  return res;
}

/** Error legible de una respuesta fallida: el `error` que redacta el backend, o uno genérico. */
async function errorFrom(res, fallback) {
  const data = await res.json().catch(() => null);
  const retry = parseInt(res.headers.get('Retry-After') || '', 10);
  const message = data && data.error ? data.error
    : res.status >= 500 ? 'El servidor ha tenido un problema. Inténtalo más tarde.'
    : `${fallback} (HTTP ${res.status}).`;
  return new ApiError(message, res.status, Number.isFinite(retry) ? retry : null, data);
}

async function json(res, fallback) {
  if (!res.ok) throw await errorFrom(res, fallback);
  return res.json();
}

/** GET que devuelve el JSON parseado y LANZA ApiError si la respuesta no es 2xx. */
export async function apiGetJson(path, opts = {}) {
  return json(await apiFetch(path, opts), 'No se pudo leer del servidor');
}

/** POST de JSON que devuelve el JSON parseado y LANZA ApiError si la respuesta no es 2xx. */
export async function apiPostJson(path, body = {}, opts = {}) {
  return json(await apiFetch(path, { ...opts, method: 'POST', body }), 'No se pudo completar la operación');
}

/** PUT / PATCH / DELETE de JSON: como apiPostJson. */
export async function apiPutJson(path, body = {}, opts = {}) {
  return json(await apiFetch(path, { ...opts, method: 'PUT', body }), 'No se pudo guardar');
}
export async function apiPatchJson(path, body = {}, opts = {}) {
  return json(await apiFetch(path, { ...opts, method: 'PATCH', body }), 'No se pudo guardar');
}
export async function apiDeleteJson(path, opts = {}) {
  return json(await apiFetch(path, { ...opts, method: 'DELETE' }), 'No se pudo borrar');
}

// Opciones de los filtros del buscador y niveles de sensibilidad (GET /species/filters). Cambian
// muy de tarde en tarde: una petición por sesión de la app (y el navegador la cachea un día).
let filtersPromise = null;
export function getSpeciesFilters() {
  if (!filtersPromise) {
    filtersPromise = apiGetJson('/species/filters').catch((e) => { filtersPromise = null; throw e; });
  }
  return filtersPromise;
}

// --- Copia de la última respuesta buena (provisional) ----------------------------------------------
// TODO F5: sustituir por IndexedDB. Hasta entonces, Catálogos y Especies guardan en localStorage
// (biodex.copy:<ruta>) la última respuesta buena de sus GET y la pintan cuando el servidor no contesta,
// en vez de un error técnico. Son datos que el servidor ya envió a este usuario; se borran al cerrar
// sesión (clearApiCopies) para que no los vea otra cuenta en el mismo dispositivo.
const COPY_PREFIX = 'biodex.copy:';

/**
 * GET con respaldo: `{data, savedAt}`. `savedAt` es null si `data` viene del servidor, o la fecha (ms)
 * de la copia si el servidor no contesta. Sin copia, o con un error del servidor que no sea de conexión
 * (403, 404…), lanza el ApiError; `e.offline` indica que fue por no poder hablar con el servidor.
 */
export async function apiGetWithCopy(path, opts = {}) {
  const copy = store.get(COPY_PREFIX + path);
  // Ya se sabe que el servidor no contesta: la copia, sin esperar. Cuando vuelva, /health cambia el
  // estado a «en línea» y las vistas recargan.
  if (copy && !state.online) return { data: copy.data, savedAt: copy.savedAt };
  try {
    // Con copia a mano no merece la pena esperar el corte largo: 3 s, como el resto del arranque.
    const data = await apiGetJson(path, { timeoutMs: copy ? SHORT_TIMEOUT_MS : API_TIMEOUT_MS, ...opts });
    store.set(COPY_PREFIX + path, { savedAt: Date.now(), data });
    return { data, savedAt: null };
  } catch (e) {
    e.offline = !e.aborted && (e.status === 0 || [502, 503, 504].includes(e.status));
    if (copy && e.offline) return { data: copy.data, savedAt: copy.savedAt };
    throw e;
  }
}

export function clearApiCopies() {
  try {
    Object.keys(localStorage).filter((k) => k.startsWith(COPY_PREFIX)).forEach((k) => store.remove(k));
  } catch (e) { /* sin almacenamiento */ }
}

/** POST de mejor esfuerzo: el JSON si fue bien; `null` si no hubo red o hubo error. Nunca lanza. */
export async function apiPostQuiet(path, body = {}, opts = {}) {
  try {
    const res = await apiFetch(path, { ...opts, method: 'POST', body });
    return res.ok ? await res.json() : null;
  } catch (e) {
    return null;
  }
}
