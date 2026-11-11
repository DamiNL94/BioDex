<?php
// ================================================================================================
// visits.php — sitios de muestreo y visitas (F11h, migración 016; docs/sync.md, docs/db.md).
// ------------------------------------------------------------------------------------------------
// Un SITIO es un lugar que se muestrea una y otra vez (parcela, transecto o punto de conteo): punto + radio, polígono
// o línea (WKT en WGS84). Una VISITA es un muestreo (Event de Darwin Core): sitio, protocolo, esfuerzo, observadores,
// especies objetivo, inicio y fin. Las marcas hechas durante la visita llevan su visit_id.
// Se sincronizan como las marcas (SYNC_ENTITIES de Api/sync.php): UUID del cliente, client_updated_at decide, solo el
// autor edita o borra, server_seq común y tombstones.
//
// SEGURIDAD: un sitio delata dónde viven sus especies. Su posición (y la de las visitas) sale por applyVisibility() con
// el nivel MÁS restrictivo de: las especies objetivo del sitio, las de sus visitas y las registradas en ellas. Y una MARCA
// de una visita sale con el más restrictivo entre su especie y su visita (visitLevelMap): una marca exacta de una especie
// común no puede delatar el sitio de una sensible contada en la misma visita. Si una marca o una visita nueva baja ese
// nivel, el sitio, sus visitas y sus marcas se vuelven a emitir (server_seq nuevo: visitsRelevel) para que el pull
// sustituya en los dispositivos la versión exacta por la generalizada. Generalizado: centro de la celda, radio ampliado
// a la celda, SIN geometría (ni polígono ni traza del transecto) y sin las condiciones (texto libre).
// ================================================================================================

require_once __DIR__ . "/sync.php";
require_once __DIR__ . "/utm.php";

const SITE_TYPES        = ["plot", "transect", "point"];
const VISIT_PROTOCOLS   = ["target_census", "plot_count", "transect", "timed_search"];
const VISIT_STATUSES    = ["open", "closed"];
const SITE_NAME_MAX     = 120;
const SITE_RADIUS_MAX   = 100000;      // m
const SITE_VERTICES_MAX = 5000;        // una traza GPS de transecto puede tener muchos puntos
const SITE_BAND_MAX     = 1000;        // m
const SITE_AREA_MAX     = 100000000;   // m² (100 km²)
const SITE_SPECIES_MAX  = 200;
const VISIT_OBSERVERS_MAX  = 500;
const VISIT_CONDITIONS_MAX = 2000;
const VISIT_EFFORT_MAX     = 100000;   // minutos

/** Etiquetas en castellano (hojas, DwC samplingProtocol). */
const VISIT_PROTOCOL_ES = [
    "target_census" => "censo completo de especies objetivo",
    "plot_count"    => "conteo en parcela",
    "transect"      => "transecto con banda",
    "timed_search"  => "búsqueda por tiempo",
];
const SITE_TYPE_ES = ["plot" => "parcela", "transect" => "transecto", "point" => "punto de conteo"];

// --- Geometría -------------------------------------------------------------------------------------

/**
 * WKT de un sitio → ["type" => POLYGON|LINESTRING, "coords" => [[lon, lat], …]] o null si no vale. Solo un anillo
 * (sin huecos); el polígono se cierra si no viene cerrado.
 */
function wktParse(string $wkt): ?array {
    if (!preg_match('/^\s*(POLYGON|LINESTRING)\s*\(\s*(\(?)([^()]*)\)?\s*\)\s*$/i', $wkt, $m)) return null;
    $type = strtoupper($m[1]);
    if (($type === "POLYGON") !== ($m[2] === "(")) return null;
    $coords = [];
    foreach (explode(",", $m[3]) as $pair) {
        $xy = preg_split('/\s+/', trim($pair));
        if (count($xy) !== 2 || !is_numeric($xy[0]) || !is_numeric($xy[1])) return null;
        [$lon, $lat] = [(float) $xy[0], (float) $xy[1]];
        if (abs($lat) > 90 || abs($lon) > 180) return null;
        $coords[] = [$lon, $lat];
    }
    if ($type === "POLYGON") {
        if ($coords && $coords[0] !== end($coords)) $coords[] = $coords[0];
        if (count($coords) < 4) return null;
    } elseif (count($coords) < 2) {
        return null;
    }
    if (count($coords) > SITE_VERTICES_MAX + 1) return null;
    return ["type" => $type, "coords" => $coords];
}

