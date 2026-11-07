<?php
// ================================================================================================
// parameters.php — parámetros de una especie en un catálogo (F11f, migración 014, docs/parametros.md).
// ------------------------------------------------------------------------------------------------
// Características que necesitan los criterios UICN y que BioDex no puede deducir de las marcas: duración
// de la generación, distancias de separación y de dispersión, qué es un individuo maduro, tamaño mínimo
// viable de una subpoblación y forma de vida. Son de la especie EN EL CATÁLOGO (dos equipos pueden usar
// valores distintos para la misma especie). Los editan owner y custodio; el resto los lee.
// Cada valor: value (número o texto), value_min/value_max opcionales (intervalo), quality (observado ·
// estimado · inferido · sospechado · proyectado, vocabulario de las Directrices UICN), source (texto libre o
// referencia), set_by, set_at. Se sincronizan por el pull de /api/sync (server_seq común, con borrados).
// `separation_distance` tiene valor por defecto por grupo (shared/species-groups.json,
// default_separation_km): sale como «por defecto del grupo» hasta que alguien lo confirme.
// ================================================================================================

require_once __DIR__ . "/species.php";   // speciesGroupsConfig(), speciesSilhouette()
require_once __DIR__ . "/sync.php";      // syncNextSeq(), syncIsoUtc()

/**
 * Definición de cada parámetro. type: number (value numérico) · text · choice (uno de `choices`).
 * criteria: lo que bloquea si falta (se pinta en la ficha y en la pestaña Evaluación UICN, F11g).
 * fact (F11g): «sí · no · desconocido» (PARAMETER_FACT_CHOICES), con calidad, fuente y justificación.
 */
const SPECIES_PARAMETERS = [
    "generation_length"   => ["type" => "number", "unit" => "años", "min" => 0.1, "max" => 500,
                              "criteria" => ["A", "C1"]],
    "separation_distance" => ["type" => "number", "unit" => "km", "min" => 0.001, "max" => 10000,
                              "criteria" => ["B (subpoblaciones)", "C2a"]],
    "dispersal_distance"  => ["type" => "number", "unit" => "km", "min" => 0.001, "max" => 10000,
                              "criteria" => ["B (fragmentación)"]],
    "mature_definition"   => ["type" => "text", "unit" => null, "max" => 2000,
                              "criteria" => ["C", "D"]],
    "min_viable_subpop"   => ["type" => "number", "unit" => "individuos maduros", "min" => 1, "max" => 100000000,
                              "integer" => true, "criteria" => ["B (fragmentación)"]],
    "life_form"           => ["type" => "choice", "unit" => null,
                              "choices" => ["perennial", "annual", "biennial", "monocarpic", "other"],
                              "criteria" => []],
    // F11g: superficie de hábitat ocupado (maduros estimados = densidad × superficie; sin ella, la AOO como cota).
    "occupied_habitat_km2" => ["type" => "number", "unit" => "km²", "min" => 0.000001, "max" => 10000000,
                               "criteria" => ["C, D (maduros estimados)"]],
    // F11g: hechos de B y C. BioDex puede SUGERIR uno (b(iii) con impactos en curso), pero solo cuenta
    // cuando alguien lo fija aquí.
    "decline_b_i"   => ["type" => "fact", "unit" => null, "criteria" => ["B b(i)"]],
    "decline_b_ii"  => ["type" => "fact", "unit" => null, "criteria" => ["B b(ii)"]],
    "decline_b_iii" => ["type" => "fact", "unit" => null, "criteria" => ["B b(iii)"]],
    "decline_b_iv"  => ["type" => "fact", "unit" => null, "criteria" => ["B b(iv)"]],
    "decline_b_v"   => ["type" => "fact", "unit" => null, "criteria" => ["B b(v)", "C2"]],
    "fluct_c_i"     => ["type" => "fact", "unit" => null, "criteria" => ["B c(i)"]],
    "fluct_c_ii"    => ["type" => "fact", "unit" => null, "criteria" => ["B c(ii)"]],
    "fluct_c_iii"   => ["type" => "fact", "unit" => null, "criteria" => ["B c(iii)"]],
    "fluct_c_iv"    => ["type" => "fact", "unit" => null, "criteria" => ["B c(iv)", "C2b"]],
    "plausible_threat" => ["type" => "fact", "unit" => null, "criteria" => ["D2"]],
    // F11g: ajuste regional (Directrices regionales v4.0, paso 2).
    "regional_endemic"              => ["type" => "fact", "unit" => null, "criteria" => ["Ajuste regional"]],
    "regional_immigration"          => ["type" => "fact", "unit" => null, "criteria" => ["Ajuste regional"]],
    "regional_immigration_decrease" => ["type" => "fact", "unit" => null, "criteria" => ["Ajuste regional"]],
    "regional_sink"                 => ["type" => "fact", "unit" => null, "criteria" => ["Ajuste regional"]],
];
const PARAMETER_FACT_CHOICES = ["yes", "no", "unknown"];
const PARAMETER_JUSTIFICATION_MAX = 4000;
const PARAMETER_QUALITIES = ["observed", "estimated", "inferred", "suspected", "projected"];
const PARAMETER_SOURCE_MAX = 1000;

