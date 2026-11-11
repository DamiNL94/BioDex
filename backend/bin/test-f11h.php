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

// ------------------------------------------------------------------------------------------------
// API: sitios y visitas por /api/sync, serie en la evaluación y exportación con núcleo Event
// ------------------------------------------------------------------------------------------------
const SENSITIVE = "9918";   // Artemisia granatensis: nivel 2 en la prueba
const COMMON = "9392";      // Viola crassiuscula: nivel 4

function httpRaw(string $method, string $path, ?array $body, array $jar): array {
    global $BASE;
    $ch = curl_init($BASE . $path);
    $h = ["Content-Type: application/json"];
    if ($jar) $h[] = "Cookie: " . implode("; ", array_map(fn($k, $v) => "{$k}={$v}", array_keys($jar), $jar));
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 120,
        CURLOPT_POSTFIELDS => json_encode($body ?? new stdClass())]);
    $raw = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $raw];
}

function unzipAll(string $raw): array {
    $tmp = tempnam(sys_get_temp_dir(), "t11h");
    file_put_contents($tmp, $raw);
    $zip = new ZipArchive();
    $out = [];
    if ($zip->open($tmp) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) $out[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        $zip->close();
    }
    unlink($tmp);
    return $out;
}

function parseTsv(string $txt): array {
    $lines = explode("\n", rtrim($txt, "\n"));
    $head = explode("\t", array_shift($lines));
    $rows = [];
    $bad = 0;
    foreach ($lines as $l) {
        $cols = explode("\t", $l);
        if (count($cols) !== count($head)) { $bad++; continue; }
        $rows[] = array_combine($head, $cols);
    }
    return [$head, $rows, $bad];
}

function cleanup(PDO $pdo): void {
    $in = "SELECT id FROM catalogs WHERE name LIKE 'test-f11h %'";
    foreach (["species_parameters", "species_locations", "species_assessments", "species_metrics", "access_log", "occurrences", "impacts", "visits", "sites"] as $t) {
        $pdo->exec("DELETE FROM {$t} WHERE catalog_id IN ({$in})");
    }
    $pdo->exec("DELETE FROM catalogs WHERE name LIKE 'test-f11h %'");
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f11h-%@biodex.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f11h-%@biodex.test'");
}
cleanup($pdo);

section("Usuarios y catálogo");
$suffix = bin2hex(random_bytes(4));
$jars = ["o" => [], "c" => [], "e" => [], "v" => []];
$ids = [];
foreach ($jars as $k => &$jar) {
    $email = "f11h-{$k}-{$suffix}@biodex.test";
    [$code] = http("POST", "/api/auth/register", ["email" => $email, "password" => "correcta-horse-battery", "name" => "F11h " . strtoupper($k)], $jar);
    check("registro de " . strtoupper($k), $code === 201, "HTTP {$code}");
    $ids[$k] = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();
}
unset($jar);
[$o, $cu, $e, $v] = [&$jars["o"], &$jars["c"], &$jars["e"], &$jars["v"]];
[$code, $r] = http("POST", "/api/catalogs", ["name" => "test-f11h {$suffix}", "taxonIDs" => [SENSITIVE, COMMON]], $o);
check("O crea el catálogo (Artemisia y Viola)", $code === 201, "HTTP {$code} " . json_encode($r));
$cat = $r["catalog"]["id"] ?? "";
$ins = $pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role, granted_by) VALUES (?, ?, ?, ?)");
foreach (["c" => "custodian", "e" => "editor", "v" => "viewer"] as $k => $role) $ins->execute([$cat, $ids[$k], $role, $ids["o"]]);
foreach ([SENSITIVE => 2, COMMON => 4] as $t => $lv) {
    [$code] = http("PUT", "/api/catalogs/{$cat}/species/{$t}/sensitivity", ["level" => $lv, "reason" => "prueba F11h"], $o);
    check("nivel {$lv} para {$t}", $code === 200, "HTTP {$code}");
}