/** Geometría → WKT normalizado (6 decimales, «lon lat»). */
function wktFormat(array $g): string {
    $pts = implode(", ", array_map(fn($c) => rtrim(rtrim(sprintf("%.6F", $c[0]), "0"), ".") . " " . rtrim(rtrim(sprintf("%.6F", $c[1]), "0"), "."), $g["coords"]));
    return $g["type"] === "POLYGON" ? "POLYGON(({$pts}))" : "LINESTRING({$pts})";
}

/**
 * Cifras de una geometría en UTM 30N (las mismas que js/site-geom.js) → [areaM2, lengthM, centro [lat, lon], extensión m
 * (distancia máxima del centro a un vértice: la «incertidumbre» del sitio visto como un punto)].
 */
function siteGeometryMetrics(array $g): array {
    $pts = array_map(function ($c) { $u = toUtm($c[1], $c[0]); return [$u["easting"], $u["northing"]]; }, $g["coords"]);
    $n = count($pts);
    $length = 0.0;
    for ($i = 1; $i < $n; $i++) $length += hypot($pts[$i][0] - $pts[$i - 1][0], $pts[$i][1] - $pts[$i - 1][1]);
    $area = null;
    if ($g["type"] === "POLYGON") {
        $s = 0.0;
        for ($i = 0; $i < $n - 1; $i++) $s += $pts[$i][0] * $pts[$i + 1][1] - $pts[$i + 1][0] * $pts[$i][1];
        $area = abs($s) / 2;
        $ring = array_slice($g["coords"], 0, $n - 1);
    } else {
        $ring = $g["coords"];
    }
    // Centro: media de los vértices (de un polígono, sin repetir el de cierre). Basta para generalizar y para «cerca».
    $lat = array_sum(array_column($ring, 1)) / count($ring);
    $lon = array_sum(array_column($ring, 0)) / count($ring);
    $cu = toUtm($lat, $lon);
    $extent = 0.0;
    foreach ($pts as $p) $extent = max($extent, hypot($p[0] - $cu["easting"], $p[1] - $cu["northing"]));
    return ["areaM2" => $area, "lengthM" => $g["type"] === "LINESTRING" ? $length : null, "center" => [$lat, $lon], "extentM" => $extent];
}

/** Superficie muestreada de un sitio (m²): la dada; si no, la del polígono, la del círculo o longitud × banda. */
function siteArea(?int $radius, ?array $gm, ?float $bandM): ?float {
    if ($radius !== null) return M_PI * $radius * $radius;
    if ($gm === null) return null;
    if ($gm["areaM2"] !== null) return $gm["areaM2"];
    return $gm["lengthM"] !== null && $bandM !== null ? $gm["lengthM"] * $bandM : null;
}

// --- Validación del push --------------------------------------------------------------------------

/** Lista de taxonID (especies objetivo) → [taxonID…] sin repetir. */
function syncTaxonList(array $o, string $field = "species"): array {
    $list = $o[$field] ?? [];
    if (!is_array($list) || !array_is_list($list) || count($list) > SITE_SPECIES_MAX) {
        syncReject("invalid_field", "«{$field}» debe ser una lista de como mucho " . SITE_SPECIES_MAX . " taxonID.");
    }
    $taxa = [];
    foreach ($list as $t) {
        if (is_int($t)) $t = (string) $t;
        if (!is_string($t) || $t === "" || strlen($t) > 64) syncReject("invalid_taxon", "Cada especie de «{$field}» es un taxonID.");
        $taxa[$t] = true;
    }
    return array_keys($taxa);
}

/**
 * Sitio del cuerpo de un push → columnas de sites + `_species` (objetivo). Punto + radio o geometría WKT (una de las
 * dos). La superficie, la longitud y el centro de una geometría se calculan aquí (el cliente las muestra, pero manda el
 * servidor); una superficie dada por el usuario (`areaGiven`) se respeta.
 */