/** Parámetro tal como sale (API y pull). Borrado: solo identidad y metadatos. */
function parameterOut(array $r): array {
    $base = [
        "taxonID"   => (string) $r["taxonID"],
        "key"       => $r["param_key"],
        "serverSeq" => (int) $r["server_seq"],
        "setAt"     => syncIsoUtc($r["set_at"]),
    ];
    if ($r["deleted_at"] !== null) return $base + ["deleted" => true];
    $num = fn($v) => $v === null ? null : (float) $v;
    $def = SPECIES_PARAMETERS[$r["param_key"]] ?? ["type" => "text"];
    $value = $r["value"];
    if ($value !== null && $def["type"] === "number") $value = (float) $value;
    return $base + [
        "deleted"  => false,
        "value"    => $value,
        "valueMin" => $num($r["value_min"]),
        "valueMax" => $num($r["value_max"]),
        "quality"  => $r["quality"],
        "source"   => $r["source"],
        "justification" => $r["justification"] ?? null,
        "setById"  => $r["set_by"] !== null ? (int) $r["set_by"] : null,
        "setBy"    => $r["set_by_name"] ?? null,
    ];
}

/** SELECT con el nombre de quien lo fijó; $where con marcadores. */
function parameterSelect(string $where): string {
    return "SELECT p.*, u.name AS set_by_name FROM species_parameters p LEFT JOIN users u ON u.id = p.set_by WHERE {$where}";
}

/**
 * Valores por defecto de la especie → {separation_distance: {value, unit, group, groupLabel} | null}.
 * El grupo es la silueta (misma regla que la foto de respaldo); sin especie en caché, sin valor.
 */
function parameterDefaults(PDO $pdo, string $taxonID): array {
    $stmt = $pdo->prepare("SELECT kingdom, phylum, class, `order` FROM species WHERE taxonID = ?");
    $stmt->execute([$taxonID]);
    $row = $stmt->fetch();
    if (!$row) return ["separation_distance" => null];
    $group = speciesSilhouette($row);
    $km = speciesGroupsConfig()["default_separation_km"][$group["id"]] ?? null;
    return ["separation_distance" => $km === null ? null
        : ["value" => (float) $km, "unit" => "km", "group" => $group["id"], "groupLabel" => $group["label"]]];
}

/** Número de un campo, o error 400. null si viene vacío. */
function parameterNumber(mixed $v, string $field, array $def): ?float {
    if ($v === null || $v === "") return null;
    if (is_string($v)) $v = str_replace(",", ".", trim($v));
    if (!is_numeric($v)) apiError("«{$field}» debe ser un número.", 400);
    $n = (float) $v;
    if (!is_finite($n) || $n < $def["min"] || $n > $def["max"]) {
        apiError("«{$field}» debe estar entre {$def["min"]} y {$def["max"]}" . ($def["unit"] ? " {$def["unit"]}" : "") . ".", 400);
    }
    if (!empty($def["integer"]) && floor($n) !== $n) apiError("«{$field}» debe ser un número entero.", 400);
    return $n;
}

