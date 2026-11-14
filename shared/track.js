// ================================================================================================
// track.js — recorrido GPS de una visita y su esfuerzo. JS puro, sin DOM.
// ------------------------------------------------------------------------------------------------
// Port en PHP: backend/Lib/track.php (analyzeTrack y visitEffort; appendFix solo existe aquí: el filtrado se hace en
// el móvil, al grabar). Casos compartidos: shared/track-cases.json (node shared/track.test.js · bin/test-track.php).
// Documentación: docs/esfuerzo.md.
//
// Un recorrido es una lista de puntos [lat, lon, t, precisión, tÚltimo]:
//   t         segundos Unix del fix que creó el punto
//   tÚltimo   último fix recibido sin salir del paso mínimo (parado): el tiempo «vigilado» en ese punto. Sin él, una
//             búsqueda quieta de diez minutos parecería un hueco.
//
//   appendFix(track, fix)                → añade un fix {lat, lon, t, acc} filtrando (precisión, salto imposible, paso mínimo)
//   analyzeTrack(points, pauses, project) → {distanceM, gaps, trackedSeconds, cells, nPoints}
//   visitEffort(visit, analysis)          → {activeSeconds, value, unit, targetMet, trackComplete, coverage}
//   trackSegments(points, pauses)         → [[punto…]…] tramos continuos (para dibujar y para el WKT)
//
// Umbrales (TRACK): este es el ÚNICO sitio donde se configuran (y TRACK_* en track.php, que debe coincidir).
// ================================================================================================

export const TRACK = {
  maxAccuracyM: 30,     // se descartan los fixes con peor precisión
  minStepM: 10,         // paso mínimo: por debajo (o por debajo de la precisión del fix) es ruido en parado
  maxSpeedMs: 12,       // por encima es un salto imposible a pie (≈ 43 km/h)
  gapS: 120,            // sin fixes válidos durante más tiempo: hueco (no suma distancia ni celdas)
  minCoverage: 0.8,     // parte del tiempo activo que debe estar vigilada para que el recorrido sea completo
  maxPoints: 5000,
  sampleM: 250,         // un tramo largo se muestrea cada tanto para no saltarse celdas
  cellM: 2000,          // celdas de la AOO
};

const R_EARTH = 6371008.8;
const RAD = Math.PI / 180;
const round = (v, d = 0) => { const f = 10 ** d; return Math.round(v * f) / f; };

/** Distancia en metros entre dos puntos (haversine). */
export function trackDistance(lat1, lon1, lat2, lon2) {
  const dLat = (lat2 - lat1) * RAD;
  const dLon = (lon2 - lon1) * RAD;
  const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * RAD) * Math.cos(lat2 * RAD) * Math.sin(dLon / 2) ** 2;
  return 2 * R_EARTH * Math.asin(Math.min(1, Math.sqrt(a)));
}

/**
 * Añade un fix al recorrido (lo modifica y lo devuelve). → 'added' | 'still' (parado: solo avanza tÚltimo) |
 * 'accuracy' | 'jump' | 'time' | 'full'. El motivo sirve para las pruebas y para la barra de visita.
 */
export function appendFix(track, fix) {
  if (!fix || !Number.isFinite(fix.lat) || !Number.isFinite(fix.lon) || !Number.isFinite(fix.t)) return 'time';
  if (!(fix.acc <= TRACK.maxAccuracyM)) return 'accuracy';
  const t = Math.round(fix.t);
  const point = [round(fix.lat, 6), round(fix.lon, 6), t, Math.round(fix.acc), t];
  const last = track[track.length - 1];
  if (!last) { track.push(point); return 'added'; }
  const dt = t - last[4];
  if (dt <= 0) return 'time';
  const d = trackDistance(last[0], last[1], fix.lat, fix.lon);
  if (d < Math.max(TRACK.minStepM, fix.acc)) { last[4] = t; return 'still'; }
  // Tras un hueco no se juzga la velocidad: el reloj del hueco no dice cuánto se anduvo de verdad.
  if (dt <= TRACK.gapS && d / dt > TRACK.maxSpeedMs) return 'jump';
  if (track.length >= TRACK.maxPoints) { last[4] = t; return 'full'; }
  track.push(point);
  return 'added';
}

/** ¿Cae alguna pausa [t0, t1] dentro del intervalo (a, b)? Una pausa abierta (t1 nulo) llega hasta el infinito. */
function pausedBetween(pauses, a, b) {
  for (const p of pauses || []) {
    const end = p[1] === null || p[1] === undefined ? Infinity : p[1];
    if (p[0] < b && end > a) return true;
  }
  return false;
}

/** Corte entre dos puntos consecutivos: 'pause' | 'gap' | null (continuo). */
function breakBetween(a, b, pauses) {
  if (pausedBetween(pauses, a[4], b[2])) return 'pause';
  return b[2] - a[4] > TRACK.gapS ? 'gap' : null;
}

