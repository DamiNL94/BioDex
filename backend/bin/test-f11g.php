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

// ------------------------------------------------------------------------------------------------
// API: impactos, localidades y evaluación calculada en el servidor
// ------------------------------------------------------------------------------------------------
const ARTEMISIA = "9918";

function cleanup(PDO $pdo): void {
    $in = "SELECT id FROM catalogs WHERE name LIKE 'test-f11g %'";
    foreach (["species_parameters", "species_locations", "species_assessments", "species_metrics", "access_log", "occurrences", "impacts"] as $t) {
        $pdo->exec("DELETE FROM {$t} WHERE catalog_id IN ({$in})");
    }
    $pdo->exec("DELETE FROM catalogs WHERE name LIKE 'test-f11g %'");
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f11g-%@biodex.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f11g-%@biodex.test'");
}
cleanup($pdo);

section("Usuarios y catálogo");
$suffix = bin2hex(random_bytes(4));
$jars = ["o" => [], "c" => [], "e" => [], "v" => []];
$ids = [];
foreach ($jars as $k => &$jar) {
    $email = "f11g-{$k}-{$suffix}@biodex.test";
    [$code] = http("POST", "/api/auth/register", ["email" => $email, "password" => "correcta-horse-battery", "name" => "F11g " . strtoupper($k)], $jar);
    check("registro de " . strtoupper($k), $code === 201, "HTTP {$code}");
    $ids[$k] = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();
}
unset($jar);
[$o, $cu, $e, $v] = [&$jars["o"], &$jars["c"], &$jars["e"], &$jars["v"]];
[$code, $r] = http("POST", "/api/catalogs", ["name" => "test-f11g {$suffix}", "taxonIDs" => [ARTEMISIA]], $o);
check("O crea el catálogo con la Artemisia", $code === 201, "HTTP {$code}");
$cat = $r["catalog"]["id"] ?? "";
$ins = $pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role, granted_by) VALUES (?, ?, ?, ?)");
foreach (["c" => "custodian", "e" => "editor", "v" => "viewer"] as $k => $role) $ins->execute([$cat, $ids[$k], $role, $ids["o"]]);
$level = fn(int $n) => http("PUT", "/api/catalogs/{$cat}/species/" . ARTEMISIA . "/sensitivity", ["level" => $n, "reason" => "prueba F11g"], $o);
[$code] = $level(4);
check("nivel 4 para empezar", $code === 200, "HTTP {$code}");

$push = fn(array &$jar, array $body) => http("POST", "/api/sync/push", ["catalogId" => $cat] + $body, $jar);
$pull = function (array &$jar) use ($cat) { return http("GET", "/api/sync/pull?catalogId={$cat}&since=0", null, $jar); };
$tick = 0;
$now = function () use (&$tick) { $tick += 10; return (new DateTimeImmutable("now", new DateTimeZone("UTC")))->modify("+{$tick} milliseconds")->format("Y-m-d\TH:i:s.v\Z"); };
$find = fn(array $list, string $id) => array_values(array_filter($list, fn($x) => ($x["impactID"] ?? null) === $id))[0] ?? null;

// ------------------------------------------------------------------------------------------------
section("Impactos por /api/sync");
$imp = uuidV4();
$impact = fn(array $extra = []) => $extra + ["impactID" => $imp, "threatCode" => "2.3", "quickKey" => "grazing", "decimalLatitude" => 37.0951,
    "decimalLongitude" => -3.3971, "radiusM" => 400, "eventDate" => "2026-10-15T10:00:00+02:00", "intensity" => "high", "scopePct" => 40,
    "timing" => "ongoing", "note" => "Ovejas en la ladera", "species" => [ARTEMISIA], "clientUpdatedAt" => $now()];
[$code, $r] = $push($e, ["impacts" => [$impact()]]);
check("el editor sube un impacto → accepted", $code === 200 && ($r["impactResults"][0]["status"] ?? null) === "accepted", json_encode($r));
[, $r] = $push($e, ["impacts" => [$impact(["impactID" => uuidV4(), "threatCode" => "99.9"])]]);
check("amenaza fuera del esquema → invalid_vocabulary", ($r["impactResults"][0]["reason"] ?? null) === "invalid_vocabulary", json_encode($r));
[, $r] = $push($e, ["impacts" => [$impact(["impactID" => uuidV4(), "polygon" => [[37, -3], [37.1, -3], [37.1, -3.1]]])]]);
check("radio y polígono a la vez → missing_field", ($r["impactResults"][0]["reason"] ?? null) === "missing_field", json_encode($r));
[, $r] = $push($e, ["impacts" => [$impact(["impactID" => uuidV4(), "species" => ["123456789"]])]]);
check("especie fuera del catálogo → taxon_not_in_catalog", ($r["impactResults"][0]["reason"] ?? null) === "taxon_not_in_catalog", json_encode($r));
[$code] = $push($v, ["impacts" => [$impact(["impactID" => uuidV4()])]]);
check("el lector no puede registrar impactos → 403", $code === 403, "HTTP {$code}");
[, $r] = $push($cu, ["impacts" => [$impact(["clientUpdatedAt" => $now()])]]);
check("otro miembro no edita un impacto ajeno → forbidden", ($r["impactResults"][0]["reason"] ?? null) === "forbidden", json_encode($r));

