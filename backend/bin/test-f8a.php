<?php
// ================================================================================================
// Pruebas de F8a (marcas completas y notas de especie): docker compose exec php php bin/test-f8a.php
// Imprime OK/FAIL por comprobación y sale con código 1 si alguna falla. Consulta EIDOS (lince) en vivo.
// Crea usuarios f8a-*@biodex.test y el catálogo «test-f8a …», y los borra al terminar (y al empezar).
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/api.php";        // uuidV4()
require_once __DIR__ . "/../Lib/migrate.php";
require_once __DIR__ . "/test-lib.php";

const LYNX = "11943";

$pdo = db();
runMigrations($pdo);

function cleanup(PDO $pdo): void {
    $in = "SELECT id FROM catalogs WHERE name LIKE 'test-f8a %'";
    $pdo->exec("DELETE FROM species_notes WHERE catalog_id IN ({$in})");
    $pdo->exec("DELETE FROM occurrences WHERE catalog_id IN ({$in})");
    $pdo->exec("DELETE FROM access_log WHERE catalog_id IN ({$in})");
    $pdo->exec("DELETE FROM catalogs WHERE name LIKE 'test-f8a %'");
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f8a-%@biodex.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f8a-%@biodex.test'");
}
cleanup($pdo);

function mark(array $patch = []): array {
    return $patch + [
        "occurrenceID" => uuidV4(), "taxonID" => LYNX, "eventDate" => "2026-05-01T08:30:00+02:00",
        "decimalLatitude" => 37.1761, "decimalLongitude" => -3.5881, "coordinateUncertaintyInMeters" => 8,
        "clientUpdatedAt" => "2026-05-01T08:31:00.000Z",
    ];
}

function note(string $text, string $ts, array $patch = []): array {
    return $patch + ["noteID" => uuidV4(), "taxonID" => LYNX, "text" => $text, "clientUpdatedAt" => $ts];
}

function syncSeq(PDO $pdo): int {
    return (int) $pdo->query("SELECT value FROM sync_seq WHERE id = 1")->fetchColumn();
}

/** Pull completo por páginas: [marcas, notas, páginas, última respuesta]. */
function pullAll(string $catalogId, array &$jar, int $limit = 500): array {
    $items = $notes = [];
    $since = 0;
    $pages = 0;
    do {
        [$c, $r] = http("GET", "/api/sync/pull?catalogId={$catalogId}&since={$since}&limit={$limit}", null, $jar);
        if ($c !== 200) return [$items, $notes, $pages, ["code" => $c] + (array) $r];
        $pages++;
        array_push($items, ...$r["items"]);
        array_push($notes, ...($r["notes"] ?? []));
        $since = $r["cursor"];
    } while ($r["hasMore"] && $pages < 50);
    return [$items, $notes, $pages, $r];
}

// ------------------------------------------------------------------------------------------------
section("Usuarios y catálogo");
$suffix = bin2hex(random_bytes(4));
$jars = ["o" => [], "a" => [], "b" => [], "v" => [], "x" => []];
$ids = [];
foreach ($jars as $k => &$jar) {
    $email = "f8a-{$k}-{$suffix}@biodex.test";
    [$c] = http("POST", "/api/auth/register", ["email" => $email, "password" => "correcta-horse-battery", "name" => "F8a " . strtoupper($k)], $jar);
    check("registro de " . strtoupper($k), $c === 201, "HTTP {$c}");
    $ids[$k] = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();
}
unset($jar);
[$o, $a, $b, $v, $x] = [&$jars["o"], &$jars["a"], &$jars["b"], &$jars["v"], &$jars["x"]];

[$c, $r] = http("POST", "/api/catalogs", ["name" => "test-f8a {$suffix}", "taxonIDs" => [LYNX]], $o);
check("O crea el catálogo con el lince", $c === 201, "HTTP {$c} " . json_encode($r));
$cat = $r["catalog"]["id"] ?? "";
$ins = $pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role, granted_by) VALUES (?, ?, ?, ?)");
foreach (["a" => "editor", "b" => "editor", "v" => "viewer"] as $k => $role) $ins->execute([$cat, $ids[$k], $role, $ids["o"]]);

$push = fn(array &$jar, array $body) => http("POST", "/api/sync/push",
    ["catalogId" => $cat, "deviceId" => "22222222-2222-4222-8222-222222222222"] + $body, $jar);
$one = function (array &$jar, array $occ) use ($push) {
    [$c, $r] = $push($jar, ["occurrences" => [$occ]]);
    return $r["results"][0] ?? ["code" => $c];
};

