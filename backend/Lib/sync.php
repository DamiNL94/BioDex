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

const SYNC_MAX_ITEMS        = 200;     // marcas + borrados por push
const SYNC_PULL_MAX_LIMIT   = 500;
const SYNC_REMARKS_MAX      = 4000;
const SYNC_FUTURE_TOLERANCE = 86400;   // eventDate / clientUpdatedAt: como mucho 24 h en el futuro
const SYNC_UINT_MAX         = 4294967295;
const SYNC_IUCN_JSON_MAX    = 16384;
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

/**
 * Marca del cuerpo de un push → columnas de occurrences. Lanza SyncRejection si algo no vale.
 * Los términos llegan con su nombre DwC exacto; los que no se conocen se ignoran.
 */
function syncValidateOccurrence(mixed $o): array {
    if (!is_array($o) || array_is_list($o)) syncReject("invalid_item", "Cada marca debe ser un objeto.");
    if (!isUuidV4($o["occurrenceID"] ?? null)) syncReject("invalid_uuid", "«occurrenceID» debe ser un UUID v4 en minúsculas.");

    $taxonID = $o["taxonID"] ?? null;
    if (is_int($taxonID)) $taxonID = (string) $taxonID;
    if (!is_string($taxonID) || $taxonID === "" || strlen($taxonID) > 64) syncReject("invalid_taxon", "«taxonID» es obligatorio.");

    $lifeStage = $o["lifeStage"] ?? null;
    if ($lifeStage !== null && (!is_string($lifeStage) || mb_strlen($lifeStage) > 64)) {
        syncReject("invalid_field", "«lifeStage» debe ser texto de 64 caracteres como mucho.");
    }
    $remarks = $o["occurrenceRemarks"] ?? null;
    if ($remarks !== null && (!is_string($remarks) || mb_strlen($remarks) > SYNC_REMARKS_MAX)) {
        syncReject("invalid_field", "«occurrenceRemarks» debe ser texto de " . SYNC_REMARKS_MAX . " caracteres como mucho.");
    }
    $iucn = $o["iucn_assessment"] ?? null;
    if ($iucn !== null) {
        if (!is_array($iucn)) syncReject("invalid_field", "«iucn_assessment» debe ser un objeto JSON.");
        $iucn = json_encode($iucn, JSON_UNESCAPED_UNICODE);
        if ($iucn === false || strlen($iucn) > SYNC_IUCN_JSON_MAX) syncReject("invalid_field", "«iucn_assessment» no es serializable o es demasiado grande.");
    }

    return [
        "occurrenceID"                  => $o["occurrenceID"],
        "taxonID"                       => $taxonID,
        "eventDate"                     => syncUtcDate($o["eventDate"] ?? null, "eventDate")->format("Y-m-d H:i:s"),
        "decimalLatitude"               => syncCoordinate($o, "decimalLatitude", 90),
        "decimalLongitude"              => syncCoordinate($o, "decimalLongitude", 180),
        "coordinateUncertaintyInMeters" => syncUint($o, "coordinateUncertaintyInMeters", true),
        "individualCount"               => syncUint($o, "individualCount", false),
        "lifeStage"                     => $lifeStage === null || trim($lifeStage) === "" ? null : trim($lifeStage),
        "occurrenceRemarks"             => $remarks === null || trim($remarks) === "" ? null : $remarks,
        "mature_count"                  => syncUint($o, "mature_count", false),
        "iucn_assessment"               => $iucn,
        "client_updated_at"             => syncClientUpdatedAt($o["clientUpdatedAt"] ?? null),
    ];
}

/** Borrado del cuerpo de un push → [occurrenceID, client_updated_at]. */
function syncValidateDelete(mixed $d): array {
    if (!is_array($d) || array_is_list($d)) syncReject("invalid_item", "Cada borrado debe ser un objeto.");
    if (!isUuidV4($d["occurrenceID"] ?? null)) syncReject("invalid_uuid", "«occurrenceID» debe ser un UUID v4 en minúsculas.");
    return [$d["occurrenceID"], syncClientUpdatedAt($d["clientUpdatedAt"] ?? null)];
}

function syncIsoUtc(?string $mysqlDatetime): ?string {
    if ($mysqlDatetime === null) return null;
    $d = new DateTimeImmutable($mysqlDatetime, new DateTimeZone("UTC"));
    return str_contains($mysqlDatetime, ".") ? $d->format("Y-m-d\TH:i:s.v\Z") : $d->format("Y-m-d\TH:i:s\Z");
}

/**
 * Observación tal como sale hacia $viewerId (rol $role en el catálogo), con el nivel de su especie.
 * Borradas: solo identidad y metadatos de sync, sin coordenadas ni datos. $row necesita además
 * `recorded_by` (nombre del autor).
 */
function occurrenceOut(array $row, int $level, string $role, int $viewerId): array {
    $meta = [
        "occurrenceID"    => $row["occurrenceID"],
        "taxonID"         => $row["taxonID"],
        "userId"          => (int) $row["user_id"],
        "clientUpdatedAt" => syncIsoUtc($row["client_updated_at"]),
        "serverSeq"       => (int) $row["server_seq"],
    ];
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
        "individualCount"               => $int($v["individualCount"]),
        "lifeStage"                     => $v["lifeStage"],
        "occurrenceRemarks"             => $v["occurrenceRemarks"],
        "mature_count"                  => $int($v["mature_count"]),
        "iucn_assessment"               => $v["iucn_assessment"] === null ? null : json_decode($v["iucn_assessment"], true),
        "generalized"                   => $generalized,
    ] + ($generalized ? [
        "dataGeneralizations" => $v["dataGeneralizations"],
        "informationWithheld" => $v["informationWithheld"],
    ] : []);
}
