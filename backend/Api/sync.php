<?php
// ================================================================================================
// /api/sync — sincronización de marcas en dos sentidos (protocolo completo: docs/sync.md).
// ------------------------------------------------------------------------------------------------
//   POST /api/sync/push   {catalogId, deviceId, occurrences:[…], deletes:[{occurrenceID, clientUpdatedAt}]}
//                         permiso mark → {results:[{occurrenceID, status, reason?, detail?, serverSeq?, occurrence?}]}
//   GET  /api/sync/pull?catalogId=&since=&limit=500
//                         permiso view → {items, cursor, hasMore, catalogContentSeq, role}
// Sin rol en el catálogo (expulsado o catálogo borrado): 403 {error, code: "not_member"}; el móvil
// borra entonces sus datos de ese catálogo.
//
// Nunca se descarta una marca en silencio: cada elemento del lote vuelve con su estado. Cada elemento
// va en su propia transacción; si la BD falla a mitad de lote la petición da 500 y el móvil repite el
// lote entero (es idempotente: lo ya aplicado vuelve como «unchanged»).
// ================================================================================================

require_once __DIR__ . "/../Lib/session.php";
require_once __DIR__ . "/../Lib/authz.php";
require_once __DIR__ . "/../Lib/audit.php";
require_once __DIR__ . "/../Lib/sync.php";

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