/**
 * Valida el cuerpo de un PUT → columnas. {value, valueMin?, valueMax?, quality?, source?}. Un número
 * admite solo intervalo (value null con valueMin y valueMax): se guarda el intervalo y value queda nulo.
 */
function parameterFields(string $key, array $b): array {
    $def = SPECIES_PARAMETERS[$key];
    $min = $max = null;
    if ($def["type"] === "number") {
        $value = parameterNumber($b["value"] ?? null, "value", $def);
        $min = parameterNumber($b["valueMin"] ?? null, "valueMin", $def);
        $max = parameterNumber($b["valueMax"] ?? null, "valueMax", $def);
        if (($min === null) !== ($max === null)) apiError("El intervalo necesita «valueMin» y «valueMax».", 400);
        if ($min !== null && $min > $max) apiError("«valueMin» no puede ser mayor que «valueMax».", 400);
        if ($value !== null && $min !== null && ($value < $min || $value > $max)) {
            apiError("«value» tiene que estar dentro del intervalo.", 400);
        }
        if ($value === null && $min === null) apiError("Falta «value» (o el intervalo «valueMin»–«valueMax»).", 400);
        $value = $value === null ? null : rtrim(rtrim(sprintf("%.6F", $value), "0"), ".");
    } elseif ($def["type"] === "choice" || $def["type"] === "fact") {
        $choices = $def["choices"] ?? PARAMETER_FACT_CHOICES;
        $value = $b["value"] ?? null;
        if (!is_string($value) || !in_array($value, $choices, true)) {
            apiError("«value» debe ser uno de: " . implode(", ", $choices) . ".", 400);
        }
    } else {
        $value = trim((string) ($b["value"] ?? ""));
        if ($value === "") apiError("Falta «value».", 400);
        if (mb_strlen($value) > $def["max"]) apiError("«value» admite {$def["max"]} caracteres.", 400);
    }
    $quality = $b["quality"] ?? null;
    if ($quality === "") $quality = null;
    if ($quality !== null && !in_array($quality, PARAMETER_QUALITIES, true)) {
        apiError("«quality» debe ser uno de: " . implode(", ", PARAMETER_QUALITIES) . ".", 400);
    }
    $source = trim((string) ($b["source"] ?? ""));
    if (mb_strlen($source) > PARAMETER_SOURCE_MAX) apiError("«source» admite " . PARAMETER_SOURCE_MAX . " caracteres.", 400);
    $justification = trim((string) ($b["justification"] ?? ""));
    if (mb_strlen($justification) > PARAMETER_JUSTIFICATION_MAX) {
        apiError("«justification» admite " . PARAMETER_JUSTIFICATION_MAX . " caracteres.", 400);
    }
    return ["value" => $value, "value_min" => $min, "value_max" => $max, "quality" => $quality,
            "source" => $source === "" ? null : $source, "justification" => $justification === "" ? null : $justification];
}

/** Texto corto de un valor para access_log: «3 años (2–4) · estimado». */
function parameterDescribe(?array $r): string {
    if ($r === null || $r["deleted_at"] !== null) return "vacío";
    $def = SPECIES_PARAMETERS[$r["param_key"]] ?? ["unit" => null];
    $fmt = fn($v) => rtrim(rtrim((string) $v, "0"), ".");
    $s = $r["value"] !== null ? mb_substr((string) $r["value"], 0, 60) : "";
    if ($r["value_min"] !== null) $s .= ($s !== "" ? " " : "") . "(" . $fmt($r["value_min"]) . "–" . $fmt($r["value_max"]) . ")";
    if (!empty($def["unit"]) && $def["type"] === "number") $s .= " {$def["unit"]}";
    return $s . ($r["quality"] ? " · {$r["quality"]}" : "");
}
