<?php
// ================================================================================================
// Pruebas de F11k (el owner elimina marcas de cualquier autor): docker compose exec php php bin/test-f11k.php
//   1. GET /api/catalogs/{id}/marks: solo el owner; todas las marcas con la coordenada exacta, filtros y facetas.
//   2. POST …/marks/delete: borrado lógico con server_seq nuevo, idempotente, de este catálogo y no de otro,
//      validación del cuerpo y una fila delete_marks en access_log (legible en el Registro).
//   3. Sincronización: el pull de todos trae el borrado; el autor no puede resucitar la marca editándola
//      (rejected «deleted_by_owner»); su borrado de lo ya borrado no falla.
// Crea usuarios f11k-*@biodex.test y los catálogos «test-f11k …», y los borra al terminar (y al empezar).
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/api.php";
require_once __DIR__ . "/../Lib/migrate.php";
require_once __DIR__ . "/test-lib.php";

$pdo = db();
runMigrations($pdo);

const SENSITIVE = "9918";   // Artemisia granatensis: nivel 2 en la prueba
const COMMON = "9392";      // Viola crassiuscula: nivel 4

function cleanup(PDO $pdo): void {
    $in = "SELECT id FROM catalogs WHERE name LIKE 'test-f11k %'";
    foreach (["species_metrics", "access_log", "occurrences"] as $t) $pdo->exec("DELETE FROM {$t} WHERE catalog_id IN ({$in})");
    $pdo->exec("DELETE FROM catalogs WHERE name LIKE 'test-f11k %'");
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f11k-%@biodex.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f11k-%@biodex.test'");
}
cleanup($pdo);

section("Usuarios y catálogos");
$suffix = bin2hex(random_bytes(4));
$jars = ["o" => [], "c" => [], "e" => []];
$ids = [];
foreach ($jars as $k => &$jar) {
    $email = "f11k-{$k}-{$suffix}@biodex.test";
    [$code] = http("POST", "/api/auth/register", ["email" => $email, "password" => "correcta-horse-battery", "name" => "F11k " . strtoupper($k)], $jar);
    check("registro de " . strtoupper($k), $code === 201, "HTTP {$code}");
    $ids[$k] = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();
}
unset($jar);
[$o, $cu, $e] = [&$jars["o"], &$jars["c"], &$jars["e"]];
[$code, $r] = http("POST", "/api/catalogs", ["name" => "test-f11k {$suffix}", "taxonIDs" => [SENSITIVE, COMMON]], $o);
check("O crea el catálogo", $code === 201, "HTTP {$code}");
$cat = $r["catalog"]["id"] ?? "";
[$code, $r] = http("POST", "/api/catalogs", ["name" => "test-f11k otro {$suffix}", "taxonIDs" => [COMMON]], $e);
check("E crea otro catálogo suyo", $code === 201, "HTTP {$code}");
$other = $r["catalog"]["id"] ?? "";
$ins = $pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role, granted_by) VALUES (?, ?, ?, ?)");
$ins->execute([$cat, $ids["c"], "custodian", $ids["o"]]);
$ins->execute([$cat, $ids["e"], "editor", $ids["o"]]);
[$code] = http("PUT", "/api/catalogs/{$cat}/species/" . SENSITIVE . "/sensitivity", ["level" => 2, "reason" => "prueba F11k"], $o);
check("nivel 2 para la sensible", $code === 200, "HTTP {$code}");

$tick = 0;
$now = function () use (&$tick) { $tick += 10; return (new DateTimeImmutable("now", new DateTimeZone("UTC")))->modify("+{$tick} milliseconds")->format("Y-m-d\TH:i:s.v\Z"); };
$mark = fn(string $taxon, string $date, array $x = []) => $x + ["occurrenceID" => uuidV4(), "taxonID" => $taxon, "eventDate" => $date,
    "decimalLatitude" => 37.0912345, "decimalLongitude" => -3.3954321, "coordinateUncertaintyInMeters" => 5, "individualCount" => 2, "clientUpdatedAt" => $now()];
$push = fn(array &$jar, string $c, array $body) => http("POST", "/api/sync/push", ["catalogId" => $c] + $body, $jar);

$eS1 = $mark(SENSITIVE, "2026-05-01T09:00:00Z");
$eS2 = $mark(SENSITIVE, "2026-06-10T09:00:00Z");
$eC  = $mark(COMMON, "2026-06-11T09:00:00Z", ["occurrenceStatus" => "absent", "individualCount" => 0]);
$oC  = $mark(COMMON, "2026-07-01T09:00:00Z");
[$code, $r] = $push($e, $cat, ["occurrences" => [$eS1, $eS2, $eC]]);
check("E sube 3 marcas", $code === 200 && count(array_filter($r["results"], fn($x) => $x["status"] === "accepted")) === 3, json_encode($r));
[$code] = $push($o, $cat, ["occurrences" => [$oC]]);
check("O sube 1 marca", $code === 200);
$eOther = $mark(COMMON, "2026-06-01T09:00:00Z");
[$code] = $push($e, $other, ["occurrences" => [$eOther]]);
check("E sube 1 marca en su otro catálogo", $code === 200);

