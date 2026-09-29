<?php
// ================================================================================================
// /api/catalogs — catálogos compartidos y sus especies. Requiere sesión; cada ruta comprueba el rol
// con requireCatalogPermission() (matriz CATALOG_PERMISSIONS de Lib/authz.php).
// ------------------------------------------------------------------------------------------------
//   GET    /api/catalogs                                   los míos y los compartidos
//   POST   /api/catalogs                                   {name, description?} → owner
//   GET    /api/catalogs/{id}                              view: datos, especies y resumen
//   GET    /api/catalogs/{id}/tree                         view: reino › filo › clase › orden › familia
//   PATCH  /api/catalogs/{id}                              edit_catalog (owner)
//   DELETE /api/catalogs/{id}                              edit_catalog (owner), borrado lógico
//   POST   /api/catalogs/{id}/species                      add_species {taxonID}, idempotente
//   DELETE /api/catalogs/{id}/species/{taxonID}            remove_species (owner), borrado lógico
//   PUT    /api/catalogs/{id}/species/{taxonID}/sensitivity  set_level {level, reason} → access_log
// Miembros e invitaciones: F6.
// ================================================================================================

require_once __DIR__ . "/../Lib/session.php";
require_once __DIR__ . "/../Lib/authz.php";
require_once __DIR__ . "/../Lib/audit.php";
require_once __DIR__ . "/../Lib/species.php";

const CATALOG_NAME_MAX = 120;

$pdo = db();
biodexSessionStart($pdo);
$userId = requireUserId();

$method = $_SERVER["REQUEST_METHOD"];
$path   = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
if (!preg_match('#^/api/catalogs(?:/([0-9a-f-]{36})(?:/(tree|species)(?:/([^/]+)(?:/(sensitivity))?)?)?)?/?$#', $path, $m)) {
    apiError("Recurso no encontrado.", 404);
}
$catalogId = $m[1] ?? null;
$sub       = $m[2] ?? null;
$taxonID   = isset($m[3]) ? rawurldecode($m[3]) : null;
$sensPath  = isset($m[4]);

// ------------------------------------------------------------------------------------------------

function catalogOut(array $c, string $role, ?int $speciesCount = null): array {
    return [
        "id" => $c["id"], "name" => $c["name"], "description" => $c["description"],
        "ownerId" => (int) $c["owner_id"], "role" => $role,
        "createdAt" => $c["created_at"], "updatedAt" => $c["updated_at"],
    ] + ($speciesCount === null ? [] : ["speciesCount" => $speciesCount]);
}

