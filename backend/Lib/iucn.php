<?php
// ================================================================================================
// iucn.php — motor de evaluación UICN y cuantificadores de B, C y D (F11g): el cálculo DEFINITIVO.
// ------------------------------------------------------------------------------------------------
// Port de shared/iucn-engine.js (evaluateIucn, assessIucn, regionalStep2) y de shared/iucn-quantifiers.js
// (prepareRecords, matureEstimate, subpopulations, fragmentation, proposeLocations, quantify, engineInputs),
// función a función y con las mismas reglas. Los dos pasan los mismos casos: shared/iucn-cases.json
// (node shared/iucn-engine.test.js · bin/test-f11g.php). Ningún umbral está aquí: salen de
// shared/iucn-criteria.json. Métodos: docs/iucn.md.
// ================================================================================================

require_once __DIR__ . "/utm.php";

const IUCN_CRITERIA_JSON = __DIR__ . "/../../shared/iucn-criteria.json";
const IUCN_THREATS_JSON  = __DIR__ . "/../../shared/iucn-threats.json";
const IUCN_DEDUP_CELL_M = 50;
const IUCN_LAST_YEAR_MS = 365 * 86400000;
const IUCN_AOO_CELL_M = 2000;
const IUCN_BOOTSTRAP_REPS = 1000;
const IUCN_BOOTSTRAP_SEED = 20261107;
const IUCN_MINSTD_M = 2147483647;
const IUCN_MINSTD_A = 48271;
const IUCN_INTENSITY_RANK = ["high" => 3, "medium" => 2, "low" => 1];

function iucnCriteria(): array {
    static $c = null;
    if ($c === null) {
        $c = json_decode((string) @file_get_contents(IUCN_CRITERIA_JSON), true);
        if (!is_array($c) || !isset($c["criteria"])) throw new RuntimeException("No se pudo leer " . IUCN_CRITERIA_JSON);
    }
    return $c;
}

function iucnThreats(): array {
    static $t = null;
    if ($t === null) {
        $t = json_decode((string) @file_get_contents(IUCN_THREATS_JSON), true);
        if (!is_array($t) || !isset($t["threats"])) throw new RuntimeException("No se pudo leer " . IUCN_THREATS_JSON);
    }
    return $t;
}

/** Códigos de amenaza válidos (los dos niveles) → etiqueta. */
function iucnThreatLabels(): array {
    static $m = null;
    if ($m === null) {
        $m = [];
        foreach (iucnThreats()["threats"] as $t) {
            $m[$t["code"]] = $t["label"];
            foreach ($t["children"] ?? [] as $c) $m[$c["code"]] = $c["label"];
        }
    }
    return $m;
}

// --- Motor (port de evaluateIucn) ---------------------------------------------------------------

function iucnMissing(mixed $v): bool { return $v === null || $v === ""; }

/** Como num() de JS: ["value" => n] · ["missing" => true] · ["invalid" => true]. */
function iucnNum(mixed $v, float $max = INF, bool $integer = false): array {
    if (iucnMissing($v)) return ["missing" => true];
    if (is_bool($v) || is_array($v)) return ["invalid" => true];
    if (is_string($v)) { $v = str_replace(",", ".", trim($v)); if (!is_numeric($v)) return ["invalid" => true]; }
    $n = (float) $v;
    if (!is_finite($n) || $n < 0 || $n > $max || ($integer && floor($n) != $n)) return ["invalid" => true];
    return ["value" => floor($n) == $n && $n < 1e15 ? (int) $n : $n];
}

/** Como fmt() de JS: entero tal cual; si no, dos decimales como mucho y coma decimal. */
function iucnFmt(int|float $n): string {
    if (is_int($n) || floor($n) == $n) return (string) (int) $n;
    $r = round($n, 2);
    return str_replace(".", ",", rtrim(rtrim(sprintf("%.2F", $r), "0"), "."));
}

function iucnPick(mixed $list, array $allowed): array {
    if (!is_array($list)) return [];
    return array_values(array_filter($allowed, fn($x) => in_array($x, $list, true)));
}

function iucnWithItems(string $letter, array $items): string { return "{$letter}(" . implode(",", $items) . ")"; }

function iucnWindowYears(array $t, ?float $gen): float {
    $w = $t["years"];
    if (!empty($t["generations"]) && $gen) $w = max($w, $t["generations"] * $gen);
    if (!empty($t["maxYears"])) $w = min($w, $t["maxYears"]);
    return $w;
}

function iucnEmptyLevels(array $cats): array {
    $out = [];
    foreach ($cats as $c) $out[$c] = ["met" => false, "notation" => null, "reasons" => []];
    return $out;
}

function iucnFinish(array $res, array $cats): array {
    $best = null;
    foreach ($cats as $c) if ($res["levels"][$c]["met"]) { $best = $c; break; }
    $res["category"] = $best;
    $res["notation"] = $best ? $res["levels"][$best]["notation"] : null;
    if ($res["status"] !== "skipped" && $res["status"] !== "incomplete") $res["status"] = $best ? "met" : "not_met";
    return $res;
}

function iucnSkipped(array $cats, string $why = "Sin datos para este criterio."): array {
    return ["status" => "skipped", "category" => null, "notation" => null, "levels" => iucnEmptyLevels($cats), "notes" => [$why]];
}

function iucnEvalA(?array $inp, array &$ctx): array {
    $cats = $ctx["cats"];
    $A = $ctx["criteria"]["criteria"]["A"];
    $gen = $ctx["gen"];
    if (!$inp || !empty($inp["skip"])) return iucnSkipped($cats);
    $res = ["status" => "evaluated", "levels" => iucnEmptyLevels($cats), "notes" => []];
    $pct = iucnNum($inp["reductionPct"] ?? null, 100);
    $sub = $inp["subcriterion"] ?? null;
    if (isset($pct["missing"]) && !$sub) return iucnSkipped($cats);
    if (isset($pct["invalid"])) { $ctx["warnings"][] = "A: la reducción debe ser un porcentaje entre 0 y 100."; $res["status"] = "incomplete"; }
    if (!is_string($sub) || !isset($A["subcriteria"][$sub])) { $res["notes"][] = "Falta el subcriterio (A1, A2, A3 o A4)."; $res["status"] = "incomplete"; }
    $basis = iucnPick($inp["basis"] ?? null, array_keys($A["basis"]));
    $banned = is_string($sub) ? ($A["basisNotAllowed"][$sub] ?? []) : [];
    $rejected = array_values(array_filter($basis, fn($b) => in_array($b, $banned, true)));
    if ($rejected) {
        $ctx["warnings"][] = "A: {$sub} no admite la base (" . implode(", ", $rejected) . "); no se tiene en cuenta.";
        $basis = array_values(array_filter($basis, fn($b) => !in_array($b, $banned, true)));
    }
    if (!$basis) { $res["notes"][] = "Falta al menos una base (a)–(e) válida."; $res["status"] = "incomplete"; }
    if (isset($pct["missing"])) { $res["notes"][] = "Falta el porcentaje de reducción."; $res["status"] = "incomplete"; }
    $p = $A["period"];
    $period = $p["minYears"];
    if ($gen) $period = max($period, $p["generations"] * $gen);
    if (($sub === "A3" || $sub === "A4") && !empty($p["maxYears"])) $period = min($period, $p["maxYears"]);
    $res["periodYears"] = $period;
    $res["notes"][] = "Periodo de referencia: " . iucnFmt($period) . " años" . ($gen ? "" : " (sin duración de generación, solo los 10 años)") . ".";
    if ($res["status"] === "incomplete") return iucnFinish($res, $cats);
    $thr = $sub === "A1" ? $A["thresholdsPct"]["A1"] : $A["thresholdsPct"]["A2_A3_A4"];
    foreach ($cats as $c) {
        $ok = $pct["value"] >= $thr[$c];
        $res["levels"][$c]["met"] = $ok;
        $res["levels"][$c]["reasons"][] = "Reducción " . iucnFmt($pct["value"]) . " % " . ($ok ? "≥" : "<") . " {$thr[$c]} % ({$sub})";
        if ($ok) $res["levels"][$c]["notation"] = $sub . implode("", $basis);
    }
    return iucnFinish($res, $cats);
}

