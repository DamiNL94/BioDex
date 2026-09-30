<?php
// ================================================================================================
// Pruebas de F8b (evaluaciones UICN de especie): docker compose exec php php bin/test-f8b.php
// Sync de evaluaciones (push, idempotencia, stale, borrado, pull), autoría y «evaluación de referencia»
// solo del owner, con su rastro en access_log. Imprime OK/FAIL y sale con código 1 si alguna falla.
// Consulta EIDOS (Artemisia granatensis) en vivo si no está en caché.
// Crea usuarios f8b-*@biodex.test y el catálogo «test-f8b …», y los borra al terminar (y al empezar).
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/api.php";        // uuidV4()
require_once __DIR__ . "/../Lib/migrate.php";
require_once __DIR__ . "/test-lib.php";

const ARTEMISIA = "9918";

$pdo = db();
runMigrations($pdo);

function cleanup(PDO $pdo): void {
    $in = "SELECT id FROM catalogs WHERE name LIKE 'test-f8b %'";
    $pdo->exec("DELETE FROM species_assessments WHERE catalog_id IN ({$in})");
    $pdo->exec("DELETE FROM access_log WHERE catalog_id IN ({$in})");
    $pdo->exec("DELETE FROM catalogs WHERE name LIKE 'test-f8b %'");
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f8b-%@biodex.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f8b-%@biodex.test'");
}
cleanup($pdo);

/** Evaluación tal como la sube el móvil (el resultado lo calcula shared/iucn-engine.js). */
function assessment(string $ts, array $patch = []): array {
    return $patch + [
        "assessmentID" => uuidV4(), "taxonID" => ARTEMISIA, "status" => "final", "clientUpdatedAt" => $ts,
        "inputs" => ["B" => ["eoo" => 80, "aoo" => 12, "locations" => 3, "decline" => ["iii"]]],
        "result" => ["category" => "EN", "baseCategory" => "EN", "criteriaString" => "EN B1ab(iii)+2ab(iii)",
                     "determining" => ["B"], "warnings" => []],
        "justification" => "Tres localidades en Sierra Nevada; retroceso del hábitat por pisoteo.",
    ];
}

function pullAssessments(string $catalogId, array &$jar, int $since = 0): array {
    [$c, $r] = http("GET", "/api/sync/pull?catalogId={$catalogId}&since={$since}", null, $jar);
    return [$c, $r["assessments"] ?? null, $r];
}

function byId(array $list, string $id): ?array {
    foreach ($list as $x) if (($x["assessmentID"] ?? null) === $id) return $x;
    return null;
}

function syncSeq(PDO $pdo): int {
    return (int) $pdo->query("SELECT value FROM sync_seq WHERE id = 1")->fetchColumn();
}

// ------------------------------------------------------------------------------------------------
section("Usuarios y catálogo");
$suffix = bin2hex(random_bytes(4));
$jars = ["o" => [], "a" => [], "b" => [], "v" => [], "x" => []];
$ids = [];
foreach ($jars as $k => &$jar) {
    $email = "f8b-{$k}-{$suffix}@biodex.test";
    [$c] = http("POST", "/api/auth/register", ["email" => $email, "password" => "correcta-horse-battery", "name" => "F8b " . strtoupper($k)], $jar);
    check("registro de " . strtoupper($k), $c === 201, "HTTP {$c}");
    $ids[$k] = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();
}
unset($jar);
[$o, $a, $b, $v, $x] = [&$jars["o"], &$jars["a"], &$jars["b"], &$jars["v"], &$jars["x"]];

[$c, $r] = http("POST", "/api/catalogs", ["name" => "test-f8b {$suffix}", "taxonIDs" => [ARTEMISIA]], $o);
check("O crea el catálogo con la Artemisia", $c === 201, "HTTP {$c} " . json_encode($r));
$cat = $r["catalog"]["id"] ?? "";
$ins = $pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role, granted_by) VALUES (?, ?, ?, ?)");
foreach (["a" => "custodian", "b" => "editor", "v" => "viewer"] as $k => $role) $ins->execute([$cat, $ids[$k], $role, $ids["o"]]);

