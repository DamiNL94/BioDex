<?php
// ================================================================================================
// track.php — recorrido GPS de una visita y su esfuerzo. Port de shared/track.js (analyzeTrack, trackSegments,
// visitEffort): mismos resultados con shared/track-cases.json (bin/test-track.php · node shared/track.test.js).
// ------------------------------------------------------------------------------------------------
// El filtrado de los fixes (precisión, saltos imposibles, paso mínimo) se hace en el móvil al grabar (appendFix de
// track.js); aquí el servidor RECALCULA lo que se deriva del recorrido que llega —distancia, huecos, tiempo vigilado
// y celdas cubiertas— para que esas cifras no dependan de lo que diga el cliente. Documentación: docs/esfuerzo.md.
// Los umbrales TRACK_* deben coincidir con TRACK de shared/track.js.
// ================================================================================================

require_once __DIR__ . "/utm.php";

const TRACK_MAX_ACCURACY_M = 30;
const TRACK_GAP_S          = 120;
const TRACK_MIN_COVERAGE   = 0.8;
const TRACK_MAX_POINTS     = 5000;
const TRACK_SAMPLE_M       = 250;
const TRACK_CELL_M         = 2000;
const TRACK_R_EARTH        = 6371008.8;
const TRACK_MAX_PAUSES     = 200;

/** Como Math.round() de JS (mitades hacia +∞) con $d decimales. */
function trackRound(float $v, int $d = 0): float {
    $f = 10 ** $d;
    return floor($v * $f + 0.5) / $f;
}

/** Distancia en metros entre dos puntos (haversine), como trackDistance() de JS. */
function trackDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $rad = M_PI / 180;
    $dLat = ($lat2 - $lat1) * $rad;
    $dLon = ($lon2 - $lon1) * $rad;
    $a = sin($dLat / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin($dLon / 2) ** 2;
    return 2 * TRACK_R_EARTH * asin(min(1, sqrt($a)));
}

function trackPausedBetween(array $pauses, float $a, float $b): bool {
    foreach ($pauses as $p) {
        $end = ($p[1] ?? null) === null ? INF : $p[1];
        if ($p[0] < $b && $end > $a) return true;
    }
    return false;
}

/** tÚltimo de un punto (el propio t si no lo trae). */
function trackLast(array $p): float {
    return (float) ($p[4] ?? $p[2]);
}

/** Corte entre dos puntos consecutivos: "pause" | "gap" | null. */
function trackBreak(array $a, array $b, array $pauses): ?string {
    if (trackPausedBetween($pauses, trackLast($a), (float) $b[2])) return "pause";
    return $b[2] - trackLast($a) > TRACK_GAP_S ? "gap" : null;
}

/** Como analyzeTrack() de JS → nPoints, distanceM, gaps, trackedSeconds, cells (claves «i,j» ordenadas). */
function trackAnalyze(array $points, array $pauses = []): array {
    $pts = array_values(array_filter($points, fn($p) => is_array($p) && count($p) >= 3));
    $cells = [];
    $cellOf = function (float $lat, float $lon) use (&$cells) {
        $u = toUtm($lat, $lon);
        $cells[(int) floor($u["easting"] / TRACK_CELL_M) . "," . (int) floor($u["northing"] / TRACK_CELL_M)] = true;
    };
    $distance = 0.0;
    $gaps = 0;
    $tracked = 0;
    foreach ($pts as $i => $p) {
        $tracked += max(0, trackLast($p) - $p[2]);
        $cellOf((float) $p[0], (float) $p[1]);
        if (!$i) continue;
        $a = $pts[$i - 1];
        $cut = trackBreak($a, $p, $pauses);
        if ($cut === "gap") $gaps++;
        if ($cut) continue;
        $d = trackDistance((float) $a[0], (float) $a[1], (float) $p[0], (float) $p[1]);
        $distance += $d;
        $tracked += $p[2] - trackLast($a);
        $n = (int) ceil($d / TRACK_SAMPLE_M);
        for ($k = 1; $k < $n; $k++) $cellOf($a[0] + (($p[0] - $a[0]) * $k) / $n, $a[1] + (($p[1] - $a[1]) * $k) / $n);
    }
    $keys = array_map("strval", array_keys($cells));
    usort($keys, function ($x, $y) {
        [$a, $b] = array_map("intval", explode(",", $x));
        [$c, $d] = array_map("intval", explode(",", $y));
        return $a <=> $c ?: $b <=> $d;
    });
    return ["nPoints" => count($pts), "distanceM" => trackRound($distance, 1), "gaps" => $gaps, "trackedSeconds" => (int) $tracked, "cells" => $keys];
}