function iucnEvalB(?array $inp, array &$ctx): array {
    $cats = $ctx["cats"];
    $B = $ctx["criteria"]["criteria"]["B"];
    if (!$inp || !empty($inp["skip"])) return iucnSkipped($cats);
    $res = ["status" => "evaluated", "levels" => iucnEmptyLevels($cats), "notes" => []];
    $eoo = iucnNum($inp["eoo"] ?? null);
    $aoo = iucnNum($inp["aoo"] ?? null);
    $loc = iucnNum($inp["locations"] ?? null, INF, true);
    if (isset($eoo["invalid"])) $ctx["warnings"][] = "B: la EOO debe ser un número de km² ≥ 0.";
    if (isset($aoo["invalid"])) $ctx["warnings"][] = "B: la AOO debe ser un número de km² ≥ 0.";
    if (isset($loc["invalid"])) $ctx["warnings"][] = "B: el número de localidades debe ser un entero ≥ 0.";
    if (($loc["value"] ?? null) === 0) $ctx["warnings"][] = "B: 0 localidades no tiene sentido para un taxón presente; revisa el dato.";
    $hasEoo = isset($eoo["value"]);
    $hasAoo = isset($aoo["value"]);
    if (!$hasEoo && !$hasAoo) {
        if (isset($eoo["invalid"]) || isset($aoo["invalid"])) { $res["status"] = "incomplete"; return iucnFinish($res, $cats); }
        return iucnSkipped($cats, "Sin EOO ni AOO: B no se evalúa.");
    }
    if ($hasEoo && $hasAoo && $eoo["value"] < $aoo["value"]) {
        $ctx["warnings"][] = "B: la EOO (" . iucnFmt($eoo["value"]) . " km²) es menor que la AOO (" . iucnFmt($aoo["value"]) . " km²); se toma EOO = AOO.";
        $eoo = ["value" => $aoo["value"]];
    }
    $res["eoo"] = $hasEoo ? $eoo["value"] : null;
    $res["aoo"] = $hasAoo ? $aoo["value"] : null;
    $decline = iucnPick($inp["decline"] ?? null, $B["conditions"]["b"]["items"]);
    $fluct = iucnPick($inp["fluctuation"] ?? null, $B["conditions"]["c"]["items"]);
    $fragmented = ($inp["severelyFragmented"] ?? null) === true;
    $need = $B["requireAtLeast"];
    foreach ($cats as $c) {
        $lv = &$res["levels"][$c];
        $subs = [];
        $maxLoc = $B["conditions"]["a"]["maxLocations"][$c];
        $aByLoc = isset($loc["value"]) && $loc["value"] <= $maxLoc;
        if ($fragmented || $aByLoc) $subs[] = "a";
        $lv["reasons"][] = "(a) " . ($fragmented ? "severamente fragmentada ✓" : (isset($loc["value"])
            ? "{$loc["value"]} localidades " . ($aByLoc ? "≤" : ">") . " {$maxLoc} " . ($aByLoc ? "✓" : "✗") : "sin fragmentación ni localidades ✗"));
        if ($decline) $subs[] = iucnWithItems("b", $decline);
        $lv["reasons"][] = "(b) " . ($decline ? "disminución continua " . iucnWithItems("", $decline) . " ✓" : "sin disminución continua ✗");
        if ($fluct) $subs[] = iucnWithItems("c", $fluct);
        $lv["reasons"][] = "(c) " . ($fluct ? "fluctuaciones extremas " . iucnWithItems("", $fluct) . " ✓" : "sin fluctuaciones extremas ✗");
        $enough = count($subs) >= $need;
        $lv["reasons"][] = count($subs) . " de {$need} subcriterios en el umbral de {$c}" . ($enough ? " ✓" : " ✗");
        $parts = [];
        if ($hasEoo) {
            $t = $B["thresholdsKm2"]["B1_EOO"][$c];
            $in = $eoo["value"] < $t;
            $lv["reasons"][] = "B1: EOO " . iucnFmt($eoo["value"]) . " km² " . ($in ? "<" : "≥") . " {$t} " . ($in ? "✓" : "✗");
            if ($in && $enough) $parts[] = "1" . implode("", $subs);
        }
        if ($hasAoo) {
            $t = $B["thresholdsKm2"]["B2_AOO"][$c];
            $in = $aoo["value"] < $t;
            $lv["reasons"][] = "B2: AOO " . iucnFmt($aoo["value"]) . " km² " . ($in ? "<" : "≥") . " {$t} " . ($in ? "✓" : "✗");
            if ($in && $enough) $parts[] = "2" . implode("", $subs);
        }
        if ($parts) { $lv["met"] = true; $lv["notation"] = "B" . implode("+", $parts); }
        unset($lv);
    }
    return iucnFinish($res, $cats);
}

function iucnEvalC(?array $inp, array &$ctx): array {
    $cats = $ctx["cats"];
    $C = $ctx["criteria"]["criteria"]["C"];
    $gen = $ctx["gen"];
    if (!$inp || !empty($inp["skip"])) return iucnSkipped($cats);
    $res = ["status" => "evaluated", "levels" => iucnEmptyLevels($cats), "notes" => []];
    $mature = iucnNum($inp["mature"] ?? null, INF, true);
    if (isset($mature["missing"])) return iucnSkipped($cats, "Sin nº de individuos maduros: C no se evalúa.");
    if (isset($mature["invalid"])) { $ctx["warnings"][] = "C: los individuos maduros deben ser un entero ≥ 0."; $res["status"] = "incomplete"; return iucnFinish($res, $cats); }
    $c1Pct = iucnNum($inp["c1DeclinePct"] ?? null, 100);
    $c1Years = iucnNum($inp["c1Years"] ?? null);
    if (isset($c1Pct["invalid"])) $ctx["warnings"][] = "C1: la disminución debe ser un porcentaje entre 0 y 100.";
    if (isset($c1Years["invalid"])) $ctx["warnings"][] = "C1: el plazo debe ser un número de años ≥ 0.";
    if (isset($c1Pct["value"]) && !isset($c1Years["value"]) && !isset($c1Years["invalid"])) $res["notes"][] = "C1: falta el plazo (años) de la disminución.";
    $maxSub = iucnNum($inp["maxPerSubpop"] ?? null, INF, true);
    $pctOne = iucnNum($inp["pctInOneSubpop"] ?? null, 100);
    if (isset($maxSub["invalid"])) $ctx["warnings"][] = "C2a(i): el máximo por subpoblación debe ser un entero ≥ 0.";
    if (isset($pctOne["invalid"])) $ctx["warnings"][] = "C2a(ii): el porcentaje debe estar entre 0 y 100.";
    $decline = ($inp["continuingDecline"] ?? null) === true;
    $fluct = ($inp["extremeFluctuation"] ?? null) === true;
    foreach ($cats as $c) {
        $lv = &$res["levels"][$c];
        $t = $C["matureIndividualsLessThan"][$c];
        $small = $mature["value"] < $t;
        $lv["reasons"][] = "Maduros {$mature["value"]} " . ($small ? "<" : "≥") . " {$t} " . ($small ? "✓" : "✗");
        $c1 = false;
        if (isset($c1Pct["value"]) && isset($c1Years["value"])) {
            $th = $C["C1"]["thresholds"][$c];
            $w = iucnWindowYears($th, $gen);
            $c1 = $c1Pct["value"] >= $th["pct"] && $c1Years["value"] <= $w;
            $lv["reasons"][] = "C1: " . iucnFmt($c1Pct["value"]) . " % en " . iucnFmt($c1Years["value"]) . " años; exige ≥ {$th["pct"]} % en ≤ " . iucnFmt($w) . " años " . ($c1 ? "✓" : "✗");
        }
        $c2 = [];
        $aItems = [];
        if (isset($maxSub["value"])) {
            $th = $C["C2"]["a_i_maxMaturePerSubpop"][$c];
            if ($maxSub["value"] <= $th) $aItems[] = "i";
            $lv["reasons"][] = "C2a(i): máx. {$maxSub["value"]} maduros por subpoblación " . ($maxSub["value"] <= $th ? "≤" : ">") . " {$th}";
        }
        if (isset($pctOne["value"])) {
            $th = $C["C2"]["a_ii_minPctInOneSubpop"][$c];
            if ($pctOne["value"] >= $th) $aItems[] = "ii";
            $lv["reasons"][] = "C2a(ii): " . iucnFmt($pctOne["value"]) . " % en una subpoblación " . ($pctOne["value"] >= $th ? "≥" : "<") . " {$th} %";
        }
        if ($aItems) $c2[] = iucnWithItems("a", $aItems);
        if ($fluct) $c2[] = "b";
        $c2ok = $decline && count($c2) > 0;
        if ($c2 || $decline) {
            $lv["reasons"][] = "C2: " . ($decline ? "disminución continua ✓" : "sin disminución continua ✗") . "; "
                . ($c2 ? implode("", $c2) : "ni a(i), a(ii) ni b") . " " . ($c2ok ? "✓" : "✗");
        }
        if ($small && ($c1 || $c2ok)) {
            $lv["met"] = true;
            $parts = [];
            if ($c1) $parts[] = "1";
            if ($c2ok) $parts[] = "2" . implode("", $c2);
            $lv["notation"] = "C" . implode("+", $parts);
        }
        unset($lv);
    }
    if (!$decline && !isset($c1Pct["value"]) && !$res["notes"]) $res["notes"][] = "C exige C1 o C2 (con disminución continua).";
    return iucnFinish($res, $cats);
}

