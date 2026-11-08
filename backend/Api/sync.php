<?php
// ================================================================================================
// /api/sync — sincronización en dos sentidos de marcas, notas de especie y evaluaciones UICN
// (protocolo: docs/sync.md).
// ------------------------------------------------------------------------------------------------
//   POST /api/sync/push   {catalogId, deviceId, occurrences:[…], deletes:[{occurrenceID, clientUpdatedAt}],
//                          notes:[…], noteDeletes:[{noteID, clientUpdatedAt}],
//                          assessments:[…], assessmentDeletes:[{assessmentID, clientUpdatedAt}],
//                          impacts:[…], impactDeletes:[{impactID, clientUpdatedAt}] (F11g)}
//                         permiso mark → {results:[…], noteResults:[…], assessmentResults:[…], impactResults:[…]}
//   GET  /api/sync/pull?catalogId=&since=&limit=500
//                         permiso view → {items, notes, assessments, parameters (F11f), impacts y locations (F11g), cursor, hasMore, catalogContentSeq, role,
//                                         visibilityEpoch}
// Cada tipo de entidad (SYNC_ENTITIES) sigue las mismas reglas y comparte el contador server_seq.
// Sin rol en el catálogo (expulsado o catálogo borrado): 403 {error, code: "not_member"}; el móvil
// borra entonces sus datos de ese catálogo. Si visibilityEpoch no es la que tiene guardada, borra las
// marcas AJENAS de ese catálogo y vuelve a bajarlas desde 0 (F6: cambió lo que puede ver; Lib/teams.php).
//
// Nunca se descarta una marca en silencio: cada elemento del lote vuelve con su estado. Cada elemento
// va en su propia transacción; si la BD falla a mitad de lote la petición da 500 y el móvil repite el
// lote entero (es idempotente: lo ya aplicado vuelve como «unchanged»).
// ================================================================================================

require_once __DIR__ . "/../Lib/session.php";
require_once __DIR__ . "/../Lib/authz.php";
require_once __DIR__ . "/../Lib/audit.php";
require_once __DIR__ . "/../Lib/sync.php";
require_once __DIR__ . "/../Lib/parameters.php";   // parámetros de especie en el pull (F11f)
require_once __DIR__ . "/../Lib/assessment.php";   // localidades confirmadas en el pull (F11g)

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
// Reintentos de un elemento ante clave duplicada (dos pushes a la vez de la misma marca nueva),
// interbloqueo o espera de bloqueo agotada.
const SYNC_RETRY_ERRORS = [1062, 1213, 1205];
const SYNC_RETRIES = 3;

$pdo = db();
biodexSessionStart($pdo);
$userId = requireUserId();

$method = $_SERVER["REQUEST_METHOD"];
$path   = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
if (!preg_match('#^/api/sync/(push|pull)/?$#', $path, $m)) apiError("Recurso no encontrado.", 404);
$action = $m[1];

// ------------------------------------------------------------------------------------------------

/**
 * {role, epoch} en el catálogo si el rol permite $permission; si no es miembro, 403 not_member; si no
 * llega, 403 forbidden.
 */
function syncRequireMembership(PDO $pdo, string $catalogId, int $userId, string $permission): array {
    $membership = catalogMembership($pdo, $catalogId, $userId);
    if ($membership === null) respond(["error" => "Ya no eres miembro de este catálogo.", "code" => "not_member"], 403);
    if (!catalogCan($membership["role"], $permission)) {
        respond(["error" => "Tu rol en el catálogo no permite esta acción.", "code" => "forbidden"], 403);
    }
    return $membership;
}

/**
 * F11g: una instantánea del motor 2 (pestaña Evaluación UICN) se recalcula entera aquí, con las coordenadas
 * exactas (assessmentSnapshot): el dispositivo pudo calcularla sin red con menos marcas. Columnas derivadas al día.
 */
