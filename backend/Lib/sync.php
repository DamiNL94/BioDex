<?php
// ================================================================================================
// sync.php — validación de marcas del móvil, contador server_seq y salida de observaciones.
// ------------------------------------------------------------------------------------------------
// Protocolo y reglas de conflicto: docs/sync.md. Resumen:
//   · Cada marca se valida sola: una inválida se rechaza con motivo y el resto del lote sigue.
//   · Gana la edición con el client_updated_at más reciente; con el mismo, «unchanged» sin tocar nada.
//   · Cada inserción o cambio toma server_seq de sync_seq DENTRO de su transacción. El bloqueo de la
//     fila de sync_seq dura hasta el COMMIT, así que los server_seq se confirman en orden y un pull
//     por cursor nunca se salta uno que aún no estaba confirmado.
//   · Toda coordenada que sale pasa por applyVisibility() (occurrenceOut()).
// ================================================================================================

require_once __DIR__ . "/sensitivity.php";
require_once __DIR__ . "/iucn.php";          // amenazas UICN de los impactos (F11g)

const SYNC_MAX_ITEMS        = 200;     // marcas + borrados por push
const SYNC_PULL_MAX_LIMIT   = 500;
const SYNC_REMARKS_MAX      = 4000;
const SYNC_FUTURE_TOLERANCE = 86400;   // eventDate / clientUpdatedAt: como mucho 24 h en el futuro
const SYNC_UINT_MAX         = 4294967295;
const SYNC_HABITAT_MAX      = 500;
const SYNC_GEOREF_REMARKS_MAX = 255;
const SYNC_NOTE_MAX         = 4000;
// Evaluaciones UICN (F8b)
const SYNC_ASSESSMENT_JSON_MAX = 32768;   // inputs y result, cada uno (result lleva el detalle por criterio)
const SYNC_JUSTIFICATION_MAX   = 4000;
const ASSESSMENT_CATEGORIES    = ["CR", "EN", "VU", "NT", "LC", "DD"];
const ASSESSMENT_STATUSES      = ["draft", "final"];
const ASSESSMENT_ADJUSTMENTS   = ["down", "up"];
// Impactos (F11g) y superficie contada de una marca
const SYNC_SAMPLE_AREA_MAX     = 100000000;   // m² (100 km²)
const SYNC_IMPACT_RADIUS_MAX   = 100000;      // m
const SYNC_IMPACT_POLYGON_MAX  = 200;         // vértices
const SYNC_IMPACT_SPECIES_MAX  = 200;
const IMPACT_INTENSITIES       = ["low", "medium", "high"];
const IMPACT_TIMINGS           = ["past", "ongoing", "future"];

// Vocabularios cerrados (F8a). lifeStage y sex: vocabulario de GBIF; las etiquetas en castellano son
// cosa de la interfaz (frontend/js/views/mark-sheet.js).
const OCC_STATUS      = ["present", "absent"];
const OCC_LIFE_STAGES = ["adult", "juvenile", "subadult", "larva", "egg", "seedling", "flowering", "fruiting", "vegetative", "undetermined"];
const OCC_SEXES       = ["male", "female", "hermaphrodite", "undetermined"];
const GEOREF_GPS      = "GPS del dispositivo";
const GEOREF_MAP      = "Punto marcado en el mapa";
const GEOREF_PROTOCOLS = [GEOREF_GPS, GEOREF_MAP];
const UUID_V4_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
// ISO 8601 con zona obligatoria: una hora sin zona no se puede pasar a UTC sin adivinar.
const ISO_DATETIME_ZONED_RE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})$/';

/** Error de validación de un elemento del lote: motivo (código estable) y detalle legible. */
final class SyncRejection extends Exception {
    public function __construct(public readonly string $reason, string $detail) {
        parent::__construct($detail);
    }
}

function syncReject(string $reason, string $detail): never {
    throw new SyncRejection($reason, $detail);
}

