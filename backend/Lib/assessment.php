<?php
// ================================================================================================
// assessment.php — evaluación UICN de una especie en un catálogo calculada en el servidor.
// ------------------------------------------------------------------------------------------------
// GET /api/catalogs/{id}/species/{taxonID}/assessment: con las coordenadas EXACTAS (como EOO/AOO) junta
// EOO/AOO, maduros, subpoblaciones, fragmentación, localidades (propuesta y confirmadas), hechos y parámetros,
// y pasa el motor (Lib/iucn.php). Cifras para cualquier miembro; la geometría de las subpoblaciones, según la
// confianza (metricsGeometryAccess, la misma regla que applyVisibility): casco y celdas a quien ve exactas;
// a quien no, el centro de cada subpoblación generalizado a su nivel; con nivel 1, nada.
// Localidades confirmadas: species_locations (PUT/DELETE …/locations, owner y custodio, a access_log).
// Comparación con GBIF (?gbif=1): Lib/gbif-compare.php.
// ================================================================================================

require_once __DIR__ . "/iucn.php";
require_once __DIR__ . "/metrics.php";
require_once __DIR__ . "/parameters.php";
require_once __DIR__ . "/sync.php";
require_once __DIR__ . "/authz.php";      // catalogCan()

const LOCATIONS_MAX = 1000;
const LOCATIONS_GROUPS_MAX = 500;
const LOCATIONS_JUSTIFICATION_MAX = 4000;

/** Localidades confirmadas tal como salen (API y pull). Borradas: solo identidad. */
function locationsOut(array $r): array {
    $base = ["taxonID" => (string) $r["taxonID"], "serverSeq" => (int) $r["server_seq"], "setAt" => syncIsoUtc($r["set_at"])];
    if ($r["deleted_at"] !== null) return $base + ["deleted" => true];
    return $base + [
        "deleted"       => false,
        "count"         => (int) $r["n_locations"],
        "groups"        => json_decode($r["location_groups"], true),
        "justification" => $r["justification"],
        "quality"       => $r["quality"],
        "source"        => $r["source"],
        "separationKm"  => $r["separation_km"] === null ? null : (float) $r["separation_km"],
        "setById"       => $r["set_by"] !== null ? (int) $r["set_by"] : null,
        "setBy"         => $r["set_by_name"] ?? null,
    ];
}

function locationsSelect(string $where): string {
    return "SELECT l.*, u.name AS set_by_name FROM species_locations l LEFT JOIN users u ON u.id = l.set_by WHERE {$where}";
}

/** Cuerpo de PUT …/locations → columnas (400 si algo no vale). */
function locationsFields(array $b): array {
    $count = $b["count"] ?? null;
    if (!is_int($count) || $count < 1 || $count > LOCATIONS_MAX) apiError("«count» debe ser un entero entre 1 y " . LOCATIONS_MAX . ".", 400);
    $groups = $b["groups"] ?? [];
    if (!is_array($groups) || !array_is_list($groups) || count($groups) > LOCATIONS_GROUPS_MAX) apiError("«groups» debe ser una lista.", 400);
    $clean = [];
    $seen = [];
    foreach ($groups as $g) {
        if (!is_array($g) || array_is_list($g)) apiError("Cada grupo de «groups» es un objeto.", 400);
        $subs = $g["subpops"] ?? [];
        if (!is_array($subs) || !array_is_list($subs)) apiError("«subpops» de cada grupo es una lista.", 400);
        foreach ($subs as $s) {
            if (!is_string($s) || !preg_match('/^S\d{1,4}$/', $s)) apiError("Las subpoblaciones se nombran S1, S2…", 400);
            if (isset($seen[$s])) apiError("La subpoblación {$s} está en dos localidades.", 400);
            $seen[$s] = true;
        }
        if (!$subs) apiError("Cada localidad tiene al menos una subpoblación.", 400);
        $impact = $g["impactID"] ?? null;
        if ($impact !== null && !isUuidV4($impact)) apiError("«impactID» debe ser el UUID de un impacto.", 400);
        $code = $g["threatCode"] ?? null;
        if ($code !== null && !isset(iucnThreatLabels()[$code])) apiError("«threatCode» desconocido.", 400);
        $label = mb_substr(trim((string) ($g["label"] ?? "")), 0, 40);
        $clean[] = ["label" => $label, "subpops" => array_values($subs), "impactID" => $impact, "threatCode" => $code,
                    "noThreat" => ($g["noThreat"] ?? false) === true];
    }
    if ($clean && count($clean) !== $count) apiError("«count» ({$count}) no coincide con el número de localidades de «groups» (" . count($clean) . ").", 400);
    $just = trim((string) ($b["justification"] ?? ""));
    if ($just === "") apiError("Falta la justificación de las localidades («justification»).", 400);
    if (mb_strlen($just) > LOCATIONS_JUSTIFICATION_MAX) apiError("«justification» admite " . LOCATIONS_JUSTIFICATION_MAX . " caracteres.", 400);
    $quality = $b["quality"] ?? null;
    if ($quality === "") $quality = null;
    if ($quality !== null && !in_array($quality, PARAMETER_QUALITIES, true)) apiError("«quality» debe ser uno de: " . implode(", ", PARAMETER_QUALITIES) . ".", 400);
    $source = trim((string) ($b["source"] ?? ""));
    if (mb_strlen($source) > PARAMETER_SOURCE_MAX) apiError("«source» admite " . PARAMETER_SOURCE_MAX . " caracteres.", 400);
    $sep = $b["separationKm"] ?? null;
    if ($sep !== null && (!is_int($sep) && !is_float($sep) || $sep <= 0 || $sep > 10000)) apiError("«separationKm» no es una distancia válida.", 400);
    return ["n_locations" => $count, "location_groups" => json_encode($clean, JSON_UNESCAPED_UNICODE), "justification" => $just,
            "quality" => $quality, "source" => $source === "" ? null : $source, "separation_km" => $sep];
}

