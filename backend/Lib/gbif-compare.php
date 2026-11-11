<?php
// ================================================================================================
// gbif-compare.php — comparación de fuentes para la evaluación UICN (déficit wallaceano).
// ------------------------------------------------------------------------------------------------
// GET …/species/{taxonID}/assessment?gbif=1 añade `gbif`: los registros de GBIF de la especie (nombre de EIDOS →
// backbone de GBIF, gbifMatchDecide de Lib/gbif.php) dentro del ÁREA DEL CATÁLOGO (el recuadro de todas sus marcas,
// ampliado 0,25°), con caché en MySQL (gbif_occurrence_cache, 30 días). Esos registros sirven SOLO para recalcular
// EOO, AOO, subpoblaciones y el criterio B en paralelo («solo BioDex» frente a «BioDex + GBIF»): nunca se guardan
// como ocurrencias ni se exportan. Se citan con la búsqueda de GBIF (una búsqueda no tiene DOI; solo las descargas
// lo tienen, y esas piden cuenta: `doi` queda null).
// Puntos para el mapa: los de GBIF son públicos; los de BioDex, según la confianza (exactos con acceso «full»,
// el centro de su celda con «generalized», ninguno con «none»).
// ================================================================================================

require_once __DIR__ . "/gbif.php";       // gbifMatch(), gbifMatchDecide(), GBIF_API
require_once __DIR__ . "/photo-files.php"; // photoGetJson()
require_once __DIR__ . "/iucn.php";
require_once __DIR__ . "/metrics.php";
require_once __DIR__ . "/sensitivity.php";

const GBIF_COMPARE_TTL_DAYS = 30;
const GBIF_COMPARE_PAGE = 300;
const GBIF_COMPARE_MAX = 3000;
const GBIF_COMPARE_MARGIN_DEG = 0.25;

