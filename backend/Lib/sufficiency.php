<?php
// ================================================================================================
// sufficiency.php — suficiencia de los datos para la evaluación UICN automática (port de shared/iucn-sufficiency.js).
// ------------------------------------------------------------------------------------------------
// Qué CALIDAD tiene cada dato que BioDex calcula desde las marcas y cuánto falta para que llegue al mínimo de la
// tabla 3.1 de las Directrices UICN v16. Reglas, umbrales y fuentes: cabecera de shared/iucn-sufficiency.js y
// bibliografia/suficiencia-de-datos.md. Casos compartidos: shared/sufficiency-cases.json (bin/test-sufficiency.php).
// ================================================================================================

require_once __DIR__ . "/utm.php";

const SUFF_LEVEL = 0.9;
const SUFF_PLOTS_BASE = 10;
const SUFF_PLOTS_HIGH_CV = 15;
const SUFF_CV_HIGH = 1;
const SUFF_EOO_CELLS = 20;
const SUFF_COMPLETENESS_PCT = 90;
const SUFF_MIN_OCCASIONS = 5;
const SUFF_ABSENCE_OCCASIONS = 3;
const SUFF_FRONTIER_PCT = 50;
const SUFF_EFFORT_RANGE = [0.5, 2];
const SUFF_RANGE_MIN_OCCASIONS = 3;
const SUFF_EXCLUSIONS = ["free", "notBlind", "effort", "track", "protocol", "range"];
const SUFF_PROTOCOL_LABEL = ["timed" => "búsqueda con tiempo fijo", "transect" => "transecto"];
const SUFF_SERIES_YEARS = 3;
const SUFF_SERIES_DF = 5;
const SUFF_POWER = 0.8;
const SUFF_AOO_CELL_M = 2000;

/** Como Math.round(v · 10^d) / 10^d de JS, con los enteros exactos como int (JSON igual que en JS). */
function suffRound(float $v, int $d = 0): int|float {
    $f = 10 ** $d;
    $r = floor($v * $f + 0.5) / $f;
    return floor($r) == $r && abs($r) < 1e15 ? (int) $r : $r;
}

/** Φ(x) (Abramowitz y Stegun 26.2.17), como normCdf() de JS. */
function suffNormCdf(float $x): float {
    $t = 1 / (1 + 0.2316419 * abs($x));
    $d = 0.3989422804014327 * exp(-$x * $x / 2);
    $p = $d * $t * (0.319381530 + $t * (-0.356563782 + $t * (1.781477937 + $t * (-1.821255978 + $t * 1.330274429))));
    return $x >= 0 ? 1 - $p : $p;
}

/** Φ⁻¹(p) (Acklam), como normInv() de JS. */
function suffNormInv(float $p): float {
    if ($p <= 0) return -INF;
    if ($p >= 1) return INF;
    $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
    $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01];
    $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
    $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];
    $lo = 0.02425;
    if ($p < $lo) {
        $q = sqrt(-2 * log($p));
        return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
    }
    if ($p > 1 - $lo) {
        $q = sqrt(-2 * log(1 - $p));
        return -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
    }
    $q = $p - 0.5;
    $r = $q * $q;
    return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
}

/** Esfuerzo de una visita en la unidad de su protocolo (minutos o metros), como effortValue() de JS. */
function suffEffortValue(array $v): ?float {
    $p = $v["effortProtocol"] ?? null;
    if ($p === "timed") { $s = iucnToNum($v["activeSeconds"] ?? null); return $s === null ? null : $s / 60; }
    if ($p === "transect") return iucnToNum($v["distanceM"] ?? null);
    return null;
}