// ------------------------------------------------------------------------------------------------
section("Marca completa y vocabularios");
$full = mark([
    "individualCount" => 2, "mature_count" => 1, "lifeStage" => "adult", "sex" => "female",
    "habitat" => "Encinar adehesado con matorral", "occurrenceRemarks" => "Huellas y excrementos",
    "georeferenceProtocol" => "GPS del dispositivo", "occurrenceStatus" => "present",
    "iucn_assessment" => ["category" => "EN"],   // ya no se usa: se ignora
]);
$res = $one($a, $full);
check("marca con todos los términos → accepted", ($res["status"] ?? null) === "accepted", json_encode($res));
$stmt = $pdo->prepare("SELECT occurrenceStatus, individualCount, mature_count, lifeStage, sex, habitat, georeferenceProtocol FROM occurrences WHERE occurrenceID = ?");
$stmt->execute([$full["occurrenceID"]]);
$row = $stmt->fetch();
check("…guardada con sus columnas DwC", $row && $row["occurrenceStatus"] === "present" && (int) $row["individualCount"] === 2
    && (int) $row["mature_count"] === 1 && $row["lifeStage"] === "adult" && $row["sex"] === "female"
    && $row["habitat"] === "Encinar adehesado con matorral" && $row["georeferenceProtocol"] === "GPS del dispositivo", json_encode($row));
$cols = $pdo->query("SHOW COLUMNS FROM occurrences LIKE 'iucn_assessment'")->fetchAll();
check("occurrences.iucn_assessment ya no existe", $cols === []);

$bare = mark();
$res = $one($a, $bare);
$stmt->execute([$bare["occurrenceID"]]);
$row = $stmt->fetch();
check("marca mínima (con prisa) → present y GPS del dispositivo por defecto", ($res["status"] ?? null) === "accepted"
    && $row["occurrenceStatus"] === "present" && $row["georeferenceProtocol"] === "GPS del dispositivo", json_encode($row));

$vocab = [
    "lifeStage «adulto» (texto libre)"          => ["lifeStage" => "adulto"],
    "lifeStage «Adult» (mayúscula)"             => ["lifeStage" => "Adult"],
    "sex «macho»"                               => ["sex" => "macho"],
    "occurrenceStatus «unknown»"                => ["occurrenceStatus" => "unknown"],
    "georeferenceProtocol «GPS»"                => ["georeferenceProtocol" => "GPS"],
    "lifeStage numérico"                        => ["lifeStage" => 3],
];
foreach ($vocab as $label => $patch) {
    $res = $one($a, mark($patch));
    check("{$label} → rejected invalid_vocabulary", ($res["status"] ?? null) === "rejected" && ($res["reason"] ?? null) === "invalid_vocabulary", json_encode($res));
}
$okAll = true;
foreach (["adult", "juvenile", "subadult", "larva", "egg", "seedling", "flowering", "fruiting", "vegetative", "undetermined"] as $stage) {
    $okAll = $okAll && ($one($a, mark(["lifeStage" => $stage]))["status"] ?? null) === "accepted";
}
check("los 10 valores de lifeStage → accepted", $okAll);
$okAll = true;
foreach (["male", "female", "hermaphrodite", "undetermined"] as $sex) {
    $okAll = $okAll && ($one($a, mark(["sex" => $sex]))["status"] ?? null) === "accepted";
}
check("los 4 valores de sex → accepted", $okAll);
$res = $one($a, mark(["habitat" => str_repeat("h", 501)]));
check("habitat de 501 caracteres → invalid_field", ($res["reason"] ?? null) === "invalid_field", json_encode($res));
$res = $one($a, mark(["georeferenceRemarks" => str_repeat("g", 256)]));
check("georeferenceRemarks de 256 caracteres → invalid_field", ($res["reason"] ?? null) === "invalid_field", json_encode($res));

