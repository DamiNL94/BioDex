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

/** Como samplingCompleteness() de JS: ocasiones (días UTC), celdas de 2 km con presencia, Chao2 y ausencias. */
function suffSamplingCompleteness(array $records): array {
    $days = [];
    $presence = [];
    $absence = [];
    foreach ($records as $r) {
        if (!is_array($r) || !empty($r["deletedAt"])) continue;
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
        $w = iucnWhen($r["eventDate"] ?? null);
        if (!$w) continue;
        $day = $w["day"];
        $days[$day] = true;
        $cell = (int) floor($x / SUFF_AOO_CELL_M) . "," . (int) floor($y / SUFF_AOO_CELL_M);
        if (($r["occurrenceStatus"] ?? null) === "absent") $absence[$cell][$day] = true;
        else $presence[$cell][$day] = true;
    }
    $T = count($days);
    $sObs = count($presence);
    $q1 = $q2 = 0;
    foreach ($presence as $set) { $n = count($set); if ($n === 1) $q1++; elseif ($n === 2) $q2++; }
    $chao2 = $sObs && $T ? $sObs + (($T - 1) / $T) * ($q1 * ($q1 - 1)) / (2 * ($q2 + 1)) : null;
    $absentCells = $confirmed = 0;
    foreach ($absence as $cell => $set) {
        if (isset($presence[$cell])) continue;
        $absentCells++;
        if (count($set) >= SUFF_ABSENCE_OCCASIONS) $confirmed++;
    }
    return ["occasions" => $T, "cellsPresent" => $sObs, "q1" => $q1, "q2" => $q2, "chao2" => $chao2 === null ? null : suffRound($chao2, 3),
            "completenessPct" => $chao2 ? suffRound(100 * $sObs / $chao2, 1) : null, "absentCells" => $absentCells, "confirmedAbsentCells" => $confirmed];
}

function suffRangeQuality(?array $c): array {
    if (!$c || empty($c["cellsPresent"])) return ["eoo" => null, "aoo" => null, "completenessOk" => false];
    $ok = $c["occasions"] >= SUFF_MIN_OCCASIONS && $c["completenessPct"] !== null && $c["completenessPct"] >= SUFF_COMPLETENESS_PCT;
    return ["eoo" => $ok || $c["cellsPresent"] >= SUFF_EOO_CELLS ? "estimated" : "inferred", "aoo" => $ok ? "estimated" : "inferred", "completenessOk" => $ok];
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
    $note = !$c || empty($c["cellsPresent"]) ? "Sin presencias: no hay EOO ni AOO."
        : "Calidad: EOO " . SUFF_QLABEL[$rq["eoo"]] . "; AOO " . SUFF_QLABEL[$rq["aoo"]] . "."
          . ($rq["aoo"] === "inferred" ? " Mientras la AOO sea inferida es solo una cota inferior: B2 no se puede cumplir (tabla 3.1 pide estimada)." : "")
          . ($c["absentCells"] ? " Ausencias: {$c["absentCells"]} " . ($c["absentCells"] === 1 ? "celda" : "celdas") . " sin detección, {$c["confirmedAbsentCells"]} confirmadas (≥ " . SUFF_ABSENCE_OCCASIONS . " días)." : "");
    $groups[] = ["id" => "range", "title" => "Distribución: EOO y AOO", "criteria" => ["B"], "quality" => ["eoo" => $rq["eoo"], "aoo" => $rq["aoo"]],
        "items" => [
            suffItem("occasions", "Días de muestreo de la especie", $c ? $c["occasions"] : 0, SUFF_MIN_OCCASIONS,
                ["unit" => "días", "ref" => "BioDex (Chao2: Colwell y Coddington 1994)", "hint" => "Cada día con alguna marca de la especie, presente o ausente."]),
            suffItem("completeness", "Completitud del muestreo (Chao2 sobre celdas de 2 km)", $c ? $c["completenessPct"] : 0, SUFF_COMPLETENESS_PCT,
                ["unit" => "%", "ref" => "Colwell y Coddington 1994; Marsh et al. 2023", "hint" => "Sube cuando las celdas ocupadas se vuelven a encontrar en días distintos y deja de aparecer ocupación nueva."]),
            suffItem("eooCells", "Celdas de 2 km con presencia (EOO estable)", $c ? $c["cellsPresent"] : 0, SUFF_EOO_CELLS,
                ["unit" => "celdas", "ref" => "Syfert et al. 2014 (vía Marsh et al. 2023)", "hint" => "Con 20 o más, el EOO es estimado aunque falte completitud."]),
        ], "note" => $note];

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
