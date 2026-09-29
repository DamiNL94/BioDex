<?php
// ================================================================================================
// /api/species — buscador, ficha y especies creadas por el usuario. Requiere sesión.
// ------------------------------------------------------------------------------------------------
//   GET  /api/species?q=&group=&origin=&scope=&legal=&category=&limit=20&offset=0
//   GET  /api/species/{taxonID}   ficha completa (la trae de EIDOS si falta o tiene > 30 días)
//   POST /api/species             {scientificName, scientificNameAuthorship, taxonRank, vernacularName?}
//
// Búsqueda con `q`: EIDOS en vivo (grupo, origen y ámbito se filtran allí) + especies de usuario de la
// caché. Sin `q`: solo la caché. `legal` y `category` se aplican sobre la caché: la categoría llega ya con
// la búsqueda; el estado legal se pide a EIDOS para un máximo de SEARCH_DETAIL_MAX resultados por
// petición y el resto sale con `detailPending: true` (el cliente puede repetir la búsqueda).
// ================================================================================================

require_once __DIR__ . "/../Lib/session.php";
require_once __DIR__ . "/../Lib/species.php";

const SEARCH_DEFAULT_LIMIT = 20;
const SEARCH_MAX_LIMIT     = 50;
const SEARCH_DETAIL_MAX    = 5;     // estados legales pedidos a EIDOS por búsqueda
const SEARCH_FILTER_WINDOW = 100;   // candidatos de EIDOS sobre los que se aplican legal/category
const SEARCH_USER_CAP      = 50;    // especies de usuario por búsqueda
const USER_SPECIES_RANKS   = ["Species", "Subspecies", "Variety", "Form"];
const GBIF_MATCH_URL       = "https://api.gbif.org/v1/species/match";
const IUCN_CODES           = ["EX", "EW", "RE", "CR", "EN", "VU", "NT", "LC", "DD", "NA", "NE"];

$pdo = db();
biodexSessionStart($pdo);
$userId = requireUserId();

$method = $_SERVER["REQUEST_METHOD"];
$path   = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
if (!preg_match('#^/api/species(?:/([^/]+))?/?$#', $path, $m)) apiError("Recurso no encontrado.", 404);
$taxonID = isset($m[1]) ? rawurldecode($m[1]) : null;

// ------------------------------------------------------------------------------------------------

/** Lista separada por comas de valores permitidos, o 400. */
function listParam(string $name, array $allowed): array {
    $raw = trim((string) ($_GET[$name] ?? ""));
    if ($raw === "") return [];
    $values = array_values(array_unique(array_filter(array_map("trim", explode(",", $raw)))));
    $bad = array_diff($values, $allowed);
    if ($bad) apiError("Valor no válido en «{$name}»: " . implode(", ", $bad) . ". Admitidos: " . implode(", ", $allowed) . ".", 400);
    return $values;
}

function intParam(string $name, int $default, int $min, int $max): int {
    $v = filter_var($_GET[$name] ?? $default, FILTER_VALIDATE_INT);
    if ($v === false || $v < $min || $v > $max) apiError("«{$name}» debe ser un entero entre {$min} y {$max}.", 400);
    return $v;
}

/** Filas de `species` en el orden de $ids. */
function speciesRowsByIds(PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $stmt = $pdo->prepare("SELECT * FROM species WHERE taxonID IN (" . rtrim(str_repeat("?,", count($ids)), ",") . ")");
    $stmt->execute($ids);
    $byId = array_column($stmt->fetchAll(), null, "taxonID");
    return array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, $ids)));
}

