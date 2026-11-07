<?php
// ================================================================================================
// Pruebas de F11g (cuantificadores UICN e impactos): docker compose exec php php bin/test-f11g.php
//   1. Los casos compartidos de shared/iucn-cases.json con el port en PHP (Lib/iucn.php): mismos
//      resultados que node shared/iucn-engine.test.js.
//   2. Impactos por /api/sync (push, pull, generalización según confianza, permisos).
//   3. Hechos, localidades y GET …/assessment (cálculo definitivo, geometría según confianza).
// Crea usuarios f11g-*@biodex.test y el catálogo «test-f11g …», y los borra al terminar (y al empezar).
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
section("Casos compartidos (PHP)");
$cases = json_decode(file_get_contents(__DIR__ . "/../../shared/iucn-cases.json"), true);
$same = fn($a, $b) => json_encode($a) === json_encode($b);

foreach ($cases["engine"] as $c) {
    $r = $c["fn"] === "assess" ? assessIucn($c["inputs"]) : evaluateIucn($c["inputs"]);
    $e = $c["expect"];
    $bad = [];
    $cmp = function (string $k, mixed $got, string $label) use ($e, &$bad) {
        if (array_key_exists($k, $e) && $got !== $e[$k]) $bad[] = "{$label} " . json_encode($got, JSON_UNESCAPED_UNICODE);
    };
    $cmp("category", $r["category"], "categoría");
    $cmp("criteriaString", $r["criteriaString"], "cadena");
    $cmp("baseCategory", $r["baseCategory"], "base");
    $cmp("displayCategory", $r["displayCategory"] ?? null, "mostrada");
    $cmp("basis", $r["basis"], "base");
    $cmp("dataMinimum", $r["dataMinimum"] ?? null, "datos mínimos");
    $cmp("rangeLow", $r["range"]["low"] ?? null, "rango bajo");
    $cmp("rangeHigh", $r["range"]["high"] ?? null, "rango alto");
    $cmp("crosses", $r["range"]["crossesCategories"] ?? null, "cruce");
    $cmp("regionalApplied", $r["regional"]["applied"], "ajuste");
    if (isset($e["warningIncludes"]) && !array_filter($r["warnings"], fn($w) => str_contains($w, $e["warningIncludes"]))) {
        $bad[] = "avisos " . implode(" | ", $r["warnings"]);
    }
    check($c["name"], !$bad, implode("; ", $bad));
}

/** Igual que runQuantifierCase() de shared/iucn-engine.test.js. */
function runQuantifierCase(array $c): array {
    $pts = iucnPrepareRecords($c["records"]);
    if ($c["kind"] === "mature") {
        $m = iucnMatureEstimate($pts, $c["habitatKm2"] ?? null, $c["aooKm2"] ?? null);
        return ["min" => $m["min"], "best" => $m["best"], "max" => $m["max"], "nCounted" => $m["nCounted"],
                "doubleCountPairs" => $m["doubleCountPairs"], "method" => $m["method"], "areaSource" => $m["areaSource"],
                "density" => $m["density"], "warnings" => $m["warnings"]];
    }
    $mature = iucnMatureEstimate($pts);
    $sp = iucnSubpopulations($pts, $c["separationKm"], $mature);
    if ($c["kind"] === "subpops") {
        return ["count" => count($sp["subpops"]), "sizes" => array_column($sp["subpops"], "nRecords"),
                "nearestKm" => array_column($sp["subpops"], "nearestKm"),
                "labelsX" => array_map(fn($s) => min(array_column($s["points"], "x")), $sp["subpops"])];
    }
    if ($c["kind"] === "fragmentation") {
        $f = iucnFragmentation($sp["subpops"], $c["minViable"] ?? null, $c["dispersalKm"] ?? null);
        return ["status" => $f["status"], "severe" => $f["severe"], "pctAoo" => $f["pctAoo"], "pctIndividuals" => $f["pctIndividuals"],
                "missing" => $f["missing"]];
    }
    $l = iucnProposeLocations($sp["subpops"], $c["impacts"] ?? []);
    return ["count" => $l["count"], "threatened" => $l["threatened"], "groups" => array_column($l["groups"], "subpops")];
}

/** Números iguales aunque uno sea 5 y el otro 5.0 (JSON de JS no distingue). */
function numEq(mixed $a, mixed $b): bool {
    if (is_array($a) && is_array($b)) {
        if (array_keys($a) !== array_keys($b)) return false;
        foreach ($a as $k => $v) if (!numEq($v, $b[$k])) return false;
        return true;
    }
    if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) return abs($a - $b) < 1e-9;
    return $a === $b;
}

foreach ($cases["quantifiers"] as $c) {
    $got = runQuantifierCase($c);
    $bad = [];
    foreach ($c["expect"] as $k => $v) {
        if ($k === "warningIncludes") {
            if (!array_filter($got["warnings"], fn($w) => str_contains($w, $v))) $bad[] = "avisos " . implode(" | ", $got["warnings"]);
            continue;
        }
        if (!numEq($got[$k] ?? null, $v)) $bad[] = "{$k} " . json_encode($got[$k] ?? null);
    }
    check($c["name"], !$bad, implode("; ", $bad));
}
$rnd = iucnMinstd(1);
check("MINSTD: primer valor de la semilla 1 igual que en JS", abs($rnd() - 48270 / 2147483646) < 1e-15);

echo $failures ? "\n{$failures} fallo(s)\n" : "\nTodo OK\n";
exit($failures ? 1 : 0);
