<?php
// ================================================================================================
// Pruebas de F11j (modo búsqueda y esfuerzo de muestreo de las visitas): docker compose exec php php bin/test-f11j.php
//   1. Visitas con protocolo de búsqueda por /api/sync: el servidor calcula del recorrido la distancia, los huecos, el
//      tiempo activo, el objetivo alcanzado y las celdas cubiertas (no acepta esas cifras del cliente); el modo búsqueda
//      es irreversible; «libre» no puede ser ciega; un cliente antiguo no borra el esfuerzo.
//   2. Pull: el recorrido no sale nunca; las celdas cubiertas, solo a quien ve exacta la visita.
//   3. Evaluación: Chao2 solo con ocasiones válidas, con las excluidas por motivo.
//   4. Diseño de muestreo y método de referencia: permisos (owner y custodio), valores admitidos e historial.
//   5. Exportación: samplingProtocol, samplingEffort, sampleSize, footprintWKT con el recorrido, y el recorrido de una
//      visita con especie sensible NO sale en la exportación para publicar (ni en Plinian Core).
// Crea usuarios f11j-*@biodex.test y el catálogo «test-f11j …», y los borra al terminar (y al empezar).
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/api.php";
require_once __DIR__ . "/../Lib/migrate.php";
require_once __DIR__ . "/../Lib/iucn.php";
require_once __DIR__ . "/../Lib/track.php";
require_once __DIR__ . "/test-lib.php";

$pdo = db();
runMigrations($pdo);

const SENSITIVE = "9918";   // Artemisia granatensis: nivel 2 en la prueba
const COMMON = "9392";      // Viola crassiuscula: nivel 4

function cleanup(PDO $pdo): void {
    $in = "SELECT id FROM catalogs WHERE name LIKE 'test-f11j %'";
    foreach (["species_parameters", "species_locations", "species_assessments", "species_metrics", "access_log", "occurrences", "impacts", "visits", "sites"] as $t) {
        $pdo->exec("DELETE FROM {$t} WHERE catalog_id IN ({$in})");
    }
    $pdo->exec("DELETE FROM catalogs WHERE name LIKE 'test-f11j %'");
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f11j-%@biodex.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f11j-%@biodex.test'");
}
cleanup($pdo);

section("Usuarios y catálogo");
$suffix = bin2hex(random_bytes(4));
$jars = ["o" => [], "c" => [], "e" => [], "v" => []];
$ids = [];
foreach ($jars as $k => &$jar) {
    $email = "f11j-{$k}-{$suffix}@biodex.test";
    [$code] = http("POST", "/api/auth/register", ["email" => $email, "password" => "correcta-horse-battery", "name" => "F11j " . strtoupper($k)], $jar);
    check("registro de " . strtoupper($k), $code === 201, "HTTP {$code}");
    $ids[$k] = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();
}
unset($jar);
[$o, $cu, $e, $v] = [&$jars["o"], &$jars["c"], &$jars["e"], &$jars["v"]];
[$code, $r] = http("POST", "/api/catalogs", ["name" => "test-f11j {$suffix}", "taxonIDs" => [SENSITIVE, COMMON]], $o);
check("O crea el catálogo (Artemisia y Viola)", $code === 201, "HTTP {$code} " . json_encode($r));
$cat = $r["catalog"]["id"] ?? "";
$ins = $pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role, granted_by) VALUES (?, ?, ?, ?)");
foreach (["c" => "custodian", "e" => "editor", "v" => "viewer"] as $k => $role) $ins->execute([$cat, $ids[$k], $role, $ids["o"]]);
foreach ([SENSITIVE => 2, COMMON => 4] as $t => $lv) {
    [$code] = http("PUT", "/api/catalogs/{$cat}/species/{$t}/sensitivity", ["level" => $lv, "reason" => "prueba F11j"], $o);
    check("nivel {$lv} para {$t}", $code === 200, "HTTP {$code}");
}