function iucnEvalD(?array $inp, array &$ctx, ?array $bInp): array {
    $cats = $ctx["cats"];
    $D = $ctx["criteria"]["criteria"]["D"];
    if (!$inp || !empty($inp["skip"])) return iucnSkipped($cats);
    $res = ["status" => "evaluated", "levels" => iucnEmptyLevels($cats), "notes" => []];
    $mature = iucnNum($inp["mature"] ?? null, INF, true);
    if (isset($mature["invalid"])) $ctx["warnings"][] = "D: los individuos maduros deben ser un entero ≥ 0.";
    $b = $bInp && empty($bInp["skip"]) ? $bInp : [];
    $fromB = [];
    $aoo = iucnNum($inp["aoo"] ?? null);
    if (isset($aoo["missing"]) && !iucnMissing($b["aoo"] ?? null)) { $aoo = iucnNum($b["aoo"]); $fromB[] = "AOO"; }
    $loc = iucnNum($inp["locations"] ?? null, INF, true);
    if (isset($loc["missing"]) && !iucnMissing($b["locations"] ?? null)) { $loc = iucnNum($b["locations"], INF, true); $fromB[] = "localidades"; }
    $threat = ($inp["plausibleThreat"] ?? null) === true;
    if (isset($mature["missing"]) && !$threat) return iucnSkipped($cats);
    if ($fromB && $threat) $res["notes"][] = "D2 usa " . implode(" y ", $fromB) . " del paso B.";
    $d2c = $D["D2"];
    $d2Aoo = isset($aoo["value"]) && $aoo["value"] < $d2c["aooLessThanKm2"];
    $d2Loc = isset($loc["value"]) && $loc["value"] <= $d2c["maxLocations"];
    $d2 = $threat && ($d2Aoo || $d2Loc);
    foreach ($cats as $c) {
        $lv = &$res["levels"][$c];
        $d1 = false;
        if (isset($mature["value"])) {
            $t = $D["matureIndividualsLessThan"][$c];
            $d1 = $mature["value"] < $t;
            $lv["reasons"][] = "Maduros {$mature["value"]} " . ($d1 ? "<" : "≥") . " {$t} " . ($d1 ? "✓" : "✗");
        }
        $here = $d2 && in_array($c, $d2c["categories"], true);
        if ($threat) {
            $lv["reasons"][] = in_array($c, $d2c["categories"], true)
                ? "D2: " . (isset($aoo["value"]) ? "AOO " . iucnFmt($aoo["value"]) . " km² " . ($d2Aoo ? "<" : "≥") . " {$d2c["aooLessThanKm2"]}" : "sin AOO") . " · "
                  . (isset($loc["value"]) ? "{$loc["value"]} localidades " . ($d2Loc ? "≤" : ">") . " {$d2c["maxLocations"]}" : "sin localidades")
                  . " · amenaza plausible " . ($here ? "✓" : "✗")
                : "D2 solo sirve para " . implode(", ", $d2c["categories"]);
        }
        if ($d1 && $here) { $lv["met"] = true; $lv["notation"] = "{$D["notation"][$c]}+2"; }
        elseif ($d1) { $lv["met"] = true; $lv["notation"] = $D["notation"][$c]; }
        elseif ($here) { $lv["met"] = true; $lv["notation"] = "D2"; }
        unset($lv);
    }
    return iucnFinish($res, $cats);
}

function iucnEvalE(?array $inp, array &$ctx): array {
    $cats = $ctx["cats"];
    $E = $ctx["criteria"]["criteria"]["E"];
    $gen = $ctx["gen"];
    if (!$inp || !empty($inp["skip"])) return iucnSkipped($cats);
    $res = ["status" => "evaluated", "levels" => iucnEmptyLevels($cats), "notes" => []];
    $p = iucnNum($inp["probabilityPct"] ?? null, 100);
    $h = iucnNum($inp["horizonYears"] ?? null);
    if (isset($p["missing"]) && isset($h["missing"])) return iucnSkipped($cats);
    if (isset($p["invalid"])) $ctx["warnings"][] = "E: la probabilidad debe ser un porcentaje entre 0 y 100.";
    if (isset($h["invalid"])) $ctx["warnings"][] = "E: el horizonte debe ser un número de años ≥ 0.";
    if (!isset($p["value"]) || !isset($h["value"])) {
        $res["status"] = "incomplete";
        $res["notes"][] = "E necesita la probabilidad de extinción y su horizonte en años.";
        return iucnFinish($res, $cats);
    }
    foreach ($cats as $c) {
        $th = $E["thresholds"][$c];
        $w = iucnWindowYears($th, $gen);
        $ok = $p["value"] >= $th["pct"] && $h["value"] <= $w;
        $res["levels"][$c]["reasons"][] = iucnFmt($p["value"]) . " % en " . iucnFmt($h["value"]) . " años; exige ≥ {$th["pct"]} % en ≤ " . iucnFmt($w) . " años " . ($ok ? "✓" : "✗");
        if ($ok) { $res["levels"][$c]["met"] = true; $res["levels"][$c]["notation"] = "E"; }
    }
    return iucnFinish($res, $cats);
}

const IUCN_NO_THREAT_MESSAGE = "No alcanza umbrales de amenaza: valorar NT o LC (juicio experto)";

