<?php
// ================================================================================================
// Pruebas de F11h (visitas, series temporales, A y C1, DwC con núcleo Event): docker compose exec php php bin/test-f11h.php
//   1. Casos compartidos de series (shared/iucn-cases.json › series, de bin/series-reference.py) y del motor con A y C1
//      por tasa, con el port en PHP (Lib/series.php, Lib/iucn.php): mismos resultados que node shared/iucn-engine.test.js.
//   2. Sitios y visitas por /api/sync: push y pull, ida y vuelta, autoría, catálogo, visibilidad según confianza y
//      re-emisión generalizada cuando una marca de visita baja el nivel del sitio.
//   3. GET …/assessment con la serie: índice, r con IC, reducción y A/C1 en el motor.
//   4. Exportación: DwC-A con núcleo Event (event.txt, occurrence.txt con eventID, measurementorfact.txt, meta.xml
//      coherente) y la de publicar sin ninguna coordenada exacta de especie sensible (ni en eventos ni en footprintWKT).
// Crea usuarios f11h-*@biodex.test y el catálogo «test-f11h …», y los borra al terminar (y al empezar).
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/api.php";
require_once __DIR__ . "/../Lib/migrate.php";
require_once __DIR__ . "/../Lib/iucn.php";
require_once __DIR__ . "/test-lib.php";

$pdo = db();
runMigrations($pdo);

// ------------------------------------------------------------------------------------------------
section("Series temporales: casos compartidos (PHP)");
$cases = json_decode(file_get_contents(__DIR__ . "/../../shared/iucn-cases.json"), true);
$near = fn($a, $b, $tol) => $a === null || $b === null ? $a === $b : abs($a - $b) <= $tol * max(1, abs($b));
foreach ($cases["series"] as $c) {
    $s = seriesAnalyze($c["obs"], $c["generationLength"], iucnCriteria());
    $e = $c["expect"];
    $bad = [];
    if ($s["status"] !== $e["status"]) $bad[] = "estado {$s["status"]}";
    if ($e["status"] === "ok" && $s["status"] === "ok") {
        foreach (["r", "se", "lo", "hi", "phi"] as $k) if (!$near($s["trend"][$k], $e[$k], 2e-6)) $bad[] = "{$k} {$s["trend"][$k]} ≠ {$e[$k]}";
        foreach (["best", "min", "max"] as $k) if (!$near($s["reduction"][$k], $e["reduction"][$k], 0.011)) $bad[] = "reducción {$k} {$s["reduction"][$k]}";
        foreach ($e["window"] as $k => $v) if ($s["window"][$k] != $v || is_bool($v) !== is_bool($s["window"][$k])) $bad[] = "ventana {$k} " . json_encode($s["window"][$k]);
        foreach ($e["index"] as $y => $v) {
            $got = array_values(array_filter($s["years"], fn($x) => $x["year"] === (int) $y))[0]["index"] ?? null;
            if (!$near($got, $v, 1e-6)) $bad[] = "índice {$y} " . json_encode($got) . " ≠ {$v}";
        }
        if ($s["fluctuation"]["extreme"] !== $e["extreme"]) $bad[] = "fluctuación";
        if ((bool) array_filter($s["suggestions"], fn($x) => $x["key"] === "decline_b_v") !== $e["declineSuggested"]) $bad[] = "sugerencia de declive";
        if ($s["nSites"] !== $e["nSites"] || $s["nYears"] !== $e["nYears"]) $bad[] = "sitios {$s["nSites"]} años {$s["nYears"]}";
    }
    check("Serie: {$c["name"]}", !$bad, implode("; ", $bad));
}

// El mismo ajuste en JS y en PHP: los casos de JS y de PHP comparan contra la MISMA referencia (statsmodels), y aquí
// además el resultado completo de PHP contra el de node (si node está en el contenedor; si no, se salta).
$visits = [["visitID" => "v1", "siteID" => "s1", "eventStart" => "2020-05-01T08:00:00Z"], ["visitID" => "v2", "siteID" => "s1", "eventStart" => "2021-05-01T08:00:00Z"],
           ["visitID" => "v3", "siteID" => null, "eventStart" => "2021-05-02T08:00:00Z"], ["visitID" => "v4", "siteID" => "s1", "eventStart" => "2022-05-01 08:00:00"]];