function syncValidateSite(mixed $s): array {
    if (!is_array($s) || array_is_list($s)) syncReject("invalid_item", "Cada sitio debe ser un objeto.");
    if (!isUuidV4($s["siteID"] ?? null)) syncReject("invalid_uuid", "«siteID» debe ser un UUID v4 en minúsculas.");
    $name = syncText($s, "name", SITE_NAME_MAX);
    if ($name === null) syncReject("missing_field", "El sitio necesita un nombre («name»).");
    $type = syncEnum($s, "siteType", SITE_TYPES) ?? syncReject("missing_field", "Falta «siteType» (plot, transect, point).");
    $radius = syncUint($s, "radiusM", false);
    if ($radius !== null && ($radius < 1 || $radius > SITE_RADIUS_MAX)) syncReject("invalid_field", "«radiusM» debe estar entre 1 y " . SITE_RADIUS_MAX . " m.");
    $wkt = $s["footprintWKT"] ?? null;
    $geom = null;
    if ($wkt !== null) {
        $geom = is_string($wkt) && strlen($wkt) <= 400000 ? wktParse($wkt) : null;
        if ($geom === null) syncReject("invalid_field", "«footprintWKT» debe ser POLYGON((lon lat, …)) o LINESTRING(lon lat, …) en WGS84 (≤ " . SITE_VERTICES_MAX . " vértices).");
    }
    if (($radius === null) === ($geom === null)) syncReject("missing_field", "Un sitio lleva radio («radiusM») o geometría («footprintWKT»), una de las dos.");
    $band = syncPositiveNumber($s, "bandM", SITE_BAND_MAX);
    $given = ($s["areaGiven"] ?? false) === true;
    $area = syncPositiveNumber($s, "areaM2", SITE_AREA_MAX);
    if ($given && $area === null) syncReject("missing_field", "«areaGiven» exige «areaM2».");
    $gm = $geom ? siteGeometryMetrics($geom) : null;
    if ($gm) {
        [$lat, $lon] = $gm["center"];
    } else {
        $lat = syncCoordinate($s, "decimalLatitude", 90);
        $lon = syncCoordinate($s, "decimalLongitude", 180);
    }
    $computed = siteArea($radius, $gm, $band);
    return [
        "siteID"            => $s["siteID"],
        "name"              => $name,
        "site_type"         => $type,
        "decimalLatitude"   => round($lat, 6),
        "decimalLongitude"  => round($lon, 6),
        "radius_m"          => $radius,
        "footprint_wkt"     => $geom ? wktFormat($geom) : null,
        "band_m"            => $band,
        "area_m2"           => $given ? $area : ($computed === null ? null : round($computed, 2)),
        "area_given"        => $given ? 1 : 0,
        "length_m"          => $gm && $gm["lengthM"] !== null ? round($gm["lengthM"], 2) : null,
        "protocol"          => syncEnum($s, "protocol", VISIT_PROTOCOLS),
        "client_updated_at" => syncClientUpdatedAt($s["clientUpdatedAt"] ?? null),
        "_species"          => syncTaxonList($s),
    ];
}