function evaluateIucn(array $inp, ?array $criteria = null): array {
    $criteria ??= iucnCriteria();
    $cats = $criteria["categories"];
    $g = iucnNum($inp["generationLength"] ?? null);
    $ctx = ["cats" => $cats, "criteria" => $criteria, "warnings" => [], "gen" => isset($g["value"]) && $g["value"] ? (float) $g["value"] : null];
    if (isset($g["invalid"])) $ctx["warnings"][] = "La duración de una generación debe ser un número de años ≥ 0.";
    $by = [
        "A" => iucnEvalA(is_array($inp["A"] ?? null) ? $inp["A"] : null, $ctx),
        "B" => iucnEvalB(is_array($inp["B"] ?? null) ? $inp["B"] : null, $ctx),
        "C" => iucnEvalC(is_array($inp["C"] ?? null) ? $inp["C"] : null, $ctx),
        "D" => iucnEvalD(is_array($inp["D"] ?? null) ? $inp["D"] : null, $ctx, is_array($inp["B"] ?? null) ? $inp["B"] : null),
        "E" => iucnEvalE(is_array($inp["E"] ?? null) ? $inp["E"] : null, $ctx),
    ];
    $threatened = null;
    foreach ($cats as $c) {
        foreach ($by as $r) if ($r["levels"][$c]["met"]) { $threatened = $c; break 2; }
    }
    $out = $criteria["outcomes"];
    $base = null; $string = null; $determining = []; $message = null; $basis = "none";
    if ($threatened) {
        $determining = array_values(array_filter(array_keys($by), fn($k) => $by[$k]["levels"][$threatened]["met"]));
        $base = $threatened;
        $string = $threatened . " " . implode("; ", array_map(fn($k) => $by[$k]["levels"][$threatened]["notation"], $determining));
        $basis = "criteria";
        if (!empty($inp["dataDeficient"])) $ctx["warnings"][] = "Con un criterio de amenaza cumplido no procede DD: se propone la categoría del criterio.";
        if (!empty($inp["expertCategory"])) $ctx["warnings"][] = "Con un criterio de amenaza cumplido no procede {$inp["expertCategory"]} por juicio experto.";
    } elseif (!empty($inp["dataDeficient"])) {
        $base = $out["dataDeficient"];
        $basis = "dd";
        $message = "Datos insuficientes (DD), elegido por el investigador.";
    } elseif (in_array($inp["expertCategory"] ?? null, $out["expertJudgement"], true)) {
        $base = $inp["expertCategory"];
        $basis = "expert";
        $message = IUCN_NO_THREAT_MESSAGE . ". Elegido: {$base}.";
    } else {
        $message = IUCN_NO_THREAT_MESSAGE;
    }
    $regional = ["direction" => null, "from" => $base, "to" => $base, "applied" => false];
    $r = is_array($inp["regional"] ?? null) ? $inp["regional"] : [];
    $dir = $r["direction"] ?? null;
    if ($dir === "down" || $dir === "up") {
        $regional["direction"] = $dir;
        $order = $out["order"];
        $i = array_search($base, $order, true);
        $just = is_string($r["justification"] ?? null) ? trim($r["justification"]) : "";
        if ($i === false) $ctx["warnings"][] = "El ajuste regional solo se aplica a CR, EN, VU, NT o LC.";
        elseif ($out["regionalAdjustment"]["justificationRequired"] && $just === "") $ctx["warnings"][] = "El ajuste regional exige una justificación: no se ha aplicado.";
        else {
            $j = $i + ($dir === "down" ? $out["regionalAdjustment"]["steps"] : -$out["regionalAdjustment"]["steps"]);
            if ($j < 0 || $j >= count($order)) $ctx["warnings"][] = "No se puede " . ($dir === "down" ? "rebajar" : "subir") . " más allá de {$base}.";
            else { $regional["to"] = $order[$j]; $regional["applied"] = true; }
        }
    }
    return ["category" => $regional["to"], "baseCategory" => $base, "criteriaString" => $string, "determining" => $determining,
            "byCriterion" => $by, "warnings" => $ctx["warnings"], "message" => $message, "basis" => $basis, "regional" => $regional];
}

// --- Incertidumbre, DD, CR(PE) y ajuste regional v4.0 (port de assessIucn) ----------------------

const IUCN_UNCERTAIN_FIELDS = [
    "A" => ["reductionPct" => "higher"],
    "B" => ["eoo" => "lower", "aoo" => "lower", "locations" => "lower"],
    "C" => ["mature" => "lower", "maxPerSubpop" => "lower", "pctInOneSubpop" => "higher", "c1DeclinePct" => "higher"],
    "D" => ["mature" => "lower", "aoo" => "lower", "locations" => "lower"],
    "E" => ["probabilityPct" => "higher"],
];
const IUCN_CATEGORY_RANK = ["CR" => 0, "EN" => 1, "VU" => 2];

function iucnScenarioValue(mixed $v, string $dir, string $scenario): mixed {
    if (!is_array($v) || array_is_list($v)) return $v;
    $best = iucnMissing($v["best"] ?? null) ? null : $v["best"];
    $lo = iucnMissing($v["min"] ?? null) ? $best : $v["min"];
    $hi = iucnMissing($v["max"] ?? null) ? $best : $v["max"];
    if ($scenario === "best") return $best ?? $lo ?? $hi;
    $wantHigh = ($scenario === "low") === ($dir === "lower");
    if ($wantHigh && ($v["openMax"] ?? null) === true) return null;   // máximo desconocido (ver scenarioValue de JS)
    return $wantHigh ? $hi : $lo;
}

function iucnScenarioInputs(array $inputs, string $scenario): array {
    foreach (IUCN_UNCERTAIN_FIELDS as $k => $fields) {
        if (!is_array($inputs[$k] ?? null)) continue;
        foreach ($fields as $f => $dir) {
            if (array_key_exists($f, $inputs[$k])) $inputs[$k][$f] = iucnScenarioValue($inputs[$k][$f], $dir, $scenario);
        }
    }
    return $inputs;
}

/** Paso 2 de las Directrices regionales v4.0 (ver regionalStep2() de JS). */
function iucnRegionalStep2(array $a): array {
    $path = [];
    $done = function (?string $direction, string $last) use (&$path): array {
        $path[] = $last;
        return ["direction" => $direction, "path" => $path, "justification" => implode(" ", $path)];
    };
    if (($a["endemic"] ?? null) === "yes") return $done(null, "Endémica del área de evaluación: no hay ajuste regional.");
    $imm = $a["immigration"] ?? null;
    if ($imm !== "yes") {
        return $done(null, $imm === "no" ? "No recibe inmigración significativa de propágulos: sin ajuste."
            : "No se sabe si recibe inmigración significativa de propágulos: sin ajuste.");
    }
    $path[] = "Recibe inmigración significativa de propágulos capaces de reproducirse en la región.";
    $dec = $a["immigrationDecrease"] ?? null;
    if ($dec !== "no") {
        return $done(null, $dec === "yes" ? "Se espera que la inmigración disminuya: sin ajuste." : "No se sabe si la inmigración disminuirá: sin ajuste.");
    }
    $path[] = "No se espera que la inmigración disminuya.";
    $sink = $a["sink"] ?? null;
    if ($sink === "yes") return $done("up", "La población regional es un sumidero: se sube un escalón.");
    return $done("down", $sink === "no" ? "La población regional no es un sumidero: se rebaja un escalón (efecto rescate)."
        : "No se sabe si es un sumidero: se rebaja un escalón (efecto rescate).");
}

function iucnAnyEvaluated(array $r): bool {
    foreach ($r["byCriterion"] as $c) if ($c["status"] === "met" || $c["status"] === "not_met") return true;
    return false;
}