$push = fn(array &$jar, array $body) => http("POST", "/api/sync/push", ["catalogId" => $cat] + $body, $jar);
$pull = fn(array &$jar, int $since = 0) => http("GET", "/api/sync/pull?catalogId={$cat}&since={$since}", null, $jar);
$tick = 0;
$now = function () use (&$tick) { $tick += 10; return (new DateTimeImmutable("now", new DateTimeZone("UTC")))->modify("+{$tick} milliseconds")->format("Y-m-d\TH:i:s.v\Z"); };
$by = fn(array $list, string $key, string $id) => array_values(array_filter($list, fn($x) => ($x[$key] ?? null) === $id))[0] ?? null;
$iso = fn(int $t) => gmdate("Y-m-d\TH:i:s", $t) . ".000Z";

/** Recta hacia el este desde ($lat, $lon): $n tramos de $step m cada $dt s desde $t0 → puntos [lat, lon, t, 5, t]. */
$line = function (float $lat, float $lon, int $t0, int $n, float $step = 50, int $dt = 100): array {
    $pts = [];
    for ($i = 0; $i <= $n; $i++) $pts[] = [$lat, round($lon + $i * $step / (111195 * cos(deg2rad($lat))), 6), $t0 + $i * $dt, 5, $t0 + $i * $dt];
    return $pts;
};
$visitBody = fn(string $id, string $taxon, int $t0, array $x = []) => $x + ["visitID" => $id, "siteID" => null, "protocol" => "timed_search", "status" => "open",
    "eventStart" => $iso($t0), "observers" => "F11j E", "species" => [$taxon], "decimalLatitude" => 37.06, "decimalLongitude" => -3.36,
    "effortProtocol" => "timed", "effortTarget" => 30, "blind" => true, "visitKind" => "exploratory", "clientUpdatedAt" => $now()];
$mark = fn(string $taxon, string $visit, float $lat, float $lon, int $t) => ["occurrenceID" => uuidV4(), "taxonID" => $taxon, "visitID" => $visit,
    "eventDate" => $iso($t), "decimalLatitude" => $lat, "decimalLongitude" => $lon, "coordinateUncertaintyInMeters" => 6, "individualCount" => 3,
    "clientUpdatedAt" => $now()];

// ------------------------------------------------------------------------------------------------
section("Visita con tiempo fijo en modo búsqueda: el servidor calcula el esfuerzo del recorrido");
$T = strtotime("2026-10-20T08:00:00Z");
$v1 = uuidV4();
[$code, $r] = $push($e, ["visits" => [$visitBody($v1, COMMON, $T)]]);
check("visita abierta con protocolo y modo búsqueda → accepted", $code === 200 && ($r["visitResults"][0]["status"] ?? null) === "accepted", json_encode($r));
[, $p] = $pull($e);
$got = $by($p["visits"] ?? [], "visitID", $v1);
check("pull: effortProtocol timed, objetivo 30, blind, exploratoria y sin cifras todavía", $got && $got["effortProtocol"] === "timed" && $got["effortTarget"] === 30
    && $got["blind"] === true && $got["visitKind"] === "exploratory" && $got["distanceM"] === null && $got["targetMet"] === false && $got["coveredCells"] === null, json_encode($got));
// 20 tramos de 50 m cada 100 s: 1000 m en 2000 s (33,3 min ≥ 30).
$track1 = $line(37.06, -3.36, $T, 20);
[$code, $r] = $push($e, ["visits" => [$visitBody($v1, COMMON, $T, ["status" => "closed", "eventEnd" => $iso($T + 2000), "track" => $track1, "pauses" => [],
    "distanceM" => 99999, "targetMet" => false, "trackComplete" => false, "coveredCells" => ["1,1"]])], "occurrences" => [$mark(COMMON, $v1, 37.06, -3.36, $T + 300)]]);
check("cierre con recorrido → accepted", $code === 200 && ($r["visitResults"][0]["status"] ?? null) === "accepted" && ($r["results"][0]["status"] ?? null) === "accepted", json_encode($r));
[, $p] = $pull($o);
$got = $by($p["visits"] ?? [], "visitID", $v1);
$expectA = trackAnalyze($track1, []);
check("distancia (≈ 1000 m), tiempo activo (2000 s), objetivo y recorrido completos: los del servidor, no los del cliente", $got && abs($got["distanceM"] - 1000) < 2
    && $got["distanceM"] == $expectA["distanceM"] && $got["activeSeconds"] === 2000 && $got["trackedSeconds"] === 2000 && $got["trackGaps"] === 0
    && $got["targetMet"] === true && $got["trackComplete"] === true, json_encode($got));
