<?php
// ================================================================================================
// Pruebas de F5a (sincronización en el servidor): docker compose exec php php bin/test-f5.php
// Imprime OK/FAIL por comprobación y sale con código 1 si alguna falla. Consulta EIDOS (lince) y la
// Fototeca en vivo. Crea usuarios f5-*@biodex.test, el catálogo «test-f5 …» y la especie de usuario
// «Inventadus sincronicus», y los borra al terminar (y al empezar, por si quedó algo).
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/api.php";        // uuidV4()
require_once __DIR__ . "/../Lib/migrate.php";
require_once __DIR__ . "/test-lib.php";

const LYNX = "11943";
const INVENTED = "Inventadus sincronicus";

$pdo = db();
runMigrations($pdo);

function cleanup(PDO $pdo): void {
    $pdo->exec("DELETE FROM occurrences WHERE catalog_id IN (SELECT id FROM catalogs WHERE name LIKE 'test-f5 %')");
    $pdo->exec("DELETE FROM access_log WHERE catalog_id IN (SELECT id FROM catalogs WHERE name LIKE 'test-f5 %')");
    $pdo->exec("DELETE FROM catalogs WHERE name LIKE 'test-f5 %'");
    $pdo->exec("DELETE FROM species WHERE source = 'user' AND canonical_name = " . $pdo->quote(INVENTED));
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f5-%@biodex.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f5-%@biodex.test'");
}
cleanup($pdo);

/** GET sin normalizar la ruta (para /img/fototeca/../): [código, Content-Type]. */
function rawGet(string $path): array {
    global $BASE;
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PATH_AS_IS => true, CURLOPT_TIMEOUT => 30]);
    curl_exec($ch);
    $out = [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
    curl_close($ch);
    return $out;
}

/** Marca nueva con los términos DwC que manda el móvil. */
function mark(string $taxonID, float $lat, float $lon, string $clientUpdatedAt): array {
    return [
        "occurrenceID" => uuidV4(), "taxonID" => $taxonID, "eventDate" => "2026-05-01T08:30:00+02:00",
        "decimalLatitude" => $lat, "decimalLongitude" => $lon, "coordinateUncertaintyInMeters" => 8,
        "individualCount" => 1, "lifeStage" => "adult", "occurrenceRemarks" => "prueba f5",
        "clientUpdatedAt" => $clientUpdatedAt,
    ];
}

function byId(?array $body, string $key = "results"): array {
    return array_column($body[$key] ?? [], null, "occurrenceID");
}

function syncSeq(PDO $pdo): int {
    return (int) $pdo->query("SELECT value FROM sync_seq WHERE id = 1")->fetchColumn();
}

/** Pull completo por páginas: [items en orden, páginas, última respuesta]. */
function pullAll(string $catalogId, array &$jar, int $limit = 500): array {
    $items = [];
    $since = 0;
    $pages = 0;
    do {
        [$c, $r] = http("GET", "/api/sync/pull?catalogId={$catalogId}&since={$since}&limit={$limit}", null, $jar);
        if ($c !== 200) return [$items, $pages, ["code" => $c] + (array) $r];
        $pages++;
        array_push($items, ...$r["items"]);
        $since = $r["cursor"];
    } while ($r["hasMore"] && $pages < 50);
    return [$items, $pages, $r];
}

function viewExactCount(PDO $pdo, string $catalogId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM access_log WHERE catalog_id = ? AND action = 'view_exact'");
    $stmt->execute([$catalogId]);
    return (int) $stmt->fetchColumn();
}

// ------------------------------------------------------------------------------------------------
section("Usuarios y catálogo");
$suffix = bin2hex(random_bytes(4));
$jars = ["o" => [], "a" => [], "b" => [], "v" => []];
$ids = [];
foreach ($jars as $k => &$jar) {
    $email = "f5-{$k}-{$suffix}@biodex.test";
    [$c] = http("POST", "/api/auth/register", ["email" => $email, "password" => "correcta-horse-battery", "name" => "F5 " . strtoupper($k)], $jar);
    check("registro de " . strtoupper($k), $c === 201, "HTTP {$c}");
    $ids[$k] = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();
}
unset($jar);
[$o, $a, $b, $v] = [&$jars["o"], &$jars["a"], &$jars["b"], &$jars["v"]];