$push = fn(array &$jar, array $body) => http("POST", "/api/sync/push", ["catalogId" => $cat] + $body, $jar);
$pull = fn(array &$jar, int $since = 0) => http("GET", "/api/sync/pull?catalogId={$cat}&since={$since}", null, $jar);
$tick = 0;
$now = function () use (&$tick) { $tick += 10; return (new DateTimeImmutable("now", new DateTimeZone("UTC")))->modify("+{$tick} milliseconds")->format("Y-m-d\TH:i:s.v\Z"); };
$by = fn(array $list, string $key, string $id) => array_values(array_filter($list, fn($x) => ($x[$key] ?? null) === $id))[0] ?? null;

// ------------------------------------------------------------------------------------------------
section("Sitios y visitas por /api/sync");
$site = uuidV4();
$visit = uuidV4();
$siteBody = fn(array $x = []) => $x + ["siteID" => $site, "name" => "Cañada del Lobo", "siteType" => "point", "decimalLatitude" => 37.0921,
    "decimalLongitude" => -3.3971, "radiusM" => 50, "protocol" => "target_census", "species" => [COMMON], "clientUpdatedAt" => $now()];
$visitBody = fn(array $x = []) => $x + ["visitID" => $visit, "siteID" => $site, "protocol" => "target_census", "status" => "open",
    "eventStart" => "2026-10-20T08:00:00Z", "observers" => "F11h E", "conditions" => "Junto a la fuente del Lobo", "species" => [COMMON],
    "clientUpdatedAt" => $now()];
$mark = fn(string $taxon, array $x = []) => $x + ["occurrenceID" => uuidV4(), "taxonID" => $taxon, "eventDate" => "2026-10-20T08:30:00Z",
    "decimalLatitude" => 37.0923, "decimalLongitude" => -3.3969, "coordinateUncertaintyInMeters" => 6, "individualCount" => 3, "mature_count" => 2,
    "visitID" => $visit, "clientUpdatedAt" => $now()];
$commonMark = $mark(COMMON);
$siteFirst = $siteBody();
[$code, $r] = $push($e, ["sites" => [$siteFirst], "visits" => [$visitBody()], "occurrences" => [$commonMark]]);
check("el editor sube sitio, visita y una marca de la visita → accepted",
    $code === 200 && ($r["siteResults"][0]["status"] ?? null) === "accepted" && ($r["visitResults"][0]["status"] ?? null) === "accepted"
    && ($r["results"][0]["status"] ?? null) === "accepted", json_encode($r));
[, $r2] = $push($e, ["sites" => [$siteFirst]]);
check("reenviar el mismo sitio → unchanged (idempotente)", ($r2["siteResults"][0]["status"] ?? null) === "unchanged", json_encode($r2));
[, $r] = $push($e, ["sites" => [$siteBody(["siteID" => uuidV4(), "footprintWKT" => "LINESTRING(-3.4 37.1, -3.39 37.1)"])]]);
check("radio y geometría a la vez → missing_field", ($r["siteResults"][0]["reason"] ?? null) === "missing_field", json_encode($r));
[, $r] = $push($e, ["visits" => [$visitBody(["visitID" => uuidV4(), "status" => "closed"])]]);
check("visita cerrada sin fin → missing_field", ($r["visitResults"][0]["reason"] ?? null) === "missing_field", json_encode($r));
[, $r] = $push($e, ["occurrences" => [$mark(COMMON, ["visitID" => "no-es-uuid"])]]);
check("marca con visitID que no es UUID → invalid_uuid", ($r["results"][0]["reason"] ?? null) === "invalid_uuid", json_encode($r));
[, $r] = $push($cu, ["visits" => [$visitBody(["clientUpdatedAt" => $now()])]]);
check("otro miembro no edita una visita ajena → forbidden", ($r["visitResults"][0]["reason"] ?? null) === "forbidden", json_encode($r));
[$code] = $push($v, ["sites" => [$siteBody(["siteID" => uuidV4()])]]);
check("el lector no crea sitios → 403", $code === 403, "HTTP {$code}");
// Transecto: el servidor calcula longitud y superficie (longitud × banda) en UTM 30N.
$tr = uuidV4();
[, $r] = $push($e, ["sites" => [["siteID" => $tr, "name" => "Transecto del arroyo", "siteType" => "transect", "bandM" => 10, "species" => [SENSITIVE],
    "footprintWKT" => "LINESTRING(-3.4000 37.1000, -3.3900 37.1000, -3.3900 37.1050)", "clientUpdatedAt" => $now()]]]);