check("celdas cubiertas calculadas del recorrido (las del cliente se ignoran)", $got && $got["coveredCells"] === $expectA["cells"] && $got["nCoveredCells"] === count($expectA["cells"])
    && !in_array("1,1", $got["coveredCells"], true), json_encode($got["coveredCells"] ?? null));
check("el recorrido no sale por el pull", $got && !array_key_exists("track", $got) && !str_contains(json_encode($p), "\"points\""));
$stored = $pdo->query("SELECT n_points FROM visit_tracks WHERE visitID = " . $pdo->quote($v1))->fetchColumn();
check("…y queda guardado en visit_tracks (21 puntos)", (int) $stored === 21, (string) $stored);

section("Validación y reglas del modo búsqueda");
[, $r] = $push($e, ["visits" => [$visitBody(uuidV4(), COMMON, $T, ["effortTarget" => null])]]);
check("tiempo fijo sin objetivo → rechazada (missing_field)", ($r["visitResults"][0]["reason"] ?? null) === "missing_field", json_encode($r));
[, $r] = $push($e, ["visits" => [$visitBody(uuidV4(), COMMON, $T, ["effortProtocol" => "paseo"])]]);
check("protocolo de búsqueda desconocido → rechazada", ($r["visitResults"][0]["status"] ?? null) === "rejected", json_encode($r));
[, $r] = $push($e, ["visits" => [$visitBody(uuidV4(), COMMON, $T, ["status" => "closed", "eventEnd" => $iso($T + 2000), "track" => [[37.06, -3.36, $T, 80, $T]]])]]);
check("punto con precisión peor de 30 m → rechazada (invalid_field)", ($r["visitResults"][0]["reason"] ?? null) === "invalid_field", json_encode($r));
[, $r] = $push($e, ["visits" => [$visitBody(uuidV4(), COMMON, $T, ["status" => "closed", "eventEnd" => $iso($T + 2000),
    "track" => [[37.06, -3.36, $T + 500, 5, $T + 500], [37.06, -3.359, $T + 100, 5, $T + 100]]])]]);
check("recorrido desordenado → rechazada", ($r["visitResults"][0]["reason"] ?? null) === "invalid_field", json_encode($r));
$vFree = uuidV4();
[, $r] = $push($e, ["visits" => [$visitBody($vFree, COMMON, $T, ["effortProtocol" => "free", "effortTarget" => 30, "blind" => true, "status" => "closed", "eventEnd" => $iso($T + 600)])]]);
[, $p] = $pull($e);
$got = $by($p["visits"] ?? [], "visitID", $vFree);
check("«libre» no puede ser ciega ni lleva objetivo", ($r["visitResults"][0]["status"] ?? null) === "accepted" && $got && $got["blind"] === false && $got["effortTarget"] === null, json_encode($got));

// Salir del modo búsqueda es irreversible.
$v2 = uuidV4();
$push($e, ["visits" => [$visitBody($v2, COMMON, $T + 86400)]]);
$push($e, ["visits" => [$visitBody($v2, COMMON, $T + 86400, ["blind" => false])]]);
[, $r] = $push($e, ["visits" => [$visitBody($v2, COMMON, $T + 86400, ["blind" => true, "status" => "closed", "eventEnd" => $iso($T + 86400 + 2000),
    "track" => $line(37.06, -3.36, $T + 86400, 20), "pauses" => []])], "occurrences" => [$mark(COMMON, $v2, 37.06, -3.359, $T + 86400 + 300)]]);
[, $p] = $pull($e);
$got = $by($p["visits"] ?? [], "visitID", $v2);
check("una visita que salió del modo búsqueda no vuelve a ser ciega aunque el cliente lo diga", ($r["visitResults"][0]["status"] ?? null) === "accepted"
    && $got && $got["blind"] === false && $got["targetMet"] === true, json_encode($got));

