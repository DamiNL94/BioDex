// ================================================================================================
// blind.js — MODO BÚSQUEDA: qué marcas no pueden verse durante una visita. Solo depende de state.js (lo usan marks.js,
// visits.js, el mapa, la ficha y el buscador sin dependencias circulares).
// ------------------------------------------------------------------------------------------------
// Con una visita en modo búsqueda, las marcas ANTERIORES de sus especies objetivo —de cualquier usuario y fecha— no
// pueden aparecer por NINGÚN camino: mapa (puntos, clústeres, filtros, lista, EOO/AOO, subpoblaciones), ficha de la
// especie (minimapa, marcas, evaluación, series), buscador («cerca de aquí»), hoja de impacto, exportación. Todos
// preguntan aquí; no hay otra regla en ningún otro sitio. Si la visita no tiene especies objetivo se busca todo, y se
// oculta todo lo anterior del catálogo. Las marcas de la propia visita se ven siempre.
//
// El estado manda desde IndexedDB (campo `blind` del registro de la visita): state.visit es su copia en memoria y en
// localStorage, y checkActiveVisit() (js/visits.js) la corrige al arrancar. Por qué: docs/esfuerzo.md.
// ================================================================================================

import { state } from './state.js';

/** Visita en curso en modo búsqueda (de este usuario), o null. */
export function blindVisit() {
  const v = state.visit;
  return v && v.blind === true && state.user && v.userId === state.user.id ? v : null;
}

/** ¿Oculta el modo búsqueda las marcas anteriores de esta especie (en ese catálogo)? */
export function blindHidesTaxon(taxonID, catalogId = null) {
  const v = blindVisit();
  if (!v || (catalogId && catalogId !== v.catalogId)) return false;
  return !(v.species || []).length || v.species.map(String).includes(String(taxonID));
}

/** ¿Hay que ocultar esta marca? Las de la visita en curso se ven siempre. */
export function hiddenByBlind(o) {
  const v = blindVisit();
  if (!v || !o || o.visitID === v.visitID) return false;
  return blindHidesTaxon(o.taxonID, o.catalogId || null);
}

/** Lista de marcas sin las que oculta el modo búsqueda. */
export const blindFilter = (marks) => (blindVisit() ? (marks || []).filter((o) => !hiddenByBlind(o)) : (marks || []));

/** Texto corto para los sitios donde algo queda oculto. */
export const BLIND_NOTICE = 'Modo búsqueda: las marcas anteriores están ocultas hasta que cierres la visita (o salgas del modo búsqueda desde la barra de la visita).';
