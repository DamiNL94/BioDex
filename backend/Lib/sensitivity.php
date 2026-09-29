<?php
// ================================================================================================
// sensitivity.php — niveles de sensibilidad y generalización de coordenadas (shared/sensitivity.json).
// ------------------------------------------------------------------------------------------------
// SEGURIDAD: applyVisibility() es la ÚNICA función que decide qué coordenadas salen del servidor.
// Todo endpoint que devuelva o exporte observaciones tiene que pasar cada una por aquí.
// El registro original nunca se toca: se trabaja siempre sobre copias.
// Ante un nivel desconocido se falla hacia lo más restrictivo (nivel 1, sin coordenadas).
// ================================================================================================

require_once __DIR__ . "/eidos.php";   // eidosCategoryCode(): código UICN a partir del literal

const SENSITIVITY_JSON = __DIR__ . "/../../shared/sensitivity.json";

/** Metros por grado de latitud (y de longitud en el ecuador). Aproximación esférica suficiente aquí. */
const METERS_PER_DEGREE = 111320;

/** Reglas de shared/sensitivity.json (se leen una vez por petición). */
function sensitivityConfig(): array {
    static $config = null;
    if ($config === null) {
        $config = json_decode((string) @file_get_contents(SENSITIVITY_JSON), true);
        if (!is_array($config) || !isset($config["levels"])) {
            throw new RuntimeException("No se pudo leer " . SENSITIVITY_JSON);
        }
    }
    return $config;
}

/** Nivel válido (1–4) o 1 si no lo es: ante la duda, lo más restrictivo. */
function normalizeSensitivityLevel($level): int {
    $level = filter_var($level, FILTER_VALIDATE_INT);
    return ($level !== false && $level >= 1 && $level <= 4) ? $level : 1;
}

/**
 * Nivel sugerido a partir de EIDOS → [level, reason].
 *   · UICN CR en cualquier ámbito vigente → 2; EN o VU → 3 (por CÓDIGO, nunca por el literal).
 *   · estadolegal exacto «CEEA: En peligro de extinción» → 2; «CEEA: Vulnerable» → 3, solo si la norma
 *     es el RD 139/2011 con ámbito Nacional y sin ccaa.
 *   · Si aplican varias, gana la más restrictiva; si ninguna, 4. El nivel 1 nunca se sugiere (solo manual).
 *
 * $iucnAssessments: la salida de eidosConservation() (ámbito → {code, label, year, criteria}) o una
 * lista de evaluaciones con `code`/`label` y `scope`. $legalStatus: la salida de eidosLegal() o una
 * lista de filas de v_estadolegal.
 */
function suggestSensitivityLevel(array $iucnAssessments, array $legalStatus): array {
    $level = 4;
    $reasons = [];
    $consider = function (int $candidate, string $reason) use (&$level, &$reasons) {
        if ($candidate < $level) { $level = $candidate; $reasons = []; }
        if ($candidate === $level) $reasons[] = $reason;
    };

    $iucnLevels = ["CR" => 2, "EN" => 3, "VU" => 3];
    foreach ($iucnAssessments as $scopeKey => $a) {
        if (!is_array($a)) continue;
        if (isset($a["vigencia"]) && $a["vigencia"] !== "Vigente") continue;
        $code = $a["code"] ?? eidosCategoryCode($a["label"] ?? $a["categoriaconservacion"] ?? null);
        if (!isset($iucnLevels[$code])) continue;
        $scope = $a["scope"] ?? $a["aplicaa"] ?? (is_string($scopeKey) ? $scopeKey : "?");
        $year  = isset($a["year"]) ? " {$a["year"]}" : "";
        $consider($iucnLevels[$code], "UICN {$code} ({$scope}{$year})");
    }

    $ceeaLevels = ["CEEA: En peligro de extinción" => 2, "CEEA: Vulnerable" => 3];
    foreach (legalRows($legalStatus) as $r) {
        $status = $r["estadolegal"] ?? null;
        if (!isset($ceeaLevels[$status])) continue;
        if (isset($r["idvigente"]) && (int) $r["idvigente"] !== 1) continue;
        if (($r["ambito"] ?? null) !== "Nacional" || !empty($r["ccaa"])) continue;
        if (!str_contains((string) ($r["norma"] ?? ""), "139/2011")) continue;
        $consider($ceeaLevels[$status], "{$status} (RD 139/2011)");
    }

    $reason = $level === 4 ? "Sin categoría UICN amenazada ni protección CEEA" : implode("; ", array_unique($reasons));
    return [$level, $reason];
}

