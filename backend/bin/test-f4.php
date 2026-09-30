<?php
// ================================================================================================
// Pruebas de F4 (buscador, filtros, tarjetas y alta de catálogo con especies):
//   docker compose exec php php bin/test-f4.php
// Imprime OK/FAIL por comprobación y sale con código 1 si alguna falla. Consulta EIDOS en vivo.
// Crea usuarios f4-*@biodex.test y catálogos «test-f4 …», y los borra al terminar.
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/migrate.php";
require_once __DIR__ . "/test-lib.php";

const LYNX = "11943";
const AQUILA_ADALBERTI = "11014";
const ARTEMISIA = "9918";

$pdo = db();
runMigrations($pdo);

function cleanup(PDO $pdo): void {
    // También los catálogos de f4-cdp-* (recorrido del navegador), que no llevan el prefijo test-f4.
    $mine = "SELECT c.id FROM catalogs c JOIN users u ON u.id = c.owner_id WHERE c.name LIKE 'test-f4 %' OR u.email LIKE 'f4-%@biodex.test'";
    $pdo->exec("DELETE FROM access_log WHERE catalog_id IN (SELECT id FROM ({$mine}) t)");
    $pdo->exec("DELETE FROM catalogs WHERE id IN (SELECT id FROM ({$mine}) t)");
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f4-%@biodex.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f4-%@biodex.test'");
}
cleanup($pdo);

function item(?array $body, string $taxonID): ?array {
    foreach ($body["items"] ?? [] as $i) if ($i["taxonID"] === $taxonID) return $i;
    return null;
}

// ------------------------------------------------------------------------------------------------
section("Usuario");
$a = [];
$suffix = bin2hex(random_bytes(4));
[$c] = http("POST", "/api/auth/register", ["email" => "f4-a-{$suffix}@biodex.test", "password" => "correcta-horse-battery",
    "name" => "Prueba F4"], $a);
