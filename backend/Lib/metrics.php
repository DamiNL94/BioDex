<?php
// ================================================================================================
// metrics.php — EOO y AOO de la UICN (F9): el cálculo DEFINITIVO, con las coordenadas exactas.
// ------------------------------------------------------------------------------------------------
// Port de shared/geo-metrics.js, función a función y con las mismas reglas (docs/iucn.md › «EOO y
// AOO en BioDex»): casco convexo por la cadena monótona de Andrew, área por la fórmula del lazo,
// celdas floor(x / tamaño) ancladas al origen UTM (EPSG:25830), EOO = AOO si queda por debajo, rango
// informativo desplazando la rejilla medio tamaño. Casos compartidos: shared/metrics-cases.json.
// ================================================================================================

require_once __DIR__ . "/utm.php";
require_once __DIR__ . "/sensitivity.php";   // canSeeExact(), sensitivityConfig()
require_once __DIR__ . "/audit.php";         // logAccess()
require_once __DIR__ . "/sync.php";          // syncIsoUtc()

const METRICS_DEFAULT_CELL_KM = 2;
const METRICS_CELL_OPTIONS_KM = [2, 1];
const METRICS_CELL_1KM_WARNING = "Celdas de 1 km: los umbrales de B2 están calibrados para celdas de 2 km.";
const METRICS_ZONE_MIN_LON = -6.0;
const METRICS_ZONE_MAX_LON = 0.0;

/**
 * Registros → ["used" => [["lat", "lon"]], "excluded" => ["absent", "imprecise", "noCoordinates"]].
 * Un registro: decimalLatitude, decimalLongitude, coordinateUncertaintyInMeters, occurrenceStatus.
 */
function metricsSelectRecords(array $records, int $cellKm = METRICS_DEFAULT_CELL_KM): array {
    $used = [];
    $excluded = ["absent" => 0, "imprecise" => 0, "noCoordinates" => 0];
    $maxM = $cellKm * 1000;
    foreach ($records as $r) {
        if (($r["occurrenceStatus"] ?? "present") === "absent") { $excluded["absent"]++; continue; }
        $lat = $r["decimalLatitude"] ?? null;
        $lon = $r["decimalLongitude"] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon)) { $excluded["noCoordinates"]++; continue; }
        $u = $r["coordinateUncertaintyInMeters"] ?? null;
        if ($u !== null && (float) $u > $maxM) { $excluded["imprecise"]++; continue; }
        $used[] = ["lat" => (float) $lat, "lon" => (float) $lon];
    }
    return ["used" => $used, "excluded" => $excluded];
}

function metricsCross(array $o, array $a, array $b): float {
    return ($a["x"] - $o["x"]) * ($b["y"] - $o["y"]) - ($a["y"] - $o["y"]) * ($b["x"] - $o["x"]);
}

/** Casco convexo (cadena monótona de Andrew), antihorario y sin puntos colineales. */
function metricsConvexHull(array $points): array {
    usort($points, fn($a, $b) => $a["x"] <=> $b["x"] ?: $a["y"] <=> $b["y"]);
    $pts = [];
    foreach ($points as $p) {
        $last = end($pts);
        if ($last === false || $p["x"] !== $last["x"] || $p["y"] !== $last["y"]) $pts[] = $p;
    }
    if (count($pts) < 3) return $pts;
    $lower = [];
    foreach ($pts as $p) {
        while (count($lower) >= 2 && metricsCross($lower[count($lower) - 2], $lower[count($lower) - 1], $p) <= 0) array_pop($lower);
        $lower[] = $p;
    }
    $upper = [];
    for ($i = count($pts) - 1; $i >= 0; $i--) {
        $p = $pts[$i];
        while (count($upper) >= 2 && metricsCross($upper[count($upper) - 2], $upper[count($upper) - 1], $p) <= 0) array_pop($upper);
        $upper[] = $p;
    }
    array_pop($lower);
    array_pop($upper);
    return array_merge($lower, $upper);
}