// ------------------------------------------------------------------------------------------------
section("Individuos, maduros y ausencias");
$cases = [
    "mature_count 3 > individualCount 2"      => [["individualCount" => 2, "mature_count" => 3], "rejected", "invalid_mature_count"],
    "mature_count sin individualCount"         => [["mature_count" => 1], "rejected", "invalid_mature_count"],
    "mature_count = individualCount"           => [["individualCount" => 4, "mature_count" => 4], "accepted", null],
    "ausencia con 3 individuos"                => [["occurrenceStatus" => "absent", "individualCount" => 3], "rejected", "invalid_absence"],
    "ausencia con mature_count 0"              => [["occurrenceStatus" => "absent", "mature_count" => 0], "rejected", "invalid_absence"],
    "ausencia con individualCount 0"           => [["occurrenceStatus" => "absent", "individualCount" => 0], "accepted", null],
    "ausencia sin individualCount"             => [["occurrenceStatus" => "absent"], "accepted", null],
    "punto del mapa sin precisión (0 m)"       => [["georeferenceProtocol" => "Punto marcado en el mapa", "coordinateUncertaintyInMeters" => 0], "rejected", "missing_field"],
    "punto del mapa a 150 m con comentario"    => [["georeferenceProtocol" => "Punto marcado en el mapa", "coordinateUncertaintyInMeters" => 150,
                                                    "georeferenceRemarks" => "Visto con prismáticos desde el mirador"], "accepted", null],
];
$absentId = null;
$mapId = null;
foreach ($cases as $label => [$patch, $status, $reason]) {
    $m = mark($patch);
    $res = $one($a, $m);
    $ok = ($res["status"] ?? null) === $status && ($reason === null || ($res["reason"] ?? null) === $reason);
    check("{$label} → {$status}" . ($reason ? " {$reason}" : ""), $ok, json_encode($res));
    if ($label === "ausencia con individualCount 0") $absentId = $m["occurrenceID"];
    if ($label === "punto del mapa a 150 m con comentario") $mapId = $m["occurrenceID"];
}
$res = $one($a, mark(["occurrenceStatus" => "absent", "individualCount" => 1]));
check("…el rechazo explica el motivo en castellano", str_contains((string) ($res["detail"] ?? ""), "ausencia"), json_encode($res));

[$items] = pullAll($cat, $b);
$byId = array_column($items, null, "occurrenceID");
$f = $byId[$full["occurrenceID"]] ?? [];
check("pull: B recibe los términos nuevos", ($f["sex"] ?? null) === "female" && ($f["habitat"] ?? null) === "Encinar adehesado con matorral"
    && ($f["occurrenceStatus"] ?? null) === "present" && ($f["mature_count"] ?? null) === 1 && ($f["lifeStage"] ?? null) === "adult", json_encode($f));
check("…sin iucn_assessment", $f && !array_key_exists("iucn_assessment", $f), json_encode(array_keys($f)));
check("…la ausencia llega como absent", ($byId[$absentId]["occurrenceStatus"] ?? null) === "absent", json_encode($byId[$absentId] ?? null));
$mp = $byId[$mapId] ?? [];
check("…el punto del mapa, con su protocolo y comentario", ($mp["georeferenceProtocol"] ?? null) === "Punto marcado en el mapa"
    && ($mp["georeferenceRemarks"] ?? null) === "Visto con prismáticos desde el mirador" && ($mp["coordinateUncertaintyInMeters"] ?? null) >= 150, json_encode($mp));

// Editar una marca propia: la edición sube clientUpdatedAt.
$edit = ["individualCount" => 3, "clientUpdatedAt" => "2026-05-01T09:00:00.000Z", "habitat" => "Encinar"] + $full;
$res = $one($a, $edit);
check("A edita su marca (clientUpdatedAt más reciente) → accepted", ($res["status"] ?? null) === "accepted", json_encode($res));
$res = $one($b, ["clientUpdatedAt" => "2026-05-02T00:00:00.000Z"] + $full);
check("B edita la marca de A → forbidden", ($res["reason"] ?? null) === "forbidden", json_encode($res));

// ------------------------------------------------------------------------------------------------
section("Notas de especie: push, idempotencia y autoría");
$t1 = "2026-05-01T10:00:00.000Z";
$n1 = note("Rastro fresco junto al arroyo; volver en mayo.", $t1);
$n2 = note("Cámara trampa colocada en el cortafuegos.", $t1);
[$c, $r] = $push($a, ["notes" => [$n1, $n2]]);
$nr = array_column($r["noteResults"] ?? [], null, "noteID");
check("2 notas → accepted en noteResults", $c === 200 && ($nr[$n1["noteID"]]["status"] ?? null) === "accepted"
    && ($nr[$n2["noteID"]]["status"] ?? null) === "accepted", "HTTP {$c} " . json_encode($r));
check("…y results de marcas vacío", ($r["results"] ?? null) === [], json_encode($r["results"] ?? null));
$seq1 = $nr[$n1["noteID"]]["serverSeq"] ?? null;
$counter = syncSeq($pdo);
[$c, $r] = $push($a, ["notes" => [$n1, $n2]]);
$nr = array_column($r["noteResults"] ?? [], null, "noteID");
check("el mismo lote → unchanged, mismo server_seq y sin consumir sync_seq", ($nr[$n1["noteID"]]["status"] ?? null) === "unchanged"
    && ($nr[$n1["noteID"]]["serverSeq"] ?? null) === $seq1 && syncSeq($pdo) === $counter, json_encode($r));