function assessIucn(array $inp, ?array $criteria = null): array {
    $criteria ??= iucnCriteria();
    $regionalV4 = null;
    if (is_array($inp["regionalV4"] ?? null)) {
        $regionalV4 = iucnRegionalStep2($inp["regionalV4"]);
        $inp["regional"] = $regionalV4["direction"] ? ["direction" => $regionalV4["direction"], "justification" => $regionalV4["justification"]]
            : ["direction" => null];
    }
    $best = evaluateIucn(iucnScenarioInputs($inp, "best"), $criteria);
    $low = evaluateIucn(iucnScenarioInputs($inp, "low"), $criteria);
    $high = evaluateIucn(iucnScenarioInputs($inp, "high"), $criteria);
    $dataMinimum = iucnAnyEvaluated($best) || iucnAnyEvaluated($low) || iucnAnyEvaluated($high);
    if (!$dataMinimum && $best["basis"] !== "criteria") {
        if ($best["basis"] === "expert") $best["warnings"][] = "Sin datos mínimos no procede {$best["baseCategory"]} por juicio experto: sale DD.";
        $best["baseCategory"] = $criteria["outcomes"]["dataDeficient"];
        $best["category"] = $best["baseCategory"];
        $best["basis"] = "insufficient";
        $best["message"] = "Datos insuficientes (DD): ningún criterio tiene los datos mínimos para evaluarse.";
        $best["regional"] = array_merge($best["regional"], ["from" => $best["category"], "to" => $best["category"], "applied" => false]);
    }
    $pe = false;
    if (($inp["possiblyExtinct"] ?? null) === true) {
        if ($best["category"] === "CR") $pe = true;
        else $best["warnings"][] = "«Posiblemente extinta» (PE) solo se aplica a CR: no se tiene en cuenta.";
    }
    if ($pe && $best["criteriaString"] && $best["baseCategory"] === "CR") $best["criteriaString"] = preg_replace('/^CR /', 'CR(PE) ', $best["criteriaString"]);
    $thr = fn($r) => isset(IUCN_CATEGORY_RANK[$r["baseCategory"] ?? ""]) ? $r["baseCategory"] : null;
    $lowCat = $thr($low);
    $highCat = $thr($high);
    $rank = fn($c) => $c === null ? 3 : IUCN_CATEGORY_RANK[$c];
    $range = ["low" => $lowCat, "lowString" => $lowCat ? $low["criteriaString"] : null, "high" => $highCat,
              "highString" => $highCat ? $high["criteriaString"] : null, "crossesCategories" => $rank($lowCat) !== $rank($highCat)];
    if ($range["crossesCategories"]) {
        $best["warnings"][] = "Los datos inciertos cruzan categorías: de " . ($lowCat ?? "no amenazada") . " a " . ($highCat ?? "no amenazada") . ".";
    }
    return $best + ["displayCategory" => $pe ? "CR(PE)" : $best["category"], "possiblyExtinct" => $pe, "range" => $range,
                    "dataMinimum" => $dataMinimum, "regionalV4" => $regionalV4];
}

// --- Cuantificadores (port de shared/iucn-quantifiers.js) ---------------------------------------

/** Generador MINSTD (Park–Miller): misma secuencia que minstd() de JS. */
function iucnMinstd(int $seed): Closure {
    $m1 = IUCN_MINSTD_M - 1;
    $s = ((($seed - 1) % $m1) + $m1) % $m1 + 1;   // en [1, M − 1]
    return function () use (&$s, $m1): float {
        $s = ($s * IUCN_MINSTD_A) % IUCN_MINSTD_M;
        return ($s - 1) / $m1;
    };
}

function iucnToNum(mixed $v): ?float {
    if ($v === null || $v === "" || is_bool($v) || is_array($v)) return null;
    return is_numeric($v) ? (float) $v : null;
}

/** Como Math.round(v * 10^d) / 10^d de JS (redondeo hacia +∞ en el .5). */
function iucnRound(float $v, int $d = 0): float {
    $f = 10 ** $d;
    return floor($v * $f + 0.5) / $f;
}

function iucnJsRoundInt(float $v): int { return (int) floor($v + 0.5); }

function iucnDist(array $a, array $b): float { return hypot($a["x"] - $b["x"], $a["y"] - $b["y"]); }

function iucnCellKey(array $p, float $size): string { return (int) floor($p["x"] / $size) . "," . (int) floor($p["y"] / $size); }

/** Fecha ISO o DATETIME de MySQL (UTC) → [t ms, día AAAA-MM-DD UTC] o null. */
function iucnWhen(mixed $iso): ?array {
    if (!is_string($iso) || $iso === "") return null;
    try {
        $d = new DateTimeImmutable($iso, new DateTimeZone("UTC"));
    } catch (Exception) {
        return null;
    }
    $d = $d->setTimezone(new DateTimeZone("UTC"));
    return ["t" => (int) $d->format("U") * 1000 + (int) $d->format("v"), "day" => $d->format("Y-m-d")];
}

function iucnPrepareRecords(array $records): array {
    $out = [];
    foreach ($records as $r) {
        if (($r["occurrenceStatus"] ?? "present") === "absent" || !empty($r["deletedAt"])) continue;
        $u = iucnToNum($r["coordinateUncertaintyInMeters"] ?? null);
        if ($u !== null && $u > IUCN_AOO_CELL_M) continue;
        $x = iucnToNum($r["x"] ?? null);
        $y = iucnToNum($r["y"] ?? null);
        $lat = iucnToNum($r["decimalLatitude"] ?? null);
        $lon = iucnToNum($r["decimalLongitude"] ?? null);
        if ($x === null || $y === null) {
            if ($lat === null || $lon === null) continue;
            $p = toUtm($lat, $lon);
            $x = (float) $p["easting"];
            $y = (float) $p["northing"];
        }
        $w = iucnWhen($r["eventDate"] ?? null);
        $m = $r["mature_count"] ?? null;
        $mature = is_int($m) && $m >= 0 ? $m : (is_string($m) && ctype_digit($m) ? (int) $m : null);
        $area = iucnToNum($r["sampleSizeValue"] ?? null);
        $author = $r["authorId"] ?? $r["user_id"] ?? $r["userId"] ?? null;
        $out[] = ["id" => (string) ($r["occurrenceID"] ?? count($out)), "x" => $x, "y" => $y, "lat" => $lat, "lon" => $lon,
                  "t" => $w["t"] ?? null, "day" => $w["day"] ?? null, "mature" => $mature, "area" => $area !== null && $area > 0 ? $area : null,
                  "author" => $author === null ? null : (string) $author];
    }
    return $out;
}

function iucnLater(array $a, array $b): bool { return $a["t"] !== $b["t"] ? $a["t"] > $b["t"] : strcmp($a["id"], $b["id"]) > 0; }

const IUCN_EVENT_WINDOW_MS = 60000;

/** Recuento más reciente por clave; las marcas del mismo autor a ≤ 60 s suman (ver latestBy() de JS). */
function iucnLatestBy(array $list, callable $keyOf): array {
    $groups = [];
    foreach ($list as $p) $groups[$keyOf($p)][] = $p;
    $out = [];
    foreach ($groups as $g) {
        $last = $g[0];
        foreach ($g as $p) if (iucnLater($p, $last)) $last = $p;
        $members = array_values(array_filter($g, fn($p) => $p["id"] === $last["id"] || ($p["author"] === $last["author"] && $last["t"] - $p["t"] <= IUCN_EVENT_WINDOW_MS)));
        usort($members, fn($a, $b) => strcmp($a["id"], $b["id"]));
        $area = null;
        foreach ($members as $p) if ($p["area"] !== null) { $area = $p["area"]; break; }
        $out[] = array_merge($last, ["mature" => array_sum(array_column($members, "mature")), "area" => $area, "members" => count($members)]);
    }
    usort($out, fn($a, $b) => strcmp($a["id"], $b["id"]));
    return $out;
}

