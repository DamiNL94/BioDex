<?php
// ================================================================================================
// Pruebas de la suficiencia de datos y de la calidad mínima (tabla 3.1), SIN base de datos:
//   php backend/bin/test-sufficiency.php
// Pasa shared/sufficiency-cases.json con el port en PHP (Lib/sufficiency.php, Lib/iucn.php, Lib/series.php): mismos
// resultados que node shared/iucn-engine.test.js. También los casos de maduros de shared/iucn-cases.json (BCa).
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Lib/iucn.php";
require_once __DIR__ . "/test-lib.php";   // check(), section() (sin red ni base de datos)

/** Números iguales aunque uno sea 5 y el otro 5.0 (como numEq de test-f11g.php). */
function numEq(mixed $a, mixed $b): bool {
    if (is_array($a) && is_array($b)) {
        if (array_keys($a) !== array_keys($b)) return false;
        foreach ($a as $k => $v) if (!numEq($v, $b[$k])) return false;
        return true;
    }
    if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) return abs($a - $b) < 1e-9;
    return $a === $b;
}

$sc = json_decode(file_get_contents(__DIR__ . "/../../shared/sufficiency-cases.json"), true);
$criteria = iucnCriteria();

section("Completitud del muestreo");
foreach ($sc["completeness"] as $c) {
    $got = suffSamplingCompleteness($c["records"]);
    $q = suffRangeQuality($got);
    check("Completitud: {$c["name"]}", numEq($got, $c["expect"]) && $q["eoo"] === $c["expectQuality"]["eoo"] && $q["aoo"] === $c["expectQuality"]["aoo"],
        json_encode([$got, $q], JSON_UNESCAPED_UNICODE));
}

section("Individuos maduros");
foreach ($sc["mature"] as $c) {
    $m = iucnMatureEstimate(iucnPrepareRecords($c["records"]), $c["habitatKm2"] ?? null, $c["aooKm2"] ?? null, IUCN_BOOTSTRAP_REPS, IUCN_BOOTSTRAP_SEED,
        ($c["censusComplete"] ?? false) === true);
    $got = array_intersect_key($m, array_flip(["min", "best", "max", "totalQuality", "method", "plotsRequired", "quality", "density"]));
    $got = array_merge(array_flip(["min", "best", "max", "totalQuality", "method", "plotsRequired", "quality", "density"]), $got);
    check("Maduros: {$c["name"]}", numEq($got, $c["expect"]), json_encode($got, JSON_UNESCAPED_UNICODE));
}
$cases = json_decode(file_get_contents(__DIR__ . "/../../shared/iucn-cases.json"), true);
foreach ($cases["quantifiers"] as $c) {
    if ($c["kind"] !== "mature" || empty($c["expect"]["density"])) continue;
    $m = iucnMatureEstimate(iucnPrepareRecords($c["records"]), $c["habitatKm2"] ?? null, $c["aooKm2"] ?? null);
    check("BCa (iucn-cases): {$c["name"]}", numEq($m["density"], $c["expect"]["density"]) && $m["max"] === $c["expect"]["max"], json_encode($m["density"]));
}

section("Series: potencia, grados de libertad e IC 90 %");
foreach ($sc["series"] as $c) {
    $s = seriesAnalyze($c["obs"], $c["generationLength"], $criteria);
    $got = ["df" => $s["df"], "power" => $s["power"], "lo90" => $s["trend"]["lo90"], "hi90" => $s["trend"]["hi90"], "reduction90" => $s["reduction90"]];
    check("Serie: {$c["name"]}", numEq($got, $c["expect"]), json_encode($got));
}

section("Calidad mínima en el motor (tabla 3.1)");
foreach ($sc["engine"] as $c) {
    $r = $c["fn"] === "assess" ? assessIucn($c["inputs"]) : evaluateIucn($c["inputs"]);
    $e = $c["expect"];
    $bad = [];
    foreach (["category", "criteriaString", "basis", "dataMinimum"] as $k) {
        if (array_key_exists($k, $e) && ($r[$k] ?? null) !== $e[$k]) $bad[] = "{$k} " . json_encode($r[$k] ?? null);
    }
    foreach ($e["status"] ?? [] as $k => $v) if ($r["byCriterion"][$k]["status"] !== $v) $bad[] = "{$k} {$r["byCriterion"][$k]["status"]}";
    if (!empty($e["warningIncludes"]) && !array_filter($r["warnings"], fn($w) => str_contains($w, $e["warningIncludes"]))) $bad[] = "avisos " . implode(" | ", $r["warnings"]);
    check("Calidad mínima: {$c["name"]}", !$bad, implode("; ", $bad));
}

section("Requisitos mínimos (mismo JSON que JS)");
foreach ($sc["requirements"] as $c) {
    $got = suffRequirements($c["q"], $c["series"]);
    check("Requisitos: {$c["name"]}", numEq($got, $c["expect"]), substr(json_encode($got, JSON_UNESCAPED_UNICODE), 0, 400));
}

echo $failures ? "\n{$failures} fallo(s)\n" : "\nTodo OK\n";
exit($failures ? 1 : 0);