/** Rol en el catálogo si permite $permission; si no es miembro, 403 not_member; si no llega, 403 forbidden. */
function syncRequireRole(PDO $pdo, string $catalogId, int $userId, string $permission): string {
    $role = catalogRole($pdo, $catalogId, $userId);
    if ($role === null) respond(["error" => "Ya no eres miembro de este catálogo.", "code" => "not_member"], 403);
    if (!catalogCan($role, $permission)) {
        respond(["error" => "Tu rol en el catálogo no permite esta acción.", "code" => "forbidden"], 403);
    }
    return $role;
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

/** Fila actual de la marca, bloqueada hasta el final de la transacción; null si no existe. */
function syncLockOccurrence(PDO $pdo, string $occurrenceID): ?array {
    $stmt = $pdo->prepare("SELECT occurrenceID, catalog_id, user_id, client_updated_at, server_seq, deleted_at
                             FROM occurrences WHERE occurrenceID = ? FOR UPDATE");
    $stmt->execute([$occurrenceID]);
    return $stmt->fetch() ?: null;
}

/** La marca tal como la ve su autor (para devolver la versión ganadora en un «stale»). */
function syncCurrent(PDO $pdo, string $occurrenceID, string $role, int $userId): array {
    $stmt = $pdo->prepare(
        "SELECT o.*, u.name AS recorded_by, ss.level AS sens_level
           FROM occurrences o
           JOIN users u ON u.id = o.user_id
           LEFT JOIN species_sensitivity ss ON ss.catalog_id = o.catalog_id AND ss.taxonID = o.taxonID
          WHERE o.occurrenceID = ?"
    );
    $stmt->execute([$occurrenceID]);
    $row = $stmt->fetch();
    return occurrenceOut($row, normalizeSensitivityLevel($row["sens_level"]), $role, $userId);
}

/**
 * Comprobaciones comunes a marca y borrado sobre la fila existente: autor y catálogo. Devuelve el
 * resultado de rechazo, o null si se puede seguir. Deshace la transacción si rechaza.
 */
function syncOwnershipRejection(PDO $pdo, array $existing, string $catalogId, int $userId): ?array {
    $reason = null;
    if ((int) $existing["user_id"] !== $userId) $reason = ["forbidden", "La marca es de otro autor: solo él puede editarla o borrarla."];
    elseif ($existing["catalog_id"] !== $catalogId) $reason = ["catalog_mismatch", "La marca pertenece a otro catálogo."];
    if ($reason === null) return null;
    $pdo->rollBack();
    return ["occurrenceID" => $existing["occurrenceID"], "status" => "rejected", "reason" => $reason[0], "detail" => $reason[1]];
}

function syncPushOccurrence(PDO $pdo, string $catalogId, int $userId, ?string $deviceId, string $role, array $c): array {
    $id = $c["occurrenceID"];
    $pdo->beginTransaction();
    $existing = syncLockOccurrence($pdo, $id);

    if ($existing === null) {
        $c += ["catalog_id" => $catalogId, "user_id" => $userId, "device_id" => $deviceId, "server_seq" => syncNextSeq($pdo)];
        $cols = array_keys($c);
        $pdo->prepare("INSERT INTO occurrences (`" . implode("`, `", $cols) . "`) VALUES (" . rtrim(str_repeat("?, ", count($cols)), ", ") . ")")
            ->execute(array_values($c));
        $pdo->commit();
        return ["occurrenceID" => $id, "status" => "accepted", "serverSeq" => $c["server_seq"]];
    }

    if ($rejected = syncOwnershipRejection($pdo, $existing, $catalogId, $userId)) return $rejected;

    $cmp = strcmp($c["client_updated_at"], $existing["client_updated_at"]);
    if ($cmp === 0) {
        $pdo->rollBack();
        return ["occurrenceID" => $id, "status" => "unchanged", "serverSeq" => (int) $existing["server_seq"]];
    }
    if ($cmp < 0) {
        $pdo->rollBack();
        return ["occurrenceID" => $id, "status" => "stale", "serverSeq" => (int) $existing["server_seq"],
                "occurrence" => syncCurrent($pdo, $id, $role, $userId)];
    }

    // Más reciente: gana. Una edición posterior a un borrado lo deshace (última escritura gana).
    unset($c["occurrenceID"]);
    $c += ["device_id" => $deviceId, "server_seq" => syncNextSeq($pdo)];
    $sets = implode(", ", array_map(fn($k) => "`{$k}` = ?", array_keys($c)));
    $pdo->prepare("UPDATE occurrences SET {$sets}, deleted_at = NULL WHERE occurrenceID = ?")
        ->execute([...array_values($c), $id]);
    $pdo->commit();
    return ["occurrenceID" => $id, "status" => "accepted", "serverSeq" => $c["server_seq"]];
}

function syncPushDelete(PDO $pdo, string $catalogId, int $userId, ?string $deviceId, string $role, string $id, string $clientUpdatedAt): array {
    $pdo->beginTransaction();
    $existing = syncLockOccurrence($pdo, $id);
    if ($existing === null) {
        $pdo->rollBack();
        return ["occurrenceID" => $id, "status" => "rejected", "reason" => "not_found",
                "detail" => "El servidor no tiene esa marca: no hay nada que borrar."];
    }
    if ($rejected = syncOwnershipRejection($pdo, $existing, $catalogId, $userId)) return $rejected;

    $cmp = strcmp($clientUpdatedAt, $existing["client_updated_at"]);
    if ($cmp < 0) {
        $pdo->rollBack();
        return ["occurrenceID" => $id, "status" => "stale", "serverSeq" => (int) $existing["server_seq"],
                "occurrence" => syncCurrent($pdo, $id, $role, $userId)];
    }
    if ($existing["deleted_at"] !== null) {
        $pdo->rollBack();
        return ["occurrenceID" => $id, "status" => "unchanged", "serverSeq" => (int) $existing["server_seq"]];
    }

    // A igualdad de hora, el borrado gana a la edición.
    $seq = syncNextSeq($pdo);
    $pdo->prepare("UPDATE occurrences SET deleted_at = NOW(), client_updated_at = ?, device_id = ?, server_seq = ? WHERE occurrenceID = ?")
        ->execute([$clientUpdatedAt, $deviceId, $seq, $id]);
    $pdo->commit();
    return ["occurrenceID" => $id, "status" => "accepted", "serverSeq" => $seq];
}

function syncRejected(mixed $item, SyncRejection $e): array {
    $id = is_array($item) && is_string($item["occurrenceID"] ?? null) ? $item["occurrenceID"] : null;
    return ["occurrenceID" => $id, "status" => "rejected", "reason" => $e->reason, "detail" => $e->getMessage()];
}

// --- POST /api/sync/push ------------------------------------------------------------------------

if ($action === "push") {
    if ($method !== "POST") apiMethodNotAllowed("POST");
    $body = requireJsonBody("{catalogId, deviceId, occurrences, deletes}");
    $catalogId = $body["catalogId"] ?? null;
    if (!is_string($catalogId) || !preg_match(UUID_RE, $catalogId)) apiError("«catalogId» debe ser el id del catálogo.", 400);
    $deviceId = $body["deviceId"] ?? null;
    if ($deviceId !== null && !isUuidV4($deviceId)) apiError("«deviceId» debe ser un UUID v4.", 400);
    $occurrences = $body["occurrences"] ?? [];
    $deletes = $body["deletes"] ?? [];
    if (!is_array($occurrences) || !array_is_list($occurrences) || !is_array($deletes) || !array_is_list($deletes)) {
        apiError("«occurrences» y «deletes» deben ser listas.", 400);
    }
    if (count($occurrences) + count($deletes) > SYNC_MAX_ITEMS) {
        apiError("Como mucho " . SYNC_MAX_ITEMS . " elementos por lote (marcas + borrados).", 400);
    }

    $role = syncRequireRole($pdo, $catalogId, $userId, "mark");
    $stmt = $pdo->prepare("SELECT taxonID FROM catalog_species WHERE catalog_id = ? AND deleted_at IS NULL");
    $stmt->execute([$catalogId]);
    $taxa = array_flip(array_map("strval", $stmt->fetchAll(PDO::FETCH_COLUMN)));

    $results = [];
    foreach ($occurrences as $item) {
        try {
            $c = syncValidateOccurrence($item);
            if (!isset($taxa[$c["taxonID"]])) syncReject("taxon_not_in_catalog", "La especie {$c["taxonID"]} no está en el catálogo.");
        } catch (SyncRejection $e) {
            $results[] = syncRejected($item, $e);
            continue;
        }
        $results[] = syncWithRetry($pdo, fn() => syncPushOccurrence($pdo, $catalogId, $userId, $deviceId, $role, $c));
    }
    foreach ($deletes as $item) {
        try {
            [$id, $ts] = syncValidateDelete($item);
        } catch (SyncRejection $e) {
            $results[] = syncRejected($item, $e);
            continue;
        }
        $results[] = syncWithRetry($pdo, fn() => syncPushDelete($pdo, $catalogId, $userId, $deviceId, $role, $id, $ts));
    }
    respond(["results" => $results]);
}

// --- GET /api/sync/pull -------------------------------------------------------------------------

if ($method !== "GET") apiMethodNotAllowed("GET");
$catalogId = (string) ($_GET["catalogId"] ?? "");
if (!preg_match(UUID_RE, $catalogId)) apiError("«catalogId» debe ser el id del catálogo.", 400);
$since = filter_var($_GET["since"] ?? 0, FILTER_VALIDATE_INT, ["options" => ["min_range" => 0]]);
if ($since === false) apiError("«since» debe ser un entero ≥ 0 (el cursor de la respuesta anterior).", 400);
$limit = filter_var($_GET["limit"] ?? SYNC_PULL_MAX_LIMIT, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => SYNC_PULL_MAX_LIMIT]]);
if ($limit === false) apiError("«limit» debe estar entre 1 y " . SYNC_PULL_MAX_LIMIT . ".", 400);

$role = syncRequireRole($pdo, $catalogId, $userId, "view");

// Una fila de más para saber si hay otra página. $limit es un entero validado.
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
$rows = $stmt->fetchAll();
$hasMore = count($rows) > $limit;
if ($hasMore) array_pop($rows);

$items = [];
$exactForeign = 0;
foreach ($rows as $r) {
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
    "cursor"            => $rows ? (int) end($rows)["server_seq"] : $since,
    "hasMore"           => $hasMore,
    "catalogContentSeq" => (int) $stmt->fetchColumn(),
    "role"              => $role,
]);