/** Como validOccasions() de JS → valid, protocol, medianEffort, considered, excluded. */
function suffValidOccasions(?array $visits, mixed $taxonID): array {
    $excluded = array_fill_keys(SUFF_EXCLUSIONS, 0);
    $by = ["timed" => [], "transect" => []];
    $considered = 0;
    foreach ($visits ?? [] as $v) {
        if (!is_array($v) || !empty($v["deletedAt"]) || !empty($v["deleted"]) || ($v["visitStatus"] ?? $v["status"] ?? null) !== "closed") continue;
        $targets = array_map("strval", is_array($v["species"] ?? null) ? $v["species"] : []);
        if ($targets && $taxonID !== null && !in_array((string) $taxonID, $targets, true)) continue;
        $considered++;
        $p = $v["effortProtocol"] ?? null;
        if ($p !== "timed" && $p !== "transect") { $excluded["free"]++; continue; }
        if (($v["blind"] ?? null) !== true) { $excluded["notBlind"]++; continue; }
        if (($v["targetMet"] ?? null) !== true || suffEffortValue($v) === null) { $excluded["effort"]++; continue; }
        if (($v["trackComplete"] ?? null) !== true || !is_array($v["coveredCells"] ?? null)) { $excluded["track"]++; continue; }
        $by[$p][] = $v;
    }
    $protocol = !$by["timed"] && !$by["transect"] ? null : (count($by["transect"]) > count($by["timed"]) ? "transect" : "timed");
    if ($protocol === null) return ["valid" => [], "protocol" => null, "medianEffort" => null, "considered" => $considered, "excluded" => $excluded];
    $excluded["protocol"] = count($by[$protocol === "timed" ? "transect" : "timed"]);
    $pool = $by[$protocol];
    $values = array_map("suffEffortValue", $pool);
    sort($values);
    $n = count($values);
    $med = $n % 2 ? $values[($n - 1) / 2] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    $valid = array_values(array_filter($pool, fn($v) => $n < SUFF_RANGE_MIN_OCCASIONS
        || (suffEffortValue($v) >= SUFF_EFFORT_RANGE[0] * $med && suffEffortValue($v) <= SUFF_EFFORT_RANGE[1] * $med)));
    $excluded["range"] = $n - count($valid);
    return ["valid" => $valid, "protocol" => $protocol, "medianEffort" => suffRound($med, 1), "considered" => $considered, "excluded" => $excluded];
}

/**
 * Como samplingCompleteness() de JS: ocasiones = visitas válidas y comparables; celdas de 2 km cubiertas por ellas,
 * Chao2, ausencias (celdas cubiertas sin detección) y frontera prospectada.
 */
function suffSamplingCompleteness(array $records, ?array $visits = null, mixed $taxonID = null): array {
    $occ = suffValidOccasions($visits, $taxonID);
    $covered = [];
    foreach ($occ["valid"] as $v) foreach ($v["coveredCells"] as $cell) $covered[(string) $cell][$v["visitID"]] = true;
    $validIds = array_flip(array_column($occ["valid"], "visitID"));
    $all = [];
    $presence = [];
    foreach ($records as $r) {
        if (!is_array($r) || !empty($r["deletedAt"]) || ($r["occurrenceStatus"] ?? null) === "absent") continue;
        $u = iucnToNum($r["coordinateUncertaintyInMeters"] ?? null);
        if ($u !== null && $u > SUFF_AOO_CELL_M) continue;
        $x = iucnToNum($r["x"] ?? null);
        $y = iucnToNum($r["y"] ?? null);
        if ($x === null || $y === null) {
            $lat = iucnToNum($r["decimalLatitude"] ?? null);
            $lon = iucnToNum($r["decimalLongitude"] ?? null);
            if ($lat === null || $lon === null) continue;
            $p = toUtm($lat, $lon);
            $x = (float) $p["easting"];
            $y = (float) $p["northing"];
        }
        $cell = (int) floor($x / SUFF_AOO_CELL_M) . "," . (int) floor($y / SUFF_AOO_CELL_M);
        $all[$cell] = true;
        $visit = $r["visitID"] ?? null;
        if (!$visit || !isset($validIds[$visit])) continue;
        $presence[$cell][$visit] = true;
        $covered[$cell][$visit] = true;
    }
    $T = count($occ["valid"]);
    $sObs = count($presence);
    $q1 = $q2 = 0;
    foreach ($presence as $set) { $n = count($set); if ($n === 1) $q1++; elseif ($n === 2) $q2++; }
    $chao2 = $sObs && $T ? $sObs + (($T - 1) / $T) * ($q1 * ($q1 - 1)) / (2 * ($q2 + 1)) : null;
    $absentCells = $confirmed = 0;
    foreach ($covered as $cell => $set) {
        if (isset($all[$cell])) continue;
        $absentCells++;
        if (count($set) >= SUFF_ABSENCE_OCCASIONS) $confirmed++;
    }
    $frontier = [];
    foreach (array_keys($all) as $cell) {
        [$i, $j] = array_map("intval", explode(",", (string) $cell));
        for ($di = -1; $di <= 1; $di++) for ($dj = -1; $dj <= 1; $dj++) {
            $k = ($i + $di) . "," . ($j + $dj);
            if (!isset($all[$k])) $frontier[$k] = true;
        }
    }
    $frontierCovered = count(array_intersect_key($frontier, $covered));
    return ["occasions" => $T, "cellsPresent" => $sObs, "q1" => $q1, "q2" => $q2, "chao2" => $chao2 === null ? null : suffRound($chao2, 3),
            "completenessPct" => $chao2 ? suffRound(100 * $sObs / $chao2, 1) : null, "absentCells" => $absentCells, "confirmedAbsentCells" => $confirmed,
            "coveredCells" => count($covered), "frontierCells" => count($frontier), "frontierCovered" => $frontierCovered,
            "frontierPct" => $frontier ? suffRound(100 * $frontierCovered / count($frontier), 1) : null, "cellsAll" => count($all),
            "protocol" => $occ["protocol"], "medianEffort" => $occ["medianEffort"], "considered" => $occ["considered"], "excluded" => $occ["excluded"]];
}