function catalogRow(PDO $pdo, string $id): array {
    $stmt = $pdo->prepare("SELECT * FROM catalogs WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: apiError("Catálogo no encontrado.", 404);
}

/** Nombre y descripción validados del cuerpo; $partial para PATCH (solo lo que venga). */
function catalogFields(array $body, bool $partial): array {
    $out = [];
    if (!$partial || array_key_exists("name", $body)) {
        $name = trim((string) ($body["name"] ?? ""));
        if ($name === "" || mb_strlen($name) > CATALOG_NAME_MAX) apiError("«name» es obligatorio (≤ " . CATALOG_NAME_MAX . " caracteres).", 400);
        $out["name"] = $name;
    }
    if (array_key_exists("description", $body)) {
        $d = $body["description"];
        if ($d !== null && !is_string($d)) apiError("«description» debe ser texto o null.", 400);
        $out["description"] = $d === null || trim($d) === "" ? null : trim($d);
    }
    if ($partial && !$out) apiError("Nada que cambiar: envía «name» o «description».", 400);
    return $out;
}

/** Especies vigentes del catálogo con su nivel de sensibilidad. */
function catalogSpecies(PDO $pdo, string $catalogId): array {
    $stmt = $pdo->prepare(
        "SELECT s.*, ss.level AS sens_level, ss.suggested_level AS sens_suggested, ss.reason AS sens_reason,
                ss.set_by AS sens_set_by, cs.added_at
           FROM catalog_species cs
           JOIN species s ON s.taxonID = cs.taxonID
           LEFT JOIN species_sensitivity ss ON ss.catalog_id = cs.catalog_id AND ss.taxonID = cs.taxonID
          WHERE cs.catalog_id = ? AND cs.deleted_at IS NULL
          ORDER BY s.canonical_name"
    );
    $stmt->execute([$catalogId]);
    return $stmt->fetchAll();
}

function sensitivityOut(array $r): array {
    return [
        "level"          => $r["sens_level"] !== null ? (int) $r["sens_level"] : null,
        "suggestedLevel" => $r["sens_suggested"] !== null ? (int) $r["sens_suggested"] : null,
        "reason"         => $r["sens_reason"],
        "confirmed"      => $r["sens_set_by"] !== null,
    ];
}

/** Especie vigente del catálogo con su sensibilidad, o 404. */
function catalogSpeciesRow(PDO $pdo, string $catalogId, string $taxonID): array {
    $stmt = $pdo->prepare(
        "SELECT cs.taxonID, ss.level AS sens_level, ss.suggested_level AS sens_suggested, ss.reason AS sens_reason,
                ss.set_by AS sens_set_by
           FROM catalog_species cs
           LEFT JOIN species_sensitivity ss ON ss.catalog_id = cs.catalog_id AND ss.taxonID = cs.taxonID
          WHERE cs.catalog_id = ? AND cs.taxonID = ? AND cs.deleted_at IS NULL"
    );
    $stmt->execute([$catalogId, $taxonID]);
    return $stmt->fetch() ?: apiError("La especie no está en el catálogo.", 404);
}

/** Árbol reino › filo › clase › orden › familia › especies. Nodo = id de EIDOS o, si falta, el nombre. */
function catalogTree(array $rows): array {
    $ranks = ["kingdom", "phylum", "class", "order", "family"];
    $root = ["children" => []];
    foreach ($rows as $r) {
        $node = &$root;
        foreach ($ranks as $rank) {
            $name = $r[$rank];
            $key = $r["{$rank}_id"] ?? null;
            $key = $key !== null ? $key : ($name !== null ? "name:{$name}" : "unknown");
            $node["children"][$key] ??= ["rank" => $rank, "id" => $key, "name" => $name, "count" => 0, "children" => []];
            $node = &$node["children"][$key];
            $node["count"]++;
        }
        $node["species"][] = [
            "taxonID" => $r["taxonID"], "scientificName" => $r["scientificName"],
            "vernacularName" => $r["vernacularName"], "iucnPrimary" => speciesIucnPrimary($r),
            "sensitivityLevel" => $r["sens_level"] !== null ? (int) $r["sens_level"] : null,
        ];
        unset($node);
    }
    $finish = function (array $n) use (&$finish): array {
        if (isset($n["species"])) { unset($n["children"]); return $n; }
        $children = array_values(array_map($finish, $n["children"]));
        usort($children, fn($a, $b) => [$a["name"] === null, $a["name"]] <=> [$b["name"] === null, $b["name"]]);
        $n["children"] = $children;
        return $n;
    };
    return $finish($root)["children"];
}

// --- /api/catalogs ------------------------------------------------------------------------------

if ($catalogId === null) {
    if ($method === "GET") {
        $stmt = $pdo->prepare(
            "SELECT c.*, m.role,
                    (SELECT COUNT(*) FROM catalog_species cs WHERE cs.catalog_id = c.id AND cs.deleted_at IS NULL) AS n
               FROM catalog_members m
               JOIN catalogs c ON c.id = m.catalog_id AND c.deleted_at IS NULL
              WHERE m.user_id = ?
              ORDER BY c.name"
        );
        $stmt->execute([$userId]);
        respond(["catalogs" => array_map(fn($c) => catalogOut($c, $c["role"], (int) $c["n"]), $stmt->fetchAll())]);
    }
    if ($method === "POST") {
        $f = catalogFields(requireJsonBody("{name, description?}"), false);
        $id = uuidV4();
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO catalogs (id, owner_id, name, description) VALUES (?, ?, ?, ?)")
            ->execute([$id, $userId, $f["name"], $f["description"] ?? null]);
        $pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role, granted_by) VALUES (?, ?, 'owner', ?)")
            ->execute([$id, $userId, $userId]);
        $pdo->commit();
        respond(["catalog" => catalogOut(catalogRow($pdo, $id), "owner", 0)], 201);
    }
    apiMethodNotAllowed("GET o POST");
}

// --- /api/catalogs/{id} y /tree -----------------------------------------------------------------

if ($sub === null) {
    if ($method === "GET") {
        $role = requireCatalogPermission($pdo, $catalogId, $userId, "view");
        $rows = catalogSpecies($pdo, $catalogId);
        $byGroup = [];
        $byCategory = [];
        foreach ($rows as $r) {
            $g = $r["taxonomicGroup"] ?? "Sin grupo";
            $c = $r["iucn_primary_code"] ?? "none";
            $byGroup[$g] = ($byGroup[$g] ?? 0) + 1;
            $byCategory[$c] = ($byCategory[$c] ?? 0) + 1;
        }
        ksort($byGroup);
        respond([
            "catalog" => catalogOut(catalogRow($pdo, $catalogId), $role, count($rows)),
            "species" => array_map(fn($r) => speciesSummaryOut($r) + [
                "sensitivity" => sensitivityOut($r), "addedAt" => $r["added_at"],
            ], $rows),
            "summary" => ["total" => count($rows), "byTaxonomicGroup" => $byGroup, "byIucnCategory" => $byCategory],
        ]);
    }
    if ($method === "PATCH") {
        requireCatalogPermission($pdo, $catalogId, $userId, "edit_catalog");
        $f = catalogFields(requireJsonBody("{name?, description?}"), true);
        $sets = implode(", ", array_map(fn($k) => "`{$k}` = ?", array_keys($f)));
        $pdo->prepare("UPDATE catalogs SET {$sets} WHERE id = ?")->execute([...array_values($f), $catalogId]);
        respond(["catalog" => catalogOut(catalogRow($pdo, $catalogId), "owner")]);
    }
    if ($method === "DELETE") {
        requireCatalogPermission($pdo, $catalogId, $userId, "edit_catalog");
        $pdo->prepare("UPDATE catalogs SET deleted_at = NOW() WHERE id = ?")->execute([$catalogId]);
        respond(["ok" => true]);
    }
    apiMethodNotAllowed("GET, PATCH o DELETE");
}