/** Parámetros vivos de la especie → [clave => parameterOut]. */
function assessmentParams(PDO $pdo, string $catalogId, string $taxonID): array {
    $stmt = $pdo->prepare(parameterSelect("p.catalog_id = ? AND p.taxonID = ? AND p.deleted_at IS NULL"));
    $stmt->execute([$catalogId, $taxonID]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) $out[$r["param_key"]] = parameterOut($r);
    return $out;
}

/** Impactos vivos que afectan a la especie, proyectados para los cuantificadores (exactos: no salen de aquí). */
function assessmentImpacts(PDO $pdo, string $catalogId, string $taxonID): array {
    $rows = impactRows($pdo, "i.catalog_id = ? AND i.deleted_at IS NULL AND i.impactID IN (SELECT impactID FROM impact_species WHERE taxonID = ?)",
        [$catalogId, $taxonID], $catalogId);
    $out = [];
    foreach ($rows as [$r]) {
        $u = toUtm((float) $r["decimalLatitude"], (float) $r["decimalLongitude"]);
        $poly = $r["polygon"] !== null ? json_decode($r["polygon"], true) : null;
        $out[] = ["impactID" => $r["impactID"], "threatCode" => $r["threat_code"], "intensity" => $r["intensity"],
                  "scopePct" => $r["scope_pct"] === null ? null : (float) $r["scope_pct"], "timing" => $r["timing"],
                  "x" => (float) $u["easting"], "y" => (float) $u["northing"], "radiusM" => $r["radius_m"] === null ? null : (int) $r["radius_m"],
                  "polygonXY" => $poly ? array_map(function ($v) { $p = toUtm((float) $v[0], (float) $v[1]); return ["x" => (float) $p["easting"], "y" => (float) $p["northing"]]; }, $poly) : null];
    }
    return $out;
}