function isUuidV4(mixed $v): bool {
    return is_string($v) && preg_match(UUID_V4_RE, $v) === 1;
}

/** Siguiente server_seq. Llamar DENTRO de la transacción de la escritura (ver cabecera). */
function syncNextSeq(PDO $pdo): int {
    $pdo->exec("UPDATE sync_seq SET value = LAST_INSERT_ID(value + 1) WHERE id = 1");
    return (int) $pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
}

/** Fecha ISO 8601 con zona → DateTimeImmutable en UTC, o rechazo. No admite más de 24 h en el futuro. */
function syncUtcDate(mixed $v, string $field): DateTimeImmutable {
    if (!is_string($v) || !preg_match(ISO_DATETIME_ZONED_RE, $v)) {
        syncReject("invalid_date", "«{$field}» debe ser una fecha ISO 8601 con zona (p. ej. 2026-05-01T08:30:00+02:00).");
    }
    try {
        $d = (new DateTimeImmutable($v))->setTimezone(new DateTimeZone("UTC"));
    } catch (Exception) {
        syncReject("invalid_date", "«{$field}» no es una fecha válida.");
    }
    if ((int) $d->format("Y") < 1900) syncReject("invalid_date", "«{$field}» es anterior a 1900.");
    if ($d->getTimestamp() > time() + SYNC_FUTURE_TOLERANCE) {
        syncReject("future_date", "«{$field}» está más de 24 h en el futuro.");
    }
    return $d;
}

/** Hora de edición del dispositivo en el formato de client_updated_at (UTC, milisegundos). */
function syncClientUpdatedAt(mixed $v): string {
    return syncUtcDate($v, "clientUpdatedAt")->format("Y-m-d H:i:s.v");
}

/** Entero no negativo (admite 3.0, no 3.5 ni "3"); null si falta y es opcional. */
function syncUint(array $o, string $field, bool $required): ?int {
    $v = $o[$field] ?? null;
    if ($v === null) {
        if ($required) syncReject("missing_field", "Falta «{$field}».");
        return null;
    }
    if (is_float($v) && is_finite($v) && floor($v) === $v) $v = (int) $v;
    if (!is_int($v) || $v < 0 || $v > SYNC_UINT_MAX) syncReject("invalid_integer", "«{$field}» debe ser un entero no negativo.");
    return $v;
}

function syncCoordinate(array $o, string $field, float $limit): float {
    $v = $o[$field] ?? null;
    if (!is_int($v) && !is_float($v)) syncReject("invalid_coordinates", "«{$field}» debe ser un número.");
    if (!is_finite((float) $v) || abs($v) > $limit) syncReject("invalid_coordinates", "«{$field}» fuera de rango (±{$limit}).");
    return (float) $v;
}

/** Texto opcional de como mucho $max caracteres: null si falta o está vacío. */
function syncText(array $o, string $field, int $max): ?string {
    $v = $o[$field] ?? null;
    if ($v === null) return null;
    if (!is_string($v) || mb_strlen($v) > $max) syncReject("invalid_field", "«{$field}» debe ser texto de {$max} caracteres como mucho.");
    return trim($v) === "" ? null : $v;
}

/** Valor de un vocabulario cerrado; null si falta (y $default no se da). */
function syncEnum(array $o, string $field, array $allowed, ?string $default = null): ?string {
    $v = $o[$field] ?? null;
    if ($v === null || $v === "") return $default;
    if (!is_string($v) || !in_array($v, $allowed, true)) {
        syncReject("invalid_vocabulary", "«{$field}» debe ser uno de: " . implode(", ", $allowed) . ".");
    }
    return $v;
}