$push = fn(array &$jar, array $body) => http("POST", "/api/sync/push",
    ["catalogId" => $cat, "deviceId" => "33333333-3333-4333-8333-333333333333"] + $body, $jar);
$one = function (array &$jar, array $item) use ($push) {
    [$c, $r] = $push($jar, ["assessments" => [$item]]);
    return $r["assessmentResults"][0] ?? ["code" => $c, "body" => $r];
};
$ref = fn(array &$jar, ?string $id, string $taxon = ARTEMISIA) =>
    http("PUT", "/api/catalogs/{$cat}/species/{$taxon}/reference-assessment", ["assessmentID" => $id], $jar);

// ------------------------------------------------------------------------------------------------
section("Push de evaluaciones");
$t1 = "2026-09-30T10:00:00.000Z";
$e1 = assessment($t1);
$res = $one($b, $e1);
check("B (editor) sube una evaluación final → accepted", ($res["status"] ?? null) === "accepted" && ($res["serverSeq"] ?? 0) > 0, json_encode($res));
$stmt = $pdo->prepare("SELECT * FROM species_assessments WHERE assessmentID = ?");
$stmt->execute([$e1["assessmentID"]]);
$row = $stmt->fetch();
check("…guardada con inputs, result, categoría, estado y autor", $row && $row["category"] === "EN" && $row["status"] === "final"
    && (int) $row["user_id"] === $ids["b"] && json_decode($row["inputs"], true)["B"]["locations"] === 3
    && json_decode($row["result"], true)["criteriaString"] === "EN B1ab(iii)+2ab(iii)" && (int) $row["is_reference"] === 0, json_encode($row));

$seq = syncSeq($pdo);
$res = $one($b, $e1);
check("reenvío idéntico → unchanged sin consumir sync_seq", ($res["status"] ?? null) === "unchanged" && syncSeq($pdo) === $seq, json_encode($res));

$res = $one($a, ["clientUpdatedAt" => "2026-09-30T11:00:00.000Z", "justification" => "La mía"] + $e1);
check("A (custodio) no puede editar la de B → forbidden", ($res["status"] ?? null) === "rejected" && ($res["reason"] ?? null) === "forbidden", json_encode($res));
[$c, $r] = $push($v, ["assessments" => [assessment($t1)]]);
check("el lector no sube evaluaciones → 403 forbidden", $c === 403 && ($r["code"] ?? null) === "forbidden", "HTTP {$c}");
[$c, $r] = $push($x, ["assessments" => [assessment($t1)]]);
check("un no miembro → 403 not_member", $c === 403 && ($r["code"] ?? null) === "not_member", "HTTP {$c}");

$older = $one($b, ["clientUpdatedAt" => "2026-09-30T09:00:00.000Z", "status" => "draft"] + $e1);
check("edición más antigua → stale con la del servidor", ($older["status"] ?? null) === "stale"
    && ($older["assessment"]["status"] ?? null) === "final" && ($older["assessment"]["result"]["category"] ?? null) === "EN", json_encode($older));

