<?php
// ================================================================================================
// Pruebas de F9 (EOO y AOO): docker compose exec php php bin/test-f9.php
//   1. Los casos compartidos de shared/metrics-cases.json con el port en PHP (Lib/metrics.php, Lib/utm.php).
//   2. GET /api/catalogs/{id}/species/{taxonID}/metrics: geometría según confianza, recálculo tras una
//      marca nueva, exclusión de ausencias, ámbito «me», celda de 1 km, permisos y access_log.
// Crea usuarios f9-*@biodex.test y el catálogo «test-f9 …», y los borra al terminar (y al empezar).
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/api.php";        // uuidV4()
require_once __DIR__ . "/../Lib/migrate.php";
require_once __DIR__ . "/../Lib/metrics.php";
require_once __DIR__ . "/test-lib.php";

const ARTEMISIA = "9918";
const CASES = __DIR__ . "/../../shared/metrics-cases.json";

$pdo = db();
runMigrations($pdo);

// ------------------------------------------------------------------------------------------------
section("Casos compartidos (PHP)");
$data = json_decode(file_get_contents(CASES), true);
$tol = $data["toleranceKm2"];
$near = fn($a, $b) => $a === null || $b === null ? $a === $b : abs($a - $b) <= $tol;
$recordsOf = function (array $c) use ($data) {
    if (isset($c["records"])) return $c["records"];
    return array_map(fn($p) => ["decimalLatitude" => $p[0], "decimalLongitude" => $p[1], "coordinateUncertaintyInMeters" => 10,
        "occurrenceStatus" => "present"], $data["records"][$c["recordsRef"]]);
};
foreach ($data["cases"] as $c) {
    $m = isset($c["points"])
        ? metricsFromPoints(array_map(fn($p) => ["x" => (float) $p[0], "y" => (float) $p[1]], $c["points"]), $c["cellKm"])
        : computeMetrics($recordsOf($c), $c["cellKm"]);
    $e = $c["expect"];
    $bad = [];
    if (array_key_exists("eooKm2", $e) && !$near($m["eooKm2"], $e["eooKm2"])) $bad[] = "eoo " . json_encode($m["eooKm2"]);
    if (isset($e["aooKm2"]) && !$near($m["aooKm2"], $e["aooKm2"])) $bad[] = "aoo {$m["aooKm2"]}";
    if (isset($e["aooCells"]) && $m["aooCells"] !== $e["aooCells"]) $bad[] = "celdas {$m["aooCells"]}";
    if (isset($e["eooAdjusted"]) && $m["eooAdjusted"] !== $e["eooAdjusted"]) $bad[] = "igualada";
    if (isset($e["hullVertices"]) && count($m["hull"]) !== $e["hullVertices"]) $bad[] = "vértices " . count($m["hull"]);
    if (isset($e["cells"]) && $m["cells"] !== $e["cells"]) $bad[] = "celdas " . json_encode($m["cells"]);
    if (isset($e["aooRangeMin"]) && !$near($m["aooRange"]["min"], $e["aooRangeMin"])) $bad[] = "mín {$m["aooRange"]["min"]}";
    if (isset($e["aooRangeMax"]) && !$near($m["aooRange"]["max"], $e["aooRangeMax"])) $bad[] = "máx {$m["aooRange"]["max"]}";
    if (isset($e["nRecords"]) && $m["nRecords"] !== $e["nRecords"]) $bad[] = "registros {$m["nRecords"]}";
    if (isset($e["nExcluded"]) && $m["nExcluded"] !== $e["nExcluded"]) $bad[] = "excluidos {$m["nExcluded"]}";
    if (isset($e["excluded"]) && $m["excluded"] !== $e["excluded"]) $bad[] = "excluidos " . json_encode($m["excluded"]);
    if (isset($e["warningIncludes"]) && !array_filter($m["warnings"], fn($w) => str_contains($w, $e["warningIncludes"]))) {
        $bad[] = "avisos " . implode(" | ", $m["warnings"]);
    }
    if (isset($e["projected"])) {
        $recs = $recordsOf($c);
        foreach ($e["projected"] as [$i, $x, $y]) {
            $u = toUtm($recs[$i]["decimalLatitude"], $recs[$i]["decimalLongitude"]);
            if (abs($u["easting"] - $x) > 0.01 || abs($u["northing"] - $y) > 0.01) $bad[] = "punto {$i}: {$u["easting"]}, {$u["northing"]}";
        }
    }
    check($c["name"], !$bad, implode(" · ", $bad));
}
// JS y PHP dan exactamente las mismas celdas y casi el mismo casco con los puntos de Sierra Nevada
$sn = computeMetrics($recordsOf(["recordsRef" => "sierraNevada"]), 2);
check("Sierra Nevada en PHP: 14 celdas y casco de 11 vértices", $sn["aooCells"] === 14 && count($sn["hull"]) === 11);