[$code, $r] = $pull($v);
$it = $find($r["impacts"] ?? [], $imp);
check("el pull lleva el impacto (nivel 4: exacto para el lector)", $code === 200 && $it && $it["decimalLatitude"] === 37.0951 && $it["radiusM"] === 400
    && $it["species"] === [ARTEMISIA] && $it["generalized"] === false, json_encode($it));
$level(2);
[, $r] = $pull($v);
$it = $find($r["impacts"] ?? [], $imp);
check("nivel 2: al lector le llega generalizado (celda de 0,1°, radio ampliado)", $it && $it["generalized"] === true
    && abs($it["decimalLatitude"] - 37.05) < 1e-9 && $it["radiusM"] > 5000 && $it["polygon"] === null, json_encode($it));
[, $r] = $pull($cu);
$it = $find($r["impacts"] ?? [], $imp);
check("…al custodio, exacto", $it && $it["generalized"] === false && $it["decimalLatitude"] === 37.0951, json_encode($it));
[, $r] = $pull($e);
$it = $find($r["impacts"] ?? [], $imp);
check("…y a su autor, exacto", $it && $it["generalized"] === false, json_encode($it));
$level(1);
[, $r] = $pull($v);
$it = $find($r["impacts"] ?? [], $imp);
check("nivel 1: al lector, sin sitio", $it && $it["decimalLatitude"] === null && $it["decimalLongitude"] === null, json_encode($it));
$level(4);
[, $r] = $push($e, ["impacts" => [$impact(["species" => [], "clientUpdatedAt" => $now()])]]);
$n = (int) $pdo->query("SELECT COUNT(*) FROM impact_species WHERE impactID = " . $pdo->quote($imp))->fetchColumn();
check("editar el impacto sustituye sus especies", ($r["impactResults"][0]["status"] ?? null) === "accepted" && $n === 0, json_encode($r) . " n={$n}");
[, $r] = $push($e, ["impactDeletes" => [["impactID" => $imp, "clientUpdatedAt" => $now()]]]);
check("borrar el impacto → accepted", ($r["impactResults"][0]["status"] ?? null) === "accepted", json_encode($r));
[, $r] = $pull($v);
$it = $find($r["impacts"] ?? [], $imp);
check("…y al pull llega como tombstone sin sitio", $it && $it["deleted"] === true && !isset($it["decimalLatitude"]), json_encode($it));

// ------------------------------------------------------------------------------------------------
section("Evaluación calculada en el servidor");
// Dos núcleos a ~3,7 km (dos subpoblaciones con 1 km de separación) en Sierra Nevada, con recuentos y parcelas.
$mark = fn(float $lat, float $lon, int $mature, ?float $area = null) => array_filter([
    "occurrenceID" => uuidV4(), "taxonID" => ARTEMISIA, "eventDate" => "2026-10-15T10:00:00+02:00", "decimalLatitude" => $lat,
    "decimalLongitude" => $lon, "coordinateUncertaintyInMeters" => 10, "individualCount" => $mature + 2, "mature_count" => $mature,
    "sampleSizeValue" => $area, "clientUpdatedAt" => $now()], fn($x) => $x !== null);
$marks = [$mark(37.0950, -3.3970, 12, 100), $mark(37.0955, -3.3975, 8, 100), $mark(37.0960, -3.3960, 15, 200),
          $mark(37.1200, -3.3700, 6, 50), $mark(37.1205, -3.3705, 9, 100)];
[$code, $r] = $push($o, ["occurrences" => $marks]);
check("5 marcas con maduros y superficie contada", $code === 200 && count(array_filter($r["results"] ?? [], fn($x) => $x["status"] === "accepted")) === 5, json_encode($r));
[, $r] = $push($e, ["occurrences" => [$mark(37.0951, -3.3971, 3, -5)]]);
check("superficie contada ≤ 0 → rechazada", ($r["results"][0]["reason"] ?? null) === "invalid_field", json_encode($r));
$imp2 = uuidV4();
$push($e, ["impacts" => [$impact(["impactID" => $imp2, "radiusM" => 300, "clientUpdatedAt" => $now()])]]);
$base = "/api/catalogs/{$cat}/species/" . ARTEMISIA;
[$code, $a] = http("GET", "{$base}/assessment", null, $o);
check("GET …/assessment → 200 calculado en el servidor", $code === 200 && ($a["origin"] ?? null) === "server", "HTTP {$code} " . json_encode($a));
check("dos subpoblaciones con la separación por defecto (1 km)", count($a["subpopulations"]["subpops"] ?? []) === 2
    && ($a["separation"]["fromDefault"] ?? null) === true, json_encode($a["subpopulations"] ?? null));