/** taxonID obligatorio (entero de EIDOS como texto o biodex:<uuid>). */
function syncTaxonID(array $o): string {
    $taxonID = $o["taxonID"] ?? null;
    if (is_int($taxonID)) $taxonID = (string) $taxonID;
    if (!is_string($taxonID) || $taxonID === "" || strlen($taxonID) > 64) syncReject("invalid_taxon", "«taxonID» es obligatorio.");
    return $taxonID;
}

/**
 * Marca del cuerpo de un push → columnas de occurrences. Lanza SyncRejection si algo no vale.
 * Los términos llegan con su nombre DwC exacto; los que no se conocen se ignoran (también el antiguo
 * iucn_assessment: la evaluación UICN es de la especie, F8b).
 * Reglas entre campos (F8a): mature_count ≤ individualCount (y exige individualCount); una ausencia
 * lleva individualCount 0 o nulo y ningún maduro; un punto marcado en el mapa, precisión ≥ 1 m.
 */
function syncValidateOccurrence(mixed $o): array {
    if (!is_array($o) || array_is_list($o)) syncReject("invalid_item", "Cada marca debe ser un objeto.");
    if (!isUuidV4($o["occurrenceID"] ?? null)) syncReject("invalid_uuid", "«occurrenceID» debe ser un UUID v4 en minúsculas.");
    $taxonID = syncTaxonID($o);

    $status    = syncEnum($o, "occurrenceStatus", OCC_STATUS, "present");
    $protocol  = syncEnum($o, "georeferenceProtocol", GEOREF_PROTOCOLS, GEOREF_GPS);
    $count     = syncUint($o, "individualCount", false);
    $mature    = syncUint($o, "mature_count", false);
    $uncertain = syncUint($o, "coordinateUncertaintyInMeters", true);

    if ($status === "absent") {
        if ($count !== null && $count !== 0) syncReject("invalid_absence", "Una ausencia no puede tener individuos («individualCount» 0 o vacío).");
        if ($mature !== null) syncReject("invalid_absence", "Una ausencia no puede tener individuos maduros («mature_count»).");
    } elseif ($mature !== null) {
        if ($count === null) syncReject("invalid_mature_count", "«mature_count» exige «individualCount».");
        if ($mature > $count) syncReject("invalid_mature_count", "«mature_count» ({$mature}) no puede ser mayor que «individualCount» ({$count}).");
    }
    if ($protocol === GEOREF_MAP && $uncertain < 1) {
        syncReject("missing_field", "Un punto marcado en el mapa necesita la precisión estimada («coordinateUncertaintyInMeters» ≥ 1).");
    }
    // F11g: superficie contada (m²): la marca es una parcela y su densidad sirve para estimar maduros.
    $area = syncPositiveNumber($o, "sampleSizeValue", SYNC_SAMPLE_AREA_MAX);
    if ($area !== null && $status === "absent") syncReject("invalid_absence", "Una ausencia no lleva superficie contada («sampleSizeValue»).");
    // F11h: visita durante la que se hizo (null = marca oportunista). Que exista y sea del catálogo lo mira Api/sync.php.
    $visit = $o["visitID"] ?? null;
    if ($visit !== null && !isUuidV4($visit)) syncReject("invalid_uuid", "«visitID» debe ser el UUID v4 de una visita o null.");

    return [
        "occurrenceID"                  => $o["occurrenceID"],
        "taxonID"                       => $taxonID,
        "eventDate"                     => syncUtcDate($o["eventDate"] ?? null, "eventDate")->format("Y-m-d H:i:s"),
        "decimalLatitude"               => syncCoordinate($o, "decimalLatitude", 90),
        "decimalLongitude"              => syncCoordinate($o, "decimalLongitude", 180),
        "coordinateUncertaintyInMeters" => $uncertain,
        "occurrenceStatus"              => $status,
        "individualCount"               => $count,
        "lifeStage"                     => syncEnum($o, "lifeStage", OCC_LIFE_STAGES),
        "sex"                           => syncEnum($o, "sex", OCC_SEXES),
        "habitat"                       => syncText($o, "habitat", SYNC_HABITAT_MAX),
        "georeferenceRemarks"           => syncText($o, "georeferenceRemarks", SYNC_GEOREF_REMARKS_MAX),
        "georeferenceProtocol"          => $protocol,
        "occurrenceRemarks"             => syncText($o, "occurrenceRemarks", SYNC_REMARKS_MAX),
        "mature_count"                  => $mature,
        "sampleSizeValue"               => $area,
        "visit_id"                      => $visit,
        "client_updated_at"             => syncClientUpdatedAt($o["clientUpdatedAt"] ?? null),
    ];
}