// Un cliente anterior a F11j edita la visita (sin los campos nuevos): no se pierden.
[, $r] = $push($e, ["visits" => [["visitID" => $v1, "siteID" => null, "protocol" => "timed_search", "status" => "closed", "eventStart" => $iso($T),
    "eventEnd" => $iso($T + 2000), "observers" => "F11j E y otra", "species" => [COMMON], "clientUpdatedAt" => $now()]]]);
[, $p] = $pull($e);
$got = $by($p["visits"] ?? [], "visitID", $v1);
check("edición sin los campos de esfuerzo (cliente antiguo): se conservan protocolo, blind, distancia y celdas", ($r["visitResults"][0]["status"] ?? null) === "accepted"
    && $got && $got["observers"] === "F11j E y otra" && $got["effortProtocol"] === "timed" && $got["blind"] === true && abs($got["distanceM"] - 1000) < 2
    && $got["trackComplete"] === true && count($got["coveredCells"] ?? []) >= 1, json_encode($got));

// Hueco de 15 min y una pausa declarada.
$v3 = uuidV4();
$T3 = $T + 2 * 86400;
$gapTrack = array_merge($line(37.06, -3.36, $T3, 4), $line(37.06, -3.35, $T3 + 1300, 4));
$push($e, ["visits" => [$visitBody($v3, COMMON, $T3, ["status" => "closed", "eventEnd" => $iso($T3 + 1900), "track" => $gapTrack, "pauses" => []])],
    "occurrences" => [$mark(COMMON, $v3, 37.06, -3.36, $T3 + 100)]]);
[, $p] = $pull($e);
$got = $by($p["visits"] ?? [], "visitID", $v3);
check("hueco de 15 min: 1 hueco, no suma su distancia (≈ 400 m, no ≈ 1290 m) y el recorrido queda incompleto", $got && $got["trackGaps"] === 1
    && abs($got["distanceM"] - 400) < 2 && $got["trackComplete"] === false && $got["targetMet"] === true, json_encode($got));
$v3b = uuidV4();
$push($e, ["visits" => [$visitBody($v3b, COMMON, $T3 + 86400, ["effortTarget" => 10, "status" => "closed", "eventEnd" => $iso($T3 + 86400 + 1900),
    "track" => array_merge($line(37.06, -3.36, $T3 + 86400, 4), $line(37.06, -3.35, $T3 + 86400 + 1300, 6)), "pauses" => [[$T3 + 86400 + 400, $T3 + 86400 + 1300]]])]]);
[, $p] = $pull($e);
$got = $by($p["visits"] ?? [], "visitID", $v3b);
check("la misma parada declarada como pausa: sin hueco, tiempo activo 1000 s y recorrido completo", $got && $got["trackGaps"] === 0 && $got["activeSeconds"] === 1000
    && $got["trackComplete"] === true && $got["targetMet"] === true, json_encode($got));

// ------------------------------------------------------------------------------------------------
section("Visita con especie sensible: las celdas y el recorrido no salen a quien no ve exacto");
$v4 = uuidV4();
$T4 = $T + 5 * 86400;
$track4 = $line(37.0921, -3.3971, $T4, 20);
[, $r] = $push($e, ["visits" => [$visitBody($v4, SENSITIVE, $T4, ["decimalLatitude" => 37.0921, "decimalLongitude" => -3.3971, "status" => "closed",
    "eventEnd" => $iso($T4 + 2000), "track" => $track4, "pauses" => []])], "occurrences" => [$mark(SENSITIVE, $v4, 37.0923, -3.3969, $T4 + 300)]]);
check("visita a la Artemisia (nivel 2) con recorrido → accepted", ($r["visitResults"][0]["status"] ?? null) === "accepted", json_encode($r));
[, $p] = $pull($v);
$gv = $by($p["visits"] ?? [], "visitID", $v4);
check("el lector la recibe generalizada: cuántas celdas, no cuáles, y ninguna coordenada del recorrido", $gv && $gv["generalized"] === true && $gv["coveredCells"] === null
    && $gv["nCoveredCells"] >= 1 && $gv["distanceM"] > 0 && !str_contains(json_encode($p), "37.0921") && !str_contains(json_encode($p), "-3.3971"), json_encode($gv));