/** Visita del cuerpo de un push → columnas de visits + `_species` (objetivo). Una cerrada lleva fin. */
function syncValidateVisit(mixed $v): array {
    if (!is_array($v) || array_is_list($v)) syncReject("invalid_item", "Cada visita debe ser un objeto.");
    if (!isUuidV4($v["visitID"] ?? null)) syncReject("invalid_uuid", "«visitID» debe ser un UUID v4 en minúsculas.");
    $site = $v["siteID"] ?? null;
    if ($site !== null && !isUuidV4($site)) syncReject("invalid_uuid", "«siteID» debe ser un UUID v4 o null (visita oportunista).");
    $protocol = syncEnum($v, "protocol", VISIT_PROTOCOLS) ?? syncReject("missing_field", "Falta «protocol» (" . implode(", ", VISIT_PROTOCOLS) . ").");
    $status = syncEnum($v, "status", VISIT_STATUSES) ?? syncReject("missing_field", "Falta «status» (open, closed).");
    $start = syncUtcDate($v["eventStart"] ?? null, "eventStart");
    $end = ($v["eventEnd"] ?? null) === null ? null : syncUtcDate($v["eventEnd"], "eventEnd");
    if ($end !== null && $end < $start) syncReject("invalid_date", "«eventEnd» es anterior a «eventStart».");
    if ($status === "closed" && $end === null) syncReject("missing_field", "Una visita cerrada necesita «eventEnd».");
    $effort = syncUint($v, "effortMinutes", false);
    if ($effort !== null && $effort > VISIT_EFFORT_MAX) syncReject("invalid_field", "«effortMinutes» como mucho " . VISIT_EFFORT_MAX . ".");
    $hasPos = ($v["decimalLatitude"] ?? null) !== null || ($v["decimalLongitude"] ?? null) !== null;
    return [
        "visitID"           => $v["visitID"],
        "site_id"           => $site,
        "protocol"          => $protocol,
        "band_m"            => syncPositiveNumber($v, "bandM", SITE_BAND_MAX),
        "effort_minutes"    => $effort,
        "observers"         => syncText($v, "observers", VISIT_OBSERVERS_MAX),
        "event_start"       => $start->format("Y-m-d H:i:s"),
        "event_end"         => $end?->format("Y-m-d H:i:s"),
        "conditions"        => syncText($v, "conditions", VISIT_CONDITIONS_MAX),
        "status"            => $status,
        "decimalLatitude"   => $hasPos ? round(syncCoordinate($v, "decimalLatitude", 90), 6) : null,
        "decimalLongitude"  => $hasPos ? round(syncCoordinate($v, "decimalLongitude", 180), 6) : null,
        "client_updated_at" => syncClientUpdatedAt($v["clientUpdatedAt"] ?? null),
        "_species"          => syncTaxonList($v),
    ];
}

// --- Niveles de sensibilidad de sitios y visitas ---------------------------------------------------

/**
 * Especies ligadas a cada sitio (objetivo del sitio, objetivo de sus visitas y registradas en ellas) → [siteID => [taxonID…]].
 */
function siteTaxa(PDO $pdo, string $catalogId, array $siteIds): array {
    if (!$siteIds) return [];
    $in = implode(",", array_fill(0, count($siteIds), "?"));
    $stmt = $pdo->prepare(
        "SELECT siteID, taxonID FROM site_species WHERE siteID IN ({$in})
          UNION SELECT v.site_id, vs.taxonID FROM visits v JOIN visit_species vs ON vs.visitID = v.visitID
                 WHERE v.catalog_id = ? AND v.deleted_at IS NULL AND v.site_id IN ({$in})
          UNION SELECT v.site_id, o.taxonID FROM visits v JOIN occurrences o ON o.visit_id = v.visitID AND o.catalog_id = v.catalog_id
                 WHERE v.catalog_id = ? AND v.deleted_at IS NULL AND o.deleted_at IS NULL AND v.site_id IN ({$in})");
    $stmt->execute([...$siteIds, $catalogId, ...$siteIds, $catalogId, ...$siteIds]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$s, $t]) $out[$s][(string) $t] = true;
    return array_map("array_keys", $out);
}

/** Especies de cada visita (objetivo y registradas) → [visitID => [taxonID…]]. */
function visitTaxa(PDO $pdo, string $catalogId, array $visitIds): array {
    if (!$visitIds) return [];
    $in = implode(",", array_fill(0, count($visitIds), "?"));
    $stmt = $pdo->prepare(
        "SELECT visitID, taxonID FROM visit_species WHERE visitID IN ({$in})
          UNION SELECT visit_id, taxonID FROM occurrences WHERE catalog_id = ? AND deleted_at IS NULL AND visit_id IN ({$in})");
    $stmt->execute([...$visitIds, $catalogId, ...$visitIds]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$v, $t]) $out[$v][(string) $t] = true;
    return array_map("array_keys", $out);
}

/** Nivel más restrictivo de una lista de especies en el catálogo (sin especies: 4; sin nivel registrado: 1). */
function taxaLevel(PDO $pdo, string $catalogId, array $taxa): int {
    if (!$taxa) return 4;
    $stmt = $pdo->prepare("SELECT taxonID, level FROM species_sensitivity WHERE catalog_id = ? AND taxonID IN (" . implode(",", array_fill(0, count($taxa), "?")) . ")");
    $stmt->execute([$catalogId, ...$taxa]);
    $found = [];
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$t, $l]) $found[(string) $t] = normalizeSensitivityLevel($l);
    $level = 4;
    foreach ($taxa as $t) $level = min($level, $found[(string) $t] ?? 1);
    return $level;
}