/** Filas de estado legal tanto de la salida de eidosLegal() (vigentes/nacional/...) como de una lista. */
function legalRows(array $legalStatus): array {
    if (array_is_list($legalStatus)) return $legalStatus;
    $rows = [];
    foreach (["vigentes", "nacional", "peninsular", "autonomico"] as $k) {
        foreach ($legalStatus[$k] ?? [] as $r) if (is_array($r)) $rows[] = $r;
    }
    return $rows;
}

/** ¿Ve este usuario la coordenada exacta? El autor siempre; si no, según exactAccess.roles. */
function canSeeExact(string $role, bool $isAuthor): bool {
    return $isAuthor || in_array($role, sensitivityConfig()["exactAccess"]["roles"], true);
}

/**
 * Coordenadas generalizadas para un nivel → decimalLatitude, decimalLongitude,
 * coordinateUncertaintyInMeters, dataGeneralizations, informationWithheld.
 *   · Nivel 4: sin cambios. Nivel 1: sin coordenadas.
 *   · Niveles 2 y 3: centro de la celda de gridDeg que contiene el punto (floor + medio paso). Es
 *     determinista (misma entrada → misma salida), así que pedir varias veces no afina la posición.
 *   · Incertidumbre = máx(original, semidiagonal de la celda a esa latitud).
 */
function generalizePoint(float $lat, float $lon, int $level, ?int $uncertaintyM): array {
    $level = normalizeSensitivityLevel($level);
    $cfg = sensitivityConfig()["levels"][(string) $level];
    $dwc = $cfg["dwc"] ?? [];
    $out = [
        "decimalLatitude"               => $lat,
        "decimalLongitude"              => $lon,
        "coordinateUncertaintyInMeters" => $uncertaintyM,
        "dataGeneralizations"           => $dwc["dataGeneralizations"] ?? null,
        "informationWithheld"           => $dwc["informationWithheld"] ?? null,
    ];

    if ($level === 1) {
        $out["decimalLatitude"] = $out["decimalLongitude"] = $out["coordinateUncertaintyInMeters"] = null;
        return $out;
    }

    $grid = $cfg["gridDeg"] ?? null;
    if ($grid === null) return $out;   // nivel 4

    $grid = (float) $grid;
    $decimals = (int) round(-log10($grid)) + 1;   // 0,1° → centros con 2 decimales (37.05)
    // round(…, 9) antes del floor: 37.1 / 0.1 da 370.99999999999994 en coma flotante.
    $cellLat = round(floor(round($lat / $grid, 9)) * $grid + $grid / 2, $decimals);
    $cellLon = round(floor(round($lon / $grid, 9)) * $grid + $grid / 2, $decimals);

    $dy = $grid * METERS_PER_DEGREE;
    $dx = $grid * METERS_PER_DEGREE * cos(deg2rad($cellLat));
    $halfDiagonal = (int) ceil(sqrt($dx * $dx + $dy * $dy) / 2);

    $out["decimalLatitude"] = $cellLat;
    $out["decimalLongitude"] = $cellLon;
    $out["coordinateUncertaintyInMeters"] = max($uncertaintyM ?? 0, $halfDiagonal);
    return $out;
}

/**
 * Copia de la observación lista para enviar a $viewerId con rol $role, dado el nivel de sensibilidad
 * de la especie en ese catálogo. La ÚNICA función que decide qué coordenadas salen del servidor.
 * $occ: fila de occurrences (necesita user_id, decimalLatitude, decimalLongitude).
 */
function applyVisibility(array $occ, int $level, string $role, int $viewerId): array {
    $level = normalizeSensitivityLevel($level);
    $isAuthor = isset($occ["user_id"]) && (int) $occ["user_id"] === $viewerId;

    if ($level === 4 || canSeeExact($role, $isAuthor)) return $occ;

    $g = generalizePoint(
        (float) $occ["decimalLatitude"],
        (float) $occ["decimalLongitude"],
        $level,
        isset($occ["coordinateUncertaintyInMeters"]) ? (int) $occ["coordinateUncertaintyInMeters"] : null
    );
    return array_merge($occ, $g);
}
