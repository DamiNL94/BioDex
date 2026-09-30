<?php
// ================================================================================================
// export.php — exportación de un catálogo (F10a): Darwin Core Archive + hojas legibles, en un ZIP.
// ------------------------------------------------------------------------------------------------
// Se genera EN EL SERVIDOR, que tiene las exactas y aplica la seguridad:
//   · «completa» (owner, custodio): coordenadas exactas, vía applyVisibility() con su rol; export_full
//     en access_log (lo registra Api/exports.php).
//   · «para publicar» (cualquier miembro): cada coordenada pasa por applyPublicVisibility(), es decir,
//     generalizada según el nivel de su especie sea quien sea quien exporta (nivel 1: sin coordenadas).
//     En las filas generalizadas tampoco salen las notas libres (observaciones y notas de georreferencia):
//     un texto como «junto a la fuente de …» deshace la generalización. Se dice en informationWithheld.
// El registro original nunca se altera: todo se hace sobre copias en memoria.
//
// Productos: dwca/ (occurrence.txt, measurementorfact.txt, meta.xml, eml.xml; Lib/dwc.php), hojas/
// (un XLSX con Léeme · Observaciones · Especies · Evaluaciones UICN · Distribución · Notas, Lib/xlsx.php,
// y los mismos datos en CSV para Excel en español), pliniancore/ (F10c, fichas de especie en Plinian Core;
// Lib/plinian.php) y LEEME.txt. Formatos a elegir en «formats»; por defecto dwca/ y hojas/, como en F10a.
// Mapeo: docs/dwc.md y docs/plinian.md.
// ================================================================================================

require_once __DIR__ . "/sensitivity.php";
require_once __DIR__ . "/sync.php";      // syncIsoUtc(), UUID_V4_RE
require_once __DIR__ . "/metrics.php";   // metricsStored()
require_once __DIR__ . "/utm.php";
require_once __DIR__ . "/dwc.php";
require_once __DIR__ . "/xlsx.php";
require_once __DIR__ . "/plinian.php";   // F10c: pliniancore/

const EXPORT_MODES      = ["full", "public"];
/** Formatos del ZIP (F10c). Sin «formats» en la petición, los de F10a: el ZIP sale igual que antes. */
const EXPORT_FORMATS         = ["dwc", "plinian", "sheets"];
const EXPORT_FORMATS_DEFAULT = ["dwc", "sheets"];
const EXPORT_TZ         = "Europe/Madrid";
const EXPORT_TITLE_MAX  = 200;
const EXPORT_DESC_MAX   = 4000;
const EXPORT_LIST_MAX   = 1000;   // especies u observadores elegidos
const EXPORT_MODE_LABEL = ["full" => "completa (privada)", "public" => "para publicar"];
const EXPORT_MODE_FILE  = ["full" => "completa", "public" => "publicar"];
const EXPORT_PLINIAN_README = "Fichas de especie en Plinian Core " . PLIC_VERSION . " (especies.xml, validado contra su XSD): taxonomía, "
    . "categorías de amenaza oficiales de EIDOS y, aparte, la evaluación BioDex de referencia (regional, preliminar, no oficial), y "
    . "distribución en cifras (EOO, AOO, cuadrículas) de las presencias exportadas. No incluye observaciones ni coordenadas.";
const EXPORT_WITHHELD_REMARKS ="Notas de campo y de georreferencia retenidas por sensibilidad de la especie";

const EXPORT_STATUS_ES = ["present" => "Presencia", "absent" => "Ausencia"];
const EXPORT_SEX_ES    = ["male" => "Macho", "female" => "Hembra", "hermaphrodite" => "Hermafrodita", "undetermined" => "Indeterminado"];
const EXPORT_STAGE_ES  = ["adult" => "Adulto", "juvenile" => "Juvenil", "subadult" => "Subadulto", "larva" => "Larva",
                          "egg" => "Huevo", "seedling" => "Plántula", "flowering" => "En flor", "fruiting" => "En fruto",
                          "vegetative" => "Vegetativo", "undetermined" => "Indeterminado"];
const EXPORT_IUCN_SCOPES = ["Mundial", "España", "Península"];   // columnas fijas; otros ámbitos se añaden detrás

/** Versión de la app (fichero VERSION en la raíz del repo, montado en /var/www/VERSION). */
function biodexVersion(): string {
    $v = @file_get_contents(__DIR__ . "/../../VERSION");
    return $v === false ? "desconocida" : trim($v);
}

// --- Opciones ------------------------------------------------------------------------------------

/** Cuerpo de la petición → opciones validadas (400 con el motivo si algo no vale). */
function exportOptions(array $b, array $catalog): array {
    $mode = $b["mode"] ?? "public";
    if (!in_array($mode, EXPORT_MODES, true)) apiError("«mode» debe ser full o public.", 400);

    $date = function (string $k) use ($b): ?string {
        $v = $b[$k] ?? null;
        if ($v === null || $v === "") return null;
        $d = is_string($v) ? DateTimeImmutable::createFromFormat("!Y-m-d", $v) : false;
        if (!$d || $d->format("Y-m-d") !== $v) apiError("«{$k}» debe ser una fecha AAAA-MM-DD.", 400);
        return $v;
    };
    $from = $date("dateFrom");
    $to = $date("dateTo");
    if ($from && $to && $from > $to) apiError("La fecha inicial es posterior a la final.", 400);

    $list = function (string $k, callable $valid) use ($b): ?array {
        $v = $b[$k] ?? null;
        if ($v === null) return null;
        if (!is_array($v) || !array_is_list($v) || count($v) > EXPORT_LIST_MAX) apiError("«{$k}» debe ser una lista (≤ " . EXPORT_LIST_MAX . ").", 400);
        foreach ($v as $x) if (!$valid($x)) apiError("«{$k}» tiene un valor no válido.", 400);
        return array_values(array_unique($v));
    };
    $taxa = $list("taxonIDs", fn($x) => is_string($x) && $x !== "" && strlen($x) <= 64);
    $observers = $list("observers", fn($x) => is_int($x) && $x > 0);

    $formats = $b["formats"] ?? EXPORT_FORMATS_DEFAULT;
    if (!is_array($formats) || !array_is_list($formats) || array_diff($formats, EXPORT_FORMATS)) {
        apiError("«formats» debe ser una lista con " . implode(", ", EXPORT_FORMATS) . ".", 400);
    }
    if (!$formats) apiError("Elige al menos un formato.", 400);

    $license = $b["license"] ?? array_key_first(DWC_LICENSES);
    if (!isset(DWC_LICENSES[$license])) apiError("Licencia no admitida: " . implode(", ", array_keys(DWC_LICENSES)) . ".", 400);

    $title = trim((string) ($b["title"] ?? "")) ?: $catalog["name"];
    $description = trim((string) ($b["description"] ?? "")) ?: (string) ($catalog["description"] ?? "");
    if (mb_strlen($title) > EXPORT_TITLE_MAX) apiError("El título admite " . EXPORT_TITLE_MAX . " caracteres.", 400);
    if (mb_strlen($description) > EXPORT_DESC_MAX) apiError("La descripción admite " . EXPORT_DESC_MAX . " caracteres.", 400);

    return [
        "mode" => $mode, "dateFrom" => $from, "dateTo" => $to, "taxonIDs" => $taxa, "observers" => $observers,
        "includeAbsences" => ($b["includeAbsences"] ?? true) !== false, "license" => $license,
        "title" => $title, "description" => $description,
        "formats" => array_values(array_intersect(EXPORT_FORMATS, $formats)),
    ];
}

