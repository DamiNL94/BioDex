<?php
// ================================================================================================
// Pruebas de F11f (parámetros de especie): docker compose exec php php bin/test-f11f.php
// GET/PUT/DELETE /api/catalogs/{id}/species/{taxonID}/parameters[/{key}]: permisos por rol, validación,
// valor por defecto del grupo (shared/species-groups.json), access_log (set_parameter) y el pull de
// /api/sync (parámetros y su borrado como tombstone). Imprime OK/FAIL y sale con 1 si alguna falla.
// Consulta EIDOS (Artemisia granatensis) en vivo si no está en caché.
// Crea usuarios f11f-*@biodex.test y el catálogo «test-f11f …», y los borra al terminar (y al empezar).
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/api.php";
require_once __DIR__ . "/../Lib/migrate.php";
require_once __DIR__ . "/test-lib.php";

const ARTEMISIA = "9918";   // planta con flor: separación por defecto 1 km

$pdo = db();
runMigrations($pdo);

function cleanup(PDO $pdo): void {
    $in = "SELECT id FROM catalogs WHERE name LIKE 'test-f11f %'";
    $pdo->exec("DELETE FROM species_parameters WHERE catalog_id IN ({$in})");
    $pdo->exec("DELETE FROM access_log WHERE catalog_id IN ({$in})");
    $pdo->exec("DELETE FROM catalogs WHERE name LIKE 'test-f11f %'");
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f11f-%@biodex.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f11f-%@biodex.test'");
}
cleanup($pdo);

/** Número de la respuesta (JSON no distingue 8 de 8.0). */
function num(mixed $v): ?float { return is_int($v) || is_float($v) ? (float) $v : null; }

function byKey(array $list, string $key): ?array {
    foreach ($list as $p) if (($p["key"] ?? null) === $key) return $p;
    return null;
}

// ------------------------------------------------------------------------------------------------
section("Usuarios y catálogo");
$suffix = bin2hex(random_bytes(4));
$jars = ["o" => [], "c" => [], "e" => [], "v" => [], "x" => []];
$ids = [];
foreach ($jars as $k => &$jar) {
    $email = "f11f-{$k}-{$suffix}@biodex.test";
    [$code] = http("POST", "/api/auth/register", ["email" => $email, "password" => "correcta-horse-battery", "name" => "F11f " . strtoupper($k)], $jar);
    check("registro de " . strtoupper($k), $code === 201, "HTTP {$code}");
    $ids[$k] = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();
}
unset($jar);
[$o, $cu, $e, $v, $x] = [&$jars["o"], &$jars["c"], &$jars["e"], &$jars["v"], &$jars["x"]];

[$code, $r] = http("POST", "/api/catalogs", ["name" => "test-f11f {$suffix}", "taxonIDs" => [ARTEMISIA]], $o);
check("O crea el catálogo con la Artemisia", $code === 201, "HTTP {$code} " . json_encode($r));
$cat = $r["catalog"]["id"] ?? "";
$ins = $pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role, granted_by) VALUES (?, ?, ?, ?)");
foreach (["c" => "custodian", "e" => "editor", "v" => "viewer"] as $k => $role) $ins->execute([$cat, $ids[$k], $role, $ids["o"]]);

$base = "/api/catalogs/{$cat}/species/" . ARTEMISIA . "/parameters";
$put = fn(array &$jar, string $key, array $body) => http("PUT", "{$base}/{$key}", $body, $jar);
$logs = fn() => (int) $pdo->query("SELECT COUNT(*) FROM access_log WHERE action = 'set_parameter' AND catalog_id = " . $pdo->quote($cat))->fetchColumn();
$pull = function (array &$jar, int $since = 0) use ($cat) {
    [$c, $r] = http("GET", "/api/sync/pull?catalogId={$cat}&since={$since}", null, $jar);
    return [$c, $r];
};

// ------------------------------------------------------------------------------------------------
section("Lectura y valores por defecto");
[$code, $r] = http("GET", $base, null, $v);
check("el lector ve los parámetros (vacíos) → 200", $code === 200 && ($r["parameters"] ?? null) === [], "HTTP {$code} " . json_encode($r));
check("…con la separación por defecto del grupo (planta con flor, 1 km)",
    num($r["defaults"]["separation_distance"]["value"] ?? null) === 1.0 && ($r["defaults"]["separation_distance"]["group"] ?? null) === "flowering-plant",
    json_encode($r["defaults"] ?? null));
