<?php
// ================================================================================================
// Genera shared/eidos-filters.json: las opciones de los filtros del buscador con los valores REALES de
// EIDOS (grupos, orígenes, ámbitos, marcos legales y categorías UICN / Art. 17 con su etiqueta).
// Se ejecuta a mano cuando cambie EIDOS; el JSON se versiona y lo sirve GET /api/species/filters.
//
//   docker compose exec -T php php bin/build-filters.php --stdout > shared/eidos-filters.json
//   php backend/bin/build-filters.php          (en el anfitrión: escribe shared/eidos-filters.json)
//
// En el contenedor shared/ está montado solo lectura: de ahí el --stdout.
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Lib/eidos.php";

const OUT_FILE = __DIR__ . "/../../shared/eidos-filters.json";

/** Filtro `legal` del buscador (mismas claves que SPECIES_LEGAL_FILTERS de Lib/species.php). */
const LEGAL = [
    "rd139"    => ["needle" => "139/2011",  "label" => "RD 139/2011 · LESRPE y Catálogo Español de Especies Amenazadas", "short" => "LESRPE / CEEA"],
    "habitats" => ["needle" => "92/43",     "label" => "Directiva Hábitats (92/43/CEE)", "short" => "Dir. Hábitats"],
    "aves"     => ["needle" => "2009/147",  "label" => "Directiva Aves (2009/147/CE)", "short" => "Dir. Aves"],
    "rd630"    => ["needle" => "630/2013",  "label" => "RD 630/2013 · Catálogo Español de Especies Exóticas Invasoras", "short" => "Exóticas invasoras (RD 630)"],
    "ue1143"   => ["needle" => "1143/2014", "label" => "Reglamento (UE) 1143/2014 · especies exóticas invasoras", "short" => "Reg. UE 1143/2014"],
];
const ORIGINS = ["Especie nativa" => "Nativa", "Especie no nativa" => "No nativa", "Desconocido" => "Desconocido"];
const SCOPES = ["T" => "Terrestre", "M" => "Marino", "MT" => "Marino y terrestre"];
const IUCN = ["EX", "EW", "RE", "CR", "EN", "VU", "NT", "LC", "DD", "NA", "NE"];
const ART17 = ["FV", "U1", "U2", "XX"];

$stdout = in_array("--stdout", $argv, true);
$log = fn(string $m) => fwrite(STDERR, $m . "\n");

/** Número de filas de una vista con esos filtros (Prefer: count=exact). */
function eidosCount(string $view, array $params, string $base = "especie"): int {
    return eidosRequest($view, $params + ["limit" => 1, "select" => array_key_first($params)], $base, true)[1] ?? 0;
}

/** Etiqueta en castellano de un literal de categoría: «VU (Vulnerable)» → «Vulnerable». Limpia comillas sueltas. */
function categoryLabel(string $literal, string $code): string {
    $label = preg_match('/^\S+\s*\((.*)\)\s*$/su', trim($literal), $m) ? $m[1] : $literal;
    return trim(preg_replace('/^' . preg_quote($code, "/") . '\s*/', "", str_replace(['"', "\r", "\n"], "", $label)));
}

/** Literal real de una categoría para una autoridad, o null si EIDOS no la usa. */
function categoryLiteral(string $authority, string $code): ?string {
    $rows = eidosRows("v_estadoconservacion", [
        "autoridad" => "eq.{$authority}", "categoriaconservacion" => "like.{$code} (*",
        "select" => "categoriaconservacion", "limit" => 1,
    ]);
    return $rows[0]["categoriaconservacion"] ?? null;
}

// --- Grupos taxonómicos ---------------------------------------------------------------------------
$log("Grupos…");
$groups = [];
foreach (eidosRows("v_taxonomic_group_api", [], "catalogo") as $r) {
    if (($r["taxonomicgroup"] ?? "") === "") continue;
    $groups[] = ["value" => $r["taxonomicgroup"], "label" => $r["taxonomicgroup"]];
}
usort($groups, fn($a, $b) => strcoll($a["label"], $b["label"]));

// --- Orígenes y ámbitos: se comprueba que cada valor exista en v_taxonomia ------------------------
$log("Orígenes y ámbitos…");
$accepted = ["nametype" => "eq." . EIDOS_ACCEPTED];
$origins = [];
foreach (ORIGINS as $value => $label) {
    $n = eidosCount("v_taxonomia", ["origen" => "eq.{$value}"] + $accepted);
    if ($n > 0) $origins[] = ["value" => $value, "label" => $label, "count" => $n];
}
$scopes = [];
foreach (SCOPES as $value => $label) {
    $n = eidosCount("v_taxonomia", ["areacode" => "eq.{$value}"] + $accepted);
    if ($n > 0) $scopes[] = ["value" => $value, "label" => $label, "count" => $n];
}

// --- Marcos legales: título real de la norma en catalogo/v_norma ----------------------------------
$log("Marcos legales…");
$legal = [];
foreach (LEGAL as $value => $def) {
    $rows = eidosRows("v_norma", ["norma" => "ilike.*{$def["needle"]}*", "select" => "norma,anio", "limit" => 20], "catalogo");
    // La norma «madre» cita su número al principio del título (las demás son modificaciones o
    // reglamentos de ejecución que la mencionan más adelante); a igualdad, la de título más corto.
    usort($rows, fn($a, $b) => [mb_strpos($a["norma"], $def["needle"]), mb_strlen($a["norma"])]
                           <=> [mb_strpos($b["norma"], $def["needle"]), mb_strlen($b["norma"])]);
    $legal[] = ["value" => $value, "label" => $def["label"], "short" => $def["short"], "norma" => $rows[0]["norma"] ?? null];
}

// --- Categorías UICN y Art. 17 con la etiqueta que usa EIDOS --------------------------------------
$log("Categorías…");
$iucn = [];
foreach (IUCN as $code) {
    $lit = categoryLiteral("UICN", $code);
    if ($lit !== null) $iucn[] = ["value" => $code, "label" => categoryLabel($lit, $code)];
}
$art17 = [];
foreach (ART17 as $code) {
    $lit = categoryLiteral("Art. 17 (Dir. Hábitats)", $code);
    if ($lit !== null) $art17[] = ["value" => $code, "label" => categoryLabel($lit, $code)];
}

$out = [
    "generatedAt" => date("c"),
    "source"      => "EIDOS (iepnb.gob.es/api) · backend/bin/build-filters.php",
    "groups"      => $groups,
    "origins"     => $origins,
    "scopes"      => $scopes,
    "legal"       => $legal,
    "iucn"        => $iucn,
    "art17"       => $art17,
];
$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

if ($stdout) { echo $json; exit(0); }
if (@file_put_contents(OUT_FILE, $json) === false) {
    $log("FAIL no puedo escribir " . OUT_FILE . " (¿solo lectura? usa --stdout y redirige)");
    exit(1);
}
$log("OK   " . realpath(OUT_FILE) . ": " . count($groups) . " grupos, " . count($iucn) . " categorías UICN");
