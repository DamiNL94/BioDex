<?php
// ================================================================================================
// series.php — series temporales de recuentos por sitio para A y C1 (F11h): el cálculo DEFINITIVO.
// ------------------------------------------------------------------------------------------------
// Port función a función de shared/iucn-series.js (seriesFromVisits, poissonIrls, analyzeSeries, seriesEngineInputs).
// Mismos casos: shared/iucn-cases.json › series (generados con bin/series-reference.py; los pasa bin/test-f11h.php).
// Modelo y reglas: cabecera de shared/iucn-series.js y docs/iucn.md › «Series temporales (F11h)».
// ================================================================================================

const SERIES_Z95 = 1.959963984540054;
const SERIES_MIN_YEARS = 3;
const SERIES_FLUCT_RATIO = 10;
const SERIES_IRLS_MAX_ITER = 100;
const SERIES_IRLS_TOL = 1e-10;
const SERIES_A_BASIS = ["a", "b", "c", "d", "e"];

/** Math.round(v · 10^d) / 10^d de JS. */
function seriesRound(float $v, int $d): float {
    $f = 10 ** $d;
    return floor($v * $f + 0.5) / $f;
}

function seriesYearOf(?string $iso): ?int {
    if ($iso === null || $iso === "") return null;
    $t = strtotime(str_contains($iso, "T") || str_ends_with($iso, "Z") ? $iso : "{$iso} UTC");
    return $t === false ? null : (int) gmdate("Y", $t);
}

/**
 * Port de seriesFromVisits(). $visits: [visitID, siteID, eventStart, deletedAt?]; $occurrences: marcas con visitID,
 * taxonID, occurrenceStatus, mature_count, deletedAt?; $sites: [siteID => name]. → ["obs" => […], "skipped" => n]
 */
function seriesFromVisits(array $visits, array $occurrences, string $taxonID, array $sites = []): array {
    $byVisit = [];
    foreach ($visits as $v) if (!empty($v["siteID"]) && empty($v["deletedAt"])) $byVisit[$v["visitID"]] = $v;
    $acc = [];
    foreach ($occurrences as $o) {
        if (!empty($o["deletedAt"]) || (string) $o["taxonID"] !== $taxonID || !isset($byVisit[$o["visitID"] ?? ""])) continue;
        $a = $acc[$o["visitID"]] ?? ["mature" => 0, "unknown" => false];
        if (($o["occurrenceStatus"] ?? "present") !== "absent") {
            if (is_int($o["mature_count"] ?? null) && $o["mature_count"] >= 0) $a["mature"] += $o["mature_count"];
            else $a["unknown"] = true;
        }
        $acc[$o["visitID"]] = $a;
    }
    $obs = [];
    $skipped = 0;
    foreach ($acc as $visitID => $a) {
        $v = $byVisit[$visitID];
        $year = seriesYearOf($v["eventStart"]);
        if ($year === null) continue;
        if ($a["unknown"]) { $skipped++; continue; }
        $obs[] = ["site" => $v["siteID"], "siteName" => $sites[$v["siteID"]] ?? null, "year" => $year, "visitID" => (string) $visitID, "mature" => $a["mature"]];
    }
    usort($obs, fn($x, $y) => strcmp($x["site"], $y["site"]) ?: ($x["year"] <=> $y["year"]) ?: strcmp($x["visitID"], $y["visitID"]));
    return ["obs" => $obs, "skipped" => $skipped];
}

