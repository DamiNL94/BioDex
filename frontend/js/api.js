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
// ================================================================================================

import { API_BASE } from './state.js';

export const API_TIMEOUT_MS = 8000;

export class ApiError extends Error {
  constructor(message, status = 0, retryAfter = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.retryAfter = retryAfter;
  }
}

export function apiSignal(ms = API_TIMEOUT_MS) {
  return (typeof AbortSignal !== 'undefined' && AbortSignal.timeout) ? AbortSignal.timeout(ms) : undefined;
}

export const apiUrl = (path) => API_BASE + path;

/** `fetch` con corte por tiempo. Devuelve la Response cruda; un fallo de red lanza ApiError(status 0). */
export async function apiFetch(path, { method = 'GET', body, timeoutMs = API_TIMEOUT_MS } = {}) {
  const opts = { method, signal: apiSignal(timeoutMs), credentials: 'same-origin', headers: { Accept: 'application/json' } };
  if (body !== undefined) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }
  let res;
  try {
    res = await fetch(apiUrl(path), opts);
  } catch (e) {
    const timeout = e && (e.name === 'TimeoutError' || e.name === 'AbortError');
    throw new ApiError(timeout ? 'El servidor no responde. Inténtalo de nuevo en un momento.'
                               : 'Sin conexión con el servidor.', 0);
  }
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
  return new ApiError(message, res.status, Number.isFinite(retry) ? retry : null);
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

/** POST de mejor esfuerzo: el JSON si fue bien; `null` si no hubo red o hubo error. Nunca lanza. */
export async function apiPostQuiet(path, body = {}, opts = {}) {
  try {
    const res = await apiFetch(path, { ...opts, method: 'POST', body });
    return res.ok ? await res.json() : null;
  } catch (e) {
    return null;
  }
}