/** Especies de la caché por filtros de columna (y texto opcional sobre nombre canónico y común). */
function searchCache(PDO $pdo, ?string $q, array $columnFilters, ?string $source, int $cap): array {
    $where = [];
    $args = [];
    if ($q !== null) {
        // La colación _ai_ ya ignora acentos y mayúsculas.
        $like = "%" . addcslashes($q, "%_\\") . "%";
        $where[] = "(canonical_name LIKE ? OR scientificName LIKE ? OR vernacularName LIKE ?)";
        array_push($args, $like, $like, $like);
    }
    foreach ($columnFilters as $col => $value) {
        $where[] = "`{$col}` = ?";
        $args[] = $value;
    }
    if ($source !== null) { $where[] = "source = ?"; $args[] = $source; }
    $stmt = $pdo->prepare("SELECT * FROM species" . ($where ? " WHERE " . implode(" AND ", $where) : "")
        . " ORDER BY canonical_name LIMIT " . (int) $cap);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

/**
 * Aplica `legal` y `category` sobre filas de la caché. Una fila de EIDOS sin estado legal lo pide (hasta
 * SEARCH_DETAIL_MAX); si no quedan peticiones, entra con detailPending (no se puede descartar).
 * @return list<array{0: array, 1: bool}> [fila, detailPending]
 */
function applyCacheFilters(PDO $pdo, array $rows, array $legal, array $categories): array {
    $fetched = 0;
    $out = [];
    foreach ($rows as $row) {
        if ($categories && !in_array($row["iucn_primary_code"], $categories, true)) continue;
        if ($legal) {
            if ($row["legal_fetched_at"] === null && $row["source"] === "eidos") {
                if ($fetched >= SEARCH_DETAIL_MAX) { $out[] = [$row, true]; continue; }
                $fetched++;
                speciesFetchLegal($pdo, $row["taxonID"]);
                $row = speciesFind($pdo, $row["taxonID"]);
            }
            if (!speciesLegalMatches($row, $legal)) continue;
        }
        $out[] = [$row, false];
    }
    return $out;
}

function searchItem(array $row, bool $pending = false): array {
    return speciesSummaryOut($row) + ($pending ? ["detailPending" => true] : []);
}

function handleSearch(PDO $pdo): never {
    $q        = trim(preg_replace('/\s+/u', " ", (string) ($_GET["q"] ?? "")));
    $group    = trim((string) ($_GET["group"] ?? ""));
    $origin   = trim((string) ($_GET["origin"] ?? ""));
    $scope    = trim((string) ($_GET["scope"] ?? ""));
    $legal    = listParam("legal", array_keys(SPECIES_LEGAL_FILTERS));
    $category = listParam("category", IUCN_CODES);
    $limit    = intParam("limit", SEARCH_DEFAULT_LIMIT, 1, SEARCH_MAX_LIMIT);
    $offset   = intParam("offset", 0, 0, 10000);
    if ($scope !== "" && !in_array($scope, ["T", "M", "MT"], true)) apiError("«scope» debe ser T, M o MT.", 400);
    if ($q !== "" && mb_strlen($q) < 2) apiError("La búsqueda necesita al menos 2 caracteres.", 400);

    $columnFilters = array_filter([
        "taxonomicGroup" => $group, "establishmentMeans" => $origin, "habitatScope" => $scope,
    ], fn($v) => $v !== "");
    $postFilter = $legal || $category;

    // Sin texto: solo la caché.
    if ($q === "") {
        if (!$columnFilters && !$postFilter) apiError("Indica «q» o algún filtro.", 400);
        $matches = applyCacheFilters($pdo, searchCache($pdo, null, $columnFilters, null, 1000), $legal, $category);
        respond([
            "total" => count($matches),
            "items" => array_map(fn($m) => searchItem(...$m), array_slice($matches, $offset, $limit)),
        ]);
    }

    // Con texto: especies de usuario (caché) delante, luego EIDOS en vivo.
    $userRows = searchCache($pdo, $q, $columnFilters, "user", SEARCH_USER_CAP);
    $eidosFilters = array_filter(["group" => $group, "origin" => $origin, "area" => $scope], fn($v) => $v !== "");

    if ($postFilter) {
        $found = eidosSearch($q, $eidosFilters, SEARCH_FILTER_WINDOW, 0);
        foreach ($found["items"] as $item) speciesUpsertFromSearch($pdo, $item);
        $eidosRows = speciesRowsByIds($pdo, array_column($found["items"], "taxonID"));
        $matches = applyCacheFilters($pdo, array_merge($userRows, $eidosRows), $legal, $category);
        respond([
            "total"     => count($matches),
            "truncated" => $found["total"] > SEARCH_FILTER_WINDOW || $found["truncated"],
            "items"     => array_map(fn($m) => searchItem(...$m), array_slice($matches, $offset, $limit)),
        ]);
    }

    $userPage = array_slice($userRows, $offset, $limit);
    $found = eidosSearch($q, $eidosFilters, $limit - count($userPage), max(0, $offset - count($userRows)));
    foreach ($found["items"] as $item) speciesUpsertFromSearch($pdo, $item);
    $rows = array_merge($userPage, speciesRowsByIds($pdo, array_column($found["items"], "taxonID")));
    respond([
        "total"     => count($userRows) + $found["total"],
        "truncated" => $found["truncated"],
        "items"     => array_map(fn($r) => searchItem($r), $rows),
    ]);
}

/** Clasificación desde GBIF (reino…familia), con los ids de EIDOS si EIDOS conoce la familia. */
function gbifClassification(string $name, string $genus): ?array {
    foreach ([["name" => $name], ["name" => $genus, "rank" => "GENUS"]] as $params) {
        $ch = curl_init(GBIF_MATCH_URL . "?" . http_build_query($params + ["strict" => "true"], "", "&", PHP_QUERY_RFC3986));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_USERAGENT => "BioDex/0.1 (+backend)"]);
        $body = curl_exec($ch);
        curl_close($ch);
        $g = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($g) || ($g["matchType"] ?? "NONE") === "NONE" || empty($g["kingdom"])) continue;
        $cls = [
            "kingdom" => $g["kingdom"] ?? null, "phylum" => $g["phylum"] ?? null, "class" => $g["class"] ?? null,
            "order" => $g["order"] ?? null, "family" => $g["family"] ?? null,
            "kingdom_id" => null, "phylum_id" => null, "class_id" => null, "order_id" => null, "family_id" => null,
        ];
        // Ids estables de EIDOS para que el árbol del catálogo no duplique ramas.
        if ($cls["family"]) {
            $rows = eidosRows("v_taxonomia", [
                "family" => "eq." . $cls["family"], "nametype" => "eq." . EIDOS_ACCEPTED, "limit" => 1,
                "select" => "kingdom,kingdomid,phylum,phylumid,class,classid,ordertaxonomic,ordertaxonomicid,family,familyid",
            ]);
            if ($rows) return speciesClassificationColumns($rows[0]) + ["classification_source" => "gbif"];
        }
        return $cls + ["classification_source" => "gbif"];
    }
    return null;
}

