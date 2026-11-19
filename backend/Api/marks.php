<?php
// ================================================================================================
// /api/catalogs/{id}/marks — moderación de marcas por el owner del catálogo (permiso moderate_marks).
// Se incluye desde Api/catalogs.php con $marksRoute = [catalogId, "delete"|null].
// ------------------------------------------------------------------------------------------------
//   GET  /api/catalogs/{id}/marks?taxonID=&authorId=&from=&to=&status=present|absent&limit=&offset=
//        → {items, total, limit, offset, hasMore, species:[{taxonID, name, n}], authors:[{id, name, n}]}
//        Todas las marcas vigentes del catálogo, de cualquier autor, con la coordenada EXACTA (el owner la
//        ve siempre); las ajenas de especies sensibles cuentan como vista exacta en access_log.
//   POST /api/catalogs/{id}/marks/delete  {occurrenceIDs:[…≤200], reason?}
//        → {results:[{occurrenceID, status: deleted|already_deleted|not_found}], deleted}
//        Borrado lógico (deleted_at, deleted_by) con server_seq nuevo: llega como borrado a todos los
//        dispositivos por el pull. El autor no puede deshacerlo con una edición (push → «deleted_by_owner»).
//        Una fila en access_log (delete_marks) por lote.
// ================================================================================================

require_once __DIR__ . "/../Lib/sensitivity.php";   // normalizeSensitivityLevel()

const MARKS_PAGE_MAX = 500;
const MARKS_DELETE_MAX = 200;
const MARKS_REASON_MAX = 500;

[$catalogId, $marksAction] = $marksRoute;
requireCatalogPermission($pdo, $catalogId, $userId, "moderate_marks");

// --- POST /marks/delete --------------------------------------------------------------------------

