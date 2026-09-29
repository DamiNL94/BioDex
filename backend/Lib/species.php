<?php
// ================================================================================================
// species.php — caché de especies (tabla `species`) sobre el cliente de EIDOS (Lib/eidos.php).
// ------------------------------------------------------------------------------------------------
// Dos grados de detalle por especie de EIDOS:
//   · ligero: lo que da una búsqueda (nombre, clasificación, grupo, UICN). Se guarda al buscar.
//   · ficha:  además estado legal, nombre común preferente, descripción, imágenes y sinónimos
//             (detail_fetched_at). Caduca a los SPECIES_DETAIL_MAX_AGE_DAYS días.
// El estado legal también se puede traer solo (legal_fetched_at) para el filtro `legal` del buscador.
// Las especies de usuario (source='user', taxonID biodex:<uuid>) nunca se piden a EIDOS.
// ================================================================================================

require_once __DIR__ . "/eidos.php";
require_once __DIR__ . "/sensitivity.php";

const SPECIES_DETAIL_MAX_AGE_DAYS = 30;
const SPECIES_PHOTO_ATTRIBUTION = "Fototeca MITECO (vía EIDOS)";
const SPECIES_EIDOS_URL = "https://iepnb.gob.es/areas-tematicas/especies-silvestres/eidos/";
const SPECIES_ANDALUCIA = "Andalucía";

/** Filtro `legal` del buscador → fragmento del texto de la norma en v_estadolegal. */
const SPECIES_LEGAL_FILTERS = [
    "rd139"    => "139/2011",    // Listado y Catálogo Español de Especies Amenazadas (LESRPE/CEEA)
    "habitats" => "92/43",       // Directiva Hábitats
    "aves"     => "2009/147",    // Directiva Aves
    "rd630"    => "630/2013",    // Catálogo Español de Especies Exóticas Invasoras
    "ue1143"   => "1143/2014",   // Reglamento UE de especies exóticas invasoras
];

function speciesIsEidosId(string $taxonID): bool {
    return ctype_digit($taxonID);
}

function speciesFind(PDO $pdo, string $taxonID): ?array {
    $stmt = $pdo->prepare("SELECT * FROM species WHERE taxonID = ?");
    $stmt->execute([$taxonID]);
    return $stmt->fetch() ?: null;
}

/** Nombre sin autor: género + epíteto [+ marca + infraespecífico]; si no hay epítetos, el nombre tal cual. */
function speciesCanonical(array $t): string {
    if (empty($t["genus"]) || empty($t["specificepithet"])) {
        $name = trim((string) $t["name"]);
        $author = trim((string) ($t["scientificnameauthorship"] ?? ""));
        if ($author !== "" && str_ends_with($name, $author)) $name = trim(substr($name, 0, -strlen($author)));
        return $name;
    }
    $name = "{$t["genus"]} {$t["specificepithet"]}";
    if (!empty($t["infraspecificepithet"])) {
        $marker = ["Subspecies" => "subsp.", "Variety" => "var.", "Form" => "f."][$t["taxonrank"] ?? ""] ?? "";
        $name .= ($marker ? " {$marker}" : "") . " {$t["infraspecificepithet"]}";
    }
    return $name;
}

/** Columnas de clasificación (reino…familia con sus ids de EIDOS) desde una fila de v_taxonomia. */
function speciesClassificationColumns(array $t): array {
    $id = fn($v) => $v === null ? null : (string) $v;
    return [
        "kingdom" => $t["kingdom"],        "kingdom_id" => $id($t["kingdomid"]),
        "phylum"  => $t["phylum"],         "phylum_id"  => $id($t["phylumid"]),
        "class"   => $t["class"],          "class_id"   => $id($t["classid"]),
        "order"   => $t["ordertaxonomic"], "order_id"   => $id($t["ordertaxonomicid"]),
        "family"  => $t["family"],         "family_id"  => $id($t["familyid"]),
    ];
}

/** Columnas de `species` a partir de una fila de v_taxonomia (EIDOS_TAXON_SELECT). */
function speciesColumnsFromTaxon(array $t): array {
    return speciesClassificationColumns($t) + [
        "scientificName"           => $t["name"],
        "scientificNameAuthorship" => $t["scientificnameauthorship"] ?: null,
        "canonical_name"           => speciesCanonical($t),
        "taxonRank"                => $t["taxonrank"],
        "classification_source"    => "eidos",
        "genus"                    => $t["genus"],
        "specificEpithet"          => $t["specificepithet"],
        "infraspecificEpithet"     => $t["infraspecificepithet"],
        "taxonomicGroup"           => $t["taxonomicgroup"],
        "establishmentMeans"       => $t["origen"],
        "habitatScope"             => $t["areacode"],
    ];
}