/** Número > 0 y ≤ $max (admite enteros y decimales); null si falta. */
function syncPositiveNumber(array $o, string $field, float $max): ?float {
    $v = $o[$field] ?? null;
    if ($v === null) return null;
    if ((!is_int($v) && !is_float($v)) || !is_finite((float) $v) || $v <= 0 || $v > $max) {
        syncReject("invalid_field", "«{$field}» debe ser un número mayor que 0 y como mucho {$max}.");
    }
    return (float) $v;
}

/**
 * Impacto del cuerpo de un push (F11g) → columnas de impacts + `_species` (taxonIDs afectados; los escribe
 * syncPushItem en impact_species). Punto + radio o polígono (uno de los dos); amenaza del esquema UICN v3.3.
 */
function syncValidateImpact(mixed $i): array {
    if (!is_array($i) || array_is_list($i)) syncReject("invalid_item", "Cada impacto debe ser un objeto.");
    if (!isUuidV4($i["impactID"] ?? null)) syncReject("invalid_uuid", "«impactID» debe ser un UUID v4 en minúsculas.");
    $code = $i["threatCode"] ?? null;
    if (!is_string($code) || !isset(iucnThreatLabels()[$code])) syncReject("invalid_vocabulary", "«threatCode» debe ser un código del esquema de amenazas de la UICN (p. ej. 2.3).");
    $quick = $i["quickKey"] ?? null;
    if ($quick !== null && !in_array($quick, array_column(iucnThreats()["quick"], "key"), true)) syncReject("invalid_vocabulary", "«quickKey» desconocido.");
    $radius = syncUint($i, "radiusM", false);
    if ($radius !== null && ($radius < 1 || $radius > SYNC_IMPACT_RADIUS_MAX)) syncReject("invalid_field", "«radiusM» debe estar entre 1 y " . SYNC_IMPACT_RADIUS_MAX . " m.");
    $polygon = $i["polygon"] ?? null;
    if ($polygon !== null) {
        if (!is_array($polygon) || !array_is_list($polygon) || count($polygon) < 3 || count($polygon) > SYNC_IMPACT_POLYGON_MAX) {
            syncReject("invalid_field", "«polygon» debe ser una lista de 3 a " . SYNC_IMPACT_POLYGON_MAX . " vértices [lat, lon].");
        }
        foreach ($polygon as $v) {
            if (!is_array($v) || count($v) !== 2 || !is_numeric($v[0]) || !is_numeric($v[1]) || abs($v[0]) > 90 || abs($v[1]) > 180) {
                syncReject("invalid_coordinates", "Cada vértice de «polygon» es [lat, lon].");
            }
        }
        $polygon = array_map(fn($v) => [round((float) $v[0], 6), round((float) $v[1], 6)], $polygon);
    }
    if (($radius === null) === ($polygon === null)) syncReject("missing_field", "Un impacto lleva radio («radiusM») o polígono («polygon»), uno de los dos.");
    $scope = $i["scopePct"] ?? null;
    if ($scope !== null && ((!is_int($scope) && !is_float($scope)) || $scope < 0 || $scope > 100)) syncReject("invalid_field", "«scopePct» debe estar entre 0 y 100.");
    $species = $i["species"] ?? [];
    if (!is_array($species) || !array_is_list($species) || count($species) > SYNC_IMPACT_SPECIES_MAX) {
        syncReject("invalid_field", "«species» debe ser una lista de como mucho " . SYNC_IMPACT_SPECIES_MAX . " taxonID.");
    }
    $taxa = [];
    foreach ($species as $t) {
        if (is_int($t)) $t = (string) $t;
        if (!is_string($t) || $t === "" || strlen($t) > 64) syncReject("invalid_taxon", "Cada especie de «species» es un taxonID.");
        $taxa[$t] = true;
    }
    return [
        "impactID"          => $i["impactID"],
        "threat_code"       => $code,
        "quick_key"         => $quick,
        "decimalLatitude"   => syncCoordinate($i, "decimalLatitude", 90),
        "decimalLongitude"  => syncCoordinate($i, "decimalLongitude", 180),
        "radius_m"          => $radius,
        "polygon"           => $polygon === null ? null : json_encode($polygon),
        "event_date"        => syncUtcDate($i["eventDate"] ?? null, "eventDate")->format("Y-m-d H:i:s"),
        "intensity"         => syncEnum($i, "intensity", IMPACT_INTENSITIES) ?? syncReject("missing_field", "Falta «intensity» (low, medium, high)."),
        "scope_pct"         => $scope === null ? null : (float) $scope,
        "timing"            => syncEnum($i, "timing", IMPACT_TIMINGS) ?? syncReject("missing_field", "Falta «timing» (past, ongoing, future)."),
        "note"              => syncText($i, "note", SYNC_NOTE_MAX),
        "client_updated_at" => syncClientUpdatedAt($i["clientUpdatedAt"] ?? null),
        "_species"          => array_keys($taxa),
    ];
}