/** Port de invertSpd(): inversa por Cholesky; null si no es definida positiva. */
function seriesInvertSpd(array $A): ?array {
    $n = count($A);
    $L = array_fill(0, $n, array_fill(0, $n, 0.0));
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j <= $i; $j++) {
            $s = $A[$i][$j];
            for ($k = 0; $k < $j; $k++) $s -= $L[$i][$k] * $L[$j][$k];
            if ($i === $j) {
                if (!($s > 1e-300)) return null;
                $L[$i][$i] = sqrt($s);
            } else {
                $L[$i][$j] = $s / $L[$j][$j];
            }
        }
    }
    $Li = array_fill(0, $n, array_fill(0, $n, 0.0));
    for ($i = 0; $i < $n; $i++) {
        $Li[$i][$i] = 1 / $L[$i][$i];
        for ($j = 0; $j < $i; $j++) {
            $s = 0.0;
            for ($k = $j; $k < $i; $k++) $s -= $L[$i][$k] * $Li[$k][$j];
            $Li[$i][$j] = $s / $L[$i][$i];
        }
    }
    $inv = array_fill(0, $n, array_fill(0, $n, 0.0));
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j <= $i; $j++) {
            $s = 0.0;
            for ($k = $i; $k < $n; $k++) $s += $Li[$k][$i] * $Li[$k][$j];
            $inv[$i][$j] = $s;
            $inv[$j][$i] = $s;
        }
    }
    return $inv;
}

/** Port de poissonIrls(). */
function seriesPoissonIrls(array $X, array $y): ?array {
    $n = count($y);
    $p = count($X[0]);
    $mu = array_map(fn($v) => $v + 0.5, $y);
    $eta = array_map("log", $mu);
    $beta = array_fill(0, $p, 0.0);
    $dev = INF;
    $iterations = 0;
    $converged = false;
    for ($it = 1; $it <= SERIES_IRLS_MAX_ITER; $it++) {
        $iterations = $it;
        $A = array_fill(0, $p, array_fill(0, $p, 0.0));
        $b = array_fill(0, $p, 0.0);
        for ($i = 0; $i < $n; $i++) {
            $w = $mu[$i];
            $z = $eta[$i] + ($y[$i] - $mu[$i]) / $mu[$i];
            $xi = $X[$i];
            for ($j = 0; $j < $p; $j++) {
                if ($xi[$j] == 0) continue;
                $wx = $w * $xi[$j];
                $b[$j] += $wx * $z;
                for ($k = 0; $k <= $j; $k++) if ($xi[$k] != 0) $A[$j][$k] += $wx * $xi[$k];
            }
        }
        for ($j = 0; $j < $p; $j++) for ($k = 0; $k < $j; $k++) $A[$k][$j] = $A[$j][$k];
        $cov = seriesInvertSpd($A);
        if ($cov === null) return null;
        $beta = array_map(function ($row) use ($b) { $s = 0.0; foreach ($row as $k => $v) $s += $v * $b[$k]; return $s; }, $cov);
        $eta = array_map(function ($xi) use ($beta) { $s = 0.0; foreach ($xi as $k => $v) $s += $v * $beta[$k]; return $s; }, $X);
        $mu = array_map("exp", $eta);
        $d = 0.0;
        for ($i = 0; $i < $n; $i++) $d += 2 * (($y[$i] > 0 ? $y[$i] * log($y[$i] / $mu[$i]) : 0) - ($y[$i] - $mu[$i]));
        $done = abs($d - $dev) <= SERIES_IRLS_TOL * (abs($d) + 0.1);
        $dev = $d;
        if ($done) { $converged = true; break; }
    }
    $A = array_fill(0, $p, array_fill(0, $p, 0.0));
    for ($i = 0; $i < $n; $i++) for ($j = 0; $j < $p; $j++) for ($k = 0; $k < $p; $k++) $A[$j][$k] += $mu[$i] * $X[$i][$j] * $X[$i][$k];
    $cov = seriesInvertSpd($A);
    if ($cov === null) return null;
    $pearson = 0.0;
    for ($i = 0; $i < $n; $i++) $pearson += ($y[$i] - $mu[$i]) ** 2 / $mu[$i];
    return ["beta" => $beta, "cov" => $cov, "mu" => $mu, "deviance" => max(0.0, $dev), "pearson" => $pearson, "iterations" => $iterations, "converged" => $converged];
}

/** Ventana de A en años (port de aWindowYears()). */
function seriesAWindowYears(mixed $generationLength, ?array $criteria = null): float {
    $p = $criteria["criteria"]["A"]["period"] ?? ["minYears" => 10, "generations" => 3, "maxYears" => 100];
    $g = is_numeric($generationLength) ? (float) $generationLength : null;
    $w = (float) $p["minYears"];
    if ($g !== null && $g > 0) $w = max($w, $p["generations"] * $g);
    return min($w, (float) $p["maxYears"]);
}

