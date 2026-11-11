// ================================================================================================
// Pruebas de js/species-search.js (buscador de especies, F11f):  node frontend/tests/species-search.test.mjs
// Abreviaturas 3+3, tildes y mayúsculas, principio de palabra, grupos y orden (recientes → cerca → resto).
// ================================================================================================
import assert from 'node:assert/strict';
import { fold, abbreviation, matchScore, searchSpecies, recentFromMarks, nearFromMarks, distanceM, groupsOf,
  NEAR_M, RECENT_MAX } from '../js/species-search.js';

const plant = { id: 'flowering-plant', label: 'planta con flor' };
const bird = { id: 'bird', label: 'ave' };
const reptile = { id: 'reptile', label: 'reptil' };
const SP = [
  { taxonID: '1', scientificName: 'Pinguicula nevadensis (H.Lindb.) Casper', canonicalName: 'Pinguicula nevadensis',
    vernacularName: 'Grasilla de Sierra Nevada', silhouette: plant },
  { taxonID: '2', scientificName: 'Artemisia granatensis Boiss.', canonicalName: 'Artemisia granatensis',
    vernacularName: 'Manzanilla real', silhouette: plant },
  { taxonID: '3', scientificName: 'Aquila chrysaetos (Linnaeus, 1758)', canonicalName: 'Aquila chrysaetos',
    vernacularName: 'Águila real', silhouette: bird },
  { taxonID: '4', scientificName: 'Iberolacerta aurelioi', canonicalName: 'Iberolacerta aurelioi',
    vernacularName: 'Lagartija pallaresa', silhouette: reptile },
  { taxonID: '5', scientificName: 'Erodium cheilanthifolium subsp. cheilanthifolium', canonicalName: 'Erodium cheilanthifolium subsp. cheilanthifolium',
    vernacularName: null, silhouette: plant },
  { taxonID: '6', scientificName: 'Pinus nigra subsp. salzmannii', canonicalName: 'Pinus nigra subsp. salzmannii',
    vernacularName: 'Pino salgareño', silhouette: plant },
  { taxonID: '7', scientificName: 'Saxifraga nevadensis Boiss.', canonicalName: 'Saxifraga nevadensis',
    vernacularName: 'Saxífraga de Sierra Nevada', silhouette: plant },
];
const ids = (rows) => rows.map((r) => r.sp.taxonID);
const find = (q, opts = {}) => ids(searchSpecies(SP, { query: q, ...opts }));

// --- Texto comparable -----------------------------------------------------------------------------
assert.equal(fold('Águila  REAL'), 'aguila real');
assert.equal(fold('Saxífraga'), 'saxifraga');
assert.equal(fold('Señor'), 'senor', 'la ñ cuenta como n');
assert.equal(fold('subsp. cheilanthifolium'), 'subsp cheilanthifolium');

// --- Abreviaturas 3+3 -----------------------------------------------------------------------------
assert.equal(abbreviation(SP[0]), 'pinnev');
assert.equal(abbreviation(SP[2]), 'aquchr');
assert.equal(abbreviation(SP[4]), 'erocheche', '3+3+3 con subespecie, sin «subsp.»');
assert.equal(abbreviation({ canonicalName: 'Pinguicula' }), '', 'un género solo no tiene abreviatura');
assert.deepEqual(find('pinnev'), ['1'], 'pinnev → Pinguicula nevadensis');
assert.deepEqual(find('PINNEV'), ['1'], 'sin mayúsculas');
assert.deepEqual(find('aquchr'), ['3']);
assert.deepEqual(find('pinnig'), ['6'], 'Pinus nigra subsp. → pinnig');
assert.deepEqual(find('erocheche'), ['5']);
assert.equal(matchScore('pinnev', SP[0]), 0, 'abreviatura exacta = la mejor coincidencia');
assert.equal(matchScore('pinne', SP[0]), 1, 'a medio escribir (≥ 4 letras) también vale');
assert.equal(matchScore('pin', SP[0]), 1, 'principio del género');
assert.equal(matchScore('xyzxyz', SP[0]), null);

// --- Tildes, nombre común, principio de palabra ---------------------------------------------------
assert.deepEqual(find('aguila'), ['3'], 'sin tilde encuentra «Águila»');
assert.deepEqual(find('ÁGUILA REAL'), ['3']);
assert.deepEqual(find('saxifraga'), ['7']);
assert.deepEqual(find('lagartija'), ['4']);
assert.deepEqual(find('lag pal'), ['4'], 'principios de palabra en orden');
assert.deepEqual(find('pal lag'), [], 'en otro orden no');
assert.deepEqual(find('real').sort(), ['2', '3'], 'en el nombre común: manzanilla real y águila real');
assert.deepEqual(find('nevadensis').sort(), ['1', '7']);
assert.deepEqual(find('sierra nevada').sort(), ['1', '7']);
assert.deepEqual(find('salzm'), ['6'], 'el epíteto infraespecífico también cuenta');
assert.deepEqual(find('  '), ['2', '3', '5', '4', '1', '6', '7'].sort((a, b) =>
  SP.find((s) => s.taxonID === a).canonicalName.localeCompare(SP.find((s) => s.taxonID === b).canonicalName, 'es')),
'consulta vacía = todas, alfabético por nombre científico');