if ($marksAction === "delete") {
    if ($method !== "POST") apiMethodNotAllowed("POST");
    $body = requireJsonBody("{occurrenceIDs, reason?}");
    $ids = $body["occurrenceIDs"] ?? null;
    if (!is_array($ids) || !array_is_list($ids) || !$ids) apiError("«occurrenceIDs» debe ser una lista no vacía.", 400);
    if (count($ids) > MARKS_DELETE_MAX) apiError("Como mucho " . MARKS_DELETE_MAX . " marcas por petición.", 400);
    foreach ($ids as $id) if (!isUuidV4($id)) apiError("Cada elemento de «occurrenceIDs» debe ser un UUID v4 en minúsculas.", 400);
    $ids = array_values(array_unique($ids));
    $reason = $body["reason"] ?? null;
    if ($reason !== null && !is_string($reason)) apiError("«reason» debe ser texto.", 400);
    $reason = $reason === null || trim($reason) === "" ? null : trim($reason);
    if ($reason !== null && mb_strlen($reason) > MARKS_REASON_MAX) apiError("«reason» no puede pasar de " . MARKS_REASON_MAX . " caracteres.", 400);

    $results = [];
    $authors = [];   // user_id → nº de marcas borradas
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("SELECT occurrenceID, user_id, client_updated_at, deleted_at, server_seq FROM occurrences
                                WHERE occurrenceID = ? AND catalog_id = ? FOR UPDATE");
        // client_updated_at = ahora (UTC, ms), o la del autor si va por delante (se admiten hasta 24 h de adelanto):
        // así un push antiguo del autor vuelve como «stale» y no como edición ganadora.
        $del = $pdo->prepare("UPDATE occurrences
                                 SET deleted_at = NOW(), deleted_by = ?, device_id = NULL, server_seq = ?,
                                     client_updated_at = GREATEST(client_updated_at, UTC_TIMESTAMP(3))
                               WHERE occurrenceID = ?");
        foreach ($ids as $id) {
            $lock->execute([$id, $catalogId]);
            $row = $lock->fetch();
            if (!$row) { $results[] = ["occurrenceID" => $id, "status" => "not_found"]; continue; }
            if ($row["deleted_at"] !== null) { $results[] = ["occurrenceID" => $id, "status" => "already_deleted"]; continue; }
            $del->execute([$userId, syncNextSeq($pdo), $id]);
            $authors[(int) $row["user_id"]] = ($authors[(int) $row["user_id"]] ?? 0) + 1;
            $results[] = ["occurrenceID" => $id, "status" => "deleted"];
        }
        $n = array_sum($authors);
        if ($n > 0) {
            // «3 marcas de user:5 (2), user:7 (1): motivo» — el Registro cambia user:N por el nombre.
            arsort($authors);
            $who = implode(", ", array_map(fn($u, $k) => "user:{$u} ({$k})", array_keys($authors), $authors));
            $target = ($n === 1 ? "1 marca" : "{$n} marcas") . " de {$who}" . ($reason !== null ? ": {$reason}" : "");
            logAccess($pdo, $userId, "delete_marks", $catalogId, $target);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    respond(["results" => $results, "deleted" => $n]);
}

// --- GET /marks ----------------------------------------------------------------------------------

if ($method !== "GET") apiMethodNotAllowed("GET");

$where = ["o.catalog_id = ?", "o.deleted_at IS NULL"];
$params = [$catalogId];
if (isset($_GET["taxonID"]) && $_GET["taxonID"] !== "") {
    $where[] = "o.taxonID = ?";
    $params[] = (string) $_GET["taxonID"];
}
if (isset($_GET["authorId"]) && $_GET["authorId"] !== "") {
    $author = filter_var($_GET["authorId"], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if ($author === false) apiError("«authorId» debe ser el id de un usuario.", 400);
    $where[] = "o.user_id = ?";
    $params[] = $author;
}
foreach (["from" => ">=", "to" => "<"] as $key => $op) {
    if (!isset($_GET[$key]) || $_GET[$key] === "") continue;
    $d = DateTimeImmutable::createFromFormat("!Y-m-d", (string) $_GET[$key], new DateTimeZone("UTC"));
    if (!$d || $d->format("Y-m-d") !== $_GET[$key]) apiError("«{$key}» debe ser una fecha AAAA-MM-DD.", 400);
    if ($key === "to") $d = $d->modify("+1 day");   // «hasta» incluye ese día entero
    $where[] = "o.eventDate {$op} ?";
    $params[] = $d->format("Y-m-d H:i:s");
}
if (isset($_GET["status"]) && $_GET["status"] !== "") {
    if (!in_array($_GET["status"], ["present", "absent"], true)) apiError("«status» debe ser present o absent.", 400);
    $where[] = "o.occurrenceStatus = ?";
    $params[] = $_GET["status"];
}
$limit = filter_var($_GET["limit"] ?? 200, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => MARKS_PAGE_MAX]]);
if ($limit === false) apiError("«limit» debe estar entre 1 y " . MARKS_PAGE_MAX . ".", 400);
$offset = filter_var($_GET["offset"] ?? 0, FILTER_VALIDATE_INT, ["options" => ["min_range" => 0]]);
if ($offset === false) apiError("«offset» debe ser un entero ≥ 0.", 400);
$sqlWhere = implode(" AND ", $where);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM occurrences o WHERE {$sqlWhere}");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();

// $limit y $offset son enteros validados.
$stmt = $pdo->prepare(
    "SELECT o.occurrenceID, o.taxonID, o.user_id, u.name AS author_name, o.eventDate, o.decimalLatitude, o.decimalLongitude,
            o.coordinateUncertaintyInMeters, o.occurrenceStatus, o.individualCount, o.lifeStage, o.sex, o.occurrenceRemarks,
            o.visit_id, s.canonical_name, s.vernacularName, ss.level AS sens_level
       FROM occurrences o
       JOIN users u ON u.id = o.user_id
       JOIN species s ON s.taxonID = o.taxonID
       LEFT JOIN species_sensitivity ss ON ss.catalog_id = o.catalog_id AND ss.taxonID = o.taxonID
      WHERE {$sqlWhere}
      ORDER BY o.eventDate DESC, o.occurrenceID
      LIMIT {$limit} OFFSET {$offset}"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Coordenadas exactas ajenas de especies sensibles: como en el pull, una fila por día (Lib/audit.php).
$exactForeign = count(array_filter($rows, fn($r) => (int) $r["user_id"] !== $userId && normalizeSensitivityLevel($r["sens_level"]) < 4));
if ($exactForeign > 0) logViewExact($pdo, $userId, $catalogId, "", $exactForeign);

// Opciones de los filtros: especies y autores con marcas vigentes en el catálogo (sin filtrar).
$stmt = $pdo->prepare("SELECT o.taxonID, s.canonical_name, COUNT(*) AS n FROM occurrences o JOIN species s ON s.taxonID = o.taxonID
                        WHERE o.catalog_id = ? AND o.deleted_at IS NULL GROUP BY o.taxonID, s.canonical_name ORDER BY s.canonical_name");
$stmt->execute([$catalogId]);
$species = array_map(fn($r) => ["taxonID" => (string) $r["taxonID"], "name" => $r["canonical_name"], "n" => (int) $r["n"]], $stmt->fetchAll());
$stmt = $pdo->prepare("SELECT o.user_id, u.name, COUNT(*) AS n FROM occurrences o JOIN users u ON u.id = o.user_id
                        WHERE o.catalog_id = ? AND o.deleted_at IS NULL GROUP BY o.user_id, u.name ORDER BY u.name");
$stmt->execute([$catalogId]);
$authors = array_map(fn($r) => ["id" => (int) $r["user_id"], "name" => $r["name"], "n" => (int) $r["n"]], $stmt->fetchAll());

$num = fn($x) => $x === null ? null : (float) $x;
$int = fn($x) => $x === null ? null : (int) $x;
respond([
    "items" => array_map(fn($r) => [
        "occurrenceID"                  => $r["occurrenceID"],
        "taxonID"                       => (string) $r["taxonID"],
        "scientificName"                => $r["canonical_name"],
        "vernacularName"                => $r["vernacularName"],
        "authorId"                      => (int) $r["user_id"],
        "authorName"                    => $r["author_name"],
        "eventDate"                     => syncIsoUtc($r["eventDate"]),
        "decimalLatitude"               => $num($r["decimalLatitude"]),
        "decimalLongitude"              => $num($r["decimalLongitude"]),
        "coordinateUncertaintyInMeters" => $int($r["coordinateUncertaintyInMeters"]),
        "occurrenceStatus"              => $r["occurrenceStatus"],
        "individualCount"               => $int($r["individualCount"]),
        "lifeStage"                     => $r["lifeStage"],
        "sex"                           => $r["sex"],
        "occurrenceRemarks"             => $r["occurrenceRemarks"],
        "visitID"                       => $r["visit_id"],
    ], $rows),
    "total"   => $total,
    "limit"   => $limit,
    "offset"  => $offset,
    "hasMore" => $offset + count($rows) < $total,
    "species" => $species,
    "authors" => $authors,
]);