[, $p] = $pull($o);
$gv = $by($p["visits"] ?? [], "visitID", $v4);
check("la owner la ve con sus celdas", $gv && $gv["generalized"] === false && is_array($gv["coveredCells"]) && count($gv["coveredCells"]) >= 1, json_encode($gv));

// ------------------------------------------------------------------------------------------------
section("Evaluación: Chao2 solo con ocasiones válidas");
$base = "/api/catalogs/{$cat}/species/" . COMMON;
[$code, $a] = http("GET", "{$base}/assessment", null, $o);
$c = $a["completeness"] ?? [];
check("Viola: 1 ocasión válida (v1) de 5 visitas; excluidas 1 libre, 1 no ciega y 1 por recorrido; la de 16,7 min, con solo 2, no se filtra por rango", $code === 200
    && ($c["considered"] ?? null) === 5 && ($c["excluded"]["free"] ?? null) === 1 && ($c["excluded"]["notBlind"] ?? null) === 1 && ($c["excluded"]["track"] ?? null) === 1
    && ($c["occasions"] ?? null) === 2 && ($c["protocol"] ?? null) === "timed", json_encode($c));
check("…y la línea bajo Chao2 lo dice", str_contains($a["sufficiency"][0]["detail"] ?? "", "2 ocasiones usadas") && str_contains($a["sufficiency"][0]["detail"] ?? "", "1 no ciega")
    && str_contains($a["sufficiency"][0]["detail"] ?? "", "1 de protocolo libre") && str_contains($a["sufficiency"][0]["detail"] ?? "", "1 con recorrido incompleto"), $a["sufficiency"][0]["detail"] ?? "");
check("barra «Esfuerzo estandarizado» 2 de 5 y AOO inferida", ($a["sufficiency"][0]["items"][0]["id"] ?? null) === "occasions" && ($a["sufficiency"][0]["items"][0]["value"] ?? null) === 2
    && ($a["sufficiency"][0]["items"][0]["target"] ?? null) === 5 && ($a["inputs"]["B"]["quality"]["aoo"] ?? null) === "inferred", json_encode($a["sufficiency"][0]["items"][0] ?? null));
check("la visita a la Artemisia no cuenta para la Viola (otra especie objetivo)", ($c["considered"] ?? null) === 5);

// ------------------------------------------------------------------------------------------------
section("Diseño de muestreo y método de referencia");
[$code] = http("PUT", "{$base}/parameters/sampling_design", ["value" => "exploratory"], $e);
check("el editor no fija el diseño → 403", $code === 403, "HTTP {$code}");
[$code] = http("PUT", "{$base}/parameters/sampling_design", ["value" => "exploratory"], $v);
check("el lector tampoco → 403", $code === 403, "HTTP {$code}");
[$code] = http("PUT", "{$base}/parameters/sampling_design", ["value" => "al azar"], $cu);
check("un diseño que no existe → 400", $code === 400, "HTTP {$code}");
[$code, $r] = http("PUT", "{$base}/parameters/sampling_design", ["value" => "exploratory", "source" => "Primera campaña"], $cu);
check("el custodio fija «exploratorio»", $code === 200 && ($r["parameter"]["value"] ?? null) === "exploratory", json_encode($r));
[$code] = http("PUT", "{$base}/parameters/sampling_design", ["value" => "mixed", "source" => "Se añaden sitios repetidos"], $o);
check("la owner lo cambia a «mixto»", $code === 200, "HTTP {$code}");
[$code] = http("PUT", "{$base}/parameters/sufficiency_method", ["value" => "occupancy"], $o);
check("la ocupación aún no es un método admitido → 400", $code === 400, "HTTP {$code}");
[$code] = http("PUT", "{$base}/parameters/sufficiency_method", ["value" => "chao2"], $o);
check("Chao2 como método de referencia", $code === 200, "HTTP {$code}");
[, $a] = http("GET", "{$base}/assessment", null, $v);
$s = $a["sampling"] ?? [];
$h = $s["history"] ?? [];
check("el lector ve diseño, método y los métodos disponibles (ocupación: próximamente), sin poder editar", ($s["design"] ?? null) === "mixed" && ($s["method"] ?? null) === "chao2"
    && ($s["methodSet"] ?? null) === true && ($s["methods"] ?? null) === [["id" => "chao2", "available" => true], ["id" => "occupancy", "available" => false]]
    && ($a["canEdit"] ?? null) === false, json_encode($s));