function iucnDoubleCountPairs(array $list): int {
    $grid = [];
    foreach ($list as $p) $grid[iucnCellKey($p, IUCN_DEDUP_CELL_M)][] = $p;
    $pairs = 0;
    foreach ($list as $p) {
        $i = (int) floor($p["x"] / IUCN_DEDUP_CELL_M);
        $j = (int) floor($p["y"] / IUCN_DEDUP_CELL_M);
        for ($di = -1; $di <= 1; $di++) for ($dj = -1; $dj <= 1; $dj++) {
            foreach ($grid[($i + $di) . "," . ($j + $dj)] ?? [] as $q) {
                if (strcmp($q["id"], $p["id"]) <= 0 || $q["day"] === $p["day"]) continue;
                if (iucnDist($p, $q) < IUCN_DEDUP_CELL_M) $pairs++;
            }
        }
    }
    return $pairs;
}

function iucnBootstrapMean(array $values, int $reps = IUCN_BOOTSTRAP_REPS, int $seed = IUCN_BOOTSTRAP_SEED): array {
    $n = count($values);
    $sum = 0.0;
    foreach ($values as $v) $sum += $v;
    $mean = $sum / $n;
    if ($n < 2) return ["mean" => $mean, "low" => $mean, "high" => $mean, "reps" => 0];
    $rnd = iucnMinstd($seed);
    $means = [];
    for ($r = 0; $r < $reps; $r++) {
        $s = 0.0;
        for ($k = 0; $k < $n; $k++) $s += $values[(int) floor($rnd() * $n)];
        $means[] = $s / $n;
    }
    sort($means);
    $at = fn($q) => $means[min($reps - 1, (int) floor($q * $reps))];
    return ["mean" => $mean, "low" => $at(0.025), "high" => $at(0.975), "reps" => $reps];
}

function iucnMatureEstimate(array $points, ?float $habitatKm2 = null, ?float $aooKm2 = null, int $reps = IUCN_BOOTSTRAP_REPS, int $seed = IUCN_BOOTSTRAP_SEED): array {
    $warnings = [];
    $withCount = array_values(array_filter($points, fn($p) => $p["mature"] !== null && $p["t"] !== null));
    $from = $to = null;
    $list = [];
    if ($withCount) {
        $to = max(array_column($withCount, "t"));
        $from = $to - IUCN_LAST_YEAR_MS;
        $list = array_values(array_filter($withCount, fn($p) => $p["t"] >= $from));
    }
    $kept = iucnLatestBy($list, fn($p) => iucnCellKey($p, IUCN_DEDUP_CELL_M) . "|" . $p["day"]);
    $min = array_sum(array_column($kept, "mature"));
    $pairs = iucnDoubleCountPairs($kept);
    if ($pairs) $warnings[] = "Posible doble conteo: {$pairs} " . ($pairs === 1 ? "par" : "pares") . " de marcas a menos de " . IUCN_DEDUP_CELL_M . " m en días distintos.";
    $plots = iucnLatestBy(array_values(array_filter($list, fn($p) => $p["area"] !== null)), fn($p) => iucnCellKey($p, IUCN_DEDUP_CELL_M));
    $densities = array_map(fn($p) => $p["mature"] / ($p["area"] * 1e-6), $plots);
    $iso = fn($t) => $t === null ? null : gmdate("Y-m-d\TH:i:s", intdiv($t, 1000)) . "." . sprintf("%03d", $t % 1000) . "Z";
    $out = ["min" => $kept ? $min : null, "best" => $kept ? $min : null, "max" => $kept ? $min : null,
            "quality" => ["min" => "observed", "best" => "observed", "max" => "observed"], "method" => "observed",
            "density" => null, "areaKm2" => null, "areaSource" => null, "nCounted" => count($kept), "nPlots" => count($plots),
            "window" => $from === null ? null : ["from" => $iso($from), "to" => $iso($to)],
            "doubleCountPairs" => $pairs, "kept" => $kept, "warnings" => []];
    if (!$kept) { $warnings[] = "Sin recuentos de individuos maduros en las marcas."; return ["warnings" => $warnings] + $out; }
    if (!$plots) return ["warnings" => $warnings] + $out;
    $b = iucnBootstrapMean($densities, $reps, $seed);
    $out["density"] = ["mean" => iucnRound($b["mean"], 3), "low" => iucnRound($b["low"], 3), "high" => iucnRound($b["high"], 3),
                       "reps" => $b["reps"], "plots" => count($plots)];
    if (count($plots) < 2) $warnings[] = "Una sola parcela con superficie: sin intervalo de confianza.";
    if ($habitatKm2 !== null && $habitatKm2 > 0) {
        $out["areaKm2"] = $habitatKm2;
        $out["areaSource"] = "habitat";
        $out["method"] = "density";
        $out["best"] = max($min, iucnJsRoundInt($b["mean"] * $habitatKm2));
        $out["max"] = max($out["best"], iucnJsRoundInt($b["high"] * $habitatKm2));
        $out["quality"]["best"] = $out["quality"]["max"] = "estimated";
    } elseif ($aooKm2 !== null && $aooKm2 > 0) {
        $out["areaKm2"] = $aooKm2;
        $out["areaSource"] = "aoo";
        $out["method"] = "density-aoo-bound";
        $out["max"] = max($min, iucnJsRoundInt($b["high"] * $aooKm2));
        $out["quality"]["max"] = "estimated";
        $warnings[] = "Sin superficie de hábitat ocupado: la AOO se usa como cota superior (solo el máximo).";
    } else {
        $warnings[] = "Sin superficie de hábitat ocupado ni AOO: los maduros estimados no se calculan.";
    }
    return ["warnings" => $warnings] + $out;
}

function iucnAooCells(array $pts): array {
    $s = [];
    foreach ($pts as $p) $s[iucnCellKey($p, IUCN_AOO_CELL_M)] = true;
    return $s;
}

function iucnHull(array $points): array {
    usort($points, fn($a, $b) => $a["x"] <=> $b["x"] ?: $a["y"] <=> $b["y"]);
    if (count($points) < 3) return $points;
    $cross = fn($o, $a, $b) => ($a["x"] - $o["x"]) * ($b["y"] - $o["y"]) - ($a["y"] - $o["y"]) * ($b["x"] - $o["x"]);
    $lower = [];
    foreach ($points as $p) { while (count($lower) >= 2 && $cross($lower[count($lower) - 2], $lower[count($lower) - 1], $p) <= 0) array_pop($lower); $lower[] = $p; }
    $upper = [];
    for ($i = count($points) - 1; $i >= 0; $i--) { $p = $points[$i]; while (count($upper) >= 2 && $cross($upper[count($upper) - 2], $upper[count($upper) - 1], $p) <= 0) array_pop($upper); $upper[] = $p; }
    array_pop($lower);
    array_pop($upper);
    return array_merge($lower, $upper);
}