check("maduros: mínimo observado = suma (50)", ($a["mature"]["min"] ?? null) === 50 && !isset($a["mature"]["kept"]), json_encode($a["mature"] ?? null));
check("el owner recibe el casco de cada subpoblación", ($a["geometry"] ?? null) === "full" && isset($a["subpopulations"]["subpops"][0]["hull"])
    && !isset($a["subpopulations"]["subpops"][0]["points"]));
check("propuesta: la subpoblación con el impacto en curso, una localidad amenazada", ($a["locationsProposal"]["threatened"] ?? null) === 1
    && ($a["locationsProposal"]["count"] ?? null) === 2, json_encode($a["locationsProposal"] ?? null));
check("sugiere b(iii) por el impacto en curso (pastoreo), sin fijarlo", ($a["suggestions"][0]["key"] ?? null) === "decline_b_iii"
    && ($a["inputs"]["B"]["decline"] ?? null) === [], json_encode($a["suggestions"] ?? null));
check("fragmentación: no evaluable sin parámetros", ($a["fragmentation"]["status"] ?? null) === "not_evaluable");
$level(2);
[$code, $av] = http("GET", "{$base}/assessment", null, $v);
check("el lector (nivel 2) ve cifras y centros generalizados, sin casco", $code === 200 && ($av["geometry"] ?? null) === "generalized"
    && !isset($av["subpopulations"]["subpops"][0]["hull"]) && isset($av["subpopulations"]["subpops"][0]["centroid"])
    && ($av["mature"]["min"] ?? null) === 50, json_encode($av["subpopulations"]["subpops"][0] ?? null));
$level(4);

section("Localidades y hechos");
$locBody = ["count" => 2, "groups" => [["label" => "L1", "subpops" => ["S1"], "impactID" => $imp2, "threatCode" => "2.3", "noThreat" => false],
    ["label" => "L2", "subpops" => ["S2"], "noThreat" => true]], "justification" => "Pastoreo en S1; S2 sin amenaza conocida.",
    "quality" => "inferred", "separationKm" => 1];
[$code] = http("PUT", "{$base}/locations", $locBody, $e);
check("el editor no confirma localidades → 403", $code === 403, "HTTP {$code}");
[$code] = http("PUT", "{$base}/locations", ["justification" => ""] + $locBody, $cu);
check("sin justificación → 400", $code === 400, "HTTP {$code}");
[$code, $r] = http("PUT", "{$base}/locations", $locBody, $cu);
check("el custodio confirma 2 localidades", $code === 200 && ($r["locations"]["count"] ?? null) === 2, json_encode($r));
$logged = (int) $pdo->query("SELECT COUNT(*) FROM access_log WHERE action = 'set_locations' AND catalog_id = " . $pdo->quote($cat))->fetchColumn();
check("…queda en access_log (set_locations)", $logged === 1);
[, $r] = $pull($v);
check("…y baja por el pull", ($r["locations"][0]["count"] ?? null) === 2, json_encode($r["locations"] ?? null));
foreach (["decline_b_iii" => "yes", "decline_b_v" => "unknown"] as $k => $val) {
    [$code] = http("PUT", "{$base}/parameters/{$k}", ["value" => $val, "quality" => "inferred", "justification" => "Pastoreo en curso"], $cu);
    check("hecho {$k} = {$val}", $code === 200, "HTTP {$code}");
}
[$code] = http("PUT", "{$base}/parameters/decline_b_i", ["value" => "quizá"], $cu);
check("un hecho solo admite yes/no/unknown → 400", $code === 400, "HTTP {$code}");
[, $a] = http("GET", "{$base}/assessment", null, $o);
check("con 2 localidades y b(iii) → EN B1ab(iii)+2ab(iii)", ($a["result"]["category"] ?? null) === "EN"
    && str_starts_with($a["result"]["criteriaString"] ?? "", "EN B1ab(iii)+2ab(iii)"), json_encode([$a["result"]["criteriaString"] ?? null, $a["inputs"]["B"] ?? null]));

[$code] = http("PUT", "{$base}/locations", ["count" => 1] + $locBody, $cu);
check("«count» que no cuadra con los grupos → 400", $code === 400, "HTTP {$code}");
$dup = $locBody;
$dup["groups"][1]["subpops"] = ["S1"];
[$code] = http("PUT", "{$base}/locations", $dup, $cu);
check("una subpoblación en dos localidades → 400", $code === 400, "HTTP {$code}");