// Con texto, primero lo que mejor casa: «nev» es principio de palabra en Pinguicula y Saxifraga
const nev = searchSpecies(SP, { query: 'pinnev' });
assert.equal(nev[0].sp.taxonID, '1');

// --- Grupos -----------------------------------------------------------------------------------------
assert.deepEqual(groupsOf(SP).map((g) => [g.id, g.count]), [['bird', 1], ['flowering-plant', 5], ['reptile', 1]]);
assert.deepEqual(find('', { group: 'bird' }), ['3']);
assert.deepEqual(find('real', { group: 'flowering-plant' }), ['2'], 'grupo y texto a la vez');
assert.deepEqual(groupsOf([{ taxonID: 'x' }]).map((g) => g.id), ['generic'], 'sin silueta → genérico');

// --- Recientes de este usuario ---------------------------------------------------------------------
const ME = 7;
const marks = [
  { taxonID: '4', authorId: ME, eventDate: '2026-10-15T09:00:00+02:00' },
  { taxonID: '7', authorId: ME, eventDate: '2026-10-16T12:00:00+02:00' },
  { taxonID: '4', authorId: ME, eventDate: '2026-10-16T08:00:00+02:00' },
  { taxonID: '3', authorId: 99, eventDate: '2026-10-17T08:00:00+02:00' },            // de otro: no es reciente mío
  { taxonID: '2', authorId: ME, eventDate: '2026-10-17T08:00:00+02:00', deletedAt: 'x' }, // borrada: no cuenta
];
const recent = recentFromMarks(marks, ME);
assert.deepEqual([...recent.keys()], ['7', '4'], 'la más reciente primero');
assert.equal(recentFromMarks(Array.from({ length: 20 }, (_, i) => ({ taxonID: String(i), authorId: ME,
  eventDate: `2026-10-01T${String(i).padStart(2, '0')}:00:00Z` })), ME).size, RECENT_MAX, 'como mucho RECENT_MAX');

// --- Cerca (< 500 m) -------------------------------------------------------------------------------
const here = { lat: 37.0956, lng: -3.3976 };           // Veleta, más o menos
const dLat = (m) => m / 111195;                          // metros → grados de latitud
const near = nearFromMarks([
  { taxonID: '2', decimalLatitude: here.lat + dLat(300), decimalLongitude: here.lng },
  { taxonID: '6', decimalLatitude: here.lat + dLat(120), decimalLongitude: here.lng },
  { taxonID: '1', decimalLatitude: here.lat + dLat(800), decimalLongitude: here.lng },           // lejos
  { taxonID: '5', decimalLatitude: null, decimalLongitude: null },                                  // generalizada (nivel 1)
  { taxonID: '7', decimalLatitude: here.lat, decimalLongitude: here.lng, generalized: true },         // celda: no es «cerca»
  { taxonID: '3', decimalLatitude: here.lat, decimalLongitude: here.lng, occurrenceStatus: 'absent' }, // ausencia
], here);
assert.deepEqual([...near.keys()].sort(), ['2', '6']);
assert.ok(Math.abs(near.get('6') - 120) < 2, 'distancia en metros');
assert.ok(Math.abs(distanceM(here.lat, here.lng, here.lat + dLat(NEAR_M), here.lng) - NEAR_M) < 2);
assert.equal(nearFromMarks(marks, null).size, 0, 'sin GPS no hay «cerca»');

// --- Orden por defecto: recientes → cerca → resto alfabético ----------------------------------------
const ordered = searchSpecies(SP, { recent, near });
assert.deepEqual(ids(ordered), ['7', '4', '6', '2', '3', '5', '1']);
assert.deepEqual(ordered.map((r) => r.tier), ['recent', 'recent', 'near', 'near', 'all', 'all', 'all']);
// Una reciente que además está cerca sale como reciente (una sola vez)
const both = searchSpecies(SP, { recent, near: new Map([['7', 50], ['2', 300]]) });
assert.equal(both.filter((r) => r.sp.taxonID === '7').length, 1);
assert.equal(both[0].tier, 'recent');
// Con texto: primero la mejor coincidencia; a igualdad, el orden de siempre
const withText = searchSpecies(SP, { query: 'nevadensis', recent, near });
assert.deepEqual(ids(withText), ['7', '1'], 'Saxifraga (reciente) antes que Pinguicula, las dos casan igual');
const abbrFirst = searchSpecies(SP, { query: 'saxnev', recent: new Map(), near });
assert.deepEqual(ids(abbrFirst), ['7']);

// F11h: con una visita en curso, sus especies objetivo van antes que nada (también antes que las recientes)
const visit = searchSpecies(SP, { recent, near, targets: new Set(['1', '3']) });
assert.deepEqual(ids(visit).slice(0, 2), ['3', '1'], 'objetivo primero (alfabético entre ellas: Aquila, Pinguicula)');
assert.deepEqual(visit.slice(0, 3).map((r) => r.tier), ['target', 'target', 'recent']);
assert.deepEqual(ids(searchSpecies(SP, { query: 'nevadensis', recent, near, targets: new Set(['1']) })), ['1', '7'],
  'con texto y la misma coincidencia, la objetivo antes que la reciente');

console.log('species-search: OK');