/** Área (m²) de un polígono por la fórmula del lazo. */
function metricsPolygonArea(array $poly): float {
    $s = 0.0;
    $n = count($poly);
    for ($i = 0; $i < $n; $i++) {
        $a = $poly[$i];
        $b = $poly[($i + 1) % $n];
        $s += $a["x"] * $b["y"] - $b["x"] * $a["y"];
    }
    return abs($s) / 2;
}

/** Celdas ocupadas ("i,j" → [i, j]) con la rejilla desplazada ($ox, $oy) metros. */
function metricsOccupiedCells(array $points, float $cellM, float $ox = 0.0, float $oy = 0.0): array {
    $cells = [];
    foreach ($points as $p) {
        $i = (int) floor(($p["x"] - $ox) / $cellM);
        $j = (int) floor(($p["y"] - $oy) / $cellM);
        $cells["{$i},{$j}"] = [$i, $j];
    }
    return $cells;
}

/** Como Math.round(v * 1000) / 1000 de JS para valores ≥ 0. */
function metricsRound3(float $v): float {
    return floor($v * 1000 + 0.5) / 1000;
}

/**
 * Métricas a partir de puntos YA proyectados [["x", "y", "lat"?, "lon"?]] (metros, EPSG:25830).
 * Mismas claves que metricsFromPoints() de shared/geo-metrics.js.
 */