// ------------------------------------------------------------------------------------------------
section("1. Listado (GET /marks)");
foreach (["c" => $cu, "e" => $e] as $k => $jar) {
    [$code] = http("GET", "/api/catalogs/{$cat}/marks", null, $jar);
    check(strtoupper($k) . " (no owner) no puede listar: 403", $code === 403, "HTTP {$code}");
}
[$code, $r] = http("GET", "/api/catalogs/{$cat}/marks", null, $o);
check("O lista las 4 marcas, de la más reciente a la más antigua", $code === 200 && $r["total"] === 4
    && array_column($r["items"], "occurrenceID") === [$oC["occurrenceID"], $eC["occurrenceID"], $eS2["occurrenceID"], $eS1["occurrenceID"]], json_encode($r));
$it = array_column($r["items"] ?? [], null, "occurrenceID")[$eS1["occurrenceID"]] ?? [];
check("coordenada exacta de la sensible ajena y su autor", ($it["decimalLatitude"] ?? null) === 37.0912345 && ($it["authorName"] ?? "") === "F11k E", json_encode($it));
check("facetas: 2 especies y 2 autores con su nº de marcas", count($r["species"]) === 2 && count($r["authors"]) === 2
    && array_column($r["authors"], "n", "id") == [$ids["e"] => 3, $ids["o"] => 1], json_encode([$r["species"], $r["authors"]]));
$log = (int) $pdo->query("SELECT COUNT(*) FROM access_log WHERE catalog_id = " . $pdo->quote($cat) . " AND action = 'view_exact' AND user_id = {$ids["o"]}")->fetchColumn();
check("la vista de coordenadas exactas ajenas sensibles queda en access_log", $log >= 1);
[, $r] = http("GET", "/api/catalogs/{$cat}/marks?taxonID=" . SENSITIVE, null, $o);
check("filtro por especie", $r["total"] === 2);
[, $r] = http("GET", "/api/catalogs/{$cat}/marks?authorId={$ids["o"]}", null, $o);
check("filtro por autor", $r["total"] === 1 && $r["items"][0]["occurrenceID"] === $oC["occurrenceID"]);
[, $r] = http("GET", "/api/catalogs/{$cat}/marks?from=2026-06-10&to=2026-06-11", null, $o);
check("filtro por fechas (los dos extremos incluidos)", $r["total"] === 2, json_encode(array_column($r["items"], "eventDate")));
[, $r] = http("GET", "/api/catalogs/{$cat}/marks?status=absent", null, $o);
check("filtro por ausencias", $r["total"] === 1 && $r["items"][0]["occurrenceID"] === $eC["occurrenceID"]);
[, $r] = http("GET", "/api/catalogs/{$cat}/marks?limit=3", null, $o);
check("paginación: 3 de 4 y hasMore", count($r["items"]) === 3 && $r["hasMore"] === true);
foreach (["from=2026-13-01", "status=quizá", "authorId=x", "limit=0"] as $q) {
    [$code] = http("GET", "/api/catalogs/{$cat}/marks?{$q}", null, $o);
    check("parámetro inválido «{$q}»: 400", $code === 400, "HTTP {$code}");
}

// ------------------------------------------------------------------------------------------------
section("2. Eliminar (POST /marks/delete)");
[$code] = http("POST", "/api/catalogs/{$cat}/marks/delete", ["occurrenceIDs" => [$oC["occurrenceID"]]], $cu);
check("el custodio no puede eliminar: 403", $code === 403, "HTTP {$code}");
[$code] = http("POST", "/api/catalogs/{$cat}/marks/delete", ["occurrenceIDs" => [$oC["occurrenceID"]]], $e);
check("el editor no puede eliminar: 403", $code === 403, "HTTP {$code}");
foreach ([[], ["occurrenceIDs" => []], ["occurrenceIDs" => ["no-es-uuid"]], ["occurrenceIDs" => [uuidV4()], "reason" => str_repeat("x", 501)],
          ["occurrenceIDs" => array_map(fn() => uuidV4(), range(1, 201))]] as $i => $bad) {
    [$code] = http("POST", "/api/catalogs/{$cat}/marks/delete", $bad, $o);
    check("cuerpo inválido #{$i}: 400", $code === 400, "HTTP {$code}");
}
[$code] = http("GET", "/api/catalogs/{$cat}/marks/delete", null, $o);
check("GET en /marks/delete: 405", $code === 405, "HTTP {$code}");

$before = syncSeq($pdo);
$missing = uuidV4();
[$code, $r] = http("POST", "/api/catalogs/{$cat}/marks/delete",
    ["occurrenceIDs" => [$eS1["occurrenceID"], $eC["occurrenceID"], $eOther["occurrenceID"], $missing], "reason" => "Duplicadas de la jornada"], $o);