if ($sub === "tree") {
    if ($method !== "GET") apiMethodNotAllowed("GET");
    requireCatalogPermission($pdo, $catalogId, $userId, "view");
    respond(["tree" => catalogTree(catalogSpecies($pdo, $catalogId))]);
}

// --- /api/catalogs/{id}/species -----------------------------------------------------------------

if ($taxonID === null) {
    if ($method !== "POST") apiMethodNotAllowed("POST");
    requireCatalogPermission($pdo, $catalogId, $userId, "add_species");
    $taxonID = trim((string) (requireJsonBody("{taxonID}")["taxonID"] ?? ""));
    if ($taxonID === "") apiError("«taxonID» es obligatorio.", 400);

    // La sugerencia de nivel necesita UICN y estado legal: ficha completa (de caché o de EIDOS).
    $species = speciesWithDetail($pdo, $taxonID);
    if ($species === null) apiError("Especie no encontrada.", 404);
    [$level, $reason] = speciesSuggestLevel($species);

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT deleted_at FROM catalog_species WHERE catalog_id = ? AND taxonID = ? FOR UPDATE");
    $stmt->execute([$catalogId, $taxonID]);
    $existing = $stmt->fetch();
    if ($existing === false) {
        $pdo->prepare("INSERT INTO catalog_species (catalog_id, taxonID, added_by) VALUES (?, ?, ?)")
            ->execute([$catalogId, $taxonID, $userId]);
    } elseif ($existing["deleted_at"] !== null) {
        $pdo->prepare("UPDATE catalog_species SET deleted_at = NULL, added_by = ?, added_at = NOW() WHERE catalog_id = ? AND taxonID = ?")
            ->execute([$userId, $catalogId, $taxonID]);
    }
    // Idempotente: si ya hay nivel (sugerido o fijado por el owner) se conserva.
    $pdo->prepare("INSERT IGNORE INTO species_sensitivity (catalog_id, taxonID, level, suggested_level, reason) VALUES (?, ?, ?, ?, ?)")
        ->execute([$catalogId, $taxonID, $level, $level, mb_substr($reason, 0, 255)]);
    $pdo->commit();

    $created = $existing === false || $existing["deleted_at"] !== null;
    respond([
        "species"     => speciesSummaryOut($species),
        "sensitivity" => sensitivityOut(catalogSpeciesRow($pdo, $catalogId, $taxonID)),
    ], $created ? 201 : 200);
}

if (!$sensPath) {
    if ($method !== "DELETE") apiMethodNotAllowed("DELETE");
    requireCatalogPermission($pdo, $catalogId, $userId, "remove_species");
    catalogSpeciesRow($pdo, $catalogId, $taxonID);
    $pdo->prepare("UPDATE catalog_species SET deleted_at = NOW() WHERE catalog_id = ? AND taxonID = ?")
        ->execute([$catalogId, $taxonID]);
    respond(["ok" => true]);
}

// --- /api/catalogs/{id}/species/{taxonID}/sensitivity ------------------------------------------

if ($method !== "PUT") apiMethodNotAllowed("PUT");
requireCatalogPermission($pdo, $catalogId, $userId, "set_level");
$body = requireJsonBody("{level, reason}");
$level = filter_var($body["level"] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 4]]);
if ($level === false) apiError("«level» debe ser un entero de 1 a 4.", 400);
$reason = trim((string) ($body["reason"] ?? ""));
if (mb_strlen($reason) > 255) apiError("«reason» admite 255 caracteres.", 400);

$current = catalogSpeciesRow($pdo, $catalogId, $taxonID);
$suggested = $current["sens_suggested"] !== null ? (int) $current["sens_suggested"] : null;
// Bajar el nivel = número mayor = menos protección que la sugerida: exige motivo.
if ($suggested !== null && $level > $suggested && $reason === "") {
    apiError("Para bajar el nivel respecto al sugerido ({$suggested}) hay que indicar «reason».", 422);
}

$old = $current["sens_level"] !== null ? (int) $current["sens_level"] : null;
$pdo->beginTransaction();
$pdo->prepare(
    "INSERT INTO species_sensitivity (catalog_id, taxonID, level, suggested_level, reason, set_by, set_at)
     VALUES (?, ?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE level = VALUES(level), reason = VALUES(reason), set_by = VALUES(set_by), set_at = NOW()"
)->execute([$catalogId, $taxonID, $level, $suggested, $reason === "" ? null : $reason, $userId]);
logAccess($pdo, $userId, "set_level", $catalogId, "{$taxonID}: " . ($old ?? "-") . " → {$level}" . ($reason !== "" ? " ({$reason})" : ""));
$pdo->commit();

respond(["taxonID" => $taxonID, "sensitivity" => sensitivityOut(catalogSpeciesRow($pdo, $catalogId, $taxonID))]);
