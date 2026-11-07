// ================================================================================================
// flags.js — funciones a medio hacer que no se enseñan todavía (F11f).
// ------------------------------------------------------------------------------------------------
// Se activan por dispositivo con localStorage `biodex.flags` = {"<nombre>": true} (desde la consola), sin
// tocar el código ni la CSP. Cada flag dice qué fase la rellena. Ahora mismo no hay ninguna: `impact` (el
// botón «Impacto») ya está activo para todos desde F11g.
// ================================================================================================

import { store } from './util.js';

const raw = () => store.get('biodex.flags', {}) || {};

/** ¿Está activa la flag `name` en este dispositivo? */
export const flag = (name) => raw()[name] === true;

export const FLAGS = {};
