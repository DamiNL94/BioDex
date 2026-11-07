// ================================================================================================
// species-search.js — buscar y ordenar las especies de un catálogo (F11f): la hoja de marcar y la
// pestaña Especies del modo campo. Funciones puras, sin DOM ni imports: lo prueba node
// (frontend/tests/species-search.test.mjs) y funciona igual con las especies de IndexedDB, sin red.
// ------------------------------------------------------------------------------------------------
// Un solo cuadro encuentra por:
//   · nombre común (vernacularName) y nombre científico, en cualquier parte del texto;
//   · el principio de cada palabra, en orden («pin nev», «lagartija col»);
//   · la abreviatura 3+3 de género y epíteto («pinnev» → Pinguicula nevadensis; con subespecie, 3+3+3),
//     también a medio escribir desde 4 letras («pinne»).
// Sin tildes ni mayúsculas (y la ñ cuenta como n).
// Orden por defecto: recientes de este usuario → con registros a menos de 500 m de la posición actual
// (si hay GPS) → el resto, alfabético por nombre científico. Con texto, primero lo que mejor casa
// (abreviatura exacta → principio de palabra → en medio) y, a igualdad, el mismo orden de siempre.
// ================================================================================================

export const NEAR_M = 500;         // «cerca»: registros a menos de 500 m
export const RECENT_MAX = 8;       // recientes que suben arriba (los demás vuelven a su sitio)
export const GRID_MAX = 8;         // con 8 especies o menos, rejilla de tarjetas en vez de lista

/** Texto comparable: sin tildes, sin mayúsculas y con lo que no es letra o cifra como espacio. */
export function fold(s) {
  return String(s ?? '').normalize('NFD').replace(/\p{Mn}+/gu, '').toLowerCase()
    .replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
}

/** Nombre científico sin autor (canonicalName, o scientificName si no lo hay). */
export function canonicalOf(sp) {
  return String((sp && (sp.canonicalName || sp.scientificName)) || '').trim();
}

// Marcas de rango que no son parte de la abreviatura («subsp.», «var.»…).
const RANK_WORDS = new Set(['subsp', 'ssp', 'var', 'f', 'forma', 'cf', 'aff', 'x', 'nothosubsp', 'agg']);

/** Abreviatura 3+3 (y +3 con infraespecie): «Pinguicula nevadensis» → «pinnev». '' si no hay epíteto. */
export function abbreviation(sp) {
  const words = fold(canonicalOf(sp)).split(' ').filter((w) => w && !RANK_WORDS.has(w));
  if (words.length < 2) return '';
  return words.slice(0, 3).map((w) => w.slice(0, 3)).join('');
}

/** Palabras en las que se busca (científico y común). */
function wordsOf(text) {
  return fold(text).split(' ').filter(Boolean);
}

/** ¿Son los términos de la consulta principios de palabras de `words`, en orden? */
function prefixesInOrder(terms, words) {
  let i = 0;
  for (const t of terms) {
    while (i < words.length && !words[i].startsWith(t)) i++;
    if (i === words.length) return false;
    i++;
  }
  return true;
}

/**
 * Lo bien que casa la consulta con la especie: 0 abreviatura exacta · 1 principio de palabra (o la
 * abreviatura a medio escribir) · 2 en medio del texto · null si no casa. Consulta vacía → 2 (todas).
 */
export function matchScore(query, sp) {
  const q = fold(query);
  if (!q) return 2;
  const sci = canonicalOf(sp);
  const common = String(sp.vernacularName || '');
  const compact = q.replace(/ /g, '');
  const abbr = abbreviation(sp);
  if (abbr && compact.length >= 6 && abbr.startsWith(compact) && !q.includes(' ')) return 0;
  const terms = q.split(' ');
  if (prefixesInOrder(terms, wordsOf(sci)) || prefixesInOrder(terms, wordsOf(common))) return 1;
  if (abbr && compact.length >= 4 && abbr.startsWith(compact) && !q.includes(' ')) return 1;
  if (fold(sci).includes(q) || fold(common).includes(q) || fold(sp.scientificName).includes(q)) return 2;
  return null;
}