check("historial: 3 cambios, el último primero, con quién, valor anterior y motivo", count($h) === 3 && $h[0]["key"] === "sufficiency_method" && $h[0]["from"] === null
    && $h[0]["to"] === "chao2" && $h[1]["key"] === "sampling_design" && $h[1]["from"] === "exploratory" && $h[1]["to"] === "mixed"
    && $h[1]["reason"] === "Se añaden sitios repetidos" && $h[1]["by"] === "F11j O" && $h[2]["from"] === null && $h[2]["reason"] === "Primera campaña" && $h[2]["by"] === "F11j C", json_encode($h));
[, $p] = $pull($e);
check("el diseño baja por el pull a los móviles", (bool) array_filter($p["parameters"] ?? [], fn($x) => ($x["key"] ?? null) === "sampling_design" && ($x["value"] ?? null) === "mixed"));

// ------------------------------------------------------------------------------------------------
section("Exportación: Darwin Core (núcleo Event) con protocolo, esfuerzo y recorrido");
require_once __DIR__ . "/../Lib/visits.php";
$exp = fn(array $jar, array $body) => httpRaw("POST", "/api/catalogs/{$cat}/export", $body, $jar);
[$code, $raw] = $exp($e, ["mode" => "public", "formats" => ["dwc", "plinian", "sheets"]]);
$pub = unzipAll($raw);
[, $ev, $ebad] = parseTsv($pub["dwca/event.txt"] ?? "");
$evById = array_column($ev, null, "eventID");
$e1 = $evById[$v1] ?? null;
check("«para publicar»: 200 y la visita de la Viola es un evento", $code === 200 && !$ebad && $e1 !== null, "HTTP {$code}");
check("samplingProtocol: conteo | búsqueda con tiempo fijo, objetivo y ciega", $e1 && $e1["samplingProtocol"] === "búsqueda por tiempo | búsqueda con tiempo fijo (Goff et al. 1982), objetivo 30 min, búsqueda ciega (registros anteriores ocultos)",
    $e1["samplingProtocol"] ?? "");
check("samplingEffort: minutos de búsqueda activa y metros recorridos", $e1 && str_starts_with($e1["samplingEffort"], "33,3 minutos de búsqueda activa; 1000")
    && str_contains($e1["samplingEffort"], "m recorridos"), $e1["samplingEffort"] ?? "");
check("sampleSizeValue 33.3 minute y eventDate como intervalo", $e1 && (float) $e1["sampleSizeValue"] === 33.3 && $e1["sampleSizeUnit"] === "minute"
    && $e1["eventDate"] === gmdate("Y-m-d\TH:i:s\Z", $T) . "/" . gmdate("Y-m-d\TH:i:s\Z", $T + 2000), json_encode([$e1["sampleSizeValue"] ?? null, $e1["eventDate"] ?? null]));
check("footprintWKT: el recorrido como LINESTRING (especie sin restricción)", $e1 && $e1["footprintWKT"] === trackWkt($track1) && str_starts_with($e1["footprintWKT"], "LINESTRING(-3.36 37.06,")
    && $e1["footprintSRS"] === "EPSG:4326", substr($e1["footprintWKT"] ?? "", 0, 80));
$e3 = $evById[$v3] ?? null;
check("con un hueco: MULTILINESTRING de dos tramos y «recorrido GPS incompleto»", $e3 && str_starts_with($e3["footprintWKT"], "MULTILINESTRING((") && substr_count($e3["footprintWKT"], "), (") === 1
    && str_contains($e3["samplingEffort"], "recorrido GPS incompleto"), json_encode([$e3["footprintWKT"] ?? null, $e3["samplingEffort"] ?? null]));