function seriesReductionPct(float $r, float $T): float {
    return max(0.0, seriesRound(100 * (1 - exp($r * $T)), 2));
}

/** Un número JSON-amable: los enteros exactos como int (igual que JS al serializar). */
function seriesNum(float $v): int|float {
    return floor($v) == $v && abs($v) < 1e15 ? (int) $v : $v;
}

/** Port de analyzeSeries(). */
function seriesAnalyze(array $obs, mixed $generationLength = null, ?array $criteria = null): array {
    $cell = [];
    $names = [];
    foreach ($obs as $o) {
        if (!is_int($o["year"] ?? null) || !is_numeric($o["mature"] ?? null) || $o["mature"] < 0) continue;
        $k = "{$o["site"]}|{$o["year"]}";
        $m = (float) $o["mature"];
        if (!isset($cell[$k]) || $m > $cell[$k]["y"]) $cell[$k] = ["site" => (string) $o["site"], "year" => $o["year"], "y" => $m];
        if (!empty($o["siteName"]) && !isset($names[$o["site"]])) $names[$o["site"]] = $o["siteName"];
    }
    $bySite = [];
    foreach ($cell as $c) $bySite[$c["site"]][] = $c;
    $siteIds = array_keys($bySite);
    usort($siteIds, fn($a, $b) => strcmp((string) $a, (string) $b));
    $sites = [];
    foreach ($siteIds as $id) {
        $list = $bySite[$id];
        usort($list, fn($a, $b) => $a["year"] <=> $b["year"]);
        $positive = (bool) array_filter($list, fn($c) => $c["y"] > 0);
        $excluded = count($list) < 2 ? "menos de dos años" : (!$positive ? "siempre a cero" : null);
        $counts = [];
        foreach ($list as $c) $counts[$c["year"]] = seriesNum($c["y"]);
        $sites[] = ["site" => (string) $id, "name" => $names[$id] ?? null, "counts" => $counts, "included" => $excluded === null, "excluded" => $excluded];
    }
    $used = array_values(array_filter($sites, fn($s) => $s["included"]));
    $T = seriesAWindowYears($generationLength, $criteria);
    $gen = is_numeric($generationLength) && $generationLength > 0 ? (float) $generationLength : null;
    $base = ["sites" => $sites, "windowYears" => seriesNum($T), "generationLength" => $gen === null ? null : seriesNum($gen)];
    if (!$cell) return $base + ["status" => "no_data", "reason" => "Sin visitas a sitios con recuentos de la especie.", "years" => []];

    $allYears = array_values(array_unique(array_column($cell, "year")));
    sort($allYears);
    $modelYears = [];
    foreach ($used as $s) foreach (array_keys($s["counts"]) as $y) $modelYears[$y] = true;
    $modelYears = array_keys($modelYears);
    sort($modelYears);
    $span = fn($list) => $list ? ["first" => $list[0], "last" => end($list)] : ["first" => null, "last" => null];
    $yr = $span($modelYears ?: $allYears);
    $yearsOut = function () use ($allYears, $sites) {
        $out = [];
        for ($y = $allYears[0]; $y <= end($allYears); $y++) {
            $counts = array_values(array_filter($sites, fn($s) => array_key_exists($y, $s["counts"])));
            $out[] = ["year" => $y, "visited" => count($counts) > 0,
                      "total" => $counts ? seriesNum((float) array_sum(array_map(fn($s) => $s["counts"][$y], $counts))) : null,
                      "sitesVisited" => count($counts), "index" => null];
        }
        return $out;
    };
    if (count($modelYears) < SERIES_MIN_YEARS || !$used) {
        $n = count($modelYears);
        return $base + ["status" => "not_evaluable", "years" => $yearsOut(), "nSites" => count($used), "nYears" => $n,
            "firstYear" => $yr["first"], "lastYear" => $yr["last"],
            "reason" => !$used ? "Ningún sitio tiene dos o más años con recuentos."
                : "Solo {$n} " . ($n === 1 ? "año" : "años") . " con datos: hacen falta al menos " . SERIES_MIN_YEARS . "."];
    }

    $sIdx = [];
    foreach ($used as $i => $s) $sIdx[$s["site"]] = $i;
    $rows = [];
    foreach ($used as $s) foreach ($s["counts"] as $y => $v) $rows[] = ["s" => $sIdx[$s["site"]], "year" => (int) $y, "y" => (float) $v];
    usort($rows, fn($a, $b) => ($a["s"] <=> $b["s"]) ?: ($a["year"] <=> $b["year"]));
    $nS = count($used);

    $tMean = array_sum($modelYears) / count($modelYears);
    $Xt = array_map(function ($r) use ($nS, $tMean) { $x = array_fill(0, $nS + 1, 0.0); $x[$r["s"]] = 1.0; $x[$nS] = $r["year"] - $tMean; return $x; }, $rows);
    $fit = seriesPoissonIrls($Xt, array_column($rows, "y"));
    if ($fit === null) return $base + ["status" => "not_evaluable", "years" => $yearsOut(), "reason" => "El ajuste no converge con estos datos."];
    $df = count($rows) - ($nS + 1);
    $phiRaw = $df > 0 ? $fit["pearson"] / $df : null;
    $phi = max(1.0, $phiRaw ?? 1.0);
    $r = $fit["beta"][$nS];
    $se = sqrt($phi * $fit["cov"][$nS][$nS]);
    $lo = $r - SERIES_Z95 * $se;
    $hi = $r + SERIES_Z95 * $se;

    $yearTotals = [];
    foreach ($modelYears as $y) $yearTotals[$y] = 0.0;
    foreach ($rows as $x) $yearTotals[$x["year"]] += $x["y"];
    $fitYears = array_values(array_filter($modelYears, fn($y) => $yearTotals[$y] > 0));
    $yIdx = [];
    foreach (array_slice($fitYears, 1) as $i => $y) $yIdx[$y] = $nS + $i;
    $rowsI = array_values(array_filter($rows, fn($x) => $yearTotals[$x["year"]] > 0));
    $nI = $nS + count($fitYears) - 1;
    $Xi = array_map(function ($x) use ($nI, $yIdx) { $v = array_fill(0, $nI, 0.0); $v[$x["s"]] = 1.0; if (isset($yIdx[$x["year"]])) $v[$yIdx[$x["year"]]] = 1.0; return $v; }, $rowsI);
    $fitI = seriesPoissonIrls($Xi, array_column($rowsI, "y"));
    $index = [];
    foreach ($modelYears as $y) {
        if ($yearTotals[$y] == 0) { $index[$y] = 0; continue; }
        if ($fitI === null) continue;
        $g = isset($yIdx[$y]) ? $fitI["beta"][$yIdx[$y]] : 0.0;
        $s = 0.0;
        for ($i = 0; $i < $nS; $i++) $s += exp($fitI["beta"][$i] + $g);
        $index[$y] = seriesNum(seriesRound($s, 4));
    }
    $years = array_map(fn($x) => array_merge($x, ["index" => array_key_exists($x["year"], $index) ? $index[$x["year"]] : null]), $yearsOut());

    $from = $yr["last"] - $T;
    $observed = min($yr["last"] - $yr["first"], $T);
    $window = ["years" => seriesNum($T), "from" => seriesNum($from), "to" => $yr["last"], "observedYears" => seriesNum($observed),
               "observedPct" => seriesNum(seriesRound(100 * $observed / $T, 1)), "projected" => $yr["last"] - $yr["first"] < $T];

    $inWin = [];
    foreach ($years as $x) if ($x["index"] !== null && $x["year"] >= $from && $x["year"] <= $yr["last"]) $inWin[] = (float) $x["index"];
    $maxI = $inWin ? max($inWin) : null;
    $minI = $inWin ? min($inWin) : null;
    $ratio = $maxI === null ? null : ($minI > 0 ? seriesNum(seriesRound($maxI / $minI, 2)) : ($maxI > 0 ? null : 1));
    $extreme = $maxI !== null && ($minI == 0 ? $maxI > 0 : $maxI / $minI > SERIES_FLUCT_RATIO);
    $fluctuation = ["ratio" => $ratio, "zeroYear" => $minI !== null && $minI == 0 && $maxI > 0, "extreme" => $extreme, "nYears" => count($inWin)];

    $pctTxt = fn($v) => str_replace(".", ",", (string) seriesNum(seriesRound(100 * (exp($v) - 1), 1)));
    $suggestions = [];
    if ($hi < 0) {
        $suggestions[] = ["key" => "decline_b_v", "value" => "yes",
            "reason" => "El IC 95 % de la tasa anual queda por debajo de 0 ({$pctTxt($lo)} % a {$pctTxt($hi)} % al año): declive continuo de maduros."];
    }
    if ($extreme) {
        $suggestions[] = ["key" => "fluct_c_iv", "value" => "yes",
            "reason" => $fluctuation["zeroYear"] ? "El índice anual llega a 0 en la ventana: posible fluctuación extrema (compruébalo; no se aplica sola)."
                : "El índice anual varía ×" . str_replace(".", ",", (string) $ratio) . " (máx./mín.) en la ventana, más de ×" . SERIES_FLUCT_RATIO . ": posible fluctuación extrema (no se aplica sola)."];
    }
    $n6 = fn($v) => seriesNum(seriesRound($v, 6));
    $n2 = fn($v) => seriesNum(seriesRound($v, 2));
    return $base + [
        "status" => "ok", "reason" => null, "years" => $years, "nSites" => $nS, "nYears" => count($modelYears), "nObs" => count($rows),
        "firstYear" => $yr["first"], "lastYear" => $yr["last"],
        "trend" => ["r" => $n6($r), "se" => $n6($se), "lo" => $n6($lo), "hi" => $n6($hi), "phi" => seriesNum(seriesRound($phi, 4)),
                    "phiRaw" => $phiRaw === null ? null : seriesNum(seriesRound($phiRaw, 4)), "deviance" => seriesNum(seriesRound($fit["deviance"], 4)),
                    "iterations" => $fit["iterations"], "converged" => $fit["converged"],
                    "annualChangePct" => $n2(100 * (exp($r) - 1)), "annualChangeLo" => $n2(100 * (exp($lo) - 1)), "annualChangeHi" => $n2(100 * (exp($hi) - 1))],
        "window" => $window,
        "reduction" => ["best" => seriesNum(seriesReductionPct($r, $T)), "min" => seriesNum(seriesReductionPct($hi, $T)), "max" => seriesNum(seriesReductionPct($lo, $T))],
        "fluctuation" => $fluctuation,
        "suggestions" => $suggestions,
    ];
}