check("registro", $c === 201, "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
section("Filtros");
$anon = [];
[$c] = http("GET", "/api/species/filters", null, $anon);
check("filtros sin sesión → 401", $c === 401, "HTTP {$c}");
[$c, $r, $h] = http("GET", "/api/species/filters", null, $a);
check("GET /api/species/filters → 200", $c === 200, "HTTP {$c}");
check("…con Cache-Control max-age=86400", str_contains($h["cache-control"] ?? "", "max-age=86400"), $h["cache-control"] ?? "(sin cabecera)");
$values = fn(string $k) => array_column($r[$k] ?? [], "value");
check("…grupos reales de EIDOS (Aves, Mamíferos, Plantas vasculares)",
    !array_diff(["Aves", "Mamíferos", "Plantas vasculares"], $values("groups")), json_encode($values("groups"), JSON_UNESCAPED_UNICODE));
check("…orígenes y ámbitos T/M/MT con etiqueta", in_array("Especie nativa", $values("origins"), true)
    && $values("scopes") === ["T", "M", "MT"] && ($r["scopes"][0]["label"] ?? "") === "Terrestre");
check("…los cinco marcos legales", $values("legal") === ["rd139", "habitats", "aves", "rd630", "ue1143"], json_encode($values("legal")));
$iucnLabels = array_column($r["iucn"] ?? [], "label", "value");
check("…UICN con etiqueta en castellano (CR = En peligro crítico)", ($iucnLabels["CR"] ?? "") === "En peligro crítico", json_encode($iucnLabels, JSON_UNESCAPED_UNICODE));
check("…y Art. 17 (FV, U1, U2, XX)", $values("art17") === ["FV", "U1", "U2", "XX"]);
$lv = array_column($r["sensitivityLevels"] ?? [], null, "level");
check("…y niveles de sensibilidad (2 → ~10 km)", ($lv[2]["approxKm"] ?? null) === 10 && count($lv) === 4, json_encode($lv));

// ------------------------------------------------------------------------------------------------
section("Tarjetas del buscador");
// Aquila adalberti es EN (Península): con category=CR no debe salir.
[$c, $r] = http("GET", "/api/species?q=aquila&category=CR&legal=rd139", null, $a);
check("«aquila» + CR + rd139 → sin Aquila adalberti (es EN)", $c === 200 && item($r, AQUILA_ADALBERTI) === null, "HTTP {$c}");
[$c, $r] = http("GET", "/api/species?q=aguila&group=Aves", null, $a);
$ad = item($r, AQUILA_ADALBERTI);
check("«aguila» + Aves → Aquila adalberti", $c === 200 && $ad !== null, "HTTP {$c}");
check("…nombre canónico y autor por separado", ($ad["canonicalName"] ?? "") === "Aquila adalberti"
    && ($ad["scientificNameAuthorship"] ?? "") !== "" && !str_contains($ad["canonicalName"] ?? "", $ad["scientificNameAuthorship"] ?? "§"),
    json_encode([$ad["canonicalName"] ?? null, $ad["scientificNameAuthorship"] ?? null], JSON_UNESCAPED_UNICODE));
check("…foto de la fototeca (thumbnail por el proxy, F5a)", str_starts_with((string) ($ad["thumbnail"] ?? ""), "/img/fototeca/"), (string) ($ad["thumbnail"] ?? "null"));
check("…extracto de descripción", mb_strlen((string) ($ad["descriptionExcerpt"] ?? "")) > 40 && mb_strlen($ad["descriptionExcerpt"]) <= 330);
$scopes = array_column($ad["iucnAssessments"] ?? [], "scope");
check("…UICN de todos los ámbitos, la principal primero", count($scopes) >= 2 && $scopes[0] === ($ad["iucnPrimary"]["scope"] ?? "?"),
    json_encode($ad["iucnAssessments"] ?? null, JSON_UNESCAPED_UNICODE));
check("…familia (para el filtro local)", ($ad["family"] ?? "") === "Accipitridae");

[$c, $r] = http("GET", "/api/species?q=lince&category=CR&legal=rd139", null, $a);
$bad = array_filter($r["items"] ?? [], fn($i) => ($i["iucnPrimary"]["code"] ?? null) !== "CR");
check("category=CR + legal=rd139 → solo CR", $c === 200 && !$bad, "HTTP {$c} " . json_encode(array_column($r["items"] ?? [], "taxonID")));
check("…con Lynx pardinus (CR en España, CEEA)", item($r, LYNX) !== null, json_encode(array_column($r["items"] ?? [], "taxonID")));

// ------------------------------------------------------------------------------------------------
section("Alta de catálogo con especies");
[$c, $r] = http("POST", "/api/catalogs", ["name" => "test-f4 Constructor", "taxonIDs" => [LYNX, ARTEMISIA, AQUILA_ADALBERTI, LYNX]], $a);
$cat = $r["catalog"]["id"] ?? "00000000-0000-4000-8000-000000000000";
check("POST con taxonIDs → 201 y 3 especies (repetidos fuera)", $c === 201 && ($r["catalog"]["speciesCount"] ?? 0) === 3, "HTTP {$c} " . json_encode($r));
[$c, $r] = http("GET", "/api/catalogs/{$cat}", null, $a);
$levels = array_column(array_map(fn($s) => ["t" => $s["taxonID"], "l" => $s["sensitivity"]["suggestedLevel"]], $r["species"] ?? []), "l", "t");
check("…cada una con su nivel sugerido (los tres 2)", $levels == [LYNX => 2, ARTEMISIA => 2, AQUILA_ADALBERTI => 2], json_encode($levels));
[$c, $r] = http("GET", "/api/catalogs", null, $a);
$mine = array_values(array_filter($r["catalogs"] ?? [], fn($x) => $x["id"] === $cat))[0] ?? [];
check("…aparece en Mis catálogos con 3 especies", ($mine["speciesCount"] ?? 0) === 3 && ($mine["role"] ?? "") === "owner");

$before = (int) $pdo->query("SELECT COUNT(*) FROM catalogs WHERE name LIKE 'test-f4 %'")->fetchColumn();
[$c, $r] = http("POST", "/api/catalogs", ["name" => "test-f4 Roto", "taxonIDs" => [LYNX, "999999999"]], $a);
$after = (int) $pdo->query("SELECT COUNT(*) FROM catalogs WHERE name LIKE 'test-f4 %'")->fetchColumn();
check("un taxón inexistente → 404 con «missing»", $c === 404 && ($r["missing"] ?? []) === ["999999999"], "HTTP {$c} " . json_encode($r));
check("…y no se crea nada (todo o nada)", $after === $before, "{$before} → {$after}");
[$c] = http("POST", "/api/catalogs", ["name" => "test-f4 Mal", "taxonIDs" => "11943"], $a);
check("taxonIDs que no es lista → 400", $c === 400, "HTTP {$c}");
[$c] = http("POST", "/api/catalogs", ["name" => "test-f4 Mal", "taxonIDs" => [["x"]]], $a);
check("taxonIDs con objetos → 400", $c === 400, "HTTP {$c}");
[$c, $r] = http("POST", "/api/catalogs", ["name" => "test-f4 Vacío", "taxonIDs" => []], $a);
check("taxonIDs vacía → 201 con 0 especies", $c === 201 && ($r["catalog"]["speciesCount"] ?? -1) === 0, "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
section("Nivel: autor del último cambio");
[$c, $r] = http("PUT", "/api/catalogs/{$cat}/species/" . ARTEMISIA . "/sensitivity", ["level" => 4], $a);
check("bajar 2 → 4 sin motivo → 422", $c === 422, "HTTP {$c}");
[$c, $r] = http("PUT", "/api/catalogs/{$cat}/species/" . ARTEMISIA . "/sensitivity", ["level" => 3, "reason" => "Prueba F4"], $a);
$s = $r["sensitivity"] ?? [];
check("con motivo → 200 con setBy y setAt", $c === 200 && ($s["setBy"] ?? "") === "Prueba F4" && !empty($s["setAt"]) && ($s["reason"] ?? "") === "Prueba F4",
    "HTTP {$c} " . json_encode($s, JSON_UNESCAPED_UNICODE));
[$c, $r] = http("GET", "/api/catalogs/{$cat}", null, $a);
$art = array_values(array_filter($r["species"] ?? [], fn($x) => $x["taxonID"] === ARTEMISIA))[0] ?? [];
check("…también en GET /catalogs/{id}", ($art["sensitivity"]["setBy"] ?? "") === "Prueba F4");
$lynx = array_values(array_filter($r["species"] ?? [], fn($x) => $x["taxonID"] === LYNX))[0] ?? [];
check("…sin cambio manual, setBy null", array_key_exists("setBy", $lynx["sensitivity"] ?? []) && $lynx["sensitivity"]["setBy"] === null);

// ------------------------------------------------------------------------------------------------
cleanup($pdo);
echo "\n" . ($failures === 0 ? "TODO OK" : "{$failures} FALLO(S)") . "\n";
exit($failures === 0 ? 0 : 1);