check("…y 2 filas", (int) $pdo->query("SELECT COUNT(*) FROM species_notes WHERE catalog_id = " . $pdo->quote($cat))->fetchColumn() === 2);

$n1e = ["text" => "Rastro fresco junto al arroyo; confirmado en mayo.", "clientUpdatedAt" => "2026-05-01T11:00:00.000Z"] + $n1;
[$c, $r] = $push($a, ["notes" => [$n1e]]);
$x1 = $r["noteResults"][0] ?? [];
check("A edita su nota → accepted y sube server_seq", ($x1["status"] ?? null) === "accepted" && ($x1["serverSeq"] ?? 0) > $seq1, json_encode($x1));
[$c, $r] = $push($a, ["notes" => [["text" => "vieja", "clientUpdatedAt" => "2026-05-01T10:30:00.000Z"] + $n1]]);
$x1 = $r["noteResults"][0] ?? [];
check("edición más antigua → stale con la nota del servidor", ($x1["status"] ?? null) === "stale"
    && ($x1["note"]["text"] ?? null) === $n1e["text"], json_encode($x1));
[$c, $r] = $push($b, ["notes" => [["clientUpdatedAt" => "2026-05-03T00:00:00.000Z", "text" => "de B"] + $n1]]);
check("B edita la nota de A → rejected forbidden", ($r["noteResults"][0]["reason"] ?? null) === "forbidden", json_encode($r));
[$c, $r] = $push($b, ["noteDeletes" => [["noteID" => $n1["noteID"], "clientUpdatedAt" => "2026-05-03T00:00:00.000Z"]]]);
check("B borra la nota de A → rejected forbidden", ($r["noteResults"][0]["reason"] ?? null) === "forbidden", json_encode($r));
[$c, $r] = $push($v, ["notes" => [note("de un lector", $t1)]]);
check("un viewer sube una nota → 403 forbidden", $c === 403 && ($r["code"] ?? null) === "forbidden", "HTTP {$c}");

$bad = [
    "nota vacía"                    => [note("   ", $t1), "missing_field"],
    "nota de 4001 caracteres"       => [note(str_repeat("n", 4001), $t1), "invalid_field"],
    "noteID que no es UUID v4"      => [note("x", $t1, ["noteID" => "nota-1"]), "invalid_uuid"],
    "especie fuera del catálogo"    => [note("x", $t1, ["taxonID" => "11014"]), "taxon_not_in_catalog"],
    "clientUpdatedAt sin zona"      => [note("x", "2026-05-01T10:00:00"), "invalid_date"],
];
foreach ($bad as $label => [$n, $reason]) {
    [$c, $r] = $push($a, ["notes" => [$n]]);
    $xr = $r["noteResults"][0] ?? [];
    check("{$label} → rejected {$reason}", ($xr["status"] ?? null) === "rejected" && ($xr["reason"] ?? null) === $reason, json_encode($xr));
}
[$c, $r] = $push($b, ["notes" => [note("Nota de B", "2026-05-01T12:00:00.000Z")]]);
check("B añade una nota propia", ($r["noteResults"][0]["status"] ?? null) === "accepted", json_encode($r));

// ------------------------------------------------------------------------------------------------
section("Notas: pull con autoría y tombstones");
[$items, $notes, $pages, $last] = pullAll($cat, $b);
$nb = array_column($notes, null, "noteID");
$pn = $nb[$n1["noteID"]] ?? [];
check("B recibe la nota de A con autor, userId y fecha", ($pn["text"] ?? null) === $n1e["text"] && ($pn["recordedBy"] ?? null) === "F8a A"
    && ($pn["userId"] ?? null) === $ids["a"] && ($pn["clientUpdatedAt"] ?? null) === "2026-05-01T11:00:00.000Z"
    && ($pn["deleted"] ?? null) === false, json_encode($pn));
check("…y las 3 notas del catálogo", count($notes) === 3, (string) count($notes));
[$c, $r] = http("GET", "/api/sync/pull?catalogId={$cat}&since=0", null, $v);
check("un viewer (permiso view) también las recibe", $c === 200 && count($r["notes"] ?? []) === 3, "HTTP {$c}");