/** Recuadro de las marcas del catálogo [minLat, minLon, maxLat, maxLon] ampliado, o null sin marcas. */
function gbifCatalogBbox(PDO $pdo, string $catalogId): ?array {
    $r = $pdo->prepare("SELECT MIN(decimalLatitude) a, MIN(decimalLongitude) b, MAX(decimalLatitude) c, MAX(decimalLongitude) d
                          FROM occurrences WHERE catalog_id = ? AND deleted_at IS NULL");
    $r->execute([$catalogId]);
    $b = $r->fetch();
    if (!$b || $b["a"] === null) return null;
    $m = GBIF_COMPARE_MARGIN_DEG;
    return [round((float) $b["a"] - $m, 2), round((float) $b["b"] - $m, 2), round((float) $b["c"] + $m, 2), round((float) $b["d"] + $m, 2)];
}

/** Registros de GBIF (de la caché o de la API) → [records, fetchedAt, cached]. Lanza PhotoSourceException sin red. */
function gbifCompareRecords(PDO $pdo, int $usageKey, array $bbox): array {
    $bboxText = implode(",", $bbox);
    $key = hash("sha256", "{$usageKey}|{$bboxText}");
    $stmt = $pdo->prepare("SELECT records, fetched_at FROM gbif_occurrence_cache WHERE cache_key = ? AND fetched_at > UTC_TIMESTAMP() - INTERVAL " . GBIF_COMPARE_TTL_DAYS . " DAY");
    $stmt->execute([$key]);
    if ($row = $stmt->fetch()) return [json_decode($row["records"], true), $row["fetched_at"], true];
    $out = [];
    for ($offset = 0; $offset < GBIF_COMPARE_MAX; $offset += GBIF_COMPARE_PAGE) {
        $q = ["taxonKey" => $usageKey, "hasCoordinate" => "true", "hasGeospatialIssue" => "false", "occurrenceStatus" => "PRESENT",
              "decimalLatitude" => "{$bbox[0]},{$bbox[2]}", "decimalLongitude" => "{$bbox[1]},{$bbox[3]}", "limit" => GBIF_COMPARE_PAGE, "offset" => $offset];
        $res = photoGetJson("gbif", GBIF_API . "/occurrence/search?" . http_build_query($q), ["timeout" => GBIF_TIMEOUT_S]);
        foreach ($res["results"] ?? [] as $o) {
            if (!isset($o["decimalLatitude"], $o["decimalLongitude"])) continue;
            $out[] = [round((float) $o["decimalLatitude"], 5), round((float) $o["decimalLongitude"], 5),
                      isset($o["coordinateUncertaintyInMeters"]) ? (float) $o["coordinateUncertaintyInMeters"] : null,
                      (string) ($o["key"] ?? $o["gbifID"] ?? ""), isset($o["year"]) ? (int) $o["year"] : null];
        }
        if (!empty($res["endOfRecords"]) || count($res["results"] ?? []) < GBIF_COMPARE_PAGE) break;
    }
    $pdo->prepare("INSERT INTO gbif_occurrence_cache (cache_key, usage_key, bbox, n_records, records, fetched_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
                   ON DUPLICATE KEY UPDATE n_records = VALUES(n_records), records = VALUES(records), fetched_at = UTC_TIMESTAMP()")
        ->execute([$key, $usageKey, $bboxText, count($out), json_encode($out)]);
    return [$out, gmdate("Y-m-d H:i:s"), false];
}

/** Lo que se compara de un conjunto de registros: nº, EOO, AOO, subpoblaciones y el criterio B. */
function gbifCompareSet(array $records, array $inputs, ?float $sepKm): array {
    $m = computeMetrics($records, METRICS_DEFAULT_CELL_KM);
    $sp = iucnSubpopulations(iucnPrepareRecords($records), $sepKm);
    $b = $inputs["B"] ?? [];
    $b["eoo"] = $m["eooKm2"];
    $b["aoo"] = $m["aooKm2"];
    $r = evaluateIucn(["B" => $b]);
    return ["nRecords" => $m["nRecords"], "eooKm2" => $m["eooKm2"], "aooKm2" => $m["aooKm2"], "subpops" => count($sp["subpops"]),
            "B" => array_intersect_key($r["byCriterion"]["B"], array_flip(["status", "category", "notation"]))];
}

/** Bloque `gbif` de la respuesta de …/assessment?gbif=1. */
function gbifComparison(PDO $pdo, string $catalogId, string $taxonID, array $assessment, string $access, int $level): array {
    $stmt = $pdo->prepare("SELECT canonical_name, taxonRank, kingdom FROM species WHERE taxonID = ?");
    $stmt->execute([$taxonID]);
    $sp = $stmt->fetch();
    if (!$sp) return ["error" => "La especie no está en la caché de BioDex."];
    $bbox = gbifCatalogBbox($pdo, $catalogId);
    if ($bbox === null) return ["error" => "El catálogo aún no tiene marcas: no hay área en la que buscar en GBIF."];
    try {
        $match = gbifMatchDecide(["name" => $sp["canonical_name"], "rank" => $sp["taxonRank"], "kingdom" => $sp["kingdom"]],
            gbifMatch($sp["canonical_name"], $sp["kingdom"]));
        if (($match["status"] ?? null) === "none") return ["error" => "Sin taxón equivalente en GBIF ({$match["reason"]})."];
        [$gbif, $fetchedAt, $cached] = gbifCompareRecords($pdo, (int) $match["key"], $bbox);
    } catch (Throwable $e) {
        return ["error" => "No se pudo consultar GBIF ahora: " . $e->getMessage()];
    }
    $gbifRecords = array_map(fn($g) => ["occurrenceID" => "gbif:{$g[3]}", "decimalLatitude" => $g[0], "decimalLongitude" => $g[1],
        "coordinateUncertaintyInMeters" => $g[2], "occurrenceStatus" => "present"], $gbif);
    $own = assessmentRecords($pdo, $catalogId, $taxonID);
    $sepKm = $assessment["subpopulations"]["separationKm"] ?? null;
    $biodex = gbifCompareSet($own, $assessment["inputs"], $sepKm);
    $combined = gbifCompareSet(array_merge($own, $gbifRecords), $assessment["inputs"], $sepKm);
    $used = count(metricsSelectRecords($gbifRecords, METRICS_DEFAULT_CELL_KM)["used"]);

    $points = [];
    foreach ($own as $r) {
        if ($r["occurrenceStatus"] === "absent" || $access === "none") continue;
        if ($access === "full") { $points[] = [(float) $r["decimalLatitude"], (float) $r["decimalLongitude"]]; continue; }
        $g = generalizePoint((float) $r["decimalLatitude"], (float) $r["decimalLongitude"], $level, null);
        $points[$g["decimalLatitude"] . "," . $g["decimalLongitude"]] = [$g["decimalLatitude"], $g["decimalLongitude"]];
    }
    $url = "https://www.gbif.org/occurrence/search?" . http_build_query(["taxon_key" => $match["key"], "has_coordinate" => "true",
        "geometry" => sprintf("POLYGON((%s %s,%s %s,%s %s,%s %s,%s %s))", $bbox[1], $bbox[0], $bbox[3], $bbox[0], $bbox[3], $bbox[2], $bbox[1], $bbox[2], $bbox[1], $bbox[0])]);
    return [
        "gbif" => ["usageKey" => (int) $match["key"], "nRecords" => count($gbif), "usedInArea" => $used, "bbox" => $bbox,
                   "fetchedAt" => syncIsoUtc($fetchedAt), "cached" => $cached, "doi" => null, "url" => $url,
                   "citation" => "GBIF.org (" . substr((string) $fetchedAt, 0, 10) . ") GBIF Occurrence Search {$url}"],
        "biodex"   => $biodex,
        "combined" => $combined,
        "points"   => ["access" => $access, "biodex" => array_values($points), "gbif" => array_map(fn($g) => [$g[0], $g[1]], $gbif)],
    ];
}