/** Celdas con presencia de cualquier registro (las evaluaciones anteriores a F11j solo traen cellsPresent). */
function suffCellsAll(array $c): int {
    return (int) ($c["cellsAll"] ?? $c["cellsPresent"] ?? 0);
}

function suffRangeQuality(?array $c): array {
    if (!$c || !suffCellsAll($c)) return ["eoo" => null, "aoo" => null, "completenessOk" => false];
    $frontierOk = !array_key_exists("frontierPct", $c) || ($c["frontierPct"] !== null && $c["frontierPct"] >= SUFF_FRONTIER_PCT);
    $ok = $c["occasions"] >= SUFF_MIN_OCCASIONS && $c["completenessPct"] !== null && $c["completenessPct"] >= SUFF_COMPLETENESS_PCT && $frontierOk;
    return ["eoo" => $ok || suffCellsAll($c) >= SUFF_EOO_CELLS ? "estimated" : "inferred", "aoo" => $ok ? "estimated" : "inferred", "completenessOk" => $ok];
}

/** Como occasionsDetail() de JS: ocasiones usadas, excluidas por motivo y celdas cubiertas. */
function suffOccasionsDetail(?array $c): string {
    if (!$c || !isset($c["excluded"])) return "";
    $e = $c["excluded"];
    $n1 = fn($n, $one, $many) => "{$n} " . ($n === 1 ? $one : $many);
    $parts = array_values(array_filter([
        $e["notBlind"] ? $n1($e["notBlind"], "no ciega", "no ciegas") : "",
        $e["free"] ? "{$e["free"]} de protocolo libre" : "",
        $e["effort"] ? "{$e["effort"]} con esfuerzo insuficiente" : "",
        $e["range"] ? "{$e["range"]} con esfuerzo fuera de rango" : "",
        $e["track"] ? "{$e["track"]} con recorrido incompleto" : "",
        $e["protocol"] ? "{$e["protocol"]} de otro protocolo" : "",
    ]));
    $used = $c["occasions"]
        ? $n1($c["occasions"], "ocasión usada", "ocasiones usadas") . " (" . SUFF_PROTOCOL_LABEL[$c["protocol"]] . ", mediana " . suffTxt($c["medianEffort"]) . " " . ($c["protocol"] === "timed" ? "min" : "m") . ")"
        : "Ninguna ocasión válida";
    return "{$used} de " . $n1($c["considered"], "visita", "visitas") . " · excluidas: " . ($parts ? implode(", ", $parts) : "ninguna") . " · " . $n1($c["coveredCells"], "celda cubierta", "celdas cubiertas") . ".";
}

function suffSeriesQuality(?array $s): array {
    if (!$s || ($s["status"] ?? null) !== "ok") return ["a" => null, "c1" => null];
    $dfOk = is_int($s["df"] ?? null) && $s["df"] >= SUFF_SERIES_DF;
    if (!$dfOk) return ["a" => "inferred", "c1" => "inferred"];
    return ["a" => !empty($s["window"]["projected"]) ? "projected" : "estimated", "c1" => "estimated"];
}

const SUFF_QLABEL = ["observed" => "observado", "estimated" => "estimado", "projected" => "proyectado", "inferred" => "inferido (cota o indicio)", "suspected" => "sospechado"];