section("Instantánea: el servidor recalcula cuantificadores y motor");
$sid = uuidV4();
// El dispositivo manda sus cuantificadores (p. ej. sin red, con menos marcas): el servidor los rehace con las exactas.
$deviceInputs = $a["inputs"];
$deviceInputs["B"]["locations"] = 1;
$deviceInputs["C"]["mature"] = ["best" => 10, "min" => 10, "max" => 10];
$fake = ["category" => "LC", "criteriaString" => null, "quantifiers" => ["nPoints" => 999]];
[, $r] = $push($e, ["assessments" => [["assessmentID" => $sid, "taxonID" => ARTEMISIA, "inputs" => $deviceInputs, "result" => $fake,
    "status" => "final", "clientUpdatedAt" => $now()]]]);
check("instantánea aceptada", ($r["assessmentResults"][0]["status"] ?? null) === "accepted", json_encode($r));
$row = $pdo->query("SELECT category, inputs, result FROM species_assessments WHERE assessmentID = " . $pdo->quote($sid))->fetch();
$res = json_decode($row["result"] ?? "{}", true);
$inp = json_decode($row["inputs"] ?? "{}", true);
check("…con la categoría y los cuantificadores del servidor, no los del dispositivo", ($row["category"] ?? null) === "EN" && ($res["computedBy"] ?? null) === "server"
    && ($res["quantifiers"]["nPoints"] ?? null) === 5 && ($inp["B"]["locations"] ?? null) === 2 && ($inp["C"]["mature"]["min"] ?? null) === 50,
    json_encode([$row["category"] ?? null, $res["criteriaString"] ?? null, $inp["B"]["locations"] ?? null]));

section("Exportación: evaluación por especie, subpoblaciones e impactos");
require_once __DIR__ . "/../Lib/export.php";
$level(2);
$spRow = $pdo->query("SELECT canonical_name FROM species WHERE taxonID = " . $pdo->quote(ARTEMISIA))->fetch();
$prep = ["species" => [ARTEMISIA => ["canonical_name" => $spRow["canonical_name"]]], "role" => "owner", "userId" => $ids["o"]];
$imp = exportImpactsSheet($pdo, $cat, ["mode" => "public"], $prep);
$row = $imp["rows"][1] ?? [];
check("Impactos (para publicar): una fila, generalizada y sin nota", count($imp["rows"]) === 2 && ($row[11] ?? null) === "Sí"
    && array_key_exists("v", $row[12] ?? []) && $row[12]["v"] === null && abs(($row[7]["v"] ?? 0) - 37.05) < 1e-9, json_encode($row, JSON_UNESCAPED_UNICODE));
$imp = exportImpactsSheet($pdo, $cat, ["mode" => "full"], $prep);
check("Impactos (completa, owner): exacto y con nota", ($imp["rows"][1][11] ?? null) === "No" && ($imp["rows"][1][12]["v"] ?? null) === "Ovejas en la ladera");
[$ev, $subs] = exportSpeciesAssessmentSheets($pdo, $cat, $prep);
check("Evaluación por especie: EN B… con 2 localidades confirmadas", ($ev["rows"][1][1]["v"] ?? null) === "EN"
    && str_starts_with((string) ($ev["rows"][1][3] ?? ""), "EN B1ab(iii)+2ab(iii)") && str_starts_with((string) ($ev["rows"][1][15] ?? ""), "2 confirmadas"),
    json_encode(array_slice($ev["rows"][1] ?? [], 0, 16), JSON_UNESCAPED_UNICODE));
check("Subpoblaciones: dos filas con su localidad", count($subs["rows"]) === 3 && ($subs["rows"][1][10] ?? null) === "L1", json_encode($subs["rows"], JSON_UNESCAPED_UNICODE));
$level(4);

section("Localidades desactualizadas");
// Una marca a ~5 km forma una tercera subpoblación: las 2 localidades confirmadas (S1, S2) ya no cuentan.
[, $r] = $push($o, ["occurrences" => [$mark(37.1600, -3.3300, 4)]]);
[, $a2] = http("GET", "{$base}/assessment", null, $o);
check("con una subpoblación nueva, las localidades confirmadas no entran en el motor", count($a2["subpopulations"]["subpops"] ?? []) === 3
    && ($a2["locations"]["count"] ?? null) === 2 && array_key_exists("locations", $a2["inputs"]["B"] ?? []) && $a2["inputs"]["B"]["locations"] === null, json_encode($a2["inputs"]["B"] ?? null));

cleanup($pdo);
echo $failures ? "\n{$failures} fallo(s)\n" : "\nTodo OK\n";
exit($failures ? 1 : 0);