$trRow = $pdo->query("SELECT * FROM sites WHERE siteID = " . $pdo->quote($tr))->fetch();
// Una visita al transecto con una marca de la Artemisia: así sale en el DwC-A (y su línea no debe salir al publicar).
$trVisit = uuidV4();
$push($e, ["visits" => [["visitID" => $trVisit, "siteID" => $tr, "protocol" => "transect", "status" => "closed", "eventStart" => "2026-10-21T08:00:00Z",
    "eventEnd" => "2026-10-21T09:00:00Z", "effortMinutes" => 60, "species" => [SENSITIVE], "clientUpdatedAt" => $now()]],
    "occurrences" => [["occurrenceID" => uuidV4(), "taxonID" => SENSITIVE, "visitID" => $trVisit, "eventDate" => "2026-10-21T08:20:00Z",
        "decimalLatitude" => 37.1012, "decimalLongitude" => -3.3911, "coordinateUncertaintyInMeters" => 5, "individualCount" => 4, "mature_count" => 4,
        "clientUpdatedAt" => $now()]]]);
check("transecto: longitud ≈ 1446 m y superficie = longitud × banda", ($r["siteResults"][0]["status"] ?? null) === "accepted" && $trRow
    && abs((float) $trRow["length_m"] - 1446) < 15 && abs((float) $trRow["area_m2"] - 10 * (float) $trRow["length_m"]) < 0.5, json_encode($trRow));

[$code, $p] = $pull($v);
$cursor = $p["cursor"] ?? 0;
$s = $by($p["sites"] ?? [], "siteID", $site);
$vv = $by($p["visits"] ?? [], "visitID", $visit);
$m = $by($p["items"] ?? [], "occurrenceID", $commonMark["occurrenceID"]);
check("pull del lector: sitio y visita exactos (solo especie de nivel 4)", $s && $s["generalized"] === false && $s["decimalLatitude"] === 37.0921
    && $s["radiusM"] === 50 && $s["species"] === [COMMON] && $vv && $vv["siteID"] === $site && $vv["conditions"] === "Junto a la fuente del Lobo", json_encode([$s, $vv]));
check("…y la marca con su visitID", $m && $m["visitID"] === $visit && $m["generalized"] === false, json_encode($m));
$t = $by($p["sites"] ?? [], "siteID", $tr);
check("transecto con especie sensible: al lector, generalizado y SIN la línea", $t && $t["generalized"] === true && $t["footprintWKT"] === null
    && $t["radiusM"] > 5000, json_encode($t));

// Una marca de la especie sensible en la visita baja el nivel del sitio: se vuelven a emitir sitio, visita y marcas.
$sens = $mark(SENSITIVE);
[, $r] = $push($e, ["occurrences" => [$sens]]);
check("marca de la Artemisia en la visita → accepted", ($r["results"][0]["status"] ?? null) === "accepted", json_encode($r));
[, $p] = $pull($v, $cursor);
$s = $by($p["sites"] ?? [], "siteID", $site);
$vv = $by($p["visits"] ?? [], "visitID", $visit);
$m = $by($p["items"] ?? [], "occurrenceID", $commonMark["occurrenceID"]);
check("el lector recibe otra vez el sitio, ahora generalizado (centro de celda, radio ampliado)", $s && $s["generalized"] === true
    && abs($s["decimalLatitude"] - 37.05) < 1e-9 && $s["radiusM"] > 5000, json_encode($s));
check("…la visita, sin las condiciones (texto libre)", $vv && $vv["generalized"] === true && $vv["conditions"] === null, json_encode($vv));
check("…y la marca de la Viola de esa visita, generalizada (no delata el sitio)", $m && $m["generalized"] === true && abs($m["decimalLatitude"] - 37.05) < 1e-9,
    json_encode($m));
[, $p] = $pull($cu);
$s = $by($p["sites"] ?? [], "siteID", $site);
check("al custodio, el sitio exacto", $s && $s["generalized"] === false && $s["decimalLatitude"] === 37.0921, json_encode($s));
[, $p] = $pull($e);
$s = $by($p["sites"] ?? [], "siteID", $site);
check("a su autor (editor), exacto", $s && $s["generalized"] === false, json_encode($s));