function syncServerSnapshot(PDO $pdo, string $catalogId, int $userId, array $c): array {
    $inputs = json_decode($c["inputs"], true);
    if (($inputs["engine"] ?? null) !== 2) return $c;
    [$inputs, $result] = assessmentSnapshot($pdo, $catalogId, $c["taxonID"], $userId, $inputs);
    $enc = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    $c["inputs"] = $enc($inputs);
    $c["result"] = $enc($result);
    if (strlen($c["result"]) > SYNC_ASSESSMENT_JSON_MAX) syncReject("invalid_field", "«result» no puede pasar de " . SYNC_ASSESSMENT_JSON_MAX . " bytes.");
    $c["category"] = $result["category"];
    $c["regional_adjustment"] = $result["regional"]["applied"] ? $result["regional"]["direction"] : null;
    if ($c["regional_adjustment"] !== null && $c["justification"] === null) $c["justification"] = $result["regionalV4"]["justification"] ?? null;
    if ($c["status"] === "final" && $c["category"] === null) {
        syncReject("invalid_field", "Con los datos del servidor no sale categoría: para guardarla como final, elige NT, LC o DD.");
    }
    return $c;
}

/** Ejecuta un elemento del lote, reintentando ante carreras con otro push (ver SYNC_RETRY_ERRORS). */
function syncWithRetry(PDO $pdo, callable $fn): array {
    for ($attempt = 1; ; $attempt++) {
        try {
            return $fn();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (!in_array($e->errorInfo[1] ?? null, SYNC_RETRY_ERRORS, true) || $attempt >= SYNC_RETRIES) throw $e;
        }
    }
}

/**
 * Tipos de entidad que se sincronizan (F8a). Todos con las mismas reglas: UUID del cliente,
 * clientUpdatedAt decide, solo el autor edita o borra, server_seq del contador común y tombstones.
 *   list/deletes/results  claves del cuerpo del push y de su respuesta
 *   pull                  clave de la lista en el pull
 *   noun                  para los mensajes («la marca es de otro autor…»)
 *   reference             (F8b) la fila puede ser la «evaluación de referencia» del owner: si su autor la
 *                         borra o la pasa a borrador, deja de serlo (y queda en access_log)
 */
const SYNC_ENTITIES = [
    "occurrence" => ["table" => "occurrences", "id" => "occurrenceID", "list" => "occurrences", "deletes" => "deletes",
                     "results" => "results", "pull" => "items", "validate" => "syncValidateOccurrence", "noun" => "La marca"],
    "note"       => ["table" => "species_notes", "id" => "noteID", "list" => "notes", "deletes" => "noteDeletes",
                     "results" => "noteResults", "pull" => "notes", "validate" => "syncValidateNote", "noun" => "La nota"],
    "assessment" => ["table" => "species_assessments", "id" => "assessmentID", "list" => "assessments",
                     "deletes" => "assessmentDeletes", "results" => "assessmentResults", "pull" => "assessments",
                     "validate" => "syncValidateAssessment", "noun" => "La evaluación", "reference" => true],
    // F11g: impactos. Sin taxonID propio: sus especies van en impact_species («_species» del validador).
    "impact"     => ["table" => "impacts", "id" => "impactID", "list" => "impacts", "deletes" => "impactDeletes",
                     "results" => "impactResults", "pull" => "impacts", "validate" => "syncValidateImpact", "noun" => "El impacto",
                     "taxon" => false, "children" => "impact_species"],
];