/** Distancia en metros entre dos puntos (haversine; de sobra para «a menos de 500 m»). */
export function distanceM(lat1, lon1, lat2, lon2) {
  const R = 6371008.8;
  const rad = Math.PI / 180;
  const dLat = (lat2 - lat1) * rad;
  const dLon = (lon2 - lon1) * rad;
  const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin(dLon / 2) ** 2;
  return 2 * R * Math.asin(Math.min(1, Math.sqrt(a)));
}

/**
 * Recientes del usuario a partir de SUS marcas (las de IndexedDB): taxonID → instante de la última, de
 * la más reciente a la más antigua, como mucho RECENT_MAX. Las borradas no cuentan.
 */
export function recentFromMarks(marks, userId, max = RECENT_MAX) {
  const last = new Map();
  for (const o of marks || []) {
    if (!o || o.deletedAt || (userId !== undefined && userId !== null && o.authorId !== userId)) continue;
    const t = Date.parse(o.eventDate);
    if (!Number.isFinite(t)) continue;
    if (!last.has(o.taxonID) || last.get(o.taxonID) < t) last.set(o.taxonID, t);
  }
  return new Map([...last.entries()].sort((a, b) => b[1] - a[1]).slice(0, max));
}

/**
 * Especies con algún registro (propio o del equipo, presente) a menos de `radius` m de `pos`
 * ({lat, lng}): taxonID → distancia mínima. Las generalizadas no cuentan: su punto es una celda de 1–10 km (o no hay
 * punto, nivel 1) y daría un «a 120 m» falso.
 */
export function nearFromMarks(marks, pos, radius = NEAR_M) {
  const near = new Map();
  if (!pos || !Number.isFinite(pos.lat) || !Number.isFinite(pos.lng)) return near;
  for (const o of marks || []) {
    if (!o || o.deletedAt || o.generalized || o.occurrenceStatus === 'absent') continue;
    const lat = Number(o.decimalLatitude);
    const lng = Number(o.decimalLongitude);
    if (o.decimalLatitude === null || o.decimalLatitude === undefined || !Number.isFinite(lat) || !Number.isFinite(lng)) continue;
    const d = distanceM(pos.lat, pos.lng, lat, lng);
    if (d < radius && (!near.has(o.taxonID) || near.get(o.taxonID) > d)) near.set(o.taxonID, d);
  }
  return near;
}

/** Grupos (silueta) presentes en la lista, en orden alfabético de su nombre → [{id, label, count}]. */
export function groupsOf(species) {
  const map = new Map();
  for (const sp of species || []) {
    const g = sp.silhouette && sp.silhouette.id ? sp.silhouette : { id: 'generic', label: 'organismo' };
    const cur = map.get(g.id) || { id: g.id, label: g.label, count: 0 };
    cur.count++;
    map.set(g.id, cur);
  }
  return [...map.values()].sort((a, b) => a.label.localeCompare(b.label, 'es'));
}

const groupId = (sp) => (sp.silhouette && sp.silhouette.id) || 'generic';

/**
 * Busca y ordena. → [{sp, tier: 'recent' | 'near' | 'all', score, distance?}]
 *   query   texto del buscador
 *   group   id de grupo (silueta) o '' para todos
 *   recent  Map taxonID → instante (recentFromMarks)
 *   near    Map taxonID → metros (nearFromMarks)
 */
export function searchSpecies(species, { query = '', group = '', recent = new Map(), near = new Map() } = {}) {
  const recentRank = new Map([...recent.keys()].map((id, i) => [id, i]));
  const out = [];
  for (const sp of species || []) {
    if (group && groupId(sp) !== group) continue;
    const score = matchScore(query, sp);
    if (score === null) continue;
    const tier = recentRank.has(sp.taxonID) ? 'recent' : near.has(sp.taxonID) ? 'near' : 'all';
    out.push({ sp, tier, score, distance: near.get(sp.taxonID) });
  }
  const TIER = { recent: 0, near: 1, all: 2 };
  const byName = (a, b) => canonicalOf(a.sp).localeCompare(canonicalOf(b.sp), 'es');
  const hasQuery = !!fold(query);
  return out.sort((a, b) => (hasQuery ? a.score - b.score : 0)
    || TIER[a.tier] - TIER[b.tier]
    || (a.tier === 'recent' ? recentRank.get(a.sp.taxonID) - recentRank.get(b.sp.taxonID) : 0)
    || (a.tier === 'near' ? a.distance - b.distance : 0)
    || byName(a, b));
}