/**
 * Impacto tal como sale hacia $viewerId (F11g). Su sitio delata dónde viven las especies afectadas, así que
 * sigue la regla de applyVisibility() con el nivel MÁS restrictivo de esas especies en el catálogo (sin
 * especies, 4): a quien no es el autor ni ve exactas, el centro generalizado, el radio ampliado a la celda y
 * sin polígono. $row lleva `recorded_by`; $species = taxonIDs; $level = nivel mínimo.
 */
function impactOut(array $row, array $species, int $level, string $role, int $viewerId): array {
    $meta = [
        "impactID"        => $row["impactID"],
        "userId"          => (int) $row["user_id"],
        "clientUpdatedAt" => syncIsoUtc($row["client_updated_at"]),
        "serverSeq"       => (int) $row["server_seq"],
    ];
    if ($row["deleted_at"] !== null) return $meta + ["deleted" => true];
    $v = applyVisibility(["user_id" => $row["user_id"], "decimalLatitude" => $row["decimalLatitude"], "decimalLongitude" => $row["decimalLongitude"],
                          "coordinateUncertaintyInMeters" => $row["radius_m"] !== null ? (int) $row["radius_m"] : null], $level, $role, $viewerId);
    $generalized = array_key_exists("dataGeneralizations", $v);
    $polygon = $row["polygon"] !== null ? json_decode($row["polygon"], true) : null;
    return $meta + [
        "deleted"          => false,
        "recordedBy"       => $row["recorded_by"] ?? null,
        "threatCode"       => $row["threat_code"],
        "quickKey"         => $row["quick_key"],
        "decimalLatitude"  => $v["decimalLatitude"] === null ? null : (float) $v["decimalLatitude"],
        "decimalLongitude" => $v["decimalLongitude"] === null ? null : (float) $v["decimalLongitude"],
        "radiusM"          => $generalized ? ($v["coordinateUncertaintyInMeters"] ?? null) : ($row["radius_m"] !== null ? (int) $row["radius_m"] : null),
        "polygon"          => $generalized ? null : $polygon,
        "eventDate"        => syncIsoUtc($row["event_date"]),
        "intensity"        => $row["intensity"],
        "scopePct"         => $row["scope_pct"] === null ? null : (float) $row["scope_pct"],
        "timing"           => $row["timing"],
        "note"             => $row["note"],
        "species"          => array_values($species),
        "generalized"      => $generalized,
    ];
}