check("…y no puede editarlos (canEdit false)", ($r["canEdit"] ?? null) === false);
[$code, $r] = http("GET", $base, null, $cu);
check("el custodio sí puede editar (canEdit true)", $code === 200 && ($r["canEdit"] ?? null) === true);
[$code] = http("GET", $base, null, $x);
check("un no miembro → 403", $code === 403, "HTTP {$code}");
[$code] = http("GET", "/api/catalogs/{$cat}/species/11943/parameters", null, $o);
check("especie fuera del catálogo → 404", $code === 404, "HTTP {$code}");

// ------------------------------------------------------------------------------------------------
section("Fijar, cambiar y borrar");
$seq0 = (int) $pdo->query("SELECT value FROM sync_seq WHERE id = 1")->fetchColumn();
[$code, $r] = $put($cu, "generation_length", ["value" => 8, "valueMin" => 5, "valueMax" => 12, "quality" => "estimated",
    "source" => "Blanca et al. (2009), Flora amenazada y endémica de Sierra Nevada"]);
$p = $r["parameter"] ?? [];
check("el custodio fija la duración de la generación → 200", $code === 200 && num($p["value"] ?? null) === 8.0
    && num($p["valueMin"] ?? null) === 5.0 && num($p["valueMax"] ?? null) === 12.0 && ($p["quality"] ?? null) === "estimated"
    && ($p["setById"] ?? null) === $ids["c"] && ($p["setBy"] ?? null) === "F11f C" && ($r["changed"] ?? null) === true, json_encode($r));
check("…con server_seq nuevo del contador común", ($p["serverSeq"] ?? 0) > $seq0);
check("…y una fila set_parameter en access_log", $logs() === 1);
$target = $pdo->query("SELECT target FROM access_log WHERE action = 'set_parameter' AND catalog_id = " . $pdo->quote($cat))->fetchColumn();
check("…con el antes y el después", str_starts_with((string) $target, ARTEMISIA . ": generation_length vacío → 8 (5–12) años · estimated"), (string) $target);

[$code, $r] = $put($cu, "generation_length", ["value" => 8, "valueMin" => 5, "valueMax" => 12, "quality" => "estimated",
    "source" => "Blanca et al. (2009), Flora amenazada y endémica de Sierra Nevada"]);
check("el mismo valor otra vez → changed false, sin registro nuevo", $code === 200 && ($r["changed"] ?? null) === false && $logs() === 1, json_encode($r));

[$code, $r] = $put($o, "separation_distance", ["value" => "1,5", "quality" => "inferred"]);
check("el owner confirma la separación (coma decimal) → 1.5 km", $code === 200 && num($r["parameter"]["value"] ?? null) === 1.5, json_encode($r));
[$code, $r] = $put($o, "mature_definition", ["value" => "Roseta florida (ramet); los clones de un mismo rizoma cuentan por separado", "quality" => "observed"]);
check("texto: definición de individuo maduro", $code === 200 && str_starts_with((string) ($r["parameter"]["value"] ?? ""), "Roseta florida"), json_encode($r));
[$code, $r] = $put($o, "life_form", ["value" => "perennial"]);
check("lista: forma de vida perenne", $code === 200 && ($r["parameter"]["value"] ?? null) === "perennial");
[$code, $r] = $put($o, "min_viable_subpop", ["valueMin" => 50, "valueMax" => 250, "quality" => "suspected"]);
check("solo intervalo (sin valor central) → value null", $code === 200 && array_key_exists("value", $r["parameter"] ?? []) && $r["parameter"]["value"] === null
    && num($r["parameter"]["valueMin"] ?? null) === 50.0, json_encode($r));

[$code] = $put($e, "generation_length", ["value" => 3]);
check("el editor no puede → 403", $code === 403, "HTTP {$code}");
[$code] = $put($v, "generation_length", ["value" => 3]);
check("el lector no puede → 403", $code === 403, "HTTP {$code}");