/** Marcas de la especie en el catálogo con lo que necesitan los cuantificadores (EXACTAS: no salen). */
function assessmentRecords(PDO $pdo, string $catalogId, string $taxonID): array {
    $stmt = $pdo->prepare("SELECT occurrenceID, user_id, decimalLatitude, decimalLongitude, coordinateUncertaintyInMeters, occurrenceStatus,
                                  eventDate, mature_count, sampleSizeValue
                             FROM occurrences WHERE catalog_id = ? AND taxonID = ? AND deleted_at IS NULL");
    $stmt->execute([$catalogId, $taxonID]);
    return array_map(function ($r) {
        $r["mature_count"] = $r["mature_count"] === null ? null : (int) $r["mature_count"];
        $r["eventDate"] = $r["eventDate"] . "Z";
        return $r;
    }, $stmt->fetchAll());
}

/**
 * Serie temporal de la especie (Lib/series.php) con las visitas a SITIOS del catálogo en las que se contó: maduros
 * por visita (las ausencias, 0), máximo por sitio y año, índice anual, tasa r con IC y reducción en la ventana de A.
 * Sin coordenadas: sitios por nombre, para cualquier miembro.
 */
function assessmentSeries(PDO $pdo, string $catalogId, string $taxonID, array $params): array {
    $stmt = $pdo->prepare("SELECT v.visitID, v.site_id AS siteID, v.event_start AS eventStart, s.name
                             FROM visits v JOIN sites s ON s.siteID = v.site_id AND s.deleted_at IS NULL
                            WHERE v.catalog_id = ? AND v.deleted_at IS NULL
                              AND v.visitID IN (SELECT visit_id FROM occurrences WHERE catalog_id = ? AND taxonID = ? AND deleted_at IS NULL)");
    $stmt->execute([$catalogId, $catalogId, $taxonID]);
    $visits = $stmt->fetchAll();
    $names = array_column($visits, "name", "siteID");
    $stmt = $pdo->prepare("SELECT visit_id AS visitID, taxonID, occurrenceStatus, mature_count FROM occurrences
                            WHERE catalog_id = ? AND taxonID = ? AND visit_id IS NOT NULL AND deleted_at IS NULL");
    $stmt->execute([$catalogId, $taxonID]);
    $occ = array_map(fn($r) => ["mature_count" => $r["mature_count"] === null ? null : (int) $r["mature_count"], "taxonID" => (string) $r["taxonID"]] + $r,
        $stmt->fetchAll());
    $from = seriesFromVisits($visits, $occ, $taxonID, $names);
    $series = seriesAnalyze($from["obs"], iucnParamValue($params, "generation_length"), iucnCriteria());
    return $series + ["skippedVisits" => $from["skipped"]];
}

/** Lo esencial de la serie para guardarlo con una instantánea (sin los recuentos por sitio). */
function assessmentSeriesSummary(array $s): array {
    return array_intersect_key($s, array_flip(["status", "reason", "nSites", "nYears", "firstYear", "lastYear", "trend", "window", "reduction", "fluctuation", "windowYears"]));
}

/**
 * Subpoblaciones para la salida según la confianza: sin puntos nunca; casco y celdas solo con acceso «full»;
 * «generalized»: el centro de cada una generalizado a la celda del nivel; «none»: solo cifras.
 */
function assessmentSubpopsOut(array $subpops, string $access, int $level): array {
    return array_map(function ($s) use ($access, $level) {
        $out = ["label" => $s["label"], "nRecords" => $s["nRecords"], "mature" => $s["mature"], "nearestKm" => $s["nearestKm"],
                "nearest" => $s["nearest"], "nCells" => count($s["cells"])];
        if ($access === "full") return $out + ["hull" => $s["hull"], "cells" => $s["cells"], "centroid" => $s["centroid"]];
        if ($access === "generalized") {
            $g = generalizePoint($s["centroid"][0], $s["centroid"][1], $level, null);
            return $out + ["centroid" => [$g["decimalLatitude"], $g["decimalLongitude"]], "radiusM" => $g["coordinateUncertaintyInMeters"]];
        }
        return $out;
    }, $subpops);
}

/**
 * Instantánea del motor 2 subida por el push → [inputs, result] DEFINITIVOS: los cuantificadores se recalculan aquí
 * con las coordenadas exactas (lo que haya en el servidor al sincronizar, no lo que vio el dispositivo) y se conservan
 * solo las decisiones del usuario (NT/LC a juicio experto, posiblemente extinta). El resultado lleva el resumen de los
 * cuantificadores y `computedBy: server`.
 */
function assessmentSnapshot(PDO $pdo, string $catalogId, string $taxonID, int $userId, array $clientInputs): array {
    $a = speciesAssessment($pdo, $catalogId, $taxonID, $userId, "viewer", 1);   // sin geometría ni view_exact
    $choices = ["expertCategory" => $clientInputs["expertCategory"] ?? null, "possiblyExtinct" => ($clientInputs["possiblyExtinct"] ?? null) === true];
    $inputs = array_merge($a["inputs"], ["expertCategory" => in_array($choices["expertCategory"], ["NT", "LC"], true) ? $choices["expertCategory"] : null,
                                         "possiblyExtinct" => $choices["possiblyExtinct"]]);
    $m = $a["mature"];
    $sp = $a["subpopulations"];
    $summary = [
        "origin" => "server", "computedAt" => $a["computedAt"], "nPoints" => $a["nPoints"],
        "metrics" => array_intersect_key($a["metrics"], array_flip(["eooKm2", "aooKm2", "aooRange", "cellSizeKm"])),
        "mature" => array_intersect_key($m, array_flip(["min", "best", "max", "quality", "totalQuality", "method", "density", "areaKm2", "areaSource", "doubleCountPairs", "nPlots", "plotsRequired"])),
        "subpopulations" => ["separationKm" => $sp["separationKm"], "c2a" => $sp["c2a"],
            "list" => array_map(fn($s) => array_intersect_key($s, array_flip(["label", "nRecords", "mature", "nearestKm"])), $sp["subpops"])],
        "fragmentation" => array_intersect_key($a["fragmentation"], array_flip(["status", "severe", "pctAoo", "pctIndividuals", "missing"])),
        "completeness" => $a["completeness"],
        "locations" => ["count" => is_int($inputs["B"]["locations"] ?? null) ? $inputs["B"]["locations"] : $a["locationsProposal"]["count"],
                        "confirmed" => is_int($inputs["B"]["locations"] ?? null)],
        "series" => assessmentSeriesSummary($a["series"]),
    ];
    return [$inputs, assessIucn($inputs) + ["quantifiers" => $summary, "computedBy" => "server"]];
}

/** Respuesta de GET …/assessment. */
function speciesAssessment(PDO $pdo, string $catalogId, string $taxonID, int $userId, string $role, int $level): array {
    ["m" => $m, "computedAt" => $computedAt, "rows" => $rows] = metricsStored($pdo, $catalogId, $taxonID, $userId, "team", METRICS_DEFAULT_CELL_KM);
    $metrics = ["eooKm2" => $m["eooKm2"], "aooKm2" => $m["aooKm2"], "aooRange" => $m["aooRange"], "aooCells" => $m["aooCells"],
                "nRecords" => $m["nRecords"], "cellSizeKm" => METRICS_DEFAULT_CELL_KM, "warnings" => $m["warnings"], "computedAt" => syncIsoUtc($computedAt)];
    $params = assessmentParams($pdo, $catalogId, $taxonID);
    $defaults = parameterDefaults($pdo, $taxonID);
    $stmt = $pdo->prepare(locationsSelect("l.catalog_id = ? AND l.taxonID = ? AND l.deleted_at IS NULL"));
    $stmt->execute([$catalogId, $taxonID]);
    $locRow = $stmt->fetch() ?: null;
    $locations = $locRow ? locationsOut($locRow) : null;
    $impacts = assessmentImpacts($pdo, $catalogId, $taxonID);
    $q = iucnQuantify(assessmentRecords($pdo, $catalogId, $taxonID), ["params" => $params, "defaults" => $defaults, "impacts" => $impacts,
        "metrics" => $metrics, "habitatCodes" => iucnThreats()["habitat_codes"]]);
    $series = assessmentSeries($pdo, $catalogId, $taxonID, $params);
    $inputs = iucnEngineInputs($q, $params, $metrics, $locations, [], $series);
    $result = assessIucn($inputs);

    // Marcas de visitas a sitios de especies más sensibles: la geometría sale con ese nivel (Lib/visits.php).
    $level = min($level, speciesVisitLevel($pdo, $catalogId, $taxonID));
    $access = metricsGeometryAccess($role, $level, "team");
    $foreign = count(array_filter($rows, fn($r) => (int) $r["user_id"] !== $userId));
    if ($access === "full" && $level < 4 && $foreign > 0 && canSeeExact($role, false) && $q["subpops"]["subpops"]) {
        logViewExact($pdo, $userId, $catalogId, "Subpoblaciones {$taxonID}", $foreign);
    }
    $mature = $q["mature"];
    unset($mature["kept"]);
    $subpops = $q["subpops"];
    $subpops["subpops"] = assessmentSubpopsOut($subpops["subpops"], $access, $level);
    return [
        "taxonID"       => $taxonID,
        "computedAt"    => gmdate("Y-m-d\TH:i:s\Z"),
        "origin"        => "server",
        "geometry"      => $access,
        "metrics"       => $metrics,
        "nPoints"       => $q["nPoints"],
        "mature"        => $mature,
        "subpopulations" => $subpops,
        "fragmentation" => $q["fragmentation"],
        "completeness"  => $q["completeness"],
        "sufficiency"   => suffRequirements($q, $series),
        "locationsProposal" => $q["locations"],
        "locations"     => $locations,
        "suggestions"   => array_merge($q["suggestions"], $series["suggestions"] ?? []),
        "series"        => $series,
        "separation"    => $q["separation"],
        "parameters"    => $params,
        "defaults"      => $defaults,
        "inputs"        => $inputs,
        "result"        => $result,
        "canEdit"       => catalogCan($role, "set_parameter"),
    ];
}