// ------------------------------------------------------------------------------------------------
function cleanup(PDO $pdo): void {
    $in = "SELECT id FROM catalogs WHERE name LIKE 'test-f9 %'";
    $pdo->exec("DELETE FROM occurrences WHERE catalog_id IN ({$in})");
    $pdo->exec("DELETE FROM access_log WHERE catalog_id IN ({$in})");
    $pdo->exec("DELETE FROM catalogs WHERE name LIKE 'test-f9 %'");
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f9-%@biodex.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f9-%@biodex.test'");
}
cleanup($pdo);

section("Usuarios, catálogo y 12 marcas");
$suffix = bin2hex(random_bytes(4));
$jars = ["o" => [], "e" => [], "v" => [], "x" => []];
$ids = [];
foreach ($jars as $k => &$jar) {
    $email = "f9-{$k}-{$suffix}@biodex.test";
    [$c] = http("POST", "/api/auth/register", ["email" => $email, "password" => "correcta-horse-battery", "name" => "F9 " . strtoupper($k)], $jar);
    check("registro de " . strtoupper($k), $c === 201, "HTTP {$c}");
    $ids[$k] = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();
}
unset($jar);
[$o, $e, $v, $x] = [&$jars["o"], &$jars["e"], &$jars["v"], &$jars["x"]];
[$c, $r] = http("POST", "/api/catalogs", ["name" => "test-f9 {$suffix}", "taxonIDs" => [ARTEMISIA]], $o);
check("O crea el catálogo con la Artemisia", $c === 201, "HTTP {$c}");
$cat = $r["catalog"]["id"] ?? "";
$ins = $pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role, granted_by) VALUES (?, ?, ?, ?)");
foreach (["e" => "editor", "v" => "viewer"] as $k => $role) $ins->execute([$cat, $ids[$k], $role, $ids["o"]]);
$level = fn(int $n, string $why = "prueba") => http("PUT", "/api/catalogs/{$cat}/species/" . ARTEMISIA . "/sensitivity", ["level" => $n, "reason" => $why], $o);
[$c] = $level(2);
check("nivel 2 para la Artemisia", $c === 200, "HTTP {$c}");

$mark = fn(float $lat, float $lon, array $patch = []) => $patch + ["occurrenceID" => uuidV4(), "taxonID" => ARTEMISIA,
    "eventDate" => "2026-09-29T10:00:00+02:00", "decimalLatitude" => $lat, "decimalLongitude" => $lon,
    "coordinateUncertaintyInMeters" => 10, "clientUpdatedAt" => "2026-09-29T10:05:00.000Z"];
$sn = $data["records"]["sierraNevada"];
$push = fn(array &$jar, array $occs) => http("POST", "/api/sync/push", ["catalogId" => $cat, "occurrences" => $occs], $jar);
// 8 de O y 4 de E (12 de los 15 puntos de Sierra Nevada)
[$c1, $r1] = $push($o, array_map(fn($p) => $mark($p[0], $p[1]), array_slice($sn, 0, 8)));
[$c2, $r2] = $push($e, array_map(fn($p) => $mark($p[0], $p[1]), array_slice($sn, 8, 4)));
check("12 marcas subidas (8 de O, 4 de E)", $c1 === 200 && $c2 === 200
    && count(array_filter([...$r1["results"], ...$r2["results"]], fn($x) => $x["status"] === "accepted")) === 12);
$expected = computeMetrics(array_map(fn($p) => ["decimalLatitude" => $p[0], "decimalLongitude" => $p[1],
    "coordinateUncertaintyInMeters" => 10, "occurrenceStatus" => "present"], array_slice($sn, 0, 12)), 2);