$st = array_column($r["results"] ?? [], "status", "occurrenceID");
check("O elimina 2 marcas de E; la de otro catálogo y la inexistente: not_found", $code === 200 && $r["deleted"] === 2
    && $st[$eS1["occurrenceID"]] === "deleted" && $st[$eC["occurrenceID"]] === "deleted"
    && $st[$eOther["occurrenceID"]] === "not_found" && $st[$missing] === "not_found", json_encode($r));
$row = $pdo->query("SELECT deleted_at, deleted_by, server_seq FROM occurrences WHERE occurrenceID = " . $pdo->quote($eS1["occurrenceID"]))->fetch();
check("borrado lógico con deleted_by y server_seq nuevo", $row["deleted_at"] !== null && (int) $row["deleted_by"] === $ids["o"] && (int) $row["server_seq"] > $before, json_encode($row));
$alive = $pdo->query("SELECT deleted_at FROM occurrences WHERE occurrenceID = " . $pdo->quote($eOther["occurrenceID"]))->fetchColumn();
check("la marca del otro catálogo sigue intacta", $alive === null);
[$code, $r] = http("POST", "/api/catalogs/{$cat}/marks/delete", ["occurrenceIDs" => [$eS1["occurrenceID"]]], $o);
check("repetir: already_deleted, sin consumir server_seq", $code === 200 && $r["deleted"] === 0 && $r["results"][0]["status"] === "already_deleted"
    && (int) $pdo->query("SELECT server_seq FROM occurrences WHERE occurrenceID = " . $pdo->quote($eS1["occurrenceID"]))->fetchColumn() === (int) $row["server_seq"]);
$logs = $pdo->query("SELECT target FROM access_log WHERE catalog_id = " . $pdo->quote($cat) . " AND action = 'delete_marks'")->fetchAll(PDO::FETCH_COLUMN);
check("una sola fila delete_marks con nº, autor y motivo", count($logs) === 1 && $logs[0] === "2 marcas de user:{$ids["e"]} (2): Duplicadas de la jornada", json_encode($logs));
[, $r] = http("GET", "/api/catalogs/{$cat}/audit", null, $o);
$entry = array_values(array_filter($r["items"] ?? [], fn($x) => $x["action"] === "delete_marks"))[0] ?? null;
check("el Registro la muestra con el nombre del autor", $entry && $entry["detail"] === "2 marcas de F11k E (2): Duplicadas de la jornada", json_encode($entry));
[, $r] = http("GET", "/api/catalogs/{$cat}/marks", null, $o);
check("el listado ya no las muestra", $r["total"] === 2);

// ------------------------------------------------------------------------------------------------
section("3. Sincronización");
[, $r] = http("GET", "/api/sync/pull?catalogId={$cat}&since={$before}", null, $e);
$pulled = array_column($r["items"] ?? [], null, "occurrenceID");
check("el pull del autor trae los dos borrados, sin coordenadas", ($pulled[$eS1["occurrenceID"]]["deleted"] ?? false) === true
    && ($pulled[$eC["occurrenceID"]]["deleted"] ?? false) === true && !isset($pulled[$eS1["occurrenceID"]]["decimalLatitude"]), json_encode($r["items"] ?? null));
[, $r] = http("GET", "/api/sync/pull?catalogId={$cat}&since={$before}", null, $cu);
check("el pull del custodio también", (array_column($r["items"] ?? [], "deleted", "occurrenceID")[$eS1["occurrenceID"]] ?? false) === true);
[$code, $r] = $push($e, $cat, ["occurrences" => [["clientUpdatedAt" => $now(), "individualCount" => 5] + $eS1]]);
check("el autor no la resucita editándola: rejected deleted_by_owner", $code === 200 && ($r["results"][0]["status"] ?? "") === "rejected"
    && ($r["results"][0]["reason"] ?? "") === "deleted_by_owner", json_encode($r));
check("sigue borrada", $pdo->query("SELECT deleted_at FROM occurrences WHERE occurrenceID = " . $pdo->quote($eS1["occurrenceID"]))->fetchColumn() !== null);
[$code, $r] = $push($e, $cat, ["deletes" => [["occurrenceID" => $eC["occurrenceID"], "clientUpdatedAt" => $now()]]]);
check("el borrado del autor de lo ya borrado no falla (unchanged)", $code === 200 && ($r["results"][0]["status"] ?? "") === "unchanged", json_encode($r));
[$code, $r] = $push($e, $cat, ["occurrences" => [["clientUpdatedAt" => $now()] + $eS2]]);
check("el autor sigue editando sus marcas no eliminadas", $code === 200 && ($r["results"][0]["status"] ?? "") === "accepted", json_encode($r));
[, $r] = http("GET", "/api/catalogs/{$cat}/species/" . SENSITIVE . "/metrics?scope=team", null, $o);
check("EOO/AOO solo con las marcas vigentes (1 de la sensible)", ($r["nRecords"] ?? $r["metrics"]["nRecords"] ?? null) === 1, json_encode($r));

cleanup($pdo);
echo $failures ? "\n{$failures} fallo(s).\n" : "\nTodo OK.\n";
exit($failures ? 1 : 0);