$bad = [
    ["clave desconocida → 404", "abundance", ["value" => 3], 404],
    ["número que no es número → 400", "generation_length", ["value" => "muchos"], 400],
    ["fuera de rango (0 años) → 400", "generation_length", ["value" => 0], 400],
    ["intervalo al revés → 400", "dispersal_distance", ["valueMin" => 5, "valueMax" => 1], 400],
    ["valor fuera de su intervalo → 400", "dispersal_distance", ["value" => 9, "valueMin" => 1, "valueMax" => 5], 400],
    ["intervalo a medias → 400", "dispersal_distance", ["value" => 2, "valueMin" => 1], 400],
    ["sin valor → 400", "dispersal_distance", ["quality" => "estimated"], 400],
    ["individuos no enteros → 400", "min_viable_subpop", ["value" => 10.5], 400],
    ["calidad fuera de lista → 400", "generation_length", ["value" => 3, "quality" => "adivinado"], 400],
    ["forma de vida fuera de lista → 400", "life_form", ["value" => "arbusto"], 400],
    ["texto vacío → 400", "mature_definition", ["value" => "   "], 400],
    ["fuente de 1001 caracteres → 400", "generation_length", ["value" => 3, "source" => str_repeat("x", 1001)], 400],
];
foreach ($bad as [$label, $key, $body, $want]) {
    [$code] = $put($o, $key, $body);
    check($label, $code === $want, "HTTP {$code}");
}
[$code] = http("POST", "{$base}/generation_length", ["value" => 3], $o);
check("POST → 405", $code === 405, "HTTP {$code}");
[$code] = http("GET", "/api/catalogs/{$cat}/species/" . ARTEMISIA . "/sensitivity/generation_length", null, $o);
check("una clave colgando de otra ruta → 404", $code === 404, "HTTP {$code}");

[$code, $r] = http("GET", $base, null, $e);
check("el editor ve los cinco con valor", $code === 200 && count($r["parameters"] ?? []) === 5
    && byKey($r["parameters"], "separation_distance")["setBy"] === "F11f O", json_encode($r));

// ------------------------------------------------------------------------------------------------
section("Pull de /api/sync");
[$code, $r] = $pull($e);
$params = $r["parameters"] ?? null;
check("el pull desde 0 trae los parámetros", $code === 200 && is_array($params) && count($params) === 5, "HTTP {$code} " . json_encode($params));
check("…con la forma de la API", num(byKey($params ?? [], "generation_length")["valueMax"] ?? null) === 12.0
    && (byKey($params ?? [], "generation_length")["deleted"] ?? null) === false);
$cursor = (int) ($r["cursor"] ?? 0);

[$code, $r] = http("DELETE", "{$base}/life_form", null, $cu);
check("el custodio deja la forma de vida sin valor → 200", $code === 200 && array_key_exists("parameter", $r ?? []) && $r["parameter"] === null && ($r["changed"] ?? null) === true, json_encode($r));
check("…y queda registrado (5 altas + el borrado; repetir el mismo valor no cuenta)", $logs() === 6, (string) $logs());
[$code, $r] = http("DELETE", "{$base}/life_form", null, $cu);
check("borrar lo que ya está vacío → changed false", $code === 200 && ($r["changed"] ?? null) === false && $logs() === 6);
[$code, $r] = $pull($e, $cursor);
$del = byKey($r["parameters"] ?? [], "life_form");
check("el pull incremental trae el borrado como tombstone", $del && ($del["deleted"] ?? false) === true && !array_key_exists("value", $del), json_encode($r["parameters"] ?? null));
check("…y nada más", count($r["parameters"] ?? []) === 1);
[$code, $r] = http("GET", $base, null, $e);
check("GET ya no lo lista", count($r["parameters"] ?? []) === 4);
[$code, $r] = $put($o, "life_form", ["value" => "biennial"]);
check("volver a fijarlo revive la fila", $code === 200 && ($r["parameter"]["value"] ?? null) === "biennial");
[$code] = $pull($x);
check("un no miembro no recibe nada del pull → 403", $code === 403, "HTTP {$code}");

// Registro del catálogo: la acción nueva con el nombre de la especie.
[$code, $r] = http("GET", "/api/catalogs/{$cat}/audit", null, $o);
$entry = null;
foreach ($r["items"] ?? [] as $it) if ($it["action"] === "set_parameter") { $entry = $it; break; }
check("el Registro muestra el parámetro con el nombre de la especie", $code === 200 && $entry
    && str_starts_with($entry["detail"], "Artemisia granatensis"), json_encode($entry));

// ------------------------------------------------------------------------------------------------
cleanup($pdo);
echo "\n" . ($failures ? "{$failures} comprobación(es) fallida(s)." : "Todo OK.") . "\n";
exit($failures ? 1 : 0);