function metricsFromPoints(array $points, int $cellKm = METRICS_DEFAULT_CELL_KM): array {
    $warnings = [];
    $cellM = (float) ($cellKm * 1000);
    if ($cellKm !== METRICS_DEFAULT_CELL_KM) $warnings[] = METRICS_CELL_1KM_WARNING;
    if (!$points) {
        $warnings[] = "Sin registros que cuenten: ni EOO ni AOO.";
        return ["eooKm2" => null, "eooAdjusted" => false, "aooKm2" => null, "aooCells" => 0, "cellSizeKm" => $cellKm,
                "aooRange" => null, "hull" => [], "cells" => [], "warnings" => $warnings];
    }
    $cells = metricsOccupiedCells($points, $cellM);
    $aooCells = count($cells);
    $aooKm2 = metricsRound3($aooCells * $cellKm * $cellKm);
    $counts = array_map(fn($o) => count(metricsOccupiedCells($points, $cellM, $o[0], $o[1])),
        [[0.0, 0.0], [$cellM / 2, 0.0], [0.0, $cellM / 2], [$cellM / 2, $cellM / 2]]);
    $aooRange = ["min" => metricsRound3(min($counts) * $cellKm * $cellKm), "max" => metricsRound3(max($counts) * $cellKm * $cellKm)];

    $hull = metricsConvexHull($points);
    $eooKm2 = null;
    $adjusted = false;
    if (count($hull) < 3) {
        $warnings[] = "Menos de 3 puntos no colineales: no hay EOO.";
    } else {
        $eooKm2 = metricsRound3(metricsPolygonArea($hull) / 1e6);
        if ($eooKm2 < $aooKm2) {
            $warnings[] = "EOO ({$eooKm2} km²) menor que AOO ({$aooKm2} km²): se toma EOO = AOO.";
            $eooKm2 = $aooKm2;
            $adjusted = true;
        }
    }
    $cellList = array_values($cells);
    usort($cellList, fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
    return ["eooKm2" => $eooKm2, "eooAdjusted" => $adjusted, "aooKm2" => $aooKm2, "aooCells" => $aooCells,
            "cellSizeKm" => $cellKm, "aooRange" => $aooRange, "hull" => count($hull) >= 3 ? $hull : [],
            "cells" => $cellList, "warnings" => $warnings];
}

/** Métricas de registros con lat/lon (EPSG:25830) → las de metricsFromPoints() + nRecords, nExcluded, excluded. */
function computeMetrics(array $records, int $cellKm = METRICS_DEFAULT_CELL_KM): array {
    if (!in_array($cellKm, METRICS_CELL_OPTIONS_KM, true)) throw new InvalidArgumentException("Celda de {$cellKm} km: solo 2 o 1 km.");
    ["used" => $used, "excluded" => $excluded] = metricsSelectRecords($records, $cellKm);
    $points = array_map(function ($r) {
        $u = toUtm($r["lat"], $r["lon"]);
        return ["x" => $u["easting"], "y" => $u["northing"], "lat" => $r["lat"], "lon" => $r["lon"]];
    }, $used);
    $m = metricsFromPoints($points, $cellKm);
    $outside = count(array_filter($used, fn($r) => $r["lon"] < METRICS_ZONE_MIN_LON || $r["lon"] > METRICS_ZONE_MAX_LON));
    if ($outside) $m["warnings"][] = "{$outside} " . ($outside === 1 ? "registro" : "registros") . " fuera del huso 30 (6° O–0°): se proyectan igual, con deformación.";
    $nExcluded = $excluded["absent"] + $excluded["imprecise"] + $excluded["noCoordinates"];
    if ($excluded["imprecise"]) {
        $m["warnings"][] = "{$excluded["imprecise"]} " . ($excluded["imprecise"] === 1 ? "registro excluido" : "registros excluidos")
            . " por precisión peor que {$cellKm} km.";
    }
    return $m + ["nRecords" => count($used), "nExcluded" => $nExcluded, "excluded" => $excluded];
}

// --- Servicio: GET /api/catalogs/{id}/species/{taxonID}/metrics ---------------------------------

/**
 * Qué geometría puede recibir quien pide (misma regla que applyVisibility):
 *   full         owner, custodio, nivel 4, o ámbito «me» (todas son suyas): casco y celdas de la AOO
 *   generalized  resto con nivel 2–3: solo las celdas de su nivel de generalización con registros
 *   none         nivel 1: solo cifras
 * NUNCA sale el casco convexo a quien no puede ver exactas: sus vértices son registros reales.
 */
function metricsGeometryAccess(string $role, int $level, string $scope): string {
    if ($scope === "me" || $level === 4 || canSeeExact($role, false)) return "full";
    return $level === 1 ? "none" : "generalized";
}

/** Celdas de gridDeg (esquina SO, como generalizePoint()) que contienen registros. */
function metricsGeneralizedCells(array $used, float $grid): array {
    $decimals = (int) round(-log10($grid)) + 1;
    $cells = [];
    foreach ($used as $r) {
        $lat = round(floor(round($r["lat"] / $grid, 9)) * $grid, $decimals);
        $lon = round(floor(round($r["lon"] / $grid, 9)) * $grid, $decimals);
        $cells["{$lat},{$lon}"] = [$lat, $lon];
    }
    $out = array_values($cells);
    sort($out);
    return $out;
}

/**
 * EOO/AOO definitivos de una especie en un catálogo para $userId (rol $role) → respuesta de la API.
 * Calcula con las coordenadas EXACTAS; guarda en species_metrics y solo lo renueva (computed_at) si hay
 * marcas nuevas, editadas o borradas desde el cálculo guardado (server_seq máximo mayor que source_seq).
 */
function speciesMetrics(PDO $pdo, string $catalogId, string $taxonID, int $userId, string $role, int $level,
                        string $scope, int $cellKm): array {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(server_seq), 0) FROM occurrences WHERE catalog_id = ? AND taxonID = ?");
    $stmt->execute([$catalogId, $taxonID]);
    $maxSeq = (int) $stmt->fetchColumn();

    $sql = "SELECT user_id, decimalLatitude, decimalLongitude, coordinateUncertaintyInMeters, occurrenceStatus
              FROM occurrences WHERE catalog_id = ? AND taxonID = ? AND deleted_at IS NULL";
    $params = [$catalogId, $taxonID];
    if ($scope === "me") { $sql .= " AND user_id = ?"; $params[] = $userId; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $m = computeMetrics($rows, $cellKm);

    $ownerKey = $scope === "me" ? $userId : 0;
    $stmt = $pdo->prepare("SELECT source_seq, computed_at FROM species_metrics
                            WHERE catalog_id = ? AND taxonID = ? AND scope = ? AND owner_key = ? AND cell_size_km = ?");
    $stmt->execute([$catalogId, $taxonID, $scope, $ownerKey, $cellKm]);
    $stored = $stmt->fetch() ?: null;
    $cached = $stored !== null && (int) $stored["source_seq"] === $maxSeq;
    if ($cached) {
        $computedAt = $stored["computed_at"];
    } else {
        $pdo->prepare(
            "INSERT INTO species_metrics (catalog_id, taxonID, scope, user_id, eoo_km2, eoo_adjusted, aoo_km2, aoo_cells,
                    aoo_min_km2, aoo_max_km2, cell_size_km, n_records, n_excluded, warnings, source_seq, computed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE eoo_km2 = VALUES(eoo_km2), eoo_adjusted = VALUES(eoo_adjusted), aoo_km2 = VALUES(aoo_km2),
                    aoo_cells = VALUES(aoo_cells), aoo_min_km2 = VALUES(aoo_min_km2), aoo_max_km2 = VALUES(aoo_max_km2),
                    n_records = VALUES(n_records), n_excluded = VALUES(n_excluded), warnings = VALUES(warnings),
                    source_seq = VALUES(source_seq), computed_at = NOW()"
        )->execute([$catalogId, $taxonID, $scope, $scope === "me" ? $userId : null, $m["eooKm2"], $m["eooAdjusted"] ? 1 : 0,
                    $m["aooKm2"], $m["aooCells"], $m["aooRange"]["min"] ?? null, $m["aooRange"]["max"] ?? null, $cellKm,
                    $m["nRecords"], $m["nExcluded"], json_encode($m["warnings"], JSON_UNESCAPED_UNICODE), $maxSeq]);
        $stmt = $pdo->prepare("SELECT computed_at FROM species_metrics
                                WHERE catalog_id = ? AND taxonID = ? AND scope = ? AND owner_key = ? AND cell_size_km = ?");
        $stmt->execute([$catalogId, $taxonID, $scope, $ownerKey, $cellKm]);
        $computedAt = $stmt->fetchColumn();
    }

    $access = metricsGeometryAccess($role, $level, $scope);
    $geometry = ["access" => $access];
    if ($access === "full") {
        $geometry += ["crs" => "EPSG:25830", "cellSizeKm" => $cellKm,
                      "hull" => array_map(fn($p) => [$p["lat"], $p["lon"]], $m["hull"]), "cells" => $m["cells"]];
        // Casco y celdas de registros exactos ajenos de una especie sensible: como view_exact del pull.
        $foreign = count(array_filter($rows, fn($r) => (int) $r["user_id"] !== $userId));
        if ($level < 4 && $foreign > 0 && $scope === "team" && canSeeExact($role, false) && $m["nRecords"] > 0) {
            logAccess($pdo, $userId, "view_exact", $catalogId, "EOO/AOO {$taxonID}: {$foreign}");
        }
    } elseif ($access === "generalized") {
        $grid = (float) sensitivityConfig()["levels"][(string) $level]["gridDeg"];
        $used = metricsSelectRecords($rows, $cellKm)["used"];
        $geometry += ["gridDeg" => $grid, "generalizedCells" => metricsGeneralizedCells($used, $grid)];
    }

    return [
        "taxonID"     => $taxonID,
        "scope"       => $scope,
        "cellSizeKm"  => $cellKm,
        "eooKm2"      => $m["eooKm2"],
        "eooAdjusted" => $m["eooAdjusted"],
        "aooKm2"      => $m["aooKm2"],
        "aooCells"    => $m["aooCells"],
        "aooRange"    => $m["aooRange"],
        "nRecords"    => $m["nRecords"],
        "nExcluded"   => $m["nExcluded"],
        "excluded"    => $m["excluded"],
        "warnings"    => $m["warnings"],
        "computedAt"  => syncIsoUtc($computedAt),
        "cached"      => $cached,
        "sourceSeq"   => $maxSeq,
        "geometry"    => $geometry,
    ];
}