[$c, $r] = http("POST", "/api/catalogs", ["name" => "test-f5 {$suffix}", "taxonIDs" => [LYNX]], $o);
check("O crea el catálogo con el lince", $c === 201, "HTTP {$c} " . json_encode($r));
$cat = $r["catalog"]["id"] ?? "";
$seq0 = $r["catalog"]["contentSeq"] ?? null;
check("…con contentSeq", is_int($seq0) && $seq0 >= 1, json_encode($seq0));

$ins = $pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role, granted_by) VALUES (?, ?, ?, ?)");
foreach (["a" => "editor", "b" => "editor", "v" => "viewer"] as $k => $role) $ins->execute([$cat, $ids[$k], $role, $ids["o"]]);

// ------------------------------------------------------------------------------------------------
section("Especies creadas sin conexión");
$userTaxon = "biodex:" . uuidV4();
$newSpecies = ["scientificName" => INVENTED, "scientificNameAuthorship" => "Prueba, 2026", "taxonRank" => "Species", "taxonID" => $userTaxon];
[$c, $r] = http("POST", "/api/species", $newSpecies, $a);
check("biodex:<uuid> propuesto → 201 con ese id", $c === 201 && ($r["species"]["taxonID"] ?? null) === $userTaxon, "HTTP {$c} " . json_encode($r));
[$c, $r] = http("POST", "/api/species", $newSpecies, $a);
check("…el mismo otra vez → 200 (idempotente)", $c === 200 && ($r["species"]["taxonID"] ?? null) === $userTaxon, "HTTP {$c}");
[$c, $r] = http("POST", "/api/species", ["scientificName" => "Lynx pardinus", "scientificNameAuthorship" => "(Temminck, 1827)",
    "taxonRank" => "Species", "taxonID" => "biodex:" . uuidV4()], $a);
check("«Lynx pardinus» con id propio → 409 con el id de EIDOS", $c === 409 && ($r["taxonID"] ?? null) === LYNX, "HTTP {$c} " . json_encode($r));
[$c] = http("POST", "/api/species", ["taxonID" => "biodex:nope"] + $newSpecies, $a);
check("taxonID mal formado → 400", $c === 400, "HTTP {$c}");

[$c] = http("POST", "/api/catalogs/{$cat}/species", ["taxonID" => $userTaxon], $a);
check("A añade la especie al catálogo", $c === 201, "HTTP {$c}");
[$c] = http("PUT", "/api/catalogs/{$cat}/species/" . LYNX . "/sensitivity", ["level" => 2, "reason" => "prueba f5"], $o);
check("O fija el lince en nivel 2", $c === 200, "HTTP {$c}");
[$c, $r] = http("GET", "/api/catalogs/{$cat}/offline", null, $v);
$offline = $r;
check("contentSeq sube al añadir especie y cambiar nivel", $c === 200 && ($r["contentSeq"] ?? 0) >= $seq0 + 2, "HTTP {$c} {$seq0} → " . ($r["contentSeq"] ?? "?"));
$levels = array_column(array_map(fn($s) => ["t" => $s["taxonID"], "l" => $s["sensitivity"]["level"]], $r["species"] ?? []), "l", "t");
check("niveles: lince 2, especie de usuario 4", ($levels[LYNX] ?? null) === 2 && ($levels[$userTaxon] ?? null) === 4, json_encode($levels));

// ------------------------------------------------------------------------------------------------
section("Push idempotente");
$t1 = "2026-05-01T08:31:00.100Z";
$m = [mark(LYNX, 37.1234567, -3.6012345, $t1), mark(LYNX, 37.2, -3.7, $t1), mark($userTaxon, 37.3012345, -3.5012345, $t1)];
$push = fn(array &$jar, array $occ, array $del = []) => http("POST", "/api/sync/push",
    ["catalogId" => $cat, "deviceId" => "11111111-1111-4111-8111-111111111111", "occurrences" => $occ, "deletes" => $del], $jar);