// Cierre de la visita con una ausencia: ida y vuelta.
$absent = $mark(COMMON, ["occurrenceStatus" => "absent", "individualCount" => 0, "mature_count" => null, "eventDate" => "2026-10-20T09:40:00Z"]);
unset($absent["mature_count"]);
[, $r] = $push($e, ["visits" => [$visitBody(["status" => "closed", "eventEnd" => "2026-10-20T09:40:00Z", "effortMinutes" => 100,
    "species" => [COMMON, SENSITIVE], "clientUpdatedAt" => $now()])], "occurrences" => [$absent]]);
check("cerrar la visita (fin, esfuerzo, objetivo) y su ausencia → accepted", ($r["visitResults"][0]["status"] ?? null) === "accepted"
    && ($r["results"][0]["status"] ?? null) === "accepted", json_encode($r));
[, $p] = $pull($e);
$vv = $by($p["visits"] ?? [], "visitID", $visit);
check("ida y vuelta: la visita vuelve cerrada con fin, esfuerzo y especies objetivo", $vv && $vv["status"] === "closed" && $vv["eventEnd"] === "2026-10-20T09:40:00Z"
    && $vv["effortMinutes"] === 100 && $vv["species"] === [COMMON, SENSITIVE], json_encode($vv));

// ------------------------------------------------------------------------------------------------
section("Serie temporal en la evaluación (servidor)");
// Dos sitios de la Viola con visitas de 2020 a 2025 y r = −0,1 exacta (recuentos sin redondear: maduros enteros, así que
// se eligen bases que den enteros… no hace falta: el ajuste trabaja con lo que llegue; se comprueba con margen).
$visits = [];
$marks = [];
foreach ([["A", 37.11, -3.41, 200], ["B", 37.12, -3.42, 120]] as [$name, $lat, $lon, $b0]) {
    $sid = uuidV4();
    [, $r] = $push($cu, ["sites" => [["siteID" => $sid, "name" => "Serie {$name}", "siteType" => "plot", "decimalLatitude" => $lat, "decimalLongitude" => $lon,
        "radiusM" => 20, "species" => [COMMON], "clientUpdatedAt" => $now()]]]);
    foreach (range(2020, 2025) as $y) {
        $vid = uuidV4();
        $visits[] = ["visitID" => $vid, "siteID" => $sid, "protocol" => "plot_count", "status" => "closed", "eventStart" => "{$y}-05-01T08:00:00Z",
            "eventEnd" => "{$y}-05-01T09:00:00Z", "effortMinutes" => 60, "species" => [COMMON], "clientUpdatedAt" => $now()];
        $n = (int) round($b0 * exp(-0.1 * ($y - 2020)));
        $marks[] = ["occurrenceID" => uuidV4(), "taxonID" => COMMON, "visitID" => $vid, "eventDate" => "{$y}-05-01T08:10:00Z", "decimalLatitude" => $lat,
            "decimalLongitude" => $lon, "coordinateUncertaintyInMeters" => 5, "individualCount" => $n, "mature_count" => $n, "clientUpdatedAt" => $now()];
    }
}
[, $r] = $push($cu, ["visits" => $visits, "occurrences" => $marks]);
check("12 visitas y sus marcas → accepted", count(array_filter($r["visitResults"] ?? [], fn($x) => $x["status"] === "accepted")) === 12
    && count(array_filter($r["results"] ?? [], fn($x) => $x["status"] === "accepted")) === 12, json_encode($r));
http("PUT", "/api/catalogs/{$cat}/species/" . COMMON . "/parameters/generation_length", ["value" => 2, "quality" => "estimated"], $cu);
http("PUT", "/api/catalogs/{$cat}/species/" . COMMON . "/parameters/a_basis_b", ["value" => "yes", "quality" => "inferred", "justification" => "Índice de los sitios"], $cu);
[$code, $a] = http("GET", "/api/catalogs/{$cat}/species/" . COMMON . "/assessment", null, $v);
$sr = $a["series"] ?? [];
check("GET …/assessment trae la serie: evaluable, r ≈ −0,1 con IC, 2 sitios", $code === 200 && ($sr["status"] ?? null) === "ok"
    && abs($sr["trend"]["r"] + 0.1) < 0.01 && $sr["trend"]["lo"] < $sr["trend"]["r"] && $sr["nSites"] === 2, json_encode($sr["trend"] ?? $sr));