/** Fila actual del elemento, bloqueada hasta el final de la transacción; null si no existe. */
function syncLockRow(PDO $pdo, array $ent, string $id): ?array {
    $extra = empty($ent["reference"]) ? "" : ", is_reference";
    $taxon = ($ent["taxon"] ?? true) ? "taxonID, " : "";
    $stmt = $pdo->prepare("SELECT `{$ent["id"]}`, catalog_id, {$taxon}user_id, client_updated_at, server_seq, deleted_at{$extra}
                             FROM `{$ent["table"]}` WHERE `{$ent["id"]}` = ? FOR UPDATE");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** El elemento tal como lo ve su autor (para devolver la versión ganadora en un «stale»). */
function syncCurrent(PDO $pdo, array $ent, string $id, string $role, int $userId): array {
    if ($ent["table"] === "species_notes") {
        $stmt = $pdo->prepare("SELECT n.*, u.name AS recorded_by FROM species_notes n JOIN users u ON u.id = n.user_id WHERE n.noteID = ?");
        $stmt->execute([$id]);
        return noteOut($stmt->fetch());
    }
    if ($ent["table"] === "species_assessments") {
        $stmt = $pdo->prepare(assessmentSelect("a.assessmentID = ?"));
        $stmt->execute([$id]);
        return assessmentOut($stmt->fetch());
    }
    if ($ent["table"] === "impacts") {
        $catalogId = $pdo->query("SELECT catalog_id FROM impacts WHERE impactID = " . $pdo->quote($id))->fetchColumn();
        [[$row, $species, $level]] = impactRows($pdo, "i.impactID = ?", [$id], (string) $catalogId);
        return impactOut($row, $species, $level, $role, $userId);
    }
    $stmt = $pdo->prepare(
        "SELECT o.*, u.name AS recorded_by, ss.level AS sens_level
           FROM occurrences o
           JOIN users u ON u.id = o.user_id
           LEFT JOIN species_sensitivity ss ON ss.catalog_id = o.catalog_id AND ss.taxonID = o.taxonID
          WHERE o.occurrenceID = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return occurrenceOut($row, normalizeSensitivityLevel($row["sens_level"]), $role, $userId);
}

/** Clave de la versión del servidor en un «stale»: `occurrence`, `note` o `assessment`. */
function syncStaleKey(array $ent): string {
    return ["species_notes" => "note", "species_assessments" => "assessment", "impacts" => "impact"][$ent["table"]] ?? "occurrence";
}

/**
 * F8b: una evaluación de referencia que su autor borra o pasa a borrador deja de ser la referencia del
 * catálogo. Devuelve las columnas que hay que poner a cero (vacío si no toca) y lo deja en access_log,
 * dentro de la misma transacción.
 */
function syncReferenceClear(PDO $pdo, array $ent, array $existing, int $userId, ?string $status): array {
    if (empty($ent["reference"]) || (int) ($existing["is_reference"] ?? 0) !== 1) return [];
    if ($status !== null && $status !== "draft") return [];   // null = borrado
    $why = $status === null ? "su autor la borró" : "su autor la pasó a borrador";
    logAccess($pdo, $userId, "set_reference", $existing["catalog_id"], "{$existing["taxonID"]}: ninguna ({$why})");
    return ["is_reference" => 0, "reference_set_by" => null, "reference_set_at" => null];
}

/**
 * Comprobaciones comunes a alta, edición y borrado sobre la fila existente: autor y catálogo. Devuelve
 * el resultado de rechazo, o null si se puede seguir. Deshace la transacción si rechaza.
 */
function syncOwnershipRejection(PDO $pdo, array $ent, array $existing, string $catalogId, int $userId): ?array {
    $reason = null;
    if ((int) $existing["user_id"] !== $userId) $reason = ["forbidden", "{$ent["noun"]} es de otro autor: solo él puede editarla o borrarla."];
    elseif ($existing["catalog_id"] !== $catalogId) $reason = ["catalog_mismatch", "{$ent["noun"]} pertenece a otro catálogo."];
    if ($reason === null) return null;
    $pdo->rollBack();
    return [$ent["id"] => $existing[$ent["id"]], "status" => "rejected", "reason" => $reason[0], "detail" => $reason[1]];
}

/** F11g: filas hijas del elemento (especies de un impacto): se sustituyen por las del push. */
function syncWriteChildren(PDO $pdo, array $ent, string $id, ?array $children): void {
    if (empty($ent["children"]) || $children === null) return;
    $pdo->prepare("DELETE FROM `{$ent["children"]}` WHERE `{$ent["id"]}` = ?")->execute([$id]);
    $ins = $pdo->prepare("INSERT INTO `{$ent["children"]}` (`{$ent["id"]}`, taxonID) VALUES (?, ?)");
    foreach ($children as $t) $ins->execute([$id, $t]);
}

function syncPushItem(PDO $pdo, array $ent, string $catalogId, int $userId, ?string $deviceId, string $role, array $c): array {
    $idField = $ent["id"];
    $table = $ent["table"];
    $id = $c[$idField];
    $children = $c["_species"] ?? null;
    unset($c["_species"]);
    $pdo->beginTransaction();
    $existing = syncLockRow($pdo, $ent, $id);

    if ($existing === null) {
        $c += ["catalog_id" => $catalogId, "user_id" => $userId, "device_id" => $deviceId, "server_seq" => syncNextSeq($pdo)];
        $cols = array_keys($c);
        $pdo->prepare("INSERT INTO `{$table}` (`" . implode("`, `", $cols) . "`) VALUES (" . rtrim(str_repeat("?, ", count($cols)), ", ") . ")")
            ->execute(array_values($c));
        syncWriteChildren($pdo, $ent, $id, $children);
        $pdo->commit();
        return [$idField => $id, "status" => "accepted", "serverSeq" => $c["server_seq"]];
    }

    if ($rejected = syncOwnershipRejection($pdo, $ent, $existing, $catalogId, $userId)) return $rejected;

    $cmp = strcmp($c["client_updated_at"], $existing["client_updated_at"]);
    if ($cmp === 0) {
        $pdo->rollBack();
        return [$idField => $id, "status" => "unchanged", "serverSeq" => (int) $existing["server_seq"]];
    }
    if ($cmp < 0) {
        $pdo->rollBack();
        return [$idField => $id, "status" => "stale", "serverSeq" => (int) $existing["server_seq"],
                syncStaleKey($ent) => syncCurrent($pdo, $ent, $id, $role, $userId)];
    }

    // Más reciente: gana. Una edición posterior a un borrado lo deshace (última escritura gana).
    unset($c[$idField]);
    $c += syncReferenceClear($pdo, $ent, $existing, $userId, $c["status"] ?? "");
    $c += ["device_id" => $deviceId, "server_seq" => syncNextSeq($pdo)];
    $sets = implode(", ", array_map(fn($k) => "`{$k}` = ?", array_keys($c)));
    $pdo->prepare("UPDATE `{$table}` SET {$sets}, deleted_at = NULL WHERE `{$idField}` = ?")
        ->execute([...array_values($c), $id]);
    syncWriteChildren($pdo, $ent, $id, $children);
    $pdo->commit();
    return [$idField => $id, "status" => "accepted", "serverSeq" => $c["server_seq"]];
}

function syncPushDelete(PDO $pdo, array $ent, string $catalogId, int $userId, ?string $deviceId, string $role, string $id, string $clientUpdatedAt): array {
    $idField = $ent["id"];
    $pdo->beginTransaction();
    $existing = syncLockRow($pdo, $ent, $id);
    if ($existing === null) {
        $pdo->rollBack();
        return [$idField => $id, "status" => "rejected", "reason" => "not_found",
                "detail" => "El servidor no lo tiene: no hay nada que borrar."];
    }
    if ($rejected = syncOwnershipRejection($pdo, $ent, $existing, $catalogId, $userId)) return $rejected;

    $cmp = strcmp($clientUpdatedAt, $existing["client_updated_at"]);
    if ($cmp < 0) {
        $pdo->rollBack();
        return [$idField => $id, "status" => "stale", "serverSeq" => (int) $existing["server_seq"],
                syncStaleKey($ent) => syncCurrent($pdo, $ent, $id, $role, $userId)];
    }
    if ($existing["deleted_at"] !== null) {
        $pdo->rollBack();
        return [$idField => $id, "status" => "unchanged", "serverSeq" => (int) $existing["server_seq"]];
    }

    // A igualdad de hora, el borrado gana a la edición.
    $seq = syncNextSeq($pdo);
    $clear = syncReferenceClear($pdo, $ent, $existing, $userId, null);
    $extra = implode("", array_map(fn($k) => ", `{$k}` = ?", array_keys($clear)));
    $pdo->prepare("UPDATE `{$ent["table"]}` SET deleted_at = NOW(), client_updated_at = ?, device_id = ?, server_seq = ?{$extra} WHERE `{$idField}` = ?")
        ->execute([$clientUpdatedAt, $deviceId, $seq, ...array_values($clear), $id]);
    $pdo->commit();
    return [$idField => $id, "status" => "accepted", "serverSeq" => $seq];
}

function syncRejected(array $ent, mixed $item, SyncRejection $e): array {
    $id = is_array($item) && is_string($item[$ent["id"]] ?? null) ? $item[$ent["id"]] : null;
    return [$ent["id"] => $id, "status" => "rejected", "reason" => $e->reason, "detail" => $e->getMessage()];
}

// --- POST /api/sync/push ------------------------------------------------------------------------

if ($action === "push") {
    if ($method !== "POST") apiMethodNotAllowed("POST");
    $body = requireJsonBody("{catalogId, deviceId, occurrences, deletes, notes, noteDeletes, assessments, assessmentDeletes, impacts, impactDeletes}");
    $catalogId = $body["catalogId"] ?? null;
    if (!is_string($catalogId) || !preg_match(UUID_RE, $catalogId)) apiError("«catalogId» debe ser el id del catálogo.", 400);
    $deviceId = $body["deviceId"] ?? null;
    if ($deviceId !== null && !isUuidV4($deviceId)) apiError("«deviceId» debe ser un UUID v4.", 400);
    $total = 0;
    foreach (SYNC_ENTITIES as $ent) {
        foreach ([$ent["list"], $ent["deletes"]] as $key) {
            $list = $body[$key] ?? [];
            if (!is_array($list) || !array_is_list($list)) apiError("«{$key}» debe ser una lista.", 400);
            $total += count($list);
        }
    }
    if ($total > SYNC_MAX_ITEMS) apiError("Como mucho " . SYNC_MAX_ITEMS . " elementos por lote (de todos los tipos, con los borrados).", 400);

    $role = syncRequireMembership($pdo, $catalogId, $userId, "mark")["role"];
    $stmt = $pdo->prepare("SELECT taxonID FROM catalog_species WHERE catalog_id = ? AND deleted_at IS NULL");
    $stmt->execute([$catalogId]);
    $taxa = array_flip(array_map("strval", $stmt->fetchAll(PDO::FETCH_COLUMN)));

    $out = [];
    foreach (SYNC_ENTITIES as $ent) {
        $results = [];
        foreach ($body[$ent["list"]] ?? [] as $item) {
            try {
                $c = ($ent["validate"])($item);
                foreach (($ent["taxon"] ?? true) ? [$c["taxonID"]] : $c["_species"] as $t) {
                    if (!isset($taxa[$t])) syncReject("taxon_not_in_catalog", "La especie {$t} no está en el catálogo.");
                }
                if ($ent["table"] === "species_assessments") $c = syncServerSnapshot($pdo, $catalogId, $userId, $c);
            } catch (SyncRejection $e) {
                $results[] = syncRejected($ent, $item, $e);
                continue;
            }
            $results[] = syncWithRetry($pdo, fn() => syncPushItem($pdo, $ent, $catalogId, $userId, $deviceId, $role, $c));
        }
        foreach ($body[$ent["deletes"]] ?? [] as $item) {
            try {
                [$id, $ts] = syncValidateDelete($item, $ent["id"]);
            } catch (SyncRejection $e) {
                $results[] = syncRejected($ent, $item, $e);
                continue;
            }
            $results[] = syncWithRetry($pdo, fn() => syncPushDelete($pdo, $ent, $catalogId, $userId, $deviceId, $role, $id, $ts));
        }
        $out[$ent["results"]] = $results;
    }
    respond($out);
}

// --- GET /api/sync/pull -------------------------------------------------------------------------

if ($method !== "GET") apiMethodNotAllowed("GET");
$catalogId = (string) ($_GET["catalogId"] ?? "");
if (!preg_match(UUID_RE, $catalogId)) apiError("«catalogId» debe ser el id del catálogo.", 400);
$since = filter_var($_GET["since"] ?? 0, FILTER_VALIDATE_INT, ["options" => ["min_range" => 0]]);
if ($since === false) apiError("«since» debe ser un entero ≥ 0 (el cursor de la respuesta anterior).", 400);
$limit = filter_var($_GET["limit"] ?? SYNC_PULL_MAX_LIMIT, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => SYNC_PULL_MAX_LIMIT]]);
if ($limit === false) apiError("«limit» debe estar entre 1 y " . SYNC_PULL_MAX_LIMIT . ".", 400);

// Rol y época se leen juntos: lo que va en esta página corresponde a esa época.
["role" => $role, "epoch" => $epoch] = syncRequireMembership($pdo, $catalogId, $userId, "view");

// Una página = los $limit primeros server_seq > since de TODOS los tipos (el contador es común), así
// que el cursor sirve para todos. De cada tabla, una fila de más para saber si hay otra página.
// $limit es un entero validado.
$stmt = $pdo->prepare(
    "SELECT o.*, u.name AS recorded_by, ss.level AS sens_level
       FROM occurrences o
       JOIN users u ON u.id = o.user_id
       LEFT JOIN species_sensitivity ss ON ss.catalog_id = o.catalog_id AND ss.taxonID = o.taxonID
      WHERE o.catalog_id = ? AND o.server_seq > ?
      ORDER BY o.server_seq
      LIMIT " . ($limit + 1)
);
$stmt->execute([$catalogId, $since]);
$rows = array_map(fn($r) => ["occurrence", $r, null], $stmt->fetchAll());

// Notas: sin coordenadas, no pasan por applyVisibility(); solo llegan hasta aquí miembros con «view».
$stmt = $pdo->prepare(
    "SELECT n.*, u.name AS recorded_by
       FROM species_notes n
       JOIN users u ON u.id = n.user_id
      WHERE n.catalog_id = ? AND n.server_seq > ?
      ORDER BY n.server_seq
      LIMIT " . ($limit + 1)
);
$stmt->execute([$catalogId, $since]);
foreach ($stmt->fetchAll() as $r) $rows[] = ["note", $r, null];

// Evaluaciones UICN (F8b): tampoco llevan coordenadas; las mismas reglas que las notas.
$stmt = $pdo->prepare(assessmentSelect("a.catalog_id = ? AND a.server_seq > ?") . " ORDER BY a.server_seq LIMIT " . ($limit + 1));
$stmt->execute([$catalogId, $since]);
foreach ($stmt->fetchAll() as $r) $rows[] = ["assessment", $r, null];

// Parámetros de especie (F11f): sin coordenadas; los fijan owner y custodio por la API y aquí solo bajan.
$stmt = $pdo->prepare(parameterSelect("p.catalog_id = ? AND p.server_seq > ?") . " ORDER BY p.server_seq LIMIT " . ($limit + 1));
$stmt->execute([$catalogId, $since]);
foreach ($stmt->fetchAll() as $r) $rows[] = ["parameter", $r, null];

// Localidades confirmadas (F11g): sin coordenadas (subpoblaciones por etiqueta); las fijan owner y custodio.
$stmt = $pdo->prepare(locationsSelect("l.catalog_id = ? AND l.server_seq > ?") . " ORDER BY l.server_seq LIMIT " . ($limit + 1));
$stmt->execute([$catalogId, $since]);
foreach ($stmt->fetchAll() as $r) $rows[] = ["locations", $r, null];

// Impactos (F11g): llevan sitio; impactOut() lo generaliza con el nivel más restrictivo de sus especies.
foreach (impactRows($pdo, "i.catalog_id = ? AND i.server_seq > ?", [$catalogId, $since], $catalogId, "ORDER BY i.server_seq LIMIT " . ($limit + 1)) as $x) {
    $rows[] = ["impact", $x[0], $x];
}

usort($rows, fn($a, $b) => (int) $a[1]["server_seq"] <=> (int) $b[1]["server_seq"]);
$hasMore = count($rows) > $limit;
$rows = array_slice($rows, 0, $limit);

$items = [];
$notes = [];
$assessments = [];
$parameters = [];
$impacts = [];
$locations = [];
$exactForeign = 0;
foreach ($rows as [$kind, $r, $extra]) {
    if ($kind === "locations") { $locations[] = locationsOut($r); continue; }
    if ($kind === "impact") { $impacts[] = impactOut($r, $extra[1], $extra[2], $role, $userId); continue; }
    if ($kind === "note") { $notes[] = noteOut($r); continue; }
    if ($kind === "assessment") { $assessments[] = assessmentOut($r); continue; }
    if ($kind === "parameter") { $parameters[] = parameterOut($r); continue; }
    // Sin nivel registrado → normalizeSensitivityLevel() cae al más restrictivo (1).
    $level = normalizeSensitivityLevel($r["sens_level"]);
    $item = occurrenceOut($r, $level, $role, $userId);
    if (!$item["deleted"] && !$item["generalized"] && $level < 4 && (int) $r["user_id"] !== $userId) $exactForeign++;
    $items[] = $item;
}
// Coordenadas exactas ajenas de especies sensibles (owner/custodian): una fila por petición.
if ($exactForeign > 0) logAccess($pdo, $userId, "view_exact", $catalogId, (string) $exactForeign);

$stmt = $pdo->prepare("SELECT content_seq FROM catalogs WHERE id = ?");
$stmt->execute([$catalogId]);

respond([
    "items"             => $items,
    "notes"             => $notes,
    "assessments"       => $assessments,
    "parameters"        => $parameters,
    "impacts"           => $impacts,
    "locations"         => $locations,
    "cursor"            => $rows ? (int) end($rows)[1]["server_seq"] : $since,
    "hasMore"           => $hasMore,
    "catalogContentSeq" => (int) $stmt->fetchColumn(),
    "role"              => $role,
    "visibilityEpoch"   => $epoch,
]);