[$c, $r] = $push($a, $m);
$res = byId($r);
check("3 marcas → accepted", $c === 200 && count(array_filter($res, fn($x) => $x["status"] === "accepted")) === 3, "HTTP {$c} " . json_encode($r));
$seqs = array_map(fn($x) => $x["serverSeq"] ?? null, $res);
$counter = syncSeq($pdo);
[$c, $r] = $push($a, $m);
$res = byId($r);
check("el mismo lote → unchanged", $c === 200 && count(array_filter($res, fn($x) => $x["status"] === "unchanged")) === 3, json_encode($r));
check("…con el mismo server_seq", array_map(fn($x) => $x["serverSeq"] ?? null, $res) === $seqs);
check("…sin consumir sync_seq", syncSeq($pdo) === $counter, $counter . " → " . syncSeq($pdo));
$in = implode(",", array_map([$pdo, "quote"], array_column($m, "occurrenceID")));
check("…y 3 filas", (int) $pdo->query("SELECT COUNT(*) FROM occurrences WHERE occurrenceID IN ({$in})")->fetchColumn() === 3);
$stmt = $pdo->prepare("SELECT eventDate, client_updated_at, decimalLatitude, device_id FROM occurrences WHERE occurrenceID = ?");
$stmt->execute([$m[0]["occurrenceID"]]);
$row = $stmt->fetch();
check("eventDate en UTC, clientUpdatedAt con ms, coordenada exacta, deviceId",
    $row["eventDate"] === "2026-05-01 06:30:00" && $row["client_updated_at"] === "2026-05-01 08:31:00.100"
    && $row["decimalLatitude"] === "37.1234567" && $row["device_id"] === "11111111-1111-4111-8111-111111111111", json_encode($row));

// ------------------------------------------------------------------------------------------------
section("Conflictos");
$edit = ["individualCount" => 4, "clientUpdatedAt" => "2026-05-01T08:40:00.000Z"] + $m[0];
[$c, $r] = $push($a, [$edit]);
$x = byId($r)[$m[0]["occurrenceID"]] ?? [];
check("edición más reciente → accepted y sube server_seq", ($x["status"] ?? null) === "accepted" && $x["serverSeq"] > $seqs[$m[0]["occurrenceID"]], json_encode($x));
$old = ["individualCount" => 9, "clientUpdatedAt" => "2026-05-01T08:35:00.000Z"] + $m[0];
[$c, $r] = $push($a, [$old]);
$x = byId($r)[$m[0]["occurrenceID"]] ?? [];
check("edición más antigua → stale con la versión del servidor", ($x["status"] ?? null) === "stale" && ($x["occurrence"]["individualCount"] ?? null) === 4, json_encode($x));
$stmt = $pdo->prepare("SELECT individualCount FROM occurrences WHERE occurrenceID = ?");
$stmt->execute([$m[0]["occurrenceID"]]);
check("…y el servidor conserva la suya", (int) $stmt->fetchColumn() === 4);