/**
 * Impactos con sus especies y el nivel mínimo de ellas → [[row, species[], level]]. $where sobre el alias i.
 */
function impactRows(PDO $pdo, string $where, array $params, string $catalogId, string $suffix = ""): array {
    $stmt = $pdo->prepare("SELECT i.*, u.name AS recorded_by FROM impacts i JOIN users u ON u.id = i.user_id WHERE {$where} {$suffix}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (!$rows) return [];
    $ids = array_column($rows, "impactID");
    $stmt = $pdo->prepare("SELECT s.impactID, s.taxonID, ss.level FROM impact_species s
                             LEFT JOIN species_sensitivity ss ON ss.catalog_id = ? AND ss.taxonID = s.taxonID
                            WHERE s.impactID IN (" . implode(",", array_fill(0, count($ids), "?")) . ") ORDER BY s.taxonID");
    $stmt->execute([$catalogId, ...$ids]);
    $sp = [];
    foreach ($stmt->fetchAll() as $r) $sp[$r["impactID"]][] = [(string) $r["taxonID"], normalizeSensitivityLevel($r["level"])];
    return array_map(function ($r) use ($sp) {
        $list = $sp[$r["impactID"]] ?? [];
        return [$r, array_column($list, 0), $list ? min(array_column($list, 1)) : 4];
    }, $rows);
}

/** Nota de especie del cuerpo de un push → columnas de species_notes. */
function syncValidateNote(mixed $n): array {
    if (!is_array($n) || array_is_list($n)) syncReject("invalid_item", "Cada nota debe ser un objeto.");
    if (!isUuidV4($n["noteID"] ?? null)) syncReject("invalid_uuid", "«noteID» debe ser un UUID v4 en minúsculas.");
    $text = syncText($n, "text", SYNC_NOTE_MAX);
    if ($text === null) syncReject("missing_field", "La nota está vacía («text»).");
    return [
        "noteID"            => $n["noteID"],
        "taxonID"           => syncTaxonID($n),
        "text"              => $text,
        "client_updated_at" => syncClientUpdatedAt($n["clientUpdatedAt"] ?? null),
    ];
}

/**
 * Evaluación UICN del cuerpo de un push (F8b) → columnas de species_assessments. El servidor no recalcula
 * (el motor es shared/iucn-engine.js, en el dispositivo): comprueba forma, tamaños y coherencia mínima.
 * No toca is_reference: la referencia solo la marca el owner (PUT .../reference-assessment).
 */
function syncValidateAssessment(mixed $a): array {
    if (!is_array($a) || array_is_list($a)) syncReject("invalid_item", "Cada evaluación debe ser un objeto.");
    if (!isUuidV4($a["assessmentID"] ?? null)) syncReject("invalid_uuid", "«assessmentID» debe ser un UUID v4 en minúsculas.");
    $taxonID = syncTaxonID($a);

    $json = [];
    foreach (["inputs", "result"] as $field) {
        $v = $a[$field] ?? null;
        if (!is_array($v)) syncReject("missing_field", "Falta «{$field}» (objeto).");
        $enc = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($enc === false || strlen($enc) > SYNC_ASSESSMENT_JSON_MAX) {
            syncReject("invalid_field", "«{$field}» no puede pasar de " . SYNC_ASSESSMENT_JSON_MAX . " bytes.");
        }
        // Un objeto vacío llega como lista vacía: se guarda como objeto.
        $json[$field] = $v === [] ? "{}" : $enc;
    }
    $result = $a["result"];
    // F11g: una instantánea del motor 2 se recalcula entera en el servidor al subirla (Api/sync.php ›
    // assessmentSnapshot(), con las coordenadas exactas): aquí solo se valida la forma.
    foreach (["category", "baseCategory"] as $k) {
        $cat = $result[$k] ?? null;
        if ($cat !== null && !in_array($cat, ASSESSMENT_CATEGORIES, true)) {
            syncReject("invalid_vocabulary", "«result.{$k}» debe ser una de: " . implode(", ", ASSESSMENT_CATEGORIES) . ".");
        }
    }
    $criteriaString = $result["criteriaString"] ?? null;
    if ($criteriaString !== null && (!is_string($criteriaString) || mb_strlen($criteriaString) > 255)) {
        syncReject("invalid_field", "«result.criteriaString» debe ser texto de 255 caracteres como mucho.");
    }

    $status = syncEnum($a, "status", ASSESSMENT_STATUSES, "draft");
    $adjust = syncEnum($a, "regionalAdjustment", ASSESSMENT_ADJUSTMENTS);
    $justification = syncText($a, "justification", SYNC_JUSTIFICATION_MAX);
    if ($adjust !== null && $justification === null) {
        syncReject("missing_field", "El ajuste regional exige una justificación («justification»).");
    }
    $category = $result["category"] ?? null;
    if ($status === "final" && $category === null) {
        syncReject("invalid_field", "Una evaluación final necesita categoría: si no alcanza umbrales, elige NT, LC o DD.");
    }

    return [
        "assessmentID"        => $a["assessmentID"],
        "taxonID"             => $taxonID,
        "inputs"              => $json["inputs"],
        "result"              => $json["result"],
        "category"            => $category,
        "regional_adjustment" => $adjust,
        "justification"       => $justification,
        "status"              => $status,
        "client_updated_at"   => syncClientUpdatedAt($a["clientUpdatedAt"] ?? null),
    ];
}

/** Borrado del cuerpo de un push → [id, client_updated_at]. $idField: occurrenceID, noteID… */
function syncValidateDelete(mixed $d, string $idField = "occurrenceID"): array {
    if (!is_array($d) || array_is_list($d)) syncReject("invalid_item", "Cada borrado debe ser un objeto.");
    if (!isUuidV4($d[$idField] ?? null)) syncReject("invalid_uuid", "«{$idField}» debe ser un UUID v4 en minúsculas.");
    return [$d[$idField], syncClientUpdatedAt($d["clientUpdatedAt"] ?? null)];
}

function syncIsoUtc(?string $mysqlDatetime): ?string {
    if ($mysqlDatetime === null) return null;
    $d = new DateTimeImmutable($mysqlDatetime, new DateTimeZone("UTC"));
    return str_contains($mysqlDatetime, ".") ? $d->format("Y-m-d\TH:i:s.v\Z") : $d->format("Y-m-d\TH:i:s\Z");
}

/**
 * Identidad y metadatos de sync comunes a marcas, notas y evaluaciones: lo único que sale de una
 * fila borrada. $idField es su clave pública (occurrenceID, noteID o assessmentID).
 */
function syncMeta(array $row, string $idField): array {
    return [
        $idField          => $row[$idField],
        "taxonID"         => $row["taxonID"],
        "userId"          => (int) $row["user_id"],
        "clientUpdatedAt" => syncIsoUtc($row["client_updated_at"]),
        "serverSeq"       => (int) $row["server_seq"],
    ];
}

/**
 * Observación tal como sale hacia $viewerId (rol $role en el catálogo), con el nivel de su especie.
 * Borradas: solo identidad y metadatos de sync, sin coordenadas ni datos. $row necesita además
 * `recorded_by` (nombre del autor).
 */
function occurrenceOut(array $row, int $level, string $role, int $viewerId): array {
    $meta = syncMeta($row, "occurrenceID");
    if ($row["deleted_at"] !== null) return $meta + ["deleted" => true];

    $v = applyVisibility($row, $level, $role, $viewerId);
    // applyVisibility() solo añade dataGeneralizations (aunque sea null) cuando generaliza la copia.
    $generalized = array_key_exists("dataGeneralizations", $v);
    $num = fn($x) => $x === null ? null : (float) $x;
    $int = fn($x) => $x === null ? null : (int) $x;

    return $meta + [
        "deleted"                       => false,
        "recordedBy"                    => $row["recorded_by"] ?? null,
        "eventDate"                     => syncIsoUtc($v["eventDate"]),
        "decimalLatitude"               => $num($v["decimalLatitude"]),
        "decimalLongitude"              => $num($v["decimalLongitude"]),
        "coordinateUncertaintyInMeters" => $int($v["coordinateUncertaintyInMeters"]),
        "geodeticDatum"                 => $v["geodeticDatum"],
        "basisOfRecord"                 => $v["basisOfRecord"],
        "occurrenceStatus"              => $v["occurrenceStatus"],
        "individualCount"               => $int($v["individualCount"]),
        "lifeStage"                     => $v["lifeStage"],
        "sex"                           => $v["sex"],
        "habitat"                       => $v["habitat"],
        "georeferenceProtocol"          => $v["georeferenceProtocol"],
        "georeferenceRemarks"           => $v["georeferenceRemarks"],
        "occurrenceRemarks"             => $v["occurrenceRemarks"],
        "mature_count"                  => $int($v["mature_count"]),
        "sampleSizeValue"               => $num($v["sampleSizeValue"] ?? null),
        "visitID"                       => $row["visit_id"] ?? null,
        "generalized"                   => $generalized,
    ] + ($generalized ? [
        "dataGeneralizations" => $v["dataGeneralizations"],
        "informationWithheld" => $v["informationWithheld"],
    ] : []);
}

/**
 * Nota de especie tal como sale. No lleva coordenadas (no pasa por applyVisibility); el pull solo la
 * manda a miembros con permiso view. Borradas: solo identidad y metadatos. $row necesita `recorded_by`.
 */
function noteOut(array $row): array {
    $meta = syncMeta($row, "noteID");
    if ($row["deleted_at"] !== null) return $meta + ["deleted" => true];
    return $meta + [
        "deleted"    => false,
        "recordedBy" => $row["recorded_by"] ?? null,
        "text"       => $row["text"],
    ];
}

/**
 * Evaluación UICN tal como sale (F8b). Sin coordenadas: EOO/AOO y localidades son números agregados.
 * El pull solo la manda a miembros con view. $row necesita `recorded_by` y `reference_set_by_name`.
 */
function assessmentOut(array $row): array {
    $meta = syncMeta($row, "assessmentID");
    if ($row["deleted_at"] !== null) return $meta + ["deleted" => true];
    $isRef = (int) $row["is_reference"] === 1;
    return $meta + [
        "deleted"            => false,
        "recordedBy"         => $row["recorded_by"] ?? null,
        "inputs"             => json_decode($row["inputs"], true),
        "result"             => json_decode($row["result"], true),
        "category"           => $row["category"],
        "regionalAdjustment" => $row["regional_adjustment"],
        "justification"      => $row["justification"],
        "status"             => $row["status"],
        "isReference"        => $isRef,
        "referenceSetBy"     => $isRef ? ($row["reference_set_by_name"] ?? null) : null,
        "referenceSetAt"     => $isRef ? syncIsoUtc($row["reference_set_at"]) : null,
    ];
}

/** SELECT de evaluaciones con los nombres que necesita assessmentOut(); $where sobre el alias a. */
function assessmentSelect(string $where): string {
    return "SELECT a.*, u.name AS recorded_by, rb.name AS reference_set_by_name
              FROM species_assessments a
              JOIN users u ON u.id = a.user_id
              LEFT JOIN users rb ON rb.id = a.reference_set_by
             WHERE {$where}";
}