/** Número con coma decimal, como String(n).replace('.', ',') de JS. */
function suffTxt(int|float $n): string {
    if (is_float($n) && floor($n) == $n && abs($n) < 1e15) $n = (int) $n;
    return str_replace(".", ",", (string) $n);
}

function suffItem(string $id, string $label, int|float|null $value, int|float $target, array $o = []): array {
    $v = $value ?? 0;
    $met = $v >= $target;
    return ["id" => $id, "label" => $label, "value" => $value, "target" => $target, "unit" => $o["unit"] ?? "", "boolean" => $o["boolean"] ?? false,
            "met" => $met, "progress" => $met ? 1 : suffRound(max(0, min(1, $v / $target)), 3), "ref" => $o["ref"] ?? "", "hint" => $o["hint"] ?? ""];
}

/** Port de requirements(): grupos de requisitos mínimos con su progreso. */
function suffRequirements(array $q, ?array $series = null): array {
    $m = $q["mature"] ?? [];
    $c = $q["completeness"] ?? null;
    $rq = suffRangeQuality($c);
    $groups = [];
    $note = !$c || !suffCellsAll($c) ? "Sin presencias: no hay EOO ni AOO."
        : "Calidad: EOO " . SUFF_QLABEL[$rq["eoo"]] . "; AOO " . SUFF_QLABEL[$rq["aoo"]] . "."
          . ($rq["aoo"] === "inferred" ? " Mientras la AOO sea inferida es solo una cota inferior: B2 no se puede cumplir (tabla 3.1 pide estimada)." : "")
          . ($c["absentCells"] ? " Ausencias: {$c["absentCells"]} " . ($c["absentCells"] === 1 ? "celda cubierta" : "celdas cubiertas") . " sin detección, {$c["confirmedAbsentCells"]} confirmadas (≥ " . SUFF_ABSENCE_OCCASIONS . " ocasiones)." : "");
    $groups[] = ["id" => "range", "title" => "Distribución: EOO y AOO", "criteria" => ["B"], "quality" => ["eoo" => $rq["eoo"], "aoo" => $rq["aoo"]],
        "items" => [
            suffItem("occasions", "Esfuerzo estandarizado: ocasiones válidas", $c ? $c["occasions"] : 0, SUFF_MIN_OCCASIONS,
                ["unit" => "ocasiones", "ref" => "BioDex (Chao 1987; Goff et al. 1982)",
                 "hint" => "Visitas en modo búsqueda, con tiempo fijo o transecto, que alcanzaron el esfuerzo objetivo con el recorrido completo y un esfuerzo comparable."]),
            suffItem("completeness", "Completitud del muestreo (Chao2 sobre celdas de 2 km cubiertas)", $c ? $c["completenessPct"] : 0, SUFF_COMPLETENESS_PCT,
                ["unit" => "%", "ref" => "Chao 1987; Colwell y Coddington 1994; Marsh et al. 2023", "hint" => "Sube cuando las celdas ocupadas se vuelven a encontrar en ocasiones distintas y deja de aparecer ocupación nueva."]),
            suffItem("frontier", "Frontera prospectada (celdas vecinas a las ocupadas, cubiertas)", $c && array_key_exists("frontierPct", $c) ? $c["frontierPct"] : 0, SUFF_FRONTIER_PCT,
                ["unit" => "%", "ref" => "BioDex", "hint" => $c && !empty($c["frontierCells"])
                    ? "{$c["frontierCovered"]} de {$c["frontierCells"]} celdas vecinas cubiertas por una ocasión válida. Volver siempre a las mismas celdas no demuestra que no haya más."
                    : "Celdas vecinas a las ocupadas que ha cubierto alguna ocasión válida."]),
            suffItem("eooCells", "Celdas de 2 km con presencia (EOO estable)", $c ? suffCellsAll($c) : 0, SUFF_EOO_CELLS,
                ["unit" => "celdas", "ref" => "Syfert et al. 2014 (vía Marsh et al. 2023)", "hint" => "Con 20 o más, el EOO es estimado aunque falte completitud."]),
        ], "note" => $note, "detail" => suffOccasionsDetail($c)];

    $plotsTarget = $m["plotsRequired"] ?? SUFF_PLOTS_BASE;
    $cv = $m["density"]["cv"] ?? null;
    $tq = $m["totalQuality"] ?? null;
    $groups[] = ["id" => "mature", "title" => "Individuos maduros", "criteria" => ["C", "D"], "quality" => ["mature" => $tq],
        "items" => [
            suffItem("census", "Censo completo declarado (hecho «censo completo» = sí)", ($m["method"] ?? null) === "census" ? 1 : 0, 1,
                ["boolean" => true, "ref" => "Iriondo 2011 (AFA)", "hint" => "Si se han contado todos los individuos de la población, el total es observado. Es una vía; las parcelas son la otra."]),
            suffItem("plots", "Parcelas con superficie contada", $m["nPlots"] ?? 0, $plotsTarget,
                ["unit" => "parcelas", "ref" => "Elzinga et al. 1998", "hint" => $cv !== null
                    ? "CV de la densidad " . suffTxt($cv) . ": se piden {$plotsTarget} parcelas (" . SUFF_PLOTS_HIGH_CV . " si el CV es > " . SUFF_CV_HIGH . ")."
                    : "Se piden " . SUFF_PLOTS_BASE . " (" . SUFF_PLOTS_HIGH_CV . " si la densidad varía mucho)."]),
            suffItem("habitat", "Superficie de hábitat ocupado (parámetro)", ($m["areaSource"] ?? null) === "habitat" ? 1 : 0, 1,
                ["boolean" => true, "ref" => "Directrices v16 §4.3", "hint" => "Densidad × superficie; la proporción ocupada debe salir del conocimiento del taxón."]),
        ],
        "note" => ($m["min"] ?? null) === null ? "Sin recuentos de individuos maduros."
            : "Calidad del total: " . (SUFF_QLABEL[$tq] ?? "—") . "." . ($tq === "inferred" ? " Es un mínimo: C y D no se pueden cumplir hasta que sea estimado u observado (tabla 3.1)." : "")];

    $s = $series && !empty($series["status"]) ? $series : null;
    $sq = suffSeriesQuality($s);
    $ok = $s && $s["status"] === "ok";
    $groups[] = ["id" => "series", "title" => "Serie temporal: reducción y tasa", "criteria" => ["A", "C1"], "quality" => ["a" => $sq["a"], "c1" => $sq["c1"]],
        "items" => [
            suffItem("years", "Años con recuentos en sitios", $s ? ($s["nYears"] ?? 0) : 0, SUFF_SERIES_YEARS,
                ["unit" => "años", "ref" => "Directrices v16 §4.5.1", "hint" => "La reducción se mide repitiendo el mismo muestreo en los mismos sitios."]),
            suffItem("df", "Grados de libertad para la sobredispersión", $ok ? $s["df"] : 0, SUFF_SERIES_DF,
                ["unit" => "g. l.", "ref" => "BioDex (Ver Hoef y Boveng 2007)", "hint" => "Recuentos sitio-año menos parámetros del modelo. Más sitios o más años."]),
            suffItem("power", "Potencia para detectar la reducción de VU en la ventana", $ok && !empty($s["power"]) ? suffRound($s["power"]["power"] * 100, 1) : 0, SUFF_POWER * 100,
                ["unit" => "%", "ref" => "Gerrodette 1987; White 2019; Connors et al. 2014", "hint" => $ok && !empty($s["power"])
                    ? "Reducción mínima detectable: " . suffTxt($s["power"]["mdrPct"]) . " % en " . suffTxt($s["window"]["years"]) . " años (objetivo: {$s["power"]["targetPct"]} %)." : ""]),
        ],
        "note" => !$ok ? ($s["reason"] ?? "Sin series temporales.")
            : "Calidad: reducción (A) " . SUFF_QLABEL[$sq["a"]] . "; tasa de C1 " . SUFF_QLABEL[$sq["c1"]] . "."
              . (!empty($s["power"]) && $s["power"]["power"] < SUFF_POWER
                ? " Con poca potencia, que A no se cumpla no demuestra que no haya reducción, y una reducción grande en una serie corta puede ser una falsa alarma." : "")];
    foreach ($groups as &$g) {
        $it = $g["items"];
        $g["met"] = $g["id"] === "mature" ? ($it[0]["met"] || ($it[1]["met"] && $it[2]["met"]))
            : ($g["id"] === "range" ? $rq["completenessOk"] : $it[0]["met"] && $it[1]["met"]);
    }
    unset($g);
    return $groups;
}