[$c, $r] = $push($a, ["noteDeletes" => [["noteID" => $n2["noteID"], "clientUpdatedAt" => "2026-05-01T13:00:00.000Z"]]]);
check("A borra su nota → accepted", ($r["noteResults"][0]["status"] ?? null) === "accepted", json_encode($r));
[$c, $r] = $push($a, ["noteDeletes" => [["noteID" => $n2["noteID"], "clientUpdatedAt" => "2026-05-01T13:00:00.000Z"]]]);
check("…el mismo borrado otra vez → unchanged", ($r["noteResults"][0]["status"] ?? null) === "unchanged", json_encode($r));
[$c, $r] = $push($a, ["noteDeletes" => [["noteID" => uuidV4(), "clientUpdatedAt" => "2026-05-01T13:00:00.000Z"]]]);
check("borrar una nota que el servidor nunca tuvo → not_found", ($r["noteResults"][0]["reason"] ?? null) === "not_found", json_encode($r));
[$items, $notes] = pullAll($cat, $b);
$tomb = array_column($notes, null, "noteID")[$n2["noteID"]] ?? [];
check("la borrada llega como deleted, sin texto ni autor", ($tomb["deleted"] ?? null) === true && !array_key_exists("text", $tomb)
    && !array_key_exists("recordedBy", $tomb), json_encode($tomb));
$stmt = $pdo->prepare("SELECT text, deleted_at FROM species_notes WHERE noteID = ?");
$stmt->execute([$n2["noteID"]]);
$row = $stmt->fetch();
check("…y en la BD es un borrado lógico", $row && $row["deleted_at"] !== null && $row["text"] === $n2["text"], json_encode($row));
[$c, $r] = $push($a, ["notes" => [["text" => "Recuperada", "clientUpdatedAt" => "2026-05-01T14:00:00.000Z"] + $n2]]);
check("una edición posterior deshace el borrado", ($r["noteResults"][0]["status"] ?? null) === "accepted", json_encode($r));

[$items, $notes, $pages, $last] = pullAll($cat, $b, 3);
$seqs = [...array_column($items, "serverSeq"), ...array_column($notes, "serverSeq")];
$sorted = $seqs;
sort($sorted);
check("pull con limit=3 recorre marcas y notas en {$pages} páginas, sin repetidos", $pages >= 3 && count(array_unique($seqs)) === count($seqs)
    && count($notes) === 3, "{$pages} páginas, " . count($notes) . " notas");
[$c, $r] = http("GET", "/api/sync/pull?catalogId={$cat}&since=" . $last["cursor"], null, $b);
check("pull desde el último cursor → vacío en los dos tipos", $c === 200 && $r["items"] === [] && $r["notes"] === [] && $r["hasMore"] === false, json_encode($r));

// ------------------------------------------------------------------------------------------------
section("Notas: no miembros y lotes");
[$c, $r] = http("GET", "/api/sync/pull?catalogId={$cat}&since=0", null, $x);
check("un no miembro no recibe notas: pull 403 not_member", $c === 403 && ($r["code"] ?? null) === "not_member" && !isset($r["notes"]), "HTTP {$c} " . json_encode($r));
[$c, $r] = $push($x, ["notes" => [note("intrusa", $t1)]]);
check("…ni puede subirlas: push 403 not_member", $c === 403 && ($r["code"] ?? null) === "not_member", "HTTP {$c}");
[$c, $r] = $push($x, ["notes" => [["clientUpdatedAt" => "2026-06-01T00:00:00.000Z"] + $n1]]);
check("…ni editar una existente", $c === 403, "HTTP {$c}");
$pdo->prepare("DELETE FROM catalog_members WHERE catalog_id = ? AND user_id = ?")->execute([$cat, $ids["b"]]);
[$c, $r] = http("GET", "/api/sync/pull?catalogId={$cat}&since=0", null, $b);
check("B expulsado → pull 403 not_member (tampoco notas)", $c === 403 && ($r["code"] ?? null) === "not_member", "HTTP {$c}");
[$c] = $push($a, ["occurrences" => array_fill(0, 150, mark()), "notes" => array_fill(0, 51, $n1)]);
check("lote de 150 marcas + 51 notas → 400 (201 elementos)", $c === 400, "HTTP {$c}");
[$c] = $push($a, ["notes" => "no"]);
check("«notes» que no es lista → 400", $c === 400, "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
cleanup($pdo);
echo "\n" . ($failures ? "{$failures} comprobación(es) fallida(s)." : "Todo OK.") . "\n";
exit($failures ? 1 : 0);