/** Columnas UICN a partir de la salida de eidosConservation() (ámbito → evaluación). */
function speciesIucnColumns(array $byScope): array {
    $primary = eidosPrimaryIucn($byScope);
    $list = [];
    foreach ($byScope as $scope => $a) {
        $list[] = ["scope" => (string) $scope, "code" => $a["code"], "label" => $a["label"] ?? null,
                   "year" => $a["year"], "criteria" => $a["criteria"] ?? null];
    }
    return [
        "iucn_assessments"   => json_encode($list, JSON_UNESCAPED_UNICODE),
        "iucn_primary_code"  => $primary["code"] ?? null,
        "iucn_primary_scope" => $primary["scope"] ?? null,
        "iucn_primary_year"  => $primary["year"] ?? null,
    ];
}

/**
 * Estado legal compacto para la caché: `general` (normas sin comunidad autónoma: nacionales, europeas e
 * internacionales; cada fila con `peninsula`, ver eidosAppliesOnPeninsula) y `andalucia`.
 */
function speciesLegalColumns(array $eidosLegal): array {
    $keep = fn($r) => [
        "estadolegal" => $r["estadolegal"], "norma" => $r["norma"], "ambito" => $r["ambito"],
        "ccaa" => $r["ccaa"] ?: null, "observacionespoblacion" => $r["observacionespoblacion"] ?: null,
    ];
    $general = [];
    foreach ($eidosLegal["vigentes"] as $r) {
        if (!empty($r["ccaa"]) || $r["ambito"] === "Autonómico") continue;
        $general[] = $keep($r) + ["peninsula" => eidosAppliesOnPeninsula($r)];
    }
    return ["legal_status" => json_encode([
        "general"   => $general,
        "andalucia" => array_map($keep, $eidosLegal["autonomico"]),
    ], JSON_UNESCAPED_UNICODE)];
}

/** INSERT … ON DUPLICATE KEY UPDATE de las columnas dadas (nombres de columna fijos, nunca del cliente). */
function speciesUpsert(PDO $pdo, string $taxonID, array $cols, string $source = "eidos"): void {
    $cols = ["taxonID" => $taxonID, "source" => $source] + $cols;
    $names = array_map(fn($c) => "`{$c}`", array_keys($cols));
    $updates = array_map(fn($c) => "`{$c}` = VALUES(`{$c}`)", array_diff(array_keys($cols), ["taxonID", "source"]));
    $pdo->prepare("INSERT INTO species (" . implode(", ", $names) . ") VALUES (" . rtrim(str_repeat("?, ", count($cols)), ", ") . ")"
        . " ON DUPLICATE KEY UPDATE " . implode(", ", $updates))
        ->execute(array_values($cols));
}

/** Guarda en la caché el detalle ligero de un resultado de eidosSearch(). */
function speciesUpsertFromSearch(PDO $pdo, array $item): void {
    $cols = speciesColumnsFromTaxon($item["taxon"]) + speciesIucnColumns($item["iucnAssessments"]);
    // El nombre común de la búsqueda no pisa el de la ficha (que usa el preferente de eidosCommonName).
    $stmt = $pdo->prepare("SELECT detail_fetched_at FROM species WHERE taxonID = ?");
    $stmt->execute([$item["taxonID"]]);
    if (!$stmt->fetchColumn()) $cols["vernacularName"] = $item["vernacularName"];
    speciesUpsert($pdo, $item["taxonID"], $cols);
}

/**
 * Ficha completa desde EIDOS → caché. Devuelve la fila guardada, o null si EIDOS no conoce el taxón.
 */
function speciesFetchDetail(PDO $pdo, string $taxonID): ?array {
    if (!speciesIsEidosId($taxonID)) return speciesFind($pdo, $taxonID);
    $id = (int) $taxonID;
    $taxon = eidosTaxon($id);
    if ($taxon === null) return null;

    $images = array_map(fn($img) => [
        "url" => $img["url"], "id" => $img["id"], "priority" => $img["priority"],
        "description" => $img["description"], "attribution" => SPECIES_PHOTO_ATTRIBUTION,
    ], eidosImages($id));

    $cols = speciesColumnsFromTaxon($taxon)
        + speciesIucnColumns(eidosConservation($id))
        + speciesLegalColumns(eidosLegal($id, SPECIES_ANDALUCIA))
        + [
            "vernacularName" => eidosCommonName($id),
            "description"    => eidosDescription($id),
            "images"         => json_encode($images, JSON_UNESCAPED_UNICODE),
            "synonyms"       => json_encode($taxon["synonyms"], JSON_UNESCAPED_UNICODE),
        ];
    $cols["detail_fetched_at"] = date("Y-m-d H:i:s");
    $cols["legal_fetched_at"] = $cols["detail_fetched_at"];
    speciesUpsert($pdo, $taxonID, $cols);
    return speciesFind($pdo, $taxonID);
}

/** ¿Hay que (re)pedir la ficha? Nunca para especies de usuario. */
function speciesDetailStale(?array $row): bool {
    if ($row !== null && $row["source"] === "user") return false;
    if ($row === null || $row["detail_fetched_at"] === null) return true;
    return strtotime($row["detail_fetched_at"]) < time() - SPECIES_DETAIL_MAX_AGE_DAYS * 86400;
}