/** Fecha local (AAAA-MM-DD, hora peninsular) → límite UTC para comparar con eventDate. $end: día siguiente. */
function exportUtcBound(string $day, bool $end): string {
    $d = new DateTimeImmutable($day . " 00:00:00", new DateTimeZone(EXPORT_TZ));
    if ($end) $d = $d->modify("+1 day");
    return $d->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d H:i:s");
}

// --- Datos ---------------------------------------------------------------------------------------

/** Marcas no borradas del catálogo que cumplen los filtros, con autor, especie y nivel (EXACTAS: no salen tal cual). */
function exportOccurrences(PDO $pdo, string $catalogId, array $o): array {
    $sql = "SELECT o.*, u.name AS recorded_by, u.orcid AS recorded_by_orcid, ss.level AS sensitivity_level
              FROM occurrences o
              JOIN users u ON u.id = o.user_id
              LEFT JOIN species_sensitivity ss ON ss.catalog_id = o.catalog_id AND ss.taxonID = o.taxonID
             WHERE o.catalog_id = ? AND o.deleted_at IS NULL";
    $p = [$catalogId];
    if ($o["dateFrom"]) { $sql .= " AND o.eventDate >= ?"; $p[] = exportUtcBound($o["dateFrom"], false); }
    if ($o["dateTo"])   { $sql .= " AND o.eventDate < ?";  $p[] = exportUtcBound($o["dateTo"], true); }
    if (!$o["includeAbsences"]) $sql .= " AND o.occurrenceStatus = 'present'";
    foreach (["taxonIDs" => "o.taxonID", "observers" => "o.user_id"] as $k => $col) {
        if ($o[$k] === null) continue;
        if (!$o[$k]) return [];
        $sql .= " AND {$col} IN (" . implode(",", array_fill(0, count($o[$k]), "?")) . ")";
        array_push($p, ...$o[$k]);
    }
    $stmt = $pdo->prepare($sql . " ORDER BY o.eventDate, o.occurrenceID");
    $stmt->execute($p);
    return $stmt->fetchAll();
}