/** Niveles de varios sitios → [siteID => nivel]. */
function siteLevels(PDO $pdo, string $catalogId, array $siteIds): array {
    $taxa = siteTaxa($pdo, $catalogId, $siteIds);
    $out = [];
    foreach ($siteIds as $id) $out[$id] = taxaLevel($pdo, $catalogId, $taxa[$id] ?? []);
    return $out;
}

/** Niveles de varias visitas (las suyas y las de su sitio) → [visitID => nivel]. $rows: filas de visits. */
function visitLevels(PDO $pdo, string $catalogId, array $rows): array {
    $taxa = visitTaxa($pdo, $catalogId, array_column($rows, "visitID"));
    $sites = siteLevels($pdo, $catalogId, array_values(array_unique(array_filter(array_column($rows, "site_id")))));
    $out = [];
    foreach ($rows as $r) {
        $own = taxaLevel($pdo, $catalogId, $taxa[$r["visitID"]] ?? []);
        $out[$r["visitID"]] = $r["site_id"] !== null ? min($own, $sites[$r["site_id"]] ?? 4) : $own;
    }
    return $out;
}

/**
 * Nivel de las visitas de una lista de marcas → [visitID => nivel]. Una marca hecha durante una visita sale con el MÁS
 * restrictivo entre el de su especie y el de su visita: si no, una marca exacta de una especie no sensible delataría
 * dónde está el sitio (generalizado) de una sensible de la misma visita. Visitas que no existen: no cuentan.
 */
function visitLevelMap(PDO $pdo, string $catalogId, array $visitIds): array {
    $visitIds = array_values(array_unique(array_filter($visitIds)));
    if (!$visitIds) return [];
    $stmt = $pdo->prepare("SELECT visitID, site_id FROM visits WHERE catalog_id = ? AND visitID IN (" . implode(",", array_fill(0, count($visitIds), "?")) . ")");
    $stmt->execute([$catalogId, ...$visitIds]);
    $rows = $stmt->fetchAll();
    return $rows ? visitLevels($pdo, $catalogId, $rows) : [];
}

/**
 * Nivel más restrictivo de las visitas en las que se registró una especie (4 si ninguna). Para la geometría que sale de
 * TODAS sus marcas juntas (casco y celdas de EOO/AOO, subpoblaciones): si una sola se hizo en el sitio de una especie
 * sensible, el conjunto se generaliza como ella (Lib/metrics.php, Lib/assessment.php).
 */
function speciesVisitLevel(PDO $pdo, string $catalogId, string $taxonID): int {
    $stmt = $pdo->prepare("SELECT DISTINCT visit_id FROM occurrences WHERE catalog_id = ? AND taxonID = ? AND visit_id IS NOT NULL AND deleted_at IS NULL");
    $stmt->execute([$catalogId, $taxonID]);
    $levels = visitLevelMap($pdo, $catalogId, $stmt->fetchAll(PDO::FETCH_COLUMN));
    return $levels ? min($levels) : 4;
}

/** Hijos (especies objetivo) de sitios o visitas → [id => [taxonID…]]. */
function childTaxa(PDO $pdo, string $table, string $idCol, array $ids): array {
    if (!$ids) return [];
    $stmt = $pdo->prepare("SELECT `{$idCol}`, taxonID FROM `{$table}` WHERE `{$idCol}` IN (" . implode(",", array_fill(0, count($ids), "?")) . ") ORDER BY taxonID");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $t]) $out[$id][] = (string) $t;
    return $out;
}

/** Firma de visibilidad de un sitio (o de una visita oportunista): nivel con sus especies de ahora. */
function visitsScopeLevel(PDO $pdo, string $catalogId, ?string $siteId, ?string $visitId): int {
    if ($siteId !== null) return siteLevels($pdo, $catalogId, [$siteId])[$siteId];
    if ($visitId === null) return 4;
    return taxaLevel($pdo, $catalogId, visitTaxa($pdo, $catalogId, [$visitId])[$visitId] ?? []);
}