$metrics = fn(array &$jar, string $q = "") => http("GET", "/api/catalogs/{$cat}/species/" . ARTEMISIA . "/metrics{$q}", null, $jar);
$logs = fn() => (int) $pdo->query("SELECT COUNT(*) FROM access_log WHERE action = 'view_exact' AND catalog_id = " . $pdo->quote($cat)
    . " AND target LIKE 'EOO/AOO%'")->fetchColumn();

// ------------------------------------------------------------------------------------------------
section("Owner: geometría completa");
[$c, $mo] = $metrics($o);
check("O: 200 con cifras del servidor", $c === 200 && $mo["nRecords"] === 12 && abs($mo["eooKm2"] - $expected["eooKm2"]) < 0.001
    && $mo["aooCells"] === $expected["aooCells"] && $mo["cellSizeKm"] === 2 && $mo["scope"] === "team", json_encode($mo));
check("…con casco (lat/lon de los vértices) y celdas en EPSG:25830", ($mo["geometry"]["access"] ?? null) === "full"
    && count($mo["geometry"]["hull"]) === count($expected["hull"]) && count($mo["geometry"]["cells"]) === $mo["aooCells"]
    && ($mo["geometry"]["crs"] ?? null) === "EPSG:25830", json_encode($mo["geometry"] ?? null));
check("…rango de la AOO, avisos, fecha y primera vez sin caché", isset($mo["aooRange"]["min"], $mo["aooRange"]["max"])
    && is_array($mo["warnings"]) && preg_match('/Z$/', (string) $mo["computedAt"]) && $mo["cached"] === false);
check("…queda en access_log como vista exacta (8+4 registros, 4 ajenos)", $logs() === 1);
$row = $pdo->query("SELECT * FROM species_metrics WHERE catalog_id = " . $pdo->quote($cat) . " AND scope = 'team'")->fetch();
check("…y se guarda en species_metrics con el tamaño de celda y el server_seq", $row && (float) $row["cell_size_km"] === 2.0
    && (int) $row["n_records"] === 12 && (int) $row["source_seq"] === $mo["sourceSeq"], json_encode($row));

[$c, $again] = $metrics($o);
check("Otra petición sin marcas nuevas: cacheada, misma fecha", $again["cached"] === true && $again["computedAt"] === $mo["computedAt"]);

// ------------------------------------------------------------------------------------------------
section("Editor en especie de nivel 2: solo cifras + celdas de 0,1°");
check("La segunda vista de la owner también se registra", $logs() === 2);
$logsBefore = $logs();
[$c, $me] = $metrics($e);
check("E: mismas cifras que la owner", $c === 200 && $me["eooKm2"] === $mo["eooKm2"] && $me["aooKm2"] === $mo["aooKm2"] && $me["nRecords"] === 12);
check("…geometría «generalized» con gridDeg 0,1", ($me["geometry"]["access"] ?? null) === "generalized" && $me["geometry"]["gridDeg"] === 0.1
    && count($me["geometry"]["generalizedCells"]) >= 1, json_encode($me["geometry"]));
$raw = json_encode($me);
check("…SIN vértices del casco ni celdas de la AOO", !isset($me["geometry"]["hull"]) && !isset($me["geometry"]["cells"])
    && !str_contains($raw, "37.0931") && !str_contains($raw, "-3.395"), $raw);
$cellsOk = true;
foreach ($me["geometry"]["generalizedCells"] as [$la, $lo]) {
    if (abs($la * 10 - round($la * 10)) > 1e-9 || abs($lo * 10 - round($lo * 10)) > 1e-9) $cellsOk = false;
}
check("…las celdas son esquinas de la rejilla de 0,1°", $cellsOk, json_encode($me["geometry"]["generalizedCells"]));
check("…y no genera vista exacta", $logs() === $logsBefore);
[$c, $mv] = $metrics($v);
check("El lector también recibe cifras (permiso view), geometría generalizada", $c === 200 && $mv["geometry"]["access"] === "generalized");
[$c, $mm] = $metrics($e, "?scope=me");
check("E con ámbito «me»: solo sus 4 registros y geometría completa (son suyos)", $c === 200 && $mm["nRecords"] === 4
    && $mm["geometry"]["access"] === "full" && $mm["scope"] === "me", json_encode($mm));