// Validación
$bad = [
    ["sin inputs → missing_field", ["inputs" => null], "missing_field"],
    ["categoría fuera de lista → invalid_vocabulary", ["result" => ["category" => "XX"]], "invalid_vocabulary"],
    ["estado fuera de lista → invalid_vocabulary", ["status" => "publicada"], "invalid_vocabulary"],
    ["ajuste regional sin justificación → missing_field", ["regionalAdjustment" => "down", "justification" => ""], "missing_field"],
    ["ajuste regional fuera de lista → invalid_vocabulary", ["regionalAdjustment" => "dos"], "invalid_vocabulary"],
    ["final sin categoría → invalid_field", ["result" => ["category" => null, "criteriaString" => null]], "invalid_field"],
    ["justificación de 4001 caracteres → invalid_field", ["justification" => str_repeat("x", 4001)], "invalid_field"],
    ["inputs de más de 32 KB → invalid_field", ["inputs" => ["x" => str_repeat("y", 33000)]], "invalid_field"],
    ["uuid no v4 → invalid_uuid", ["assessmentID" => "not-a-uuid"], "invalid_uuid"],
    ["especie fuera del catálogo → taxon_not_in_catalog", ["taxonID" => "11943"], "taxon_not_in_catalog"],
];
foreach ($bad as [$label, $patch, $reason]) {
    $res = $one($b, $patch + assessment($t1));
    check($label, ($res["status"] ?? null) === "rejected" && ($res["reason"] ?? null) === $reason, json_encode($res));
}
$res = $one($b, assessment($t1, ["status" => "draft", "result" => ["category" => null, "criteriaString" => null]]));
check("borrador sin categoría → accepted", ($res["status"] ?? null) === "accepted", json_encode($res));
$draftId = null;
$e2 = assessment("2026-09-30T10:05:00.000Z", ["status" => "draft", "regionalAdjustment" => "down",
    "result" => ["category" => "VU", "baseCategory" => "EN", "criteriaString" => "EN B1ab(iii)+2ab(iii)"],
    "justification" => "Inmigración de propágulos desde Almería"]);
$res = $one($a, $e2);
check("A sube un borrador con ajuste regional EN → VU", ($res["status"] ?? null) === "accepted", json_encode($res));
$draftId = $e2["assessmentID"];

// ------------------------------------------------------------------------------------------------
section("Pull");
[$c, $list, $r] = pullAssessments($cat, $o);
$got = $list !== null ? byId($list, $e1["assessmentID"]) : null;
check("O recibe la de B en «assessments» con autor, datos y detalle", $c === 200 && $got && $got["recordedBy"] === "F8b B"
    && $got["status"] === "final" && $got["category"] === "EN" && $got["result"]["criteriaString"] === "EN B1ab(iii)+2ab(iii)"
    && $got["inputs"]["B"]["eoo"] === 80 && $got["isReference"] === false && $got["deleted"] === false, json_encode($got));
$got2 = $list !== null ? byId($list, $draftId) : null;
check("…y el borrador de A con su ajuste y justificación", $got2 && $got2["regionalAdjustment"] === "down"
    && $got2["justification"] === "Inmigración de propágulos desde Almería" && $got2["result"]["baseCategory"] === "EN", json_encode($got2));
[$c, $list] = pullAssessments($cat, $v);
check("el lector también las recibe (permiso view)", $c === 200 && byId($list ?? [], $e1["assessmentID"]) !== null, "HTTP {$c}");
[$c, , $r] = pullAssessments($cat, $x);
check("un no miembro no: 403 not_member sin «assessments»", $c === 403 && ($r["code"] ?? null) === "not_member" && !isset($r["assessments"]), "HTTP {$c}");
check("sin coordenadas en la salida", $got && !array_key_exists("decimalLatitude", $got));

// ------------------------------------------------------------------------------------------------
section("Evaluación de referencia (solo owner)");
$logs = fn() => (int) $pdo->query("SELECT COUNT(*) FROM access_log WHERE action = 'set_reference' AND catalog_id = " . $pdo->quote($cat))->fetchColumn();
foreach (["a" => "custodio", "b" => "editor (y autor)", "v" => "lector", "x" => "no miembro"] as $k => $who) {
    [$c] = $ref($jars[$k], $e1["assessmentID"]);
    check("{$who} no puede marcar la referencia → 403", $c === 403, "HTTP {$c}");
}
check("…y nada va a access_log", $logs() === 0);
[$c, $r] = $ref($o, $draftId);
check("un borrador no puede ser la referencia → 422", $c === 422, "HTTP {$c} " . json_encode($r));
[$c] = $ref($o, uuidV4());
check("una evaluación que no existe → 404", $c === 404, "HTTP {$c}");
[$c] = $ref($o, $e1["assessmentID"], "11943");
check("especie que no está en el catálogo → 404", $c === 404, "HTTP {$c}");