check("ventana de 10 años con la mitad observada: el resto, proyectado", ($sr["window"]["years"] ?? null) === 10 && ($sr["window"]["observedPct"] ?? null) === 50
    && ($sr["window"]["projected"] ?? null) === true, json_encode($sr["window"] ?? null));
check("el motor recibe A2b con la reducción de la serie y C1 con la tasa", ($a["inputs"]["A"]["items"][0]["subcriterion"] ?? null) === "A2"
    && ($a["inputs"]["A"]["items"][0]["basis"] ?? null) === ["b"] && abs(($a["inputs"]["C"]["c1AnnualRate"]["best"] ?? 0) - $sr["trend"]["r"]) < 1e-12
    && ($a["result"]["byCriterion"]["A"]["category"] ?? null) === "EN", json_encode([$a["inputs"]["A"] ?? null, $a["result"]["byCriterion"]["A"]["notation"] ?? null]));
check("sugiere b(v) (IC de r por debajo de 0)", (bool) array_filter($a["suggestions"] ?? [], fn($x) => $x["key"] === "decline_b_v"), json_encode($a["suggestions"] ?? null));

// ------------------------------------------------------------------------------------------------
section("Exportación: Darwin Core con núcleo Event");
$exp = fn(array $jar, array $body) => httpRaw("POST", "/api/catalogs/{$cat}/export", $body, $jar);
[$code, $raw] = $exp($e, ["mode" => "public"]);
$pub = unzipAll($raw);
check("«para publicar» por el editor: 200 con event.txt, occurrence.txt, measurementorfact.txt, meta.xml, eml.xml", $code === 200
    && isset($pub["dwca/event.txt"], $pub["dwca/occurrence.txt"], $pub["dwca/measurementorfact.txt"], $pub["dwca/meta.xml"], $pub["dwca/eml.xml"]), "HTTP {$code}");
[$eh, $ev, $ebad] = parseTsv($pub["dwca/event.txt"] ?? "");
[$oh, $oc, $obad] = parseTsv($pub["dwca/occurrence.txt"] ?? "");
[$mh, $mf, $mbad] = parseTsv($pub["dwca/measurementorfact.txt"] ?? "");
$evById = array_column($ev, null, "eventID");
check("event.txt: eventID únicos y todas las filas con sus columnas", !$ebad && count($evById) === count($ev));
check("toda ocurrencia cuelga de un evento que existe", !$obad && $oc && !array_filter($oc, fn($r) => !isset($evById[$r["eventID"]])));
$ve = $evById[$visit] ?? null;
check("la visita es un evento con su sitio como padre, protocolo, intervalo y esfuerzo", $ve && $ve["parentEventID"] === "biodex:site:{$site}"
    && $ve["samplingProtocol"] === "censo completo de especies objetivo" && $ve["eventDate"] === "2026-10-20T08:00:00Z/2026-10-20T09:40:00Z"
    && str_starts_with($ve["samplingEffort"], "100 minutos") && $ve["locationID"] === "biodex:site:{$site}" && isset($evById["biodex:site:{$site}"]), json_encode($ve));
$serieVisit = $evById[$visits[0]["visitID"]] ?? null;
check("visita de parcela: sampleSizeValue = superficie del sitio (π·20²) en square metre", $serieVisit && abs((float) $serieVisit["sampleSizeValue"] - M_PI * 400) < 0.5
    && $serieVisit["sampleSizeUnit"] === "square metre", json_encode($serieVisit));
$ab = array_values(array_filter($oc, fn($r) => $r["occurrenceID"] === $absent["occurrenceID"]))[0] ?? null;
check("ausencia: occurrenceStatus absent, organismo 0, colgada de la visita", $ab && $ab["occurrenceStatus"] === "absent" && $ab["individualCount"] === "0"
    && $ab["eventID"] === $visit, json_encode($ab));