function iucnSubpopulations(array $points, mixed $separationKm, ?array $mature = null): array {
    $sep = iucnToNum($separationKm);
    if ($sep === null || $sep <= 0) {
        return ["subpops" => [], "c2a" => null, "separationKm" => null, "warnings" => ["Sin distancia de separación: no se calculan subpoblaciones."],
                "missing" => ["separation_distance"]];
    }
    $sepM = $sep * 1000;
    $parent = array_keys($points);
    $find = function (int $i) use (&$parent): int { while ($parent[$i] !== $i) { $parent[$i] = $parent[$parent[$i]]; $i = $parent[$i]; } return $i; };
    $grid = [];
    foreach ($points as $i => $p) $grid[iucnCellKey($p, $sepM)][] = $i;
    foreach ($points as $i => $p) {
        $ci = (int) floor($p["x"] / $sepM);
        $cj = (int) floor($p["y"] / $sepM);
        for ($di = -1; $di <= 1; $di++) for ($dj = -1; $dj <= 1; $dj++) {
            foreach ($grid[($ci + $di) . "," . ($cj + $dj)] ?? [] as $j) {
                if ($j <= $i || iucnDist($p, $points[$j]) > $sepM) continue;
                $a = $find($i);
                $b = $find($j);
                if ($a !== $b) $parent[max($a, $b)] = min($a, $b);
            }
        }
    }
    $groups = [];
    foreach ($points as $i => $p) $groups[$find($i)][] = $p;
    $list = array_map(fn($pts) => ["pts" => $pts, "minX" => min(array_column($pts, "x")), "minY" => min(array_column($pts, "y"))], array_values($groups));
    usort($list, fn($a, $b) => $a["minX"] <=> $b["minX"] ?: $a["minY"] <=> $b["minY"]);

    $keptById = [];
    foreach ($mature["kept"] ?? [] as $p) $keptById[$p["id"]] = $p;
    $allCells = iucnAooCells($points);
    $method = $mature["method"] ?? "observed";
    $subpops = [];
    foreach ($list as $k => $g) {
        $cells = iucnAooCells($g["pts"]);
        $counted = array_values(array_filter($g["pts"], fn($p) => isset($keptById[$p["id"]])));
        $minM = $counted ? array_sum(array_map(fn($p) => $keptById[$p["id"]]["mature"], $counted)) : null;
        $share = $allCells ? count($cells) / count($allCells) : 0;
        $est = fn($v) => ($v === null || $minM === null) ? $minM : max($minM, iucnJsRoundInt($v * $share));
        $cellList = array_map(fn($s) => array_map("intval", explode(",", $s)), array_keys($cells));
        usort($cellList, fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $n = count($g["pts"]);
        $subpops[] = [
            "label" => "S" . ($k + 1),
            "nRecords" => $n,
            "mature" => ["min" => $minM, "best" => $method === "density" ? $est($mature["best"]) : $minM,
                         "max" => $mature !== null && $mature["max"] !== null && $method !== "observed" ? $est($mature["max"]) : $minM],
            "cells" => $cellList,
            "hull" => array_map(fn($p) => [$p["lat"], $p["lon"]], iucnHull($g["pts"])),
            "centroid" => [array_sum(array_map(fn($p) => $p["lat"] ?? 0, $g["pts"])) / $n, array_sum(array_map(fn($p) => $p["lon"] ?? 0, $g["pts"])) / $n],
            "points" => $g["pts"],
            "nearestKm" => null,
            "nearest" => null,
        ];
    }
    $count = count($subpops);
    for ($a = 0; $a < $count; $a++) {
        for ($b = $a + 1; $b < $count; $b++) {
            $d = INF;
            foreach ($subpops[$a]["points"] as $p) foreach ($subpops[$b]["points"] as $q) $d = min($d, iucnDist($p, $q));
            $km = iucnRound($d / 1000, 3);
            foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
                if ($subpops[$x]["nearestKm"] === null || $km < $subpops[$x]["nearestKm"]) { $subpops[$x]["nearestKm"] = $km; $subpops[$x]["nearest"] = $subpops[$y]["label"]; }
            }
        }
    }
    $warnings = [];
    $with = array_values(array_filter($subpops, fn($s) => $s["mature"]["min"] !== null));
    if (count($with) < $count) $warnings[] = ($count - count($with)) . " de {$count} subpoblaciones sin recuento de maduros.";
    $c2a = null;
    if ($with) {
        $sum = fn($k) => array_sum(array_map(fn($s) => $s["mature"][$k], $with));
        $largest = fn($k) => max(array_map(fn($s) => $s["mature"][$k], $with));
        $pct = function ($k) use ($sum, $largest) { $t = $sum($k); return $t > 0 ? iucnRound($largest($k) / $t * 100, 2) : null; };
        $spread = function ($best, array $others) { $v = array_values(array_filter([$best, ...$others], fn($x) => $x !== null));
            return ["best" => $best, "min" => $v ? min($v) : null, "max" => $v ? max($v) : null]; };
        $c2a = ["maxPerSubpop" => ["best" => $largest("best"), "min" => $largest("min"), "max" => $largest("max")],
                "pctInLargest" => $spread($pct("best"), [$pct("min"), $pct("max")]), "complete" => count($with) === $count];
    }
    return ["subpops" => $subpops, "c2a" => $c2a, "separationKm" => $sep, "warnings" => $warnings, "missing" => []];
}

function iucnFragmentation(array $subpops, mixed $minViable, mixed $dispersalKm): array {
    $missing = [];
    $mv = iucnToNum($minViable);
    $dk = iucnToNum($dispersalKm);
    if ($mv === null) $missing[] = "min_viable_subpop";
    if ($dk === null) $missing[] = "dispersal_distance";
    if (!$subpops) $missing[] = "subpopulations";
    if ($missing) return ["status" => "not_evaluable", "severe" => null, "missing" => $missing, "pctAoo" => null, "pctIndividuals" => null, "bySubpop" => [], "warnings" => []];
    $all = []; $bad = []; $total = 0; $badInd = 0; $warnings = [];
    $by = [];
    foreach ($subpops as $s) {
        $m = $s["mature"]["best"];
        $small = $m === null ? null : $m < $mv;
        $isolated = $s["nearestKm"] === null || $s["nearestKm"] > $dk;
        foreach ($s["cells"] as $c) $all[implode(",", $c)] = true;
        if ($m !== null) $total += $m;
        if ($small && $isolated) { foreach ($s["cells"] as $c) $bad[implode(",", $c)] = true; $badInd += $m; }
        $by[] = ["label" => $s["label"], "small" => $small, "isolated" => $isolated, "mature" => $m, "nearestKm" => $s["nearestKm"]];
    }
    if (array_filter($by, fn($s) => $s["small"] === null)) $warnings[] = "Hay subpoblaciones sin recuento: no cuentan como pequeñas.";
    if (count($subpops) === 1) $warnings[] = "Una sola subpoblación: se considera aislada (no tiene vecina).";
    $pctAoo = $all ? iucnRound(count($bad) / count($all) * 100, 2) : 0;
    return ["status" => "evaluated", "severe" => $pctAoo > 50, "missing" => [], "pctAoo" => $pctAoo,
            "pctIndividuals" => $total > 0 ? iucnRound($badInd / $total * 100, 2) : null, "bySubpop" => $by, "warnings" => $warnings,
            "minViable" => $mv, "dispersalKm" => $dk];
}

function iucnImpactHits(array $impact, array $pts): bool {
    $poly = $impact["polygonXY"] ?? null;
    if (is_array($poly) && count($poly) >= 3) {
        foreach ($pts as $p) {
            $c = false;
            for ($i = 0, $j = count($poly) - 1; $i < count($poly); $j = $i++) {
                $a = $poly[$i]; $b = $poly[$j];
                if (($a["y"] > $p["y"]) !== ($b["y"] > $p["y"]) && $p["x"] < ($b["x"] - $a["x"]) * ($p["y"] - $a["y"]) / ($b["y"] - $a["y"]) + $a["x"]) $c = !$c;
            }
            if ($c) return true;
        }
        return false;
    }
    $r = iucnToNum($impact["radiusM"] ?? null) ?? 0.0;
    foreach ($pts as $p) if (iucnDist($p, $impact) <= $r) return true;
    return false;
}

function iucnMoreSevere(array $a, array $b): bool {
    $key = fn($i) => [IUCN_INTENSITY_RANK[$i["intensity"] ?? ""] ?? 0, iucnToNum($i["scopePct"] ?? null) ?? 0.0, ($i["timing"] ?? "") === "ongoing" ? 1 : 0];
    $ka = $key($a); $kb = $key($b);
    for ($k = 0; $k < 3; $k++) if ($ka[$k] != $kb[$k]) return $ka[$k] > $kb[$k];
    return strcmp($a["impactID"], $b["impactID"]) < 0;
}