/** Tramos continuos (se cortan en huecos y pausas) → [[[lat, lon]…]…] con ≥ 2 puntos. */
function trackSegments(array $points, array $pauses = []): array {
    $out = [];
    $cur = [];
    foreach (array_values($points) as $i => $p) {
        if ($i && trackBreak($points[$i - 1], $p, $pauses)) {
            if (count($cur) >= 2) $out[] = $cur;
            $cur = [];
        }
        $cur[] = [(float) $p[0], (float) $p[1]];
    }
    if (count($cur) >= 2) $out[] = $cur;
    return $out;
}

/** Recorrido → WKT en WGS84 («lon lat»): LINESTRING, MULTILINESTRING si hay cortes, o null si no hay ningún tramo. */
function trackWkt(array $points, array $pauses = []): ?string {
    $fmt = fn($v) => rtrim(rtrim(sprintf("%.6F", $v), "0"), ".");
    $lines = array_map(fn($seg) => implode(", ", array_map(fn($c) => $fmt($c[1]) . " " . $fmt($c[0]), $seg)), trackSegments($points, $pauses));
    if (!$lines) return null;
    return count($lines) === 1 ? "LINESTRING({$lines[0]})" : "MULTILINESTRING((" . implode("), (", $lines) . "))";
}

function trackPausedSeconds(array $pauses, float $start, float $end): float {
    $s = 0;
    foreach ($pauses as $p) {
        $a = max($start, $p[0]);
        $b = min($end, ($p[1] ?? null) === null ? $end : $p[1]);
        if ($b > $a) $s += $b - $a;
    }
    return $s;
}

/**
 * Como visitEffort() de JS. $visit: effortProtocol, effortTarget, start, end (segundos Unix), pauses.
 * → activeSeconds, value, unit, targetMet, trackComplete, coverage.
 */
function trackVisitEffort(array $visit, ?array $analysis): array {
    $start = $visit["start"] ?? null;
    $end = $visit["end"] ?? null;
    $active = $start !== null && $end !== null && $end >= $start
        ? (int) trackRound($end - $start - trackPausedSeconds($visit["pauses"] ?? [], $start, $end)) : null;
    $a = $analysis ?? ["distanceM" => 0, "trackedSeconds" => 0, "nPoints" => 0];
    $coverage = $active ? trackRound(min(1, $a["trackedSeconds"] / $active), 3) : 0;
    $protocol = $visit["effortProtocol"] ?? "free";
    $value = $protocol === "timed" ? ($active === null ? null : trackRound($active / 60, 1)) : ($protocol === "transect" ? $a["distanceM"] : null);
    $target = ($visit["effortTarget"] ?? null) === null ? null : (float) $visit["effortTarget"];
    return [
        "activeSeconds" => $active, "value" => $value, "unit" => $protocol === "timed" ? "min" : ($protocol === "transect" ? "m" : null),
        "targetMet" => $protocol !== "free" && $target !== null && $value !== null && $value >= $target,
        "trackComplete" => $a["nPoints"] >= 1 && $coverage >= TRACK_MIN_COVERAGE, "coverage" => $coverage,
    ];
}