[$c, $r] = $push($b, [["clientUpdatedAt" => "2026-05-02T00:00:00Z"] + $m[1]]);
$x = byId($r)[$m[1]["occurrenceID"]] ?? [];
check("B (editor) edita una de A → rejected forbidden", ($x["status"] ?? null) === "rejected" && ($x["reason"] ?? null) === "forbidden", json_encode($r));
[$c, $r] = $push($b, [], [["occurrenceID" => $m[1]["occurrenceID"], "clientUpdatedAt" => "2026-05-02T00:00:00Z"]]);
check("B borra una de A → rejected forbidden", (byId($r)[$m[1]["occurrenceID"]]["reason"] ?? null) === "forbidden", json_encode($r));
[$c, $r] = $push($v, [mark(LYNX, 37.0, -3.0, $t1)]);
check("un viewer hace push → 403", $c === 403 && ($r["code"] ?? null) === "forbidden", "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
section("Validación por elemento");
$bad = mark(LYNX, 95, -3.6, $t1);
$good = [mark(LYNX, 37.4, -3.4, $t1), mark($userTaxon, 37.5, -3.3, $t1)];
[$c, $r] = $push($a, [$bad, ...$good]);
$res = byId($r);
check("1 inválida (lat 95) y 2 buenas → 2 accepted", $c === 200 && ($res[$good[0]["occurrenceID"]]["status"] ?? null) === "accepted"
    && ($res[$good[1]["occurrenceID"]]["status"] ?? null) === "accepted", json_encode($r));
check("…y 1 rejected con motivo", ($res[$bad["occurrenceID"]]["status"] ?? null) === "rejected"
    && ($res[$bad["occurrenceID"]]["reason"] ?? null) === "invalid_coordinates" && !empty($res[$bad["occurrenceID"]]["detail"]), json_encode($res[$bad["occurrenceID"]] ?? null));
$cases = [
    "eventDate a 3 días en el futuro"  => [["eventDate" => gmdate("Y-m-d\TH:i:s\Z", time() + 3 * 86400)], "future_date"],
    "eventDate sin zona"               => [["eventDate" => "2026-05-01T08:30:00"], "invalid_date"],
    "individualCount negativo"         => [["individualCount" => -1], "invalid_integer"],
    "occurrenceID que no es UUID v4"   => [["occurrenceID" => "abc"], "invalid_uuid"],
    "occurrenceRemarks de 4001 car."   => [["occurrenceRemarks" => str_repeat("x", 4001)], "invalid_field"],
    "especie que no está en el catálogo" => [["taxonID" => "11014"], "taxon_not_in_catalog"],
];
foreach ($cases as $label => [$patch, $reason]) {
    [$c, $r] = $push($a, [$patch + mark(LYNX, 37.0, -3.0, $t1)]);
    $x = $r["results"][0] ?? [];
    check("{$label} → rejected {$reason}", ($x["status"] ?? null) === "rejected" && ($x["reason"] ?? null) === $reason, json_encode($x));
}
[$c] = http("POST", "/api/sync/push", ["catalogId" => $cat, "occurrences" => array_fill(0, 201, $good[0])], $a);
check("lote de 201 → 400", $c === 400, "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
section("Borrado y pull paginado");
$delId = $good[1]["occurrenceID"];
[$c, $r] = $push($a, [], [["occurrenceID" => $delId, "clientUpdatedAt" => "2026-05-01T09:00:00Z"]]);
check("A borra una suya → accepted", (byId($r)[$delId]["status"] ?? null) === "accepted", json_encode($r));
[$c, $r] = $push($a, [], [["occurrenceID" => $delId, "clientUpdatedAt" => "2026-05-01T09:00:00Z"]]);
check("…el mismo borrado otra vez → unchanged", (byId($r)[$delId]["status"] ?? null) === "unchanged", json_encode($r));

[$items, $pages, $last] = pullAll($cat, $a, 2);
$seen = array_column($items, "occurrenceID");
$expected = [...array_column($m, "occurrenceID"), ...array_column($good, "occurrenceID")];
$seqList = array_column($items, "serverSeq");
$sorted = $seqList;
sort($sorted);
check("pull con limit=2 recorre todo en {$pages} páginas", $pages >= 3 && !array_diff($expected, $seen) && count($seen) === count($expected), json_encode($last));
check("…sin repetidos y en orden de server_seq", count(array_unique($seen)) === count($seen) && $seqList === $sorted && count(array_unique($seqList)) === count($seqList));
$deleted = array_column($items, null, "occurrenceID")[$delId] ?? [];
check("el borrado llega como deleted, sin coordenadas", ($deleted["deleted"] ?? null) === true && !array_key_exists("decimalLatitude", $deleted), json_encode($deleted));
check("…y el pull trae catalogContentSeq y role", ($last["role"] ?? null) === "editor" && is_int($last["catalogContentSeq"] ?? null), json_encode($last));
[$c, $r] = http("GET", "/api/sync/pull?catalogId={$cat}&since=" . $last["cursor"], null, $a);
check("pull desde el último cursor → vacío", $c === 200 && $r["items"] === [] && $r["hasMore"] === false && $r["cursor"] === $last["cursor"], json_encode($r));

// ------------------------------------------------------------------------------------------------
section("Visibilidad");
$byOcc = fn(array $items) => array_column($items, null, "occurrenceID");
[$items] = pullAll($cat, $a);
$x = $byOcc($items)[$m[0]["occurrenceID"]] ?? [];
check("nivel 2: A (autor) ve la exacta", ($x["decimalLatitude"] ?? null) === 37.1234567 && ($x["generalized"] ?? null) === false, json_encode($x));

$logBefore = viewExactCount($pdo, $cat);
[$items] = pullAll($cat, $o);
$x = $byOcc($items)[$m[0]["occurrenceID"]] ?? [];
check("nivel 2: el owner ve la exacta", ($x["decimalLatitude"] ?? null) === 37.1234567 && ($x["decimalLongitude"] ?? null) === -3.6012345
    && ($x["generalized"] ?? null) === false, json_encode($x));
$stmt = $pdo->prepare("SELECT target FROM access_log WHERE catalog_id = ? AND action = 'view_exact' AND user_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$cat, $ids["o"]]);
// Exactas ajenas de nivel < 4 que no están borradas: m[0], m[1] y good[0] (lince).
check("…y queda UNA fila view_exact con el número de registros", viewExactCount($pdo, $cat) === $logBefore + 1 && $stmt->fetchColumn() === "3",
    ($logBefore) . " → " . viewExactCount($pdo, $cat));

$logBefore = viewExactCount($pdo, $cat);
[$items] = pullAll($cat, $b);
$x = $byOcc($items)[$m[0]["occurrenceID"]] ?? [];
check("nivel 2: B (editor) la ve en el centro de la celda de 0,1°", ($x["decimalLatitude"] ?? null) === 37.15 && ($x["decimalLongitude"] ?? null) === -3.65, json_encode($x));
check("…con generalized y dataGeneralizations", ($x["generalized"] ?? null) === true && !empty($x["dataGeneralizations"])
    && ($x["coordinateUncertaintyInMeters"] ?? 0) > 5000, json_encode($x));
$x = $byOcc($items)[$m[2]["occurrenceID"]] ?? [];
check("nivel 4: B ve la exacta", ($x["decimalLatitude"] ?? null) === 37.3012345 && ($x["generalized"] ?? null) === false, json_encode($x));
check("…y el pull de B no registra view_exact", viewExactCount($pdo, $cat) === $logBefore);

// ------------------------------------------------------------------------------------------------
section("Paquete offline e imágenes");
$lynx = array_column($offline["species"] ?? [], null, "taxonID")[LYNX] ?? [];
check("paquete: rol, catálogo y ficha del lince (UICN, legal, descripción)", ($offline["role"] ?? null) === "viewer"
    && ($offline["catalog"]["id"] ?? null) === $cat && !empty($lynx["iucnAssessments"]) && array_key_exists("legalStatus", $lynx)
    && !empty($lynx["description"]) && !empty($lynx["classification"]["family"]), json_encode(array_keys($lynx)));
$img = $lynx["images"][0]["url"] ?? ($lynx["thumbnail"] ?? "");
check("las imágenes van por el proxy /img/fototeca/", str_starts_with($img, "/img/fototeca/") && str_starts_with((string) $lynx["thumbnail"], "/img/fototeca/"), $img);
[$c, $type] = rawGet($img);
check("imagen por el proxy → 200 image/jpeg", $c === 200 && str_starts_with($type, "image/jpeg"), "HTTP {$c} {$type}");
[$c] = rawGet("/img/fototeca/../");
check("/img/fototeca/../ → 400/404", in_array($c, [400, 404], true), "HTTP {$c}");
[$c] = rawGet($img . "?w=1");
check("con parámetros → 400/404", in_array($c, [400, 404], true), "HTTP {$c}");
[$c] = rawGet("/img/fototeca/imagenes/..%2f..%2findex.html");
check("con ..%2f → 400/404", in_array($c, [400, 404], true), "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
section("Expulsión");
$pdo->prepare("DELETE FROM catalog_members WHERE catalog_id = ? AND user_id = ?")->execute([$cat, $ids["b"]]);
[$c, $r] = http("GET", "/api/sync/pull?catalogId={$cat}&since=0", null, $b);
check("B expulsado → pull 403 not_member", $c === 403 && ($r["code"] ?? null) === "not_member", "HTTP {$c} " . json_encode($r));
[$c, $r] = $push($b, [mark(LYNX, 37.0, -3.0, $t1)]);
check("…y push 403 not_member", $c === 403 && ($r["code"] ?? null) === "not_member", "HTTP {$c}");
[$c] = http("GET", "/api/catalogs/{$cat}/offline", null, $b);
check("…y paquete offline 403", $c === 403, "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
cleanup($pdo);
echo "\n" . ($failures ? "{$failures} comprobación(es) fallida(s)." : "Todo OK.") . "\n";
exit($failures ? 1 : 0);