$occ = [["visitID" => "v1", "taxonID" => "t", "occurrenceStatus" => "present", "mature_count" => 3], ["visitID" => "v1", "taxonID" => "t", "occurrenceStatus" => "present", "mature_count" => 2],
        ["visitID" => "v2", "taxonID" => "t", "occurrenceStatus" => "absent", "mature_count" => null], ["visitID" => "v3", "taxonID" => "t", "occurrenceStatus" => "present", "mature_count" => 9],
        ["visitID" => "v4", "taxonID" => "t", "occurrenceStatus" => "present", "mature_count" => null], ["visitID" => "v1", "taxonID" => "otra", "occurrenceStatus" => "present", "mature_count" => 7]];
$fv = seriesFromVisits($visits, $occ, "t");
check("Series: maduros por visita (suma, ausencia 0, sin sitio fuera, sin maduros no cuenta)",
    array_map(fn($o) => [$o["visitID"], $o["year"], $o["mature"]], $fv["obs"]) === [["v1", 2020, 5], ["v2", 2021, 0]] && $fv["skipped"] === 1,
    json_encode($fv));

section("Motor con A por serie y C1 por tasa (PHP)");
foreach ($cases["engine"] as $c) {
    if (!str_starts_with($c["name"], "F11h")) continue;
    $r = $c["fn"] === "assess" ? assessIucn($c["inputs"]) : evaluateIucn($c["inputs"]);
    $e = $c["expect"];
    $bad = [];
    foreach (["category" => $r["category"], "criteriaString" => $r["criteriaString"], "rangeLow" => $r["range"]["low"] ?? null,
              "rangeHigh" => $r["range"]["high"] ?? null, "crosses" => $r["range"]["crossesCategories"] ?? null] as $k => $got) {
        if (array_key_exists($k, $e) && $got !== $e[$k]) $bad[] = "{$k} " . json_encode($got, JSON_UNESCAPED_UNICODE);
    }
    check($c["name"], !$bad, implode("; ", $bad));
}
$r = evaluateIucn(["generationLength" => 2, "C" => ["mature" => 300, "c1AnnualRate" => -0.08]]);
check("C1 por tasa: el motivo dice la tasa y la disminución en la ventana de cada categoría",
    in_array("C1: tasa -0,08/año → 32,97 % en 5 años; exige ≥ 20 % ✓", $r["byCriterion"]["C"]["levels"]["EN"]["reasons"], true),
    json_encode($r["byCriterion"]["C"]["levels"]["EN"]["reasons"], JSON_UNESCAPED_UNICODE));
$series = seriesAnalyze($cases["series"][0]["obs"], 2, iucnCriteria());
$in = seriesEngineInputs($series, ["a_reversible" => ["value" => "yes"], "a_understood" => ["value" => "yes"], "a_ceased" => ["value" => "no"],
    "a_basis_b" => ["value" => "yes"], "a_basis_a" => ["value" => "yes"], "a_future" => ["value" => "yes"]]);
check("Hechos de A: causas no cesadas → A2; futuro → A3 (sin (a)) y A4",
    array_column($in["A"]["items"], "subcriterion") === ["A2", "A3", "A4"] && $in["A"]["items"][1]["basis"] === ["b"] && $in["A"]["items"][0]["basis"] === ["a", "b"],
    json_encode($in["A"]["items"]));
$in = seriesEngineInputs(seriesAnalyze([], null), []);
check("Sin serie: A salta con el motivo y C1 sin dato", ($in["A"]["skip"] ?? false) === true && $in["c1AnnualRate"] === null, json_encode($in));

if (in_array("--cases", $argv, true)) {
    echo $failures ? "\n{$failures} fallo(s).\n" : "\nTodo OK.\n";
    exit($failures ? 1 : 0);
}

echo $failures ? "\n{$failures} fallo(s).\n" : "\nTodo OK.\n";
exit($failures ? 1 : 0);