/** Fila con la ficha al día (la pide a EIDOS si falta o caducó). null si no existe en ningún sitio. */
function speciesWithDetail(PDO $pdo, string $taxonID): ?array {
    $row = speciesFind($pdo, $taxonID);
    if (!speciesDetailStale($row)) return $row;
    try {
        return speciesFetchDetail($pdo, $taxonID) ?? $row;
    } catch (EidosException $e) {
        // EIDOS caído: se sirve lo que haya en caché, aunque sea viejo.
        if ($row !== null) return $row;
        throw $e;
    }
}

/** Solo el estado legal (1 petición) para el filtro `legal` del buscador. */
function speciesFetchLegal(PDO $pdo, string $taxonID): void {
    $cols = speciesLegalColumns(eidosLegal((int) $taxonID, SPECIES_ANDALUCIA));
    $pdo->prepare("UPDATE species SET legal_status = ?, legal_fetched_at = NOW() WHERE taxonID = ?")
        ->execute([$cols["legal_status"], $taxonID]);
}

/** ¿Alguna norma vigente sin comunidad autónoma de la especie es una de $codes (SPECIES_LEGAL_FILTERS)? */
function speciesLegalMatches(array $row, array $codes): bool {
    $legal = json_decode((string) $row["legal_status"], true) ?: [];
    foreach ($legal["general"] ?? [] as $r) {
        foreach ($codes as $code) {
            if (str_contains((string) $r["norma"], SPECIES_LEGAL_FILTERS[$code])) return true;
        }
    }
    return false;
}

/**
 * Nivel de sensibilidad sugerido → [level, reason]. Solo cuentan las normas que se aplican en la
 * península (Granada): la población canaria de una especie no sube el nivel aquí.
 */
function speciesSuggestLevel(array $row): array {
    $iucn = json_decode((string) $row["iucn_assessments"], true) ?: [];
    $legal = json_decode((string) $row["legal_status"], true) ?: [];
    $rows = array_values(array_filter($legal["general"] ?? [], fn($r) => $r["peninsula"] ?? true));
    return suggestSensitivityLevel($iucn, $rows);
}

function speciesIucnPrimary(array $row): ?array {
    if ($row["iucn_primary_scope"] === null) return null;
    return ["code" => $row["iucn_primary_code"], "scope" => $row["iucn_primary_scope"],
            "year" => $row["iucn_primary_year"] !== null ? (int) $row["iucn_primary_year"] : null];
}

function speciesEidosUrl(array $row): ?string {
    return $row["source"] === "eidos" ? SPECIES_EIDOS_URL . $row["taxonID"] : null;
}

/** Tarjeta de especie (resultados de búsqueda, listas de catálogo). */
function speciesSummaryOut(array $row): array {
    $images = json_decode((string) $row["images"], true) ?: [];
    return [
        "taxonID"        => $row["taxonID"],
        "source"         => $row["source"],
        "scientificName" => $row["scientificName"],
        "vernacularName" => $row["vernacularName"],
        "taxonomicGroup" => $row["taxonomicGroup"],
        "taxonRank"      => $row["taxonRank"],
        "iucnPrimary"    => speciesIucnPrimary($row),
        "thumbnail"      => $images[0]["url"] ?? null,
        "eidosUrl"       => speciesEidosUrl($row),
    ];
}

/** Ficha completa. */
function speciesFullOut(array $row): array {
    $legal = json_decode((string) $row["legal_status"], true);
    [$level, $reason] = speciesSuggestLevel($row);
    return speciesSummaryOut($row) + [
        "scientificNameAuthorship" => $row["scientificNameAuthorship"],
        "canonicalName"            => $row["canonical_name"],
        "classification"           => speciesClassificationOut($row),
        "establishmentMeans"       => $row["establishmentMeans"],
        "habitatScope"             => $row["habitatScope"],
        "synonyms"                 => json_decode((string) $row["synonyms"], true) ?: [],
        "iucnAssessments"          => json_decode((string) $row["iucn_assessments"], true) ?: [],
        "legalStatus"              => $legal === null ? null : [
            "national"  => array_values(array_filter($legal["general"], fn($r) => $r["ambito"] === "Nacional")),
            "other"     => array_values(array_filter($legal["general"], fn($r) => $r["ambito"] !== "Nacional")),
            "andalucia" => $legal["andalucia"],
        ],
        "description"              => $row["description"],
        "images"                   => json_decode((string) $row["images"], true) ?: [],
        "photoAttribution"         => SPECIES_PHOTO_ATTRIBUTION,
        "taxonRemarks"             => $row["taxonRemarks"],
        "suggestedSensitivity"     => ["level" => $level, "reason" => $reason],
        "detailFetchedAt"          => $row["detail_fetched_at"],
    ];
}

function speciesClassificationOut(array $row): array {
    return [
        "kingdom" => $row["kingdom"], "phylum" => $row["phylum"], "class" => $row["class"],
        "order" => $row["order"], "family" => $row["family"], "genus" => $row["genus"],
        "source" => $row["classification_source"],
    ];
}