/** Especies de la exportación: las del catálogo (filtradas) y las de las marcas exportadas, con su nivel. */
function exportSpecies(PDO $pdo, string $catalogId, array $o, array $occurrences): array {
    $stmt = $pdo->prepare(
        "SELECT s.*, ss.level AS sensitivity_level FROM species s
           LEFT JOIN catalog_species cs ON cs.taxonID = s.taxonID AND cs.catalog_id = ? AND cs.deleted_at IS NULL
           LEFT JOIN species_sensitivity ss ON ss.taxonID = s.taxonID AND ss.catalog_id = ?
          WHERE cs.taxonID IS NOT NULL OR s.taxonID IN (SELECT DISTINCT taxonID FROM occurrences WHERE catalog_id = ? AND deleted_at IS NULL)
          ORDER BY s.canonical_name");
    $stmt->execute([$catalogId, $catalogId, $catalogId]);
    $withMarks = array_flip(array_column($occurrences, "taxonID"));
    $out = [];
    foreach ($stmt->fetchAll() as $s) {
        if ($o["taxonIDs"] !== null && !in_array($s["taxonID"], $o["taxonIDs"], true)) continue;
        // Con filtros de fecha u observador, una especie sin marcas en el resultado sigue en la hoja (0 marcas).
        $out[$s["taxonID"]] = $s + ["_inExport" => isset($withMarks[$s["taxonID"]])];
    }
    return $out;
}

/**
 * Copia publicable de una marca: completa (applyVisibility con el rol de quien exporta: owner/custodio
 * ven exactas) o para publicar (applyPublicVisibility). → [copia, generalizada?].
 */
function exportVisible(array $row, string $mode, string $role, int $userId): array {
    $level = normalizeSensitivityLevel($row["sensitivity_level"]);
    $v = $mode === "full" ? applyVisibility($row, $level, $role, $userId) : applyPublicVisibility($row, $level);
    $generalized = array_key_exists("dataGeneralizations", $v);
    if ($generalized) {
        // Las notas libres pueden decir dónde está: fuera en las filas generalizadas.
        $withheld = array_filter([$v["informationWithheld"] ?? null,
            ($v["occurrenceRemarks"] ?? null) !== null || ($v["georeferenceRemarks"] ?? null) !== null ? EXPORT_WITHHELD_REMARKS : null]);
        $v["occurrenceRemarks"] = $v["georeferenceRemarks"] = null;
        $v["informationWithheld"] = $withheld ? implode(". ", $withheld) : null;
        if ($v["decimalLatitude"] === null) $v["georeferenceProtocol"] = $v["geodeticDatum"] = null;
    }
    return [$v, $generalized];
}

/** Fila de occurrence.txt (término → valor) a partir de la copia publicable. */
function exportDwcRecord(array $v, array $sp, array $ctx): array {
    $absent = $v["occurrenceStatus"] === "absent";
    $hasCoords = $v["decimalLatitude"] !== null;
    return [
        "occurrenceID"                  => $v["occurrenceID"],
        "basisOfRecord"                 => $v["basisOfRecord"] ?: "HumanObservation",
        "occurrenceStatus"              => $v["occurrenceStatus"],
        "eventDate"                     => syncIsoUtc($v["eventDate"]),
        "recordedBy"                    => $v["recorded_by"],
        "recordedByID"                  => dwcOrcidUri($v["recorded_by_orcid"]),
        "individualCount"               => $absent ? 0 : ($v["individualCount"] === null ? null : (int) $v["individualCount"]),
        "sex"                           => $v["sex"],
        "lifeStage"                     => $v["lifeStage"],
        "occurrenceRemarks"             => $v["occurrenceRemarks"],
        "habitat"                       => $v["habitat"],
        "decimalLatitude"               => $hasCoords ? (float) $v["decimalLatitude"] : null,
        "decimalLongitude"              => $hasCoords ? (float) $v["decimalLongitude"] : null,
        "geodeticDatum"                 => $hasCoords ? ($v["geodeticDatum"] ?: "EPSG:4326") : null,
        "coordinateUncertaintyInMeters" => $v["coordinateUncertaintyInMeters"] === null ? null : (int) $v["coordinateUncertaintyInMeters"],
        "georeferenceProtocol"          => $v["georeferenceProtocol"],
        "georeferenceRemarks"           => $v["georeferenceRemarks"],
        "dataGeneralizations"           => $v["dataGeneralizations"] ?? null,
        "informationWithheld"           => $v["informationWithheld"] ?? null,
        "countryCode"                   => "ES",
    ] + dwcTaxonTerms($sp) + [
        "datasetName"  => $ctx["title"],
        "license"      => DWC_LICENSES[$ctx["license"]][0],
        "rightsHolder" => $ctx["rightsHolder"],
        "language"     => "es",
        "modified"     => syncIsoUtc($v["client_updated_at"]),
    ];
}

/** Marcas + especies → registros publicables y recuentos para el resumen y el EML. */
function exportPrepare(PDO $pdo, string $catalogId, array $catalog, array $o, string $role, int $userId): array {
    $occ = exportOccurrences($pdo, $catalogId, $o);
    $species = exportSpecies($pdo, $catalogId, $o, $occ);
    $owner = $pdo->prepare("SELECT id, name, orcid FROM users WHERE id = ?");
    $owner->execute([$catalog["owner_id"]]);
    $owner = $owner->fetch();
    $ctx = ["title" => $o["title"], "license" => $o["license"], "rightsHolder" => $owner["name"]];

    $records = [];
    $counts = ["occurrences" => 0, "presences" => 0, "absences" => 0, "generalized" => 0, "withheld" => 0, "measurements" => 0];
    $perSpecies = [];
    $observers = [];
    foreach ($occ as $row) {
        [$v, $generalized] = exportVisible($row, $o["mode"], $role, $userId);
        $sp = $species[$row["taxonID"]];
        $records[] = ["v" => $v, "dwc" => exportDwcRecord($v, $sp, $ctx), "sp" => $sp, "generalized" => $generalized,
                      "level" => normalizeSensitivityLevel($row["sensitivity_level"])];
        $counts["occurrences"]++;
        $absent = $row["occurrenceStatus"] === "absent";
        $counts[$absent ? "absences" : "presences"]++;
        if ($generalized) $counts["generalized"]++;
        if ($generalized && $v["decimalLatitude"] === null) $counts["withheld"]++;
        if (!$absent && $row["mature_count"] !== null) $counts["measurements"]++;
        $perSpecies[$row["taxonID"]][$absent ? "absences" : "presences"] = ($perSpecies[$row["taxonID"]][$absent ? "absences" : "presences"] ?? 0) + 1;
        $uid = (int) $row["user_id"];
        $observers[$uid] ??= ["userId" => $uid, "name" => $row["recorded_by"], "orcid" => $row["recorded_by_orcid"], "count" => 0];
        $observers[$uid]["count"]++;
    }
    usort($observers, fn($a, $b) => $b["count"] <=> $a["count"] ?: strcmp($a["name"], $b["name"]));
    $counts["species"] = count($perSpecies);
    $counts["observers"] = count($observers);
    return ["records" => $records, "species" => $species, "perSpecies" => $perSpecies, "observers" => $observers,
            "owner" => $owner, "counts" => $counts];
}

/** Lo que el diálogo necesita para elegir: especies y observadores con marcas, y rango de fechas. Sin coordenadas. */
function exportAvailable(PDO $pdo, string $catalogId): array {
    $stmt = $pdo->prepare(
        "SELECT s.taxonID, s.scientificName, s.canonical_name, s.vernacularName, COUNT(o.occurrenceID) AS n
           FROM species s
           LEFT JOIN catalog_species cs ON cs.taxonID = s.taxonID AND cs.catalog_id = ? AND cs.deleted_at IS NULL
           LEFT JOIN occurrences o ON o.taxonID = s.taxonID AND o.catalog_id = ? AND o.deleted_at IS NULL
          WHERE cs.taxonID IS NOT NULL OR o.occurrenceID IS NOT NULL
          GROUP BY s.taxonID ORDER BY s.canonical_name");
    $stmt->execute([$catalogId, $catalogId]);
    $species = array_map(fn($r) => ["taxonID" => $r["taxonID"], "scientificName" => $r["scientificName"],
        "canonicalName" => $r["canonical_name"], "vernacularName" => $r["vernacularName"], "count" => (int) $r["n"]], $stmt->fetchAll());
    $stmt = $pdo->prepare(
        "SELECT u.id, u.name, COUNT(*) AS n FROM occurrences o JOIN users u ON u.id = o.user_id
          WHERE o.catalog_id = ? AND o.deleted_at IS NULL GROUP BY u.id ORDER BY n DESC, u.name");
    $stmt->execute([$catalogId]);
    $observers = array_map(fn($r) => ["userId" => (int) $r["id"], "name" => $r["name"], "count" => (int) $r["n"]], $stmt->fetchAll());
    $stmt = $pdo->prepare("SELECT MIN(eventDate), MAX(eventDate) FROM occurrences WHERE catalog_id = ? AND deleted_at IS NULL");
    $stmt->execute([$catalogId]);
    [$min, $max] = $stmt->fetch(PDO::FETCH_NUM);
    $local = fn($d) => $d === null ? null : exportLocal($d)->format("Y-m-d");
    return ["species" => $species, "observers" => $observers, "dateMin" => $local($min), "dateMax" => $local($max)];
}

/** eventDate / client_updated_at (UTC en BD) → hora peninsular. */
function exportLocal(string $utc): DateTimeImmutable {
    return (new DateTimeImmutable($utc, new DateTimeZone("UTC")))->setTimezone(new DateTimeZone(EXPORT_TZ));
}

// --- Textos de las hojas -------------------------------------------------------------------------

function exportLevelLabel(int $level): string {
    return $level . " · " . (sensitivityConfig()["levels"][(string) $level]["name"] ?? "?");
}

/** «Real Decreto 139/2011, de 4 de febrero, …» → «Real Decreto 139/2011». */
function exportShortNorm(?string $norma): string {
    $norma = trim((string) $norma);
    if (preg_match('#^(.*?\d+/\d{4})#u', $norma, $m) && mb_strlen($m[1]) <= 90) return $m[1];
    return mb_strlen($norma) > 60 ? mb_substr($norma, 0, 57) . "…" : $norma;
}

function exportLegal(array $rows): string {
    return implode("; ", array_unique(array_map(fn($r) => trim(($r["estadolegal"] ?? "") . " (" . exportShortNorm($r["norma"] ?? null)
        . (!empty($r["ccaa"]) ? ", {$r["ccaa"]}" : "") . ")"), $rows)));
}

/** Entradas del asistente UICN → texto corto («Generación 5 años · A2 50 % (a, c) · B: EOO 80 km², …»). */
function exportAssessmentInputs(?array $in): string {
    if (!$in) return "";
    $has = fn($v) => $v !== null && $v !== "" && $v !== [] && $v !== false;
    $parts = [];
    if ($has($in["generationLength"] ?? null)) $parts[] = "Generación {$in["generationLength"]} años";
    $a = $in["A"] ?? [];
    if (empty($a["skip"]) && $has($a["reductionPct"] ?? null)) {
        $parts[] = "A: " . ($a["subcriterion"] ?? "A?") . " reducción {$a["reductionPct"]} %" . ($has($a["basis"] ?? null) ? " (" . implode(", ", $a["basis"]) . ")" : "");
    }
    $b = $in["B"] ?? [];
    if (empty($b["skip"])) {
        $bp = [];
        if ($has($b["eoo"] ?? null)) $bp[] = "EOO {$b["eoo"]} km²";
        if ($has($b["aoo"] ?? null)) $bp[] = "AOO {$b["aoo"]} km²";
        if ($has($b["locations"] ?? null)) $bp[] = "{$b["locations"]} localidades";
        if (!empty($b["severelyFragmented"])) $bp[] = "fragmentación severa";
        if ($has($b["decline"] ?? null)) $bp[] = "b(" . implode(",", $b["decline"]) . ")";
        if ($has($b["fluctuation"] ?? null)) $bp[] = "c(" . implode(",", $b["fluctuation"]) . ")";
        if ($bp) $parts[] = "B: " . implode(", ", $bp);
    }
    $c = $in["C"] ?? [];
    if (empty($c["skip"])) {
        $cp = [];
        if ($has($c["mature"] ?? null)) $cp[] = "{$c["mature"]} maduros";
        if ($has($c["c1DeclinePct"] ?? null)) $cp[] = "C1 {$c["c1DeclinePct"]} %" . ($has($c["c1Years"] ?? null) ? " en {$c["c1Years"]} años" : "");
        if (!empty($c["continuingDecline"])) $cp[] = "disminución continua";
        if ($has($c["maxPerSubpop"] ?? null)) $cp[] = "máx. {$c["maxPerSubpop"]} por subpoblación";
        if ($has($c["pctInOneSubpop"] ?? null)) $cp[] = "{$c["pctInOneSubpop"]} % en una subpoblación";
        if (!empty($c["extremeFluctuation"])) $cp[] = "fluctuaciones extremas";
        if ($cp) $parts[] = "C: " . implode(", ", $cp);
    }
    $d = $in["D"] ?? [];
    if (empty($d["skip"])) {
        $dp = [];
        if ($has($d["mature"] ?? null)) $dp[] = "{$d["mature"]} maduros";
        if (!empty($d["plausibleThreat"])) $dp[] = "amenaza plausible";
        if ($dp) $parts[] = "D: " . implode(", ", $dp);
    }
    $e = $in["E"] ?? [];
    if (empty($e["skip"]) && $has($e["probabilityPct"] ?? null)) {
        $parts[] = "E: {$e["probabilityPct"]} %" . ($has($e["horizonYears"] ?? null) ? " en {$e["horizonYears"]} años" : "");
    }
    if (!empty($in["dataDeficient"])) $parts[] = "Datos insuficientes (DD)";
    if ($has($in["expertCategory"] ?? null)) $parts[] = "Juicio experto: {$in["expertCategory"]}";
    return implode(" · ", $parts);
}

// --- Hojas ---------------------------------------------------------------------------------------

/** Columnas de Observaciones: [cabecera en castellano, término DwC (o nota), ancho]. */
const EXPORT_OBS_COLUMNS = [
    ["ID de la observación", "occurrenceID", 38], ["Especie", "scientificName", 32], ["Nombre común", "vernacularName", 22],
    ["Presencia / ausencia", "occurrenceStatus", 12], ["Fecha y hora (peninsular)", "eventDate", 17],
    ["Observador/a", "recordedBy", 22], ["ORCID", "recordedByID", 38], ["Nº de individuos", "individualCount", 10],
    ["Individuos maduros", "MeasurementOrFact", 10], ["Sexo", "sex", 13], ["Estado vital", "lifeStage", 13],
    ["Latitud", "decimalLatitude", 12], ["Longitud", "decimalLongitude", 12], ["UTM 30N X (m)", "EPSG:25830", 11],
    ["UTM 30N Y (m)", "EPSG:25830", 11], ["Precisión (m)", "coordinateUncertaintyInMeters", 10],
    ["Georreferencia", "georeferenceProtocol", 22], ["Notas de georreferencia", "georeferenceRemarks", 30],
    ["Hábitat", "habitat", 30], ["Observaciones", "occurrenceRemarks", 40], ["Generalización", "dataGeneralizations", 30],
    ["Información retenida", "informationWithheld", 30], ["Familia", "family", 18], ["Identificador del taxón", "taxonID", 22],
    ["Última edición", "modified", 17],
];

function exportObservationRow(array $rec): array {
    $v = $rec["v"];
    $d = $rec["dwc"];
    $lat = $d["decimalLatitude"];
    $utm = $lat !== null ? toUtm($lat, $d["decimalLongitude"]) : null;
    return [
        $d["occurrenceID"], $rec["sp"]["canonical_name"] ?: $d["scientificName"], $d["vernacularName"],
        EXPORT_STATUS_ES[$d["occurrenceStatus"]] ?? $d["occurrenceStatus"], exportLocal($v["eventDate"]),
        $d["recordedBy"], $d["recordedByID"], ["v" => $d["individualCount"], "s" => "int"],
        ["v" => $d["occurrenceStatus"] === "absent" || $v["mature_count"] === null ? null : (int) $v["mature_count"], "s" => "int"],
        EXPORT_SEX_ES[$d["sex"]] ?? $d["sex"], EXPORT_STAGE_ES[$d["lifeStage"]] ?? $d["lifeStage"],
        ["v" => $lat, "s" => "coord"], ["v" => $d["decimalLongitude"], "s" => "coord"],
        ["v" => $utm ? (int) round($utm["easting"]) : null, "s" => "int"], ["v" => $utm ? (int) round($utm["northing"]) : null, "s" => "int"],
        ["v" => $d["coordinateUncertaintyInMeters"], "s" => "int"], $d["georeferenceProtocol"], $d["georeferenceRemarks"],
        $d["habitat"], $d["occurrenceRemarks"], $d["dataGeneralizations"], $d["informationWithheld"], $d["family"], $d["taxonID"],
        exportLocal($v["client_updated_at"]),
    ];
}

function exportSheets(PDO $pdo, string $catalogId, array $catalog, array $o, array $prep, int $userId, array $meta): array {
    $header = fn(array $cols) => array_map(fn($c) => ["v" => $c, "s" => "header"], $cols);
    $species = $prep["species"];
    $taxa = array_keys($species);

    // Observaciones: cabecera en castellano y, debajo, el término DwC en gris.
    $obs = [$header(array_column(EXPORT_OBS_COLUMNS, 0)), array_map(fn($c) => ["v" => $c[1], "s" => "term"], EXPORT_OBS_COLUMNS)];
    foreach ($prep["records"] as $rec) $obs[] = exportObservationRow($rec);

    // Especies
    $scopes = EXPORT_IUCN_SCOPES;
    foreach ($species as $s) foreach (json_decode((string) $s["iucn_assessments"], true) ?: [] as $a) {
        if (!empty($a["scope"]) && !in_array($a["scope"], $scopes, true)) $scopes[] = $a["scope"];
    }
    $spHead = array_merge(["Especie", "Autor", "Nombre común", "Grupo", "Reino", "Filo", "Clase", "Orden", "Familia", "Género", "Rango",
        "Identificador del taxón"], array_map(fn($s) => "UICN {$s} (EIDOS)", $scopes),
        ["Estado legal nacional", "Estado legal Andalucía", "Otros estados legales", "Nivel de sensibilidad", "Marcas (presencias)", "Ausencias"]);
    $spRows = [$header($spHead)];
    foreach ($species as $s) {
        $iucn = [];
        foreach (json_decode((string) $s["iucn_assessments"], true) ?: [] as $a) {
            $iucn[$a["scope"] ?? ""] = trim(($a["code"] ?? "") . (!empty($a["criteria"]) ? " {$a["criteria"]}" : "") . (!empty($a["year"]) ? " ({$a["year"]})" : ""));
        }
        $legal = json_decode((string) $s["legal_status"], true) ?: [];
        $general = $legal["general"] ?? [];
        $t = dwcTaxonTerms($s);
        $spRows[] = array_merge([
            ["v" => $s["canonical_name"], "s" => "strong"], $s["scientificNameAuthorship"], $s["vernacularName"], $s["taxonomicGroup"],
            $s["kingdom"], $s["phylum"], $s["class"], $s["order"], $s["family"], $s["genus"], $t["taxonRank"], $t["taxonID"],
        ], array_map(fn($sc) => $iucn[$sc] ?? null, $scopes), [
            exportLegal(array_filter($general, fn($r) => ($r["ambito"] ?? "") === "Nacional")) ?: null,
            exportLegal($legal["andalucia"] ?? []) ?: null,
            exportLegal(array_filter($general, fn($r) => ($r["ambito"] ?? "") !== "Nacional")) ?: null,
            exportLevelLabel(normalizeSensitivityLevel($s["sensitivity_level"])),
            ["v" => $prep["perSpecies"][$s["taxonID"]]["presences"] ?? 0, "s" => "int"],
            ["v" => $prep["perSpecies"][$s["taxonID"]]["absences"] ?? 0, "s" => "int"],
        ]);
    }

    $in = $taxa ? implode(",", array_fill(0, count($taxa), "?")) : "NULL";

    // Evaluaciones UICN: la de referencia primero y luego las del equipo, rotuladas «Evaluación BioDex».
    $asRows = [$header(["Especie", "Rótulo", "Estado", "Autor", "Fecha", "Categoría", "Categoría antes del ajuste", "Criterios",
        "Ajuste regional", "Justificación", "Entradas"])];
    $stmt = $pdo->prepare(assessmentSelect("a.catalog_id = ? AND a.deleted_at IS NULL AND a.taxonID IN ({$in})")
        . " ORDER BY a.taxonID, a.is_reference DESC, a.client_updated_at DESC");
    $stmt->execute(array_merge([$catalogId], $taxa));
    foreach ($stmt->fetchAll() as $a) {
        $result = json_decode($a["result"], true) ?: [];
        $asRows[] = [
            $species[$a["taxonID"]]["canonical_name"],
            (int) $a["is_reference"] === 1 ? "Evaluación BioDex · referencia del catálogo" : "Evaluación BioDex",
            $a["status"] === "final" ? "Final" : "Borrador", $a["recorded_by"], exportLocal($a["client_updated_at"]),
            ["v" => $a["category"], "s" => "strong"], $result["baseCategory"] ?? null, $result["criteriaString"] ?? null,
            ["down" => "Un escalón abajo", "up" => "Un escalón arriba"][$a["regional_adjustment"]] ?? null,
            ["v" => $a["justification"], "s" => "wrap"], ["v" => exportAssessmentInputs(json_decode($a["inputs"], true)), "s" => "wrap"],
        ];
    }

    // Distribución: EOO/AOO definitivos del servidor (equipo, celda de 2 km), solo cifras.
    $diRows = [$header(["Especie", "Ámbito", "EOO (km²)", "EOO igualada a AOO", "AOO (km²)", "Celdas", "Tamaño de celda (km)",
        "AOO mínima (km²)", "AOO máxima (km²)", "Registros usados", "Excluidos", "de ellos ausencias", "de ellos imprecisos",
        "de ellos sin coordenadas", "Avisos", "Fecha de cálculo"])];
    $stmt = $pdo->prepare("SELECT DISTINCT taxonID FROM occurrences WHERE catalog_id = ? AND deleted_at IS NULL");
    $stmt->execute([$catalogId]);
    $marked = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    foreach ($species as $taxonID => $s) {
        if (!isset($marked[$taxonID])) continue;   // sin marcas en el catálogo no hay nada que calcular
        $r = metricsStored($pdo, $catalogId, $taxonID, $userId, "team", METRICS_DEFAULT_CELL_KM);
        $m = $r["m"];
        $diRows[] = [
            $s["canonical_name"], "Equipo (todas las presencias del catálogo)", ["v" => $m["eooKm2"], "s" => "km2"],
            $m["eooAdjusted"] ? "Sí" : "No", ["v" => $m["aooKm2"], "s" => "km2"], ["v" => $m["aooCells"], "s" => "int"],
            ["v" => $m["cellSizeKm"], "s" => "int"], ["v" => $m["aooRange"]["min"] ?? null, "s" => "km2"],
            ["v" => $m["aooRange"]["max"] ?? null, "s" => "km2"], ["v" => $m["nRecords"], "s" => "int"],
            ["v" => $m["nExcluded"], "s" => "int"], ["v" => $m["excluded"]["absent"], "s" => "int"],
            ["v" => $m["excluded"]["imprecise"], "s" => "int"], ["v" => $m["excluded"]["noCoordinates"], "s" => "int"],
            ["v" => implode(" ", $m["warnings"]), "s" => "wrap"], exportLocal((string) $r["computedAt"]),
        ];
    }

    // Notas de cuaderno
    $noRows = [$header(["Especie", "Autor", "Fecha", "Nota"])];
    $stmt = $pdo->prepare("SELECT n.*, u.name AS recorded_by FROM species_notes n JOIN users u ON u.id = n.user_id
                            WHERE n.catalog_id = ? AND n.deleted_at IS NULL AND n.taxonID IN ({$in})
                            ORDER BY n.taxonID, n.client_updated_at");
    $stmt->execute(array_merge([$catalogId], $taxa));
    foreach ($stmt->fetchAll() as $n) {
        $noRows[] = [$species[$n["taxonID"]]["canonical_name"], $n["recorded_by"], exportLocal($n["client_updated_at"]), ["v" => $n["text"], "s" => "wrap"]];
    }

    return [
        ["name" => "Léeme", "rows" => exportReadme($catalog, $o, $prep, $meta), "widths" => [26, 110]],
        ["name" => "Observaciones", "rows" => $obs, "widths" => array_column(EXPORT_OBS_COLUMNS, 2), "freeze" => 2,
         "autofilter" => [2, count(EXPORT_OBS_COLUMNS)], "csv" => "observaciones"],
        ["name" => "Especies", "rows" => $spRows, "widths" => array_merge([30, 22, 22, 16, 12, 16, 16, 18, 18, 16, 11, 22],
         array_fill(0, count($scopes), 16), [40, 40, 40, 18, 10, 10]), "freeze" => 1, "autofilter" => [1, count($spHead)], "csv" => "especies"],
        ["name" => "Evaluaciones UICN", "rows" => $asRows, "widths" => [30, 30, 10, 22, 17, 10, 12, 30, 16, 50, 70], "freeze" => 1,
         "autofilter" => [1, 11], "csv" => "evaluaciones_uicn"],
        ["name" => "Distribución", "rows" => $diRows, "widths" => [30, 26, 11, 10, 11, 8, 10, 11, 11, 10, 10, 10, 10, 10, 50, 17],
         "freeze" => 1, "autofilter" => [1, 16], "csv" => "distribucion"],
        ["name" => "Notas", "rows" => $noRows, "widths" => [30, 22, 17, 100], "freeze" => 1, "autofilter" => [1, 4], "csv" => "notas"],
    ];
}

/** «Cómo citar» del conjunto. */
function exportCitation(array $o, array $prep, string $date): string {
    $names = array_column($prep["observers"], "name") ?: [$prep["owner"]["name"]];
    $who = count($names) > 3 ? implode(", ", array_slice($names, 0, 3)) . " et al." : implode(", ", $names);
    return "{$who} (" . substr($date, 0, 4) . "). {$o["title"]}. Conjunto de datos exportado de BioDex el {$date}. "
         . "Licencia " . DWC_LICENSES[$o["license"]][1] . ".";
}

function exportFiltersText(array $o, array $prep): array {
    $sp = $o["taxonIDs"] === null ? "todas" : count($o["taxonIDs"]) . " elegidas";
    $ob = $o["observers"] === null ? "todos" : (implode(", ", array_column($prep["observers"], "name")) ?: "ninguno con marcas");
    $dates = $o["dateFrom"] || $o["dateTo"] ? ($o["dateFrom"] ?? "…") . " a " . ($o["dateTo"] ?? "…") : "todas";
    return ["Fechas: {$dates}", "Especies: {$sp}", "Observadores: {$ob}", "Ausencias: " . ($o["includeAbsences"] ? "incluidas" : "excluidas")];
}

function exportReadme(array $catalog, array $o, array $prep, array $meta): array {
    $c = $prep["counts"];
    $full = $o["mode"] === "full";
    $kv = fn($k, $v) => [["v" => $k, "s" => "strong"], ["v" => $v, "s" => "wrap"]];
    $rows = [
        [["v" => $o["title"], "s" => "title"]],
        [["v" => $full ? "EXPORTACIÓN COMPLETA (PRIVADA) · contiene coordenadas exactas de especies sensibles: no la publiques ni la compartas fuera del equipo."
                       : "EXPORTACIÓN PARA PUBLICAR · coordenadas generalizadas según la sensibilidad de cada especie.", "s" => "strong"]],
        [],
        $kv("Catálogo", $catalog["name"]),
        $kv("Modo", EXPORT_MODE_LABEL[$o["mode"]]),
        $kv("Generada", $meta["generatedLocal"] . " (hora peninsular) por " . $meta["by"] . " · BioDex " . $meta["version"]),
        $kv("Filtros", implode(" · ", exportFiltersText($o, $prep))),
        $kv("Contenido", "{$c["occurrences"]} observaciones ({$c["presences"]} presencias, {$c["absences"]} ausencias) de {$c["species"]} especies y {$c["observers"]} observadores"),
        $kv("Generalizadas", $full ? "Ninguna: coordenadas exactas"
            : "{$c["generalized"]} observaciones (de ellas {$c["withheld"]} sin coordenadas, nivel 1). En esas filas tampoco salen las notas de campo ni de georreferencia."),
        $kv("Licencia", DWC_LICENSES[$o["license"]][1] . " · " . DWC_LICENSES[$o["license"]][0]),
        $kv("Cómo citar", exportCitation($o, $prep, $meta["date"])),
        [],
        [["v" => "Pestañas", "s" => "strong"]],
        $kv("Observaciones", "Una fila por marca. Cabecera en castellano y, debajo en gris, el término Darwin Core. Fechas en hora peninsular (en el Darwin Core Archive, en UTC). Coordenadas en grados (WGS84, 6 decimales) y en UTM 30N (ETRS89, EPSG:25830)."),
        $kv("Especies", "Especies del catálogo (y las que tengan marcas): clasificación, UICN oficial de cada ámbito según EIDOS, estado legal, nivel de sensibilidad del catálogo y nº de marcas y ausencias de esta exportación."),
        $kv("Evaluaciones UICN", "Evaluaciones BioDex de cada especie (la de referencia del catálogo primero): propuestas orientativas hechas con el asistente, no evaluaciones oficiales. No dependen de los filtros de fecha ni de observador."),
        $kv("Distribución", "EOO y AOO definitivos calculados en el servidor con todas las presencias del catálogo (celda de 2 km), no solo las filtradas. Reflejan los registros, no la distribución completa de la especie."),
        $kv("Notas", "Notas de cuaderno por especie, con autor y fecha."),
        [],
    ];
    if (in_array("dwc", $o["formats"], true)) {
        $rows[] = $kv("dwca/", "Darwin Core Archive para GBIF u otras bases: occurrence.txt, measurementorfact.txt (individuos maduros), meta.xml y eml.xml. Para el validador de GBIF, comprime el CONTENIDO de dwca/ (meta.xml en la raíz del ZIP).");
    }
    if (in_array("plinian", $o["formats"], true)) $rows[] = $kv("pliniancore/", EXPORT_PLINIAN_README);
    $rows[] = $kv("CSV", "Los mismos datos en CSV (UTF-8, separador «;», coma decimal) para abrir con doble clic en Excel en español.");
    return $rows;
}

// --- CSV (Excel en español) ----------------------------------------------------------------------

function exportCsvValue(mixed $cell): string {
    $style = null;
    if (is_array($cell)) { $style = $cell["s"] ?? null; $cell = $cell["v"] ?? null; }
    if ($cell === null) return "";
    if ($cell instanceof DateTimeInterface) $s = $cell->format($style === "date" ? "d/m/Y" : "d/m/Y H:i");
    elseif (is_float($cell) || (is_int($cell) && in_array($style, ["coord", "km2"], true))) {
        $s = number_format((float) $cell, $style === "coord" ? 6 : ($style === "km2" ? 3 : 2), ",", "");
    } else $s = (string) $cell;
    return preg_match('/[;"\r\n]/u', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
}

/** Hoja → CSV con BOM, «;» y CRLF. En Observaciones se omite la fila de términos DwC. */
function exportCsv(array $sheet): string {
    $rows = $sheet["rows"];
    if (($rows[1][0]["s"] ?? null) === "term") array_splice($rows, 1, 1);
    $out = "\xEF\xBB\xBF";
    foreach ($rows as $r) $out .= implode(";", array_map("exportCsvValue", $r)) . "\r\n";
    return $out;
}

// --- ZIP -----------------------------------------------------------------------------------------

/** «Aves de Sierra Nevada» → «Aves_de_Sierra_Nevada» (ASCII, para nombres de fichero). */
function exportSlug(string $s): string {
    $a = function_exists("transliterator_transliterate") ? transliterator_transliterate("Any-Latin; Latin-ASCII", $s) : $s;
    $a = trim(preg_replace('/[^A-Za-z0-9]+/', "_", (string) $a), "_");
    return substr($a, 0, 60) ?: "catalogo";
}

/** EML del conjunto (datos y cobertura de las coordenadas YA publicables). */
function exportEml(array $catalog, array $o, array $prep, array $meta): string {
    $lats = $lons = $days = [];
    $families = $names = [];
    foreach ($prep["records"] as $r) {
        $d = $r["dwc"];
        if ($d["decimalLatitude"] !== null) { $lats[] = $d["decimalLatitude"]; $lons[] = $d["decimalLongitude"]; }
        $days[] = exportLocal($r["v"]["eventDate"])->format("Y-m-d");
        if ($d["family"]) $families[$d["family"]] = true;
        $names[$d["scientificName"]] = [$d["scientificName"], $d["taxonRank"]];
    }
    ksort($families);
    ksort($names);
    $c = $prep["counts"];
    $full = $o["mode"] === "full";
    $abstract = ($o["description"] !== "" ? $o["description"] . "\n\n" : "")
        . "{$c["occurrences"]} observaciones de campo ({$c["presences"]} presencias y {$c["absences"]} ausencias en puntos prospectados) de "
        . "{$c["species"]} taxones, registradas con la app BioDex en el catálogo «{$catalog["name"]}». Taxonomía según EIDOS (IEPNB, MITECO).";
    $generalization = $full
        ? "EXPORTACIÓN COMPLETA (PRIVADA): contiene las coordenadas exactas de especies sensibles. No publicar."
        : "Exportación para publicar. Las coordenadas de {$c["generalized"]} registros de especies sensibles se han generalizado en el "
          . "servidor según su nivel de sensibilidad (nivel 2: centro de la celda de 0,1°; nivel 3: de 0,01°; nivel 1: coordenadas retenidas, "
          . "{$c["withheld"]} registros), con coordinateUncertaintyInMeters acorde, y lo indican dataGeneralizations e informationWithheld. "
          . "En esos registros tampoco se publican las notas de campo ni de georreferencia. Método: GBIF, Current Best Practices for "
          . "Generalizing Sensitive Species Occurrence Data (Chapman 2020).";
    return dwcEmlXml([
        "packageId" => $meta["packageId"],
        "title" => $o["title"] . ($full ? " (exportación completa, privada: no publicar)" : ""),
        "abstract" => $abstract,
        "creators" => array_map(fn($p) => ["name" => $p["name"], "orcid" => $p["orcid"]], $prep["observers"]),
        "contact" => ["name" => $prep["owner"]["name"], "orcid" => $prep["owner"]["orcid"], "role" => "Titular del catálogo en BioDex"],
        "license" => $o["license"],
        "bbox" => $lats ? ["west" => min($lons), "east" => max($lons), "south" => min($lats), "north" => max($lats)] : null,
        "geoDescription" => $full ? "Envolvente de las coordenadas exactas de las observaciones."
                                  : "Envolvente de las coordenadas publicadas (generalizadas en las especies sensibles).",
        "dates" => $days ? ["begin" => min($days), "end" => max($days)] : null,
        "families" => array_keys($families),
        "species" => array_values($names),
        "methods" => "Observación de campo con GPS del dispositivo o punto marcado en el mapa; app BioDex v{$meta["version"]}. "
                   . "Cada registro lleva su precisión (coordinateUncertaintyInMeters) y el método (georeferenceProtocol). "
                   . "Las ausencias son puntos prospectados donde no se encontró la especie.",
        "generalization" => $generalization,
        "modeLabel" => EXPORT_MODE_LABEL[$o["mode"]],
        "pubDate" => $meta["date"],
        "dateStamp" => $meta["generatedIso"],
    ]);
}

/**
 * Genera el ZIP en un fichero temporal → [ruta, nombre de descarga]. Quien llama lo envía y lo borra.
 * $meta: by (nombre de quien exporta).
 */
function exportBuild(PDO $pdo, string $catalogId, array $catalog, array $o, array $prep, int $userId, string $byName): array {
    $now = new DateTimeImmutable("now", new DateTimeZone(EXPORT_TZ));
    $meta = ["date" => $now->format("Y-m-d"), "generatedLocal" => $now->format("d/m/Y H:i"), "generatedIso" => $now->format(DATE_ATOM),
             "by" => $byName, "version" => biodexVersion(), "packageId" => "urn:uuid:" . uuidV4()];
    $slug = exportSlug($catalog["name"]);
    $zipName = "BioDex_{$slug}_" . EXPORT_MODE_FILE[$o["mode"]] . "_{$meta["date"]}.zip";
    $xlsxName = "BioDex_{$slug}_{$meta["date"]}.xlsx";

    $mof = [];
    foreach ($prep["records"] as $r) {
        $v = $r["v"];
        if ($v["occurrenceStatus"] === "absent" || $v["mature_count"] === null) continue;
        $mof[] = ["coreid" => $v["occurrenceID"], "measurementID" => $v["occurrenceID"] . ":mature_count",
                  "measurementType" => DWC_MATURE_TYPE, "measurementValue" => (int) $v["mature_count"],
                  "measurementUnit" => "individuos", "measurementDeterminedBy" => $v["recorded_by"],
                  "measurementDeterminedDate" => $r["dwc"]["eventDate"], "measurementMethod" => "Conteo en campo (BioDex)"];
    }

    $withDwc = in_array("dwc", $o["formats"], true);
    $withSheets = in_array("sheets", $o["formats"], true);
    // Plinian Core primero: si no valida, PlinianInvalidXml y no se genera nada más (no hay ZIP con PliC inválido).
    $plinian = in_array("plinian", $o["formats"], true)
        ? plinianXml($pdo, $catalogId, $catalog, $o, $prep, $meta, ["withDwc" => $withDwc, "zipName" => $zipName])
        : null;
    $sheets = $withSheets ? exportSheets($pdo, $catalogId, $catalog, $o, $prep, $userId, $meta) : [];
    $xlsxPath = tempnam(sys_get_temp_dir(), "bdx");
    $zipPath = tempnam(sys_get_temp_dir(), "bdz");
    try {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) throw new RuntimeException("No se pudo crear el ZIP");
        if ($withDwc) {
            $zip->addFromString("dwca/occurrence.txt", dwcTsv(array_keys(DWC_OCCURRENCE_TERMS), array_column($prep["records"], "dwc")));
            if ($mof) $zip->addFromString("dwca/measurementorfact.txt", dwcTsv(array_merge(["coreid"], array_keys(DWC_MOF_TERMS)), $mof));
            $zip->addFromString("dwca/meta.xml", dwcMetaXml((bool) $mof));
            $zip->addFromString("dwca/eml.xml", exportEml($catalog, $o, $prep, $meta));
        }
        if ($withSheets) {
            xlsxWrite($xlsxPath, $sheets, ["title" => $o["title"], "creator" => "BioDex " . $meta["version"]]);
            $zip->addFile($xlsxPath, "hojas/{$xlsxName}");
            foreach ($sheets as $sheet) {
                if (!empty($sheet["csv"])) $zip->addFromString("hojas/{$sheet["csv"]}.csv", exportCsv($sheet));
            }
        }
        if ($plinian !== null) $zip->addFromString(PLIC_FILE, $plinian);
        $zip->addFromString("LEEME.txt", exportLeemeTxt($catalog, $o, $prep, $meta, $xlsxName));
        if (!$zip->close()) throw new RuntimeException("No se pudo escribir el ZIP");
    } finally {
        @unlink($xlsxPath);
    }
    return [$zipPath, $zipName];
}

function exportLeemeTxt(array $catalog, array $o, array $prep, array $meta, string $xlsxName): string {
    $c = $prep["counts"];
    $full = $o["mode"] === "full";
    $lines = [
        "BioDex · exportación " . mb_strtoupper(EXPORT_MODE_LABEL[$o["mode"]]),
        str_repeat("=", 60),
        $full ? "ATENCIÓN: contiene coordenadas EXACTAS de especies sensibles. No la publiques ni la compartas fuera del equipo."
              : "Coordenadas generalizadas según la sensibilidad de cada especie: lista para publicar.",
        "",
        "Conjunto:   {$o["title"]}",
        "Catálogo:   {$catalog["name"]}",
        "Generada:   {$meta["generatedLocal"]} (hora peninsular) por {$meta["by"]} · BioDex {$meta["version"]}",
        "Contenido:  {$c["occurrences"]} observaciones ({$c["presences"]} presencias, {$c["absences"]} ausencias), {$c["species"]} especies",
        $full ? null : "Generalizadas: {$c["generalized"]} (sin coordenadas: {$c["withheld"]})",
        "Filtros:    " . implode(" · ", exportFiltersText($o, $prep)),
        "Licencia:   " . DWC_LICENSES[$o["license"]][1],
        "Cita:       " . exportCitation($o, $prep, $meta["date"]),
        "",
    ];
    if (in_array("dwc", $o["formats"], true)) array_push($lines,
        "dwca/   Darwin Core Archive (occurrence.txt, measurementorfact.txt, meta.xml, eml.xml).",
        "        Para GBIF u otras bases. Al validador de GBIF se sube el CONTENIDO de dwca/ comprimido",
        "        (meta.xml en la raíz del ZIP): https://www.gbif.org/tools/data-validator");
    if (in_array("sheets", $o["formats"], true)) array_push($lines,
        "hojas/  {$xlsxName} (ábrelo con doble clic; empieza por la pestaña «Léeme»)",
        "        y los mismos datos en CSV (UTF-8, «;», coma decimal) para Excel en español.");
    if (in_array("plinian", $o["formats"], true)) array_push($lines,
        "pliniancore/  especies.xml: fichas de especie en Plinian Core " . PLIC_VERSION . " (TDWG), validado contra su XSD.",
        "        Taxonomía, categorías de amenaza oficiales (EIDOS) y, aparte, la evaluación BioDex de",
        "        referencia (regional, preliminar, NO oficial); EOO, AOO y cuadrículas en cifras.",
        "        No incluye observaciones ni coordenadas.");
    return implode("\r\n", array_filter($lines, fn($l) => $l !== null)) . "\r\n";
}