/**
 * Tras escribir una marca de visita, una visita o un sitio: si el nivel del sitio (o de la visita oportunista) BAJÓ
 * respecto a $before, el sitio y sus visitas toman server_seq nuevo y el pull manda la versión generalizada a quien no
 * es de confianza. Llamar DENTRO de la transacción de la escritura.
 */
function visitsRelevel(PDO $pdo, string $catalogId, ?string $siteId, ?string $visitId, int $before): void {
    $after = visitsScopeLevel($pdo, $catalogId, $siteId, $visitId);
    if ($after >= $before) return;
    $visits = [$visitId];
    if ($siteId !== null) {
        $pdo->prepare("UPDATE sites SET server_seq = ? WHERE siteID = ?")->execute([syncNextSeq($pdo), $siteId]);
        $stmt = $pdo->prepare("SELECT visitID FROM visits WHERE site_id = ? ORDER BY visitID");
        $stmt->execute([$siteId]);
        $visits = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $bump = $pdo->prepare("UPDATE visits SET server_seq = ? WHERE visitID = ?");
    $marks = $pdo->prepare("SELECT occurrenceID FROM occurrences WHERE visit_id = ? ORDER BY occurrenceID");
    $bumpMark = $pdo->prepare("UPDATE occurrences SET server_seq = ? WHERE occurrenceID = ?");
    foreach (array_filter($visits) as $v) {
        $bump->execute([syncNextSeq($pdo), $v]);
        // Sus marcas también: salen con el nivel de la visita (visitLevelMap) y la copia exacta que ya bajó hay que sustituirla.
        $marks->execute([$v]);
        foreach ($marks->fetchAll(PDO::FETCH_COLUMN) as $o) $bumpMark->execute([syncNextSeq($pdo), $o]);
    }
}

/** [siteId, visitId] a los que afecta una marca de la visita $visitId (sitio null si es oportunista o no existe aún). */
function visitScopeOf(PDO $pdo, ?string $visitId): array {
    if ($visitId === null) return [null, null];
    $stmt = $pdo->prepare("SELECT site_id FROM visits WHERE visitID = ?");
    $stmt->execute([$visitId]);
    $site = $stmt->fetchColumn();
    return [$site === false ? null : $site, $visitId];
}

// --- Salida ----------------------------------------------------------------------------------------

/** Sitio tal como sale hacia $viewerId. $species: objetivo; $level: el de siteLevels(). $row necesita `recorded_by`. */
function siteOut(array $row, array $species, int $level, string $role, int $viewerId): array {
    $meta = ["siteID" => $row["siteID"], "userId" => (int) $row["user_id"], "clientUpdatedAt" => syncIsoUtc($row["client_updated_at"]),
             "serverSeq" => (int) $row["server_seq"]];
    if ($row["deleted_at"] !== null) return $meta + ["deleted" => true];
    $geom = $row["footprint_wkt"] !== null ? wktParse($row["footprint_wkt"]) : null;
    $extent = $row["radius_m"] !== null ? (int) $row["radius_m"] : ($geom ? (int) ceil(siteGeometryMetrics($geom)["extentM"]) : null);
    $v = applyVisibility(["user_id" => $row["user_id"], "decimalLatitude" => $row["decimalLatitude"], "decimalLongitude" => $row["decimalLongitude"],
                          "coordinateUncertaintyInMeters" => $extent], $level, $role, $viewerId);
    $generalized = array_key_exists("dataGeneralizations", $v);
    $num = fn($x) => $x === null ? null : (float) $x;
    return $meta + [
        "deleted"          => false,
        "recordedBy"       => $row["recorded_by"] ?? null,
        "name"             => $row["name"],
        "siteType"         => $row["site_type"],
        "decimalLatitude"  => $num($v["decimalLatitude"]),
        "decimalLongitude" => $num($v["decimalLongitude"]),
        "radiusM"          => $generalized ? ($v["coordinateUncertaintyInMeters"] ?? null) : ($row["radius_m"] === null ? null : (int) $row["radius_m"]),
        "footprintWKT"     => $generalized ? null : $row["footprint_wkt"],
        "bandM"            => $num($row["band_m"]),
        "areaM2"           => $num($row["area_m2"]),
        "areaGiven"        => (int) $row["area_given"] === 1,
        "lengthM"          => $num($row["length_m"]),
        "protocol"         => $row["protocol"],
        "species"          => array_values($species),
        "generalized"      => $generalized,
    ] + ($generalized ? ["dataGeneralizations" => $v["dataGeneralizations"], "informationWithheld" => $v["informationWithheld"]] : []);
}

/** Visita tal como sale hacia $viewerId. $species: objetivo; $level: el de visitLevels(). */
function visitOut(array $row, array $species, int $level, string $role, int $viewerId): array {
    $meta = ["visitID" => $row["visitID"], "userId" => (int) $row["user_id"], "clientUpdatedAt" => syncIsoUtc($row["client_updated_at"]),
             "serverSeq" => (int) $row["server_seq"]];
    if ($row["deleted_at"] !== null) return $meta + ["deleted" => true];
    $hasPos = $row["decimalLatitude"] !== null;
    $v = $hasPos ? applyVisibility(["user_id" => $row["user_id"], "decimalLatitude" => $row["decimalLatitude"],
        "decimalLongitude" => $row["decimalLongitude"], "coordinateUncertaintyInMeters" => null], $level, $role, $viewerId) : [];
    // Sin posición propia, «generalizada» si lo estaría su sitio para este usuario (las condiciones se retienen igual).
    $generalized = $hasPos ? array_key_exists("dataGeneralizations", $v)
        : ($level < 4 && !canSeeExact($role, (int) $row["user_id"] === $viewerId));
    return $meta + [
        "deleted"          => false,
        "recordedBy"       => $row["recorded_by"] ?? null,
        "siteID"           => $row["site_id"],
        "protocol"         => $row["protocol"],
        "bandM"            => $row["band_m"] === null ? null : (float) $row["band_m"],
        "effortMinutes"    => $row["effort_minutes"] === null ? null : (int) $row["effort_minutes"],
        "observers"        => $row["observers"],
        "eventStart"       => syncIsoUtc($row["event_start"]),
        "eventEnd"         => syncIsoUtc($row["event_end"]),
        "conditions"       => $generalized ? null : $row["conditions"],
        "status"           => $row["status"],
        "decimalLatitude"  => $hasPos && $v["decimalLatitude"] !== null ? (float) $v["decimalLatitude"] : null,
        "decimalLongitude" => $hasPos && $v["decimalLongitude"] !== null ? (float) $v["decimalLongitude"] : null,
        "species"          => array_values($species),
        "generalized"      => $generalized,
    ];
}

/** Sitios con autor, especies objetivo y nivel → [[row, species[], level]]. $where sobre el alias s. */
function siteRows(PDO $pdo, string $where, array $params, string $catalogId, string $suffix = ""): array {
    $stmt = $pdo->prepare("SELECT s.*, u.name AS recorded_by FROM sites s JOIN users u ON u.id = s.user_id WHERE {$where} {$suffix}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (!$rows) return [];
    $ids = array_column($rows, "siteID");
    $children = childTaxa($pdo, "site_species", "siteID", $ids);
    $levels = siteLevels($pdo, $catalogId, $ids);
    return array_map(fn($r) => [$r, $children[$r["siteID"]] ?? [], $levels[$r["siteID"]]], $rows);
}

/** Visitas con autor, especies objetivo y nivel → [[row, species[], level]]. $where sobre el alias v. */
function visitRows(PDO $pdo, string $where, array $params, string $catalogId, string $suffix = ""): array {
    $stmt = $pdo->prepare("SELECT v.*, u.name AS recorded_by FROM visits v JOIN users u ON u.id = v.user_id WHERE {$where} {$suffix}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (!$rows) return [];
    $children = childTaxa($pdo, "visit_species", "visitID", array_column($rows, "visitID"));
    $levels = visitLevels($pdo, $catalogId, $rows);
    return array_map(fn($r) => [$r, $children[$r["visitID"]] ?? [], $levels[$r["visitID"]]], $rows);
}