check("measurementorfact: maduros con occurrenceID y esfuerzo de la visita solo con eventID", $mh[0] === "eventID" && !$mbad
    && array_filter($mf, fn($r) => $r["occurrenceID"] !== "" && $r["measurementType"] === "número de individuos maduros")
    && array_filter($mf, fn($r) => $r["occurrenceID"] === "" && $r["eventID"] === $visit && $r["measurementValue"] === "100"));
// meta.xml coherente con los ficheros: cada <field> es la columna de su cabecera, en orden.
$meta = new DOMDocument();
$okMeta = @$meta->loadXML($pub["dwca/meta.xml"] ?? "");
$xp = new DOMXPath($meta);
$xp->registerNamespace("t", "http://rs.tdwg.org/dwc/text/");
$cols = function (string $node) use ($xp): array {
    $out = [];
    foreach ($xp->query("{$node}/t:field") as $f) $out[(int) $f->getAttribute("index")] = substr(strrchr($f->getAttribute("term"), "/"), 1);
    ksort($out);
    return array_values($out);
};
$files = fn(string $node) => $xp->query("{$node}/t:files/t:location")->item(0)?->textContent;
check("meta.xml: núcleo Event = cabecera de event.txt", $okMeta && $files("//t:core") === "event.txt" && $cols("//t:core") === $eh);
check("meta.xml: extensión Occurrence = cabecera de occurrence.txt (coreid 0 = eventID)",
    $files("//t:extension[@rowType='http://rs.tdwg.org/dwc/terms/Occurrence']") === "occurrence.txt"
    && $cols("//t:extension[@rowType='http://rs.tdwg.org/dwc/terms/Occurrence']") === $oh && $oh[0] === "eventID");
check("meta.xml: extensión eMoF = cabecera de measurementorfact.txt",
    $cols("//t:extension[@rowType='http://rs.iobis.org/obis/terms/ExtendedMeasurementOrFact']") === $mh);

// Ninguna coordenada exacta de especie sensible en NINGÚN fichero del ZIP «para publicar»: ni de sus marcas, ni del
// sitio o la visita donde se contó (ni de la marca de la Viola de esa visita), ni de la traza del transecto.
$secrets = ["37.0921", "-3.3971", "37.0923", "-3.3969", "37.1012", "-3.3911", "-3.39 37.1", "-3.4 37.1", "37.105"];
$leaks = [];
foreach ($pub as $name => $content) foreach ($secrets as $sec) if (str_contains($content, $sec)) $leaks[] = "{$name}: {$sec}";
check("«para publicar»: ninguna coordenada exacta de especie sensible (eventos, footprintWKT, ocurrencias, hojas)", !$leaks, implode(" · ", $leaks));
check("«para publicar»: ni el nombre del sitio sensible ni sus condiciones", !array_filter($pub, fn($c) => str_contains($c, "Cañada del Lobo") || str_contains($c, "fuente del Lobo")));
[$code, $raw] = $exp($o, ["mode" => "full"]);
$full = unzipAll($raw);
check("«completa» (owner): el sitio y la línea del transecto, exactos", $code === 200 && str_contains($full["dwca/event.txt"] ?? "", "37.0921")
    && str_contains($full["dwca/event.txt"] ?? "", "LINESTRING(-3.4 37.1, -3.39 37.1, -3.39 37.105)"), "HTTP {$code}");
$te = array_column(parseTsv($pub["dwca/event.txt"] ?? "")[1], null, "eventID")[$trVisit] ?? null;
check("«para publicar»: la visita al transecto, generalizada, con muestra = longitud × banda", $te && $te["footprintWKT"] === ""
    && $te["dataGeneralizations"] !== "" && abs((float) $te["sampleSizeValue"] - (float) $trRow["area_m2"]) < 1 && $te["samplingProtocol"] === "transecto con banda de 10 m",
    json_encode($te));
check("hojas Visitas y Series en el ZIP", isset($full["hojas/csv/visitas.csv"], $full["hojas/csv/series.csv"]) && str_contains($full["hojas/csv/series.csv"], "Evaluable"));

cleanup($pdo);
echo $failures ? "\n{$failures} fallo(s).\n" : "\nTodo OK.\n";
exit($failures ? 1 : 0);