/** Port de seriesEngineInputs(): {A, c1AnnualRate} a partir de la serie y los hechos de A. */
function seriesEngineInputs(?array $series, array $params = []): array {
    if (!$series || ($series["status"] ?? null) !== "ok") {
        return ["A" => ["skip" => true, "pending" => "series", "reason" => $series["reason"] ?? "Sin series temporales."], "c1AnnualRate" => null];
    }
    $yes = fn($k) => ($params[$k]["value"] ?? null) === "yes";
    $past = $yes("a_reversible") && $yes("a_understood") && $yes("a_ceased") ? "A1" : "A2";
    $basis = array_values(array_filter(SERIES_A_BASIS, fn($b) => $yes("a_basis_{$b}")));
    $red = $series["reduction"];
    $items = [["subcriterion" => $past, "reductionPct" => $red, "basis" => $basis]];
    if ($yes("a_future")) {
        $items[] = ["subcriterion" => "A3", "reductionPct" => $red, "basis" => array_values(array_filter($basis, fn($b) => $b !== "a"))];
        $items[] = ["subcriterion" => "A4", "reductionPct" => $red, "basis" => $basis];
    }
    return [
        "A" => ["items" => $items, "window" => $series["window"]],
        "c1AnnualRate" => ["best" => $series["trend"]["r"], "min" => $series["trend"]["lo"], "max" => $series["trend"]["hi"]],
    ];
}