$cursor = pullAssessments($cat, $v)[2]["cursor"] ?? 0;
[$c, $r] = $ref($o, $e1["assessmentID"]);
check("O marca la de B como referencia → 200", $c === 200 && ($r["reference"]["isReference"] ?? false) === true
    && ($r["reference"]["referenceSetBy"] ?? null) === "F8b O" && ($r["changed"] ?? null) === true, "HTTP {$c} " . json_encode($r));
$stmt = $pdo->prepare("SELECT target, user_id FROM access_log WHERE action = 'set_reference' AND catalog_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$cat]);
$log = $stmt->fetch();
check("…queda en access_log (quién, especie, cadena)", $logs() === 1 && (int) $log["user_id"] === $ids["o"]
    && str_starts_with($log["target"], ARTEMISIA . ": EN B1ab(iii)+2ab(iii)") && str_contains($log["target"], "user:{$ids["b"]}"), json_encode($log));
[$c, $list] = pullAssessments($cat, $v, $cursor);
$got = byId($list ?? [], $e1["assessmentID"]);
check("el pull incremental lleva la referencia a los demás", $got && $got["isReference"] === true && $got["referenceSetBy"] === "F8b O", json_encode($list));
[$c, $r] = $ref($o, $e1["assessmentID"]);
check("marcar otra vez la misma: 200 sin cambio ni registro", $c === 200 && ($r["changed"] ?? null) === false && $logs() === 1, json_encode($r));

// B edita su evaluación: la referencia no se pierde (el push no toca esas columnas).
$res = $one($b, ["clientUpdatedAt" => "2026-09-30T12:00:00.000Z", "justification" => "Revisada tras la campaña de 2026."] + $e1);
$stmt = $pdo->prepare("SELECT is_reference, justification FROM species_assessments WHERE assessmentID = ?");
$stmt->execute([$e1["assessmentID"]]);
$row = $stmt->fetch();
check("B edita su evaluación final: accepted y sigue siendo la referencia", ($res["status"] ?? null) === "accepted"
    && (int) $row["is_reference"] === 1 && $row["justification"] === "Revisada tras la campaña de 2026.", json_encode($row));

// Una por especie: marcar otra quita la anterior.
$e3 = assessment("2026-09-30T12:10:00.000Z", ["result" => ["category" => "CR", "baseCategory" => "CR", "criteriaString" => "CR B1ab(iii)"]]);
$one($a, $e3);
[$c, $r] = $ref($o, $e3["assessmentID"]);
$n = (int) $pdo->query("SELECT COUNT(*) FROM species_assessments WHERE is_reference = 1 AND catalog_id = " . $pdo->quote($cat))->fetchColumn();
check("O cambia la referencia a la de A: solo una por especie", $c === 200 && $n === 1 && ($r["reference"]["assessmentID"] ?? null) === $e3["assessmentID"], "{$n} referencias");
$stmt = $pdo->prepare("SELECT target FROM access_log WHERE action = 'set_reference' AND catalog_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$cat]);
check("…el registro dice la nueva y la de antes", str_contains((string) $stmt->fetchColumn(), "CR B1ab(iii)") && $logs() === 2);
try {
    $pdo->prepare("UPDATE species_assessments SET is_reference = 1 WHERE assessmentID = ?")->execute([$e1["assessmentID"]]);
    $dup = false;
} catch (PDOException $e) { $dup = ($e->errorInfo[1] ?? null) === 1062; }
check("la base de datos impide dos referencias para la misma especie (clave única)", $dup);

// El autor la pasa a borrador → deja de ser la referencia (y queda registrado).
$res = $one($a, ["clientUpdatedAt" => "2026-09-30T12:20:00.000Z", "status" => "draft"] + $e3);
$stmt = $pdo->prepare("SELECT is_reference FROM species_assessments WHERE assessmentID = ?");
$stmt->execute([$e3["assessmentID"]]);
check("A pasa la referencia a borrador → deja de serlo y se registra", ($res["status"] ?? null) === "accepted"
    && (int) $stmt->fetchColumn() === 0 && $logs() === 3, json_encode($res));

// Borrar la referencia también la quita.
[$c] = $ref($o, $e1["assessmentID"]);
[$c2, $r2] = $push($b, ["assessmentDeletes" => [["assessmentID" => $e1["assessmentID"], "clientUpdatedAt" => "2026-09-30T12:30:00.000Z"]]]);
$stmt = $pdo->prepare("SELECT is_reference, deleted_at FROM species_assessments WHERE assessmentID = ?");
$stmt->execute([$e1["assessmentID"]]);
$row = $stmt->fetch();
check("B borra la evaluación de referencia → borrado lógico, deja de ser la referencia",
    $c === 200 && ($r2["assessmentResults"][0]["status"] ?? null) === "accepted" && $row["deleted_at"] !== null && (int) $row["is_reference"] === 0
    && $logs() === 5, json_encode($r2));
[$c, $list] = pullAssessments($cat, $o);
$got = byId($list ?? [], $e1["assessmentID"]);
check("…el pull la manda como tombstone, sin datos", $got && $got["deleted"] === true && !isset($got["inputs"]) && !isset($got["justification"]), json_encode($got));
[$c] = $ref($o, $e1["assessmentID"]);
check("una borrada no puede ser la referencia → 404", $c === 404, "HTTP {$c}");

[$c, $r] = $ref($o, $e3["assessmentID"]);
check("un borrador (tras pasarlo a borrador) tampoco → 422", $c === 422, "HTTP {$c}");
$one($a, ["clientUpdatedAt" => "2026-09-30T12:40:00.000Z", "status" => "final"] + $e3);
$ref($o, $e3["assessmentID"]);
[$c, $r] = $ref($o, null);
$n = (int) $pdo->query("SELECT COUNT(*) FROM species_assessments WHERE is_reference = 1 AND catalog_id = " . $pdo->quote($cat))->fetchColumn();
check("O quita la referencia con null → ninguna, y se registra", $c === 200 && array_key_exists("reference", $r) && $r["reference"] === null && $n === 0 && $logs() === 7, "HTTP {$c}");

// Registro del catálogo (F6) con la acción nueva y el nombre de la especie.
[$c, $r] = http("GET", "/api/catalogs/{$cat}/audit", null, $o);
$entry = null;
foreach ($r["items"] ?? [] as $it) if ($it["action"] === "set_reference") { $entry = $it; break; }
check("el Registro del catálogo muestra la referencia con el nombre de la especie", $c === 200 && $entry
    && str_starts_with($entry["detail"], "Artemisia granatensis"), json_encode($entry));

// ------------------------------------------------------------------------------------------------
section("Lotes mixtos");
[$c, $r] = $push($b, ["occurrences" => [], "notes" => [], "assessments" => [assessment("2026-09-30T13:00:00.000Z")]]);
check("push con los tres tipos devuelve results, noteResults y assessmentResults", $c === 200
    && isset($r["results"], $r["noteResults"], $r["assessmentResults"]) && count($r["assessmentResults"]) === 1, json_encode($r));
[$c] = $push($b, ["assessments" => array_fill(0, 201, assessment($t1))]);
check("201 evaluaciones → 400", $c === 400, "HTTP {$c}");
[$c] = $push($b, ["assessments" => "no"]);
check("«assessments» que no es lista → 400", $c === 400, "HTTP {$c}");
[$c, $r] = $push($b, ["assessmentDeletes" => [["assessmentID" => uuidV4(), "clientUpdatedAt" => $t1]]]);
check("borrar una que el servidor no tiene → not_found", ($r["assessmentResults"][0]["reason"] ?? null) === "not_found", json_encode($r));

// ------------------------------------------------------------------------------------------------
cleanup($pdo);
echo "\n" . ($failures ? "{$failures} comprobación(es) fallida(s)." : "Todo OK.") . "\n";
exit($failures ? 1 : 0);