function handleCreate(PDO $pdo, int $userId): never {
    $body = requireJsonBody("{scientificName, scientificNameAuthorship, taxonRank, vernacularName?}");
    $name   = trim(preg_replace('/\s+/u', " ", (string) ($body["scientificName"] ?? "")));
    $author = trim((string) ($body["scientificNameAuthorship"] ?? ""));
    $rank   = (string) ($body["taxonRank"] ?? "");
    $vernacular = trim((string) ($body["vernacularName"] ?? "")) ?: null;

    if (!in_array($rank, USER_SPECIES_RANKS, true)) apiError("«taxonRank» debe ser " . implode(", ", USER_SPECIES_RANKS) . ".", 400);
    // Nombre sin autor: «Genus epithet» o «Genus epithet subsp.|var.|f. infra».
    $re = $rank === "Species"
        ? '/^(\p{Lu}\p{Ll}+) (\p{Ll}[\p{Ll}-]+)$/u'
        : '/^(\p{Lu}\p{Ll}+) (\p{Ll}[\p{Ll}-]+) (?:(?:subsp|var|f)\. )?(\p{Ll}[\p{Ll}-]+)$/u';
    if (!preg_match($re, $name, $parts)) {
        apiError("«scientificName» debe ser el nombre sin autor, p. ej. «Genus epithet»" .
            ($rank === "Species" ? "" : " seguido de subsp./var./f. y el epíteto infraespecífico") . ".", 400);
    }
    if ($author === "" || mb_strlen($author) > 200) apiError("«scientificNameAuthorship» es obligatorio (≤ 200 caracteres).", 400);
    if ($vernacular !== null && mb_strlen($vernacular) > 255) apiError("«vernacularName» admite 255 caracteres.", 400);

    // ¿Ya existe? Primero la caché (incluye especies de usuario), luego EIDOS.
    $stmt = $pdo->prepare("SELECT taxonID FROM species WHERE canonical_name = ? LIMIT 1");
    $stmt->execute([$name]);
    if ($existing = $stmt->fetchColumn()) respond(["error" => "La especie ya existe.", "taxonID" => $existing], 409);
    $resolved = eidosResolveTaxon($name);
    if ($resolved !== null) {
        respond(["error" => "La especie ya existe en EIDOS" . ($resolved["status"] === "sinonimo" ? " (como sinónimo)" : "") . ".",
                 "taxonID" => (string) $resolved["taxonid"]], 409);
    }

    // Clasificación: el género en EIDOS; si no está, GBIF.
    $genus = $parts[1];
    $genusRow = eidosGenusClassification($genus);
    $cls = $genusRow ? speciesClassificationColumns($genusRow) + ["classification_source" => "eidos"]
                     : gbifClassification($name, $genus);

    $taxonID = "biodex:" . uuidV4();
    $markers = ["Subspecies" => "subsp.", "Variety" => "var.", "Form" => "f."];
    $infra = $rank === "Species" ? null : $parts[3];
    $canonical = $infra === null ? $name : "{$parts[1]} {$parts[2]} {$markers[$rank]} {$infra}";
    speciesUpsert($pdo, $taxonID, ($cls ?? []) + [
        "scientificName"           => "{$canonical} {$author}",
        "scientificNameAuthorship" => $author,
        "canonical_name"           => $canonical,
        "taxonRank"                => $rank,
        "genus"                    => $genus,
        "specificEpithet"          => $parts[2],
        "infraspecificEpithet"     => $infra,
        "vernacularName"           => $vernacular,
        "taxonRemarks"             => "Taxón no presente en EIDOS",
        "created_by"               => $userId,
    ], "user");

    respond(["species" => speciesFullOut(speciesFind($pdo, $taxonID))], 201);
}

// ------------------------------------------------------------------------------------------------

if ($method === "GET" && $taxonID === null) handleSearch($pdo);

if ($method === "GET") {
    $row = speciesWithDetail($pdo, $taxonID);
    if ($row === null) apiError("Especie no encontrada.", 404);
    respond(["species" => speciesFullOut($row)]);
}

if ($method === "POST" && $taxonID === null) handleCreate($pdo, $userId);

apiMethodNotAllowed("GET o POST");