/**
 * Cifras de un recorrido ya filtrado. `pauses`: [[t0, t1 | null]…] en segundos Unix. `project(lat, lon)` →
 * {easting, northing} (UTM 30N, como la AOO). Un hueco o una pausa no suman distancia, ni tiempo, ni celdas entre sus
 * extremos. → {nPoints, distanceM, gaps, trackedSeconds, cells: ['i,j'… ordenadas]}
 */
export function analyzeTrack(points, pauses, project) {
  const pts = (points || []).filter((p) => Array.isArray(p) && p.length >= 3);
  const cells = new Set();
  const cellOf = (lat, lon) => {
    const u = project(lat, lon);
    return `${Math.floor(u.easting / TRACK.cellM)},${Math.floor(u.northing / TRACK.cellM)}`;
  };
  let distance = 0;
  let gaps = 0;
  let tracked = 0;
  pts.forEach((p, i) => {
    const tLast = p[4] === undefined || p[4] === null ? p[2] : p[4];
    tracked += Math.max(0, tLast - p[2]);
    cells.add(cellOf(p[0], p[1]));
    if (!i) return;
    const prev = pts[i - 1];
    const a = [prev[0], prev[1], prev[2], prev[3], prev[4] === undefined || prev[4] === null ? prev[2] : prev[4]];
    const cut = breakBetween(a, p, pauses);
    if (cut === 'gap') gaps++;
    if (cut) return;
    const d = trackDistance(a[0], a[1], p[0], p[1]);
    distance += d;
    tracked += p[2] - a[4];
    const n = Math.ceil(d / TRACK.sampleM);
    for (let k = 1; k < n; k++) cells.add(cellOf(a[0] + ((p[0] - a[0]) * k) / n, a[1] + ((p[1] - a[1]) * k) / n));
  });
  const cmp = (x, y) => { const [a, b] = x.split(',').map(Number); const [c, d] = y.split(',').map(Number); return a - c || b - d; };
  return { nPoints: pts.length, distanceM: round(distance, 1), gaps, trackedSeconds: tracked, cells: [...cells].sort(cmp) };
}

/** Tramos continuos del recorrido (se cortan en los huecos y en las pausas). → [[[lat, lon]…]…] con ≥ 2 puntos. */
export function trackSegments(points, pauses) {
  const out = [];
  let cur = [];
  (points || []).forEach((p, i, all) => {
    if (i) {
      const prev = all[i - 1];
      const a = [prev[0], prev[1], prev[2], prev[3], prev[4] === undefined || prev[4] === null ? prev[2] : prev[4]];
      if (breakBetween(a, p, pauses)) { if (cur.length >= 2) out.push(cur); cur = []; }
    }
    cur.push([p[0], p[1]]);
  });
  if (cur.length >= 2) out.push(cur);
  return out;
}

/** Segundos de pausa dentro de [start, end] (segundos Unix). */
export function pausedSeconds(pauses, start, end) {
  let s = 0;
  for (const p of pauses || []) {
    const a = Math.max(start, p[0]);
    const b = Math.min(end, p[1] === null || p[1] === undefined ? end : p[1]);
    if (b > a) s += b - a;
  }
  return s;
}

/**
 * Esfuerzo de una visita. `visit`: {effortProtocol, effortTarget, start, end (segundos Unix), pauses}; `analysis`: la
 * salida de analyzeTrack() (o null sin recorrido).
 *   value/unit      minutos de búsqueda activa (timed) o metros recorridos (transect); null en «libre»
 *   targetMet       value ≥ objetivo
 *   trackComplete   el recorrido vigila ≥ TRACK.minCoverage del tiempo activo
 */
export function visitEffort(visit, analysis) {
  const start = visit.start;
  const end = visit.end;
  const active = Number.isFinite(start) && Number.isFinite(end) && end >= start ? Math.round(end - start - pausedSeconds(visit.pauses, start, end)) : null;
  const a = analysis || { distanceM: 0, trackedSeconds: 0, nPoints: 0 };
  const coverage = active ? round(Math.min(1, a.trackedSeconds / active), 3) : 0;
  const protocol = visit.effortProtocol || 'free';
  const value = protocol === 'timed' ? (active === null ? null : round(active / 60, 1)) : protocol === 'transect' ? a.distanceM : null;
  const target = visit.effortTarget === null || visit.effortTarget === undefined ? null : Number(visit.effortTarget);
  return {
    activeSeconds: active, value, unit: protocol === 'timed' ? 'min' : protocol === 'transect' ? 'm' : null,
    targetMet: protocol !== 'free' && target !== null && value !== null && value >= target,
    trackComplete: a.nPoints >= 1 && coverage >= TRACK.minCoverage, coverage,
  };
}