$e2 = $evById[$v2] ?? null;
check("visita no ciega: lo dice el protocolo", $e2 && str_contains($e2["samplingProtocol"], "búsqueda no ciega"), $e2["samplingProtocol"] ?? "");
[, $mf] = parseTsv($pub["dwca/measurementorfact.txt"] ?? "");
check("measurementorfact: distancia recorrida de la visita", (bool) array_filter($mf, fn($r) => $r["measurementID"] === "{$v1}:distance" && $r["measurementUnit"] === "m" && abs((float) $r["measurementValue"] - 1000) < 2));
$e4 = $evById[$v4] ?? null;
check("visita con especie sensible, «para publicar»: generalizada, SIN footprintWKT, con su esfuerzo", $e4 && $e4["footprintWKT"] === "" && $e4["footprintSRS"] === ""
    && $e4["dataGeneralizations"] !== "" && str_contains($e4["samplingEffort"], "m recorridos") && $e4["decimalLatitude"] === "37.05", json_encode($e4));
// Ninguna coordenada del recorrido de la sensible en NINGÚN fichero (Darwin Core, Plinian Core, hojas).
$secrets = ["37.0921", "-3.3971", "37.0923", "-3.3969", "-3.3965", "-3.396 37.09"];
$leaks = [];
foreach ($pub as $name => $content) foreach ($secrets as $sec) if (str_contains($content, $sec)) $leaks[] = "{$name}: {$sec}";
check("«para publicar»: ninguna coordenada del recorrido ni de la marca de la especie sensible en ningún fichero", !$leaks, implode(" · ", $leaks));
$plinian = array_filter($pub, fn($n) => str_contains(strtolower($n), "plinian"), ARRAY_FILTER_USE_KEY);
check("Plinian Core: sin recorridos ni geometría de visitas", $plinian && !array_filter($plinian, fn($c) => str_contains($c, "LINESTRING") || str_contains($c, "footprintWKT")), implode(", ", array_keys($plinian)));
check("hoja Visitas: búsqueda, objetivo, tiempo activo, distancia, celdas, modo búsqueda y recorrido completo", isset($pub["hojas/csv/visitas.csv"])
    && str_contains($pub["hojas/csv/visitas.csv"], "Modo búsqueda") && str_contains($pub["hojas/csv/visitas.csv"], "búsqueda con tiempo fijo (Goff et al. 1982)")
    && str_contains($pub["hojas/csv/visitas.csv"], "exploratoria"), substr($pub["hojas/csv/visitas.csv"] ?? "", 0, 300));
check("hoja Evaluación por especie: diseño, método, suficiencia espacial y cambios", isset($pub["hojas/csv/evaluacion_especie.csv"])
    && str_contains($pub["hojas/csv/evaluacion_especie.csv"], "Diseño de muestreo") && str_contains($pub["hojas/csv/evaluacion_especie.csv"], "mixto")
    && str_contains($pub["hojas/csv/evaluacion_especie.csv"], "Chao2 (completitud por incidencia)") && str_contains($pub["hojas/csv/evaluacion_especie.csv"], "exploratory → mixed")
    && str_contains($pub["hojas/csv/evaluacion_especie.csv"], "motivo: Se añaden sitios repetidos") && str_contains($pub["hojas/csv/evaluacion_especie.csv"], "2 ocasiones usadas"),
    substr($pub["hojas/csv/evaluacion_especie.csv"] ?? "", 0, 200));
[$code, $raw] = $exp($o, ["mode" => "full"]);
$full = unzipAll($raw);
$f4 = array_column(parseTsv($full["dwca/event.txt"] ?? "")[1], null, "eventID")[$v4] ?? null;
check("«completa» (owner): el recorrido de la visita sensible, exacto", $code === 200 && $f4 && $f4["footprintWKT"] === trackWkt($track4), substr($f4["footprintWKT"] ?? "", 0, 80));

cleanup($pdo);
echo $failures ? "\n{$failures} fallo(s).\n" : "\nTodo OK.\n";
exit($failures ? 1 : 0);