function iucnProposeLocations(array $subpops, array $impacts): array {
    $relevant = array_values(array_filter($impacts, fn($i) => in_array($i["timing"] ?? "", ["ongoing", "future"], true)));
    $out = [];
    $index = [];
    foreach ($subpops as $s) {
        $worst = null;
        foreach ($relevant as $i) if (iucnImpactHits($i, $s["points"]) && ($worst === null || iucnMoreSevere($i, $worst))) $worst = $i;
        if ($worst === null) { $out[] = ["label" => null, "subpops" => [$s["label"]], "impactID" => null, "threatCode" => null, "noThreat" => true]; continue; }
        if (!isset($index[$worst["impactID"]])) {
            $index[$worst["impactID"]] = count($out);
            $out[] = ["label" => null, "subpops" => [], "impactID" => $worst["impactID"], "threatCode" => $worst["threatCode"], "noThreat" => false];
        }
        $out[$index[$worst["impactID"]]]["subpops"][] = $s["label"];
    }
    foreach ($out as $k => &$g) $g["label"] = "L" . ($k + 1);
    unset($g);
    return ["count" => count($out), "groups" => $out, "threatened" => count(array_filter($out, fn($g) => !$g["noThreat"]))];
}

function iucnSuggestDecline(array $impacts, array $habitatCodes): array {
    $hits = array_values(array_filter($impacts, fn($i) => ($i["timing"] ?? "") === "ongoing" && in_array($i["threatCode"] ?? "", $habitatCodes, true)));
    if (!$hits) return [];
    $n = count($hits);
    return [["key" => "decline_b_iii", "value" => "yes", "impacts" => array_column($hits, "impactID"),
             "reason" => "{$n} " . ($n === 1 ? "impacto en curso" : "impactos en curso") . " sobre el hábitat de la especie."]];
}

function iucnParamValue(array $params, string $key): mixed { return $params[$key]["value"] ?? null; }

/** Port de quantify(). $opts: params, defaults, impacts (con x/y), metrics {eooKm2, aooKm2, aooRange}, habitatCodes, seed, reps. */
function iucnQuantify(array $records, array $opts = []): array {
    $points = iucnPrepareRecords($records);
    $params = $opts["params"] ?? [];
    $metrics = $opts["metrics"] ?? [];
    $mature = iucnMatureEstimate($points, iucnToNum(iucnParamValue($params, "occupied_habitat_km2")), iucnToNum($metrics["aooKm2"] ?? null),
        $opts["reps"] ?? IUCN_BOOTSTRAP_REPS, $opts["seed"] ?? IUCN_BOOTSTRAP_SEED);
    $sepKm = iucnToNum(iucnParamValue($params, "separation_distance"));
    $fromDefault = false;
    if ($sepKm === null && !empty($opts["defaults"]["separation_distance"])) { $sepKm = (float) $opts["defaults"]["separation_distance"]["value"]; $fromDefault = true; }
    $sp = iucnSubpopulations($points, $sepKm, $mature);
    if ($fromDefault) array_unshift($sp["warnings"], "Distancia de separación por defecto del grupo (" . iucnFmt($sepKm) . " km): confírmala en Parámetros.");
    $frag = iucnFragmentation($sp["subpops"], iucnParamValue($params, "min_viable_subpop"), iucnParamValue($params, "dispersal_distance"));
    $impacts = $opts["impacts"] ?? [];
    return ["nPoints" => count($points), "mature" => $mature, "subpops" => $sp, "fragmentation" => $frag,
            "locations" => iucnProposeLocations($sp["subpops"], $impacts),
            "suggestions" => iucnSuggestDecline($impacts, $opts["habitatCodes"] ?? []), "separation" => ["km" => $sepKm, "fromDefault" => $fromDefault]];
}

/** Port de locationsCurrent(): ¿siguen valiendo las localidades confirmadas con las subpoblaciones de ahora? */
function iucnLocationsCurrent(?array $loc, array $q): bool {
    if (!$loc) return false;
    $sp = $q["subpops"] ?? [];
    $labels = array_column($sp["subpops"] ?? [], "label");
    $sep = iucnToNum($loc["separationKm"] ?? null);
    if ($sep !== null && ($sp["separationKm"] ?? null) !== null && abs($sep - $sp["separationKm"]) > 1e-9) return false;
    $assigned = [];
    foreach ($loc["groups"] ?? [] as $g) foreach ($g["subpops"] ?? [] as $s) $assigned[] = $s;
    if (!$assigned) return true;
    if (count($assigned) !== count($labels)) return false;
    foreach ($labels as $l) if (!in_array($l, $assigned, true)) return false;
    return true;
}

/** Port de engineInputs(). */
function iucnEngineInputs(array $q, array $params = [], array $metrics = [], ?array $locations = null, array $choices = []): array {
    $yes = fn($k) => iucnParamValue($params, $k) === "yes";
    $decline = array_values(array_filter(["i", "ii", "iii", "iv", "v"], fn($r) => $yes("decline_b_{$r}")));
    $fluct = array_values(array_filter(["i", "ii", "iii", "iv"], fn($r) => $yes("fluct_c_{$r}")));
    $m = $q["mature"] ?? [];
    $open = ($m["method"] ?? null) === "observed";   // solo un mínimo observado: máximo desconocido
    $matureRange = ($m["best"] ?? null) === null ? null : ["best" => $m["best"], "min" => $m["min"], "max" => $m["max"]] + ($open ? ["openMax" => true] : []);
    $c2a = !empty($q["subpops"]["c2a"]["complete"]) ? $q["subpops"]["c2a"] : null;
    if ($c2a && $open) $c2a["maxPerSubpop"]["openMax"] = true;
    if ($locations !== null && !iucnLocationsCurrent($locations, $q)) $locations = null;
    $aoo = ($metrics["aooKm2"] ?? null) === null ? null
        : ["best" => $metrics["aooKm2"], "min" => $metrics["aooRange"]["min"] ?? null, "max" => $metrics["aooRange"]["max"] ?? null];
    $regionalKeys = ["regional_endemic", "regional_immigration", "regional_immigration_decrease", "regional_sink"];
    $hasRegional = (bool) array_filter($regionalKeys, fn($k) => iucnParamValue($params, $k) !== null);
    $expert = $choices["expertCategory"] ?? null;
    return [
        "engine" => 2,
        "generationLength" => iucnToNum(iucnParamValue($params, "generation_length")),
        "A" => ["skip" => true, "pending" => "series"],
        "B" => ["eoo" => $metrics["eooKm2"] ?? null, "aoo" => $aoo, "severelyFragmented" => ($q["fragmentation"]["severe"] ?? null) === true,
                "locations" => $locations !== null && is_int($locations["count"] ?? null) ? $locations["count"] : null,
                "decline" => $decline, "fluctuation" => $fluct],
        "C" => ["mature" => $matureRange, "c1DeclinePct" => null, "c1Years" => null, "continuingDecline" => $yes("decline_b_v"),
                "maxPerSubpop" => $c2a["maxPerSubpop"] ?? null, "pctInOneSubpop" => $c2a["pctInLargest"] ?? null, "extremeFluctuation" => $yes("fluct_c_iv")],
        "D" => ["mature" => $matureRange, "plausibleThreat" => $yes("plausible_threat"), "aoo" => null, "locations" => null],
        "E" => ["skip" => true, "pending" => "series"],
        "expertCategory" => in_array($expert, ["NT", "LC"], true) ? $expert : null,
        "possiblyExtinct" => ($choices["possiblyExtinct"] ?? null) === true,
        "regionalV4" => $hasRegional ? ["endemic" => iucnParamValue($params, "regional_endemic"), "immigration" => iucnParamValue($params, "regional_immigration"),
            "immigrationDecrease" => iucnParamValue($params, "regional_immigration_decrease"), "sink" => iucnParamValue($params, "regional_sink")] : null,
    ];
}