section("Nivel 3, 1 y 4");
$level(3, "prueba de nivel 3");
[$c, $m3] = $metrics($e);
check("Nivel 3: celdas de 0,01°", $m3["geometry"]["access"] === "generalized" && $m3["geometry"]["gridDeg"] === 0.01);
$level(1);
[$c, $m1] = $metrics($e);
check("Nivel 1: solo cifras (access none, sin celdas)", $m1["geometry"] === ["access" => "none"] && $m1["eooKm2"] === $mo["eooKm2"], json_encode($m1["geometry"]));
[$c, $m1o] = $metrics($o);
check("Nivel 1 para la owner: geometría completa", $m1o["geometry"]["access"] === "full");
$level(4, "prueba de nivel 4");
[$c, $m4] = $metrics($e);
check("Nivel 4: el editor recibe la geometría completa", $m4["geometry"]["access"] === "full" && count($m4["geometry"]["hull"]) >= 3);
$level(2);

// ------------------------------------------------------------------------------------------------
section("Recálculo y exclusiones");
[$c, $r] = $push($e, [$mark($sn[12][0], $sn[12][1])]);
[$c, $mn] = $metrics($o);
check("Marca nueva → se recalcula (13 registros, sin caché, fecha nueva o igual y seq mayor)", $mn["nRecords"] === 13
    && $mn["cached"] === false && $mn["sourceSeq"] > $mo["sourceSeq"], json_encode([$mn["nRecords"], $mn["cached"], $mn["sourceSeq"]]));
[$c, $r] = $push($e, [$mark(37.1500, -3.1000, ["occurrenceStatus" => "absent"])]);
[$c, $ma] = $metrics($o);
check("Ausencia: no cuenta (13) y figura en excluidas", $ma["nRecords"] === 13 && $ma["excluded"]["absent"] === 1 && $ma["nExcluded"] === 1
    && $ma["eooKm2"] === $mn["eooKm2"], json_encode($ma["excluded"]));
[$c, $r] = $push($e, [$mark(37.0500, -3.2500, ["coordinateUncertaintyInMeters" => 2500])]);
[$c, $mi] = $metrics($o);
check("Registro impreciso (2500 m > 2 km): excluido, con aviso", $mi["nRecords"] === 13 && $mi["excluded"]["imprecise"] === 1
    && (bool) array_filter($mi["warnings"], fn($w) => str_contains($w, "precisión")));
$del = $r["results"][0]["occurrenceID"] ?? null;
[$c, $mc] = $metrics($o, "?cell=1");
check("Celda de 1 km: otra fila guardada, con aviso de calibración", $mc["cellSizeKm"] === 1 && $mc["cached"] === false
    && (bool) array_filter($mc["warnings"], fn($w) => str_contains($w, "calibrados para celdas de 2 km")));
$n = (int) $pdo->query("SELECT COUNT(*) FROM species_metrics WHERE catalog_id = " . $pdo->quote($cat))->fetchColumn();
check("species_metrics: filas por ámbito y celda (team 2, team 1, me de E)", $n === 3, "{$n} filas");

section("Permisos y parámetros");
[$c] = $metrics($x);
check("No miembro → 403", $c === 403, "HTTP {$c}");
[$c] = $metrics($o, "?cell=5");
check("cell=5 → 400", $c === 400, "HTTP {$c}");
[$c] = $metrics($o, "?scope=all");
check("scope=all → 400", $c === 400, "HTTP {$c}");
[$c] = http("GET", "/api/catalogs/{$cat}/species/11943/metrics", null, $o);
check("Especie que no está en el catálogo → 404", $c === 404, "HTTP {$c}");
[$c] = http("POST", "/api/catalogs/{$cat}/species/" . ARTEMISIA . "/metrics", [], $o);
check("POST → 405", $c === 405, "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
cleanup($pdo);
echo "\n" . ($failures ? "{$failures} comprobación(es) fallida(s)." : "Todo OK.") . "\n";
exit($failures ? 1 : 0);
