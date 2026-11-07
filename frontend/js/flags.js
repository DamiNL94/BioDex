// ================================================================================================
// flags.js — funciones a medio hacer que no se enseñan todavía (F11f).
// ------------------------------------------------------------------------------------------------
// Se activan por dispositivo con localStorage `biodex.flags` = {"impact": true} (desde la consola), sin
// tocar el código ni la CSP. Cada flag dice qué fase la rellena.
//   impact   segundo botón flotante «Impacto» junto a «Marcar» en el modo campo (lo rellena F11g)
// ================================================================================================

import { store } from './util.js';

export const FLAGS = {
  get impact() { return store.get('biodex.flags', {}).impact === true; },
};
