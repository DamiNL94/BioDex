<?php
// ================================================================================================
// Pruebas de F2b (especies y catálogos): docker compose exec php php bin/test-f2b.php
// Imprime OK/FAIL por comprobación y sale con código 1 si alguna falla. Consulta EIDOS y GBIF en vivo
// (tarda ~1 min). Crea usuarios f2b-*@biodex.test, catálogos «test-f2b …» y especies de usuario
// inventadas, y los borra al terminar.
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/migrate.php";
require_once __DIR__ . "/test-lib.php";

const INVENTED = ["Inventadus falsus", "Aquila inventada", "Oophaga inventada"];
const LYNX = "11943";
const AQUILA_ADALBERTI = "11014";
const ARTEMISIA = "9918";

$pdo = db();
runMigrations($pdo);

function cleanup(PDO $pdo): void {
    $pdo->exec("DELETE FROM access_log WHERE catalog_id IN (SELECT id FROM catalogs WHERE name LIKE 'test-f2b %')");
    $pdo->exec("DELETE FROM catalogs WHERE name LIKE 'test-f2b %'");
    $in = implode(",", array_map([$pdo, "quote"], INVENTED));
    $pdo->exec("DELETE FROM species WHERE source = 'user' AND canonical_name IN ({$in})");
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f2b-%@biodex.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f2b-%@biodex.test'");
}
cleanup($pdo);

function ids(?array $body): array {
    return array_column($body["items"] ?? [], "taxonID");
}

/** Recorre el árbol por nombres (reino, filo...) y devuelve el nodo, o null. */
function treeNode(array $tree, array $names): ?array {
    $node = ["children" => $tree];
    foreach ($names as $name) {
        $next = null;
        foreach ($node["children"] ?? [] as $child) if ($child["name"] === $name) $next = $child;
        if ($next === null) return null;
        $node = $next;
    }
    return $node;
}

// ------------------------------------------------------------------------------------------------
section("Usuarios");
$a = [];
$b = [];
$suffix = bin2hex(random_bytes(4));
[$c] = http("POST", "/api/auth/register", ["email" => "f2b-a-{$suffix}@biodex.test", "password" => "correcta-horse-battery"], $a);
check("registro de A", $c === 201, "HTTP {$c}");
[$c] = http("POST", "/api/auth/register", ["email" => "f2b-b-{$suffix}@biodex.test", "password" => "correcta-horse-battery"], $b);
check("registro de B", $c === 201, "HTTP {$c}");
$bId = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote("f2b-b-{$suffix}@biodex.test"))->fetchColumn();

$anon = [];
[$c] = http("GET", "/api/species?q=lince", null, $anon);
check("buscar sin sesión → 401", $c === 401, "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
section("Búsqueda");
[$c, $r] = http("GET", "/api/species?q=lince", null, $a);
check("«lince» → Lynx pardinus", $c === 200 && in_array(LYNX, ids($r), true), "HTTP {$c} " . json_encode(ids($r)));
$lynx = array_values(array_filter($r["items"] ?? [], fn($i) => $i["taxonID"] === LYNX))[0] ?? [];
check("…con la forma de la respuesta", isset($r["total"]) && array_key_exists("thumbnail", $lynx)
    && ($lynx["eidosUrl"] ?? "") === "https://iepnb.gob.es/areas-tematicas/especies-silvestres/eidos/" . LYNX, json_encode($lynx));
check("…y UICN principal España CR (no hay evaluación de Península)",
    ($lynx["iucnPrimary"]["scope"] ?? null) === "España" && ($lynx["iucnPrimary"]["code"] ?? null) === "CR", json_encode($lynx["iucnPrimary"] ?? null));

[$c, $r] = http("GET", "/api/species?q=Aquila&group=Aves", null, $a);
$groups = array_unique(array_column($r["items"] ?? [], "taxonomicGroup"));
check("«Aquila» + group=Aves → Aquila adalberti, todo Aves", $c === 200 && in_array(AQUILA_ADALBERTI, ids($r), true) && $groups === ["Aves"],
    "HTTP {$c} " . json_encode($groups));
$adalberti = array_values(array_filter($r["items"] ?? [], fn($i) => $i["taxonID"] === AQUILA_ADALBERTI))[0] ?? [];
check("Aquila adalberti: UICN principal de Península", ($adalberti["iucnPrimary"]["scope"] ?? null) === "Península",
    json_encode($adalberti["iucnPrimary"] ?? null));

[$c, $r] = http("GET", "/api/species?q=aguila", null, $a);
check("«aguila» sin acento → Aquila adalberti (Águila imperial ibérica)", $c === 200 && in_array(AQUILA_ADALBERTI, ids($r), true),
    "HTTP {$c} total " . ($r["total"] ?? "?"));
[$c, $r2] = http("GET", "/api/species?q=" . rawurlencode("águila"), null, $a);
check("«águila» con acento da el mismo total", $c === 200 && ($r2["total"] ?? -1) === ($r["total"] ?? -2),
    ($r["total"] ?? "?") . " vs " . ($r2["total"] ?? "?"));

[$c, $r] = http("GET", "/api/species?q=lince&category=CR", null, $a);
$codes = array_unique(array_map(fn($i) => $i["iucnPrimary"]["code"] ?? null, $r["items"] ?? []));
check("«lince» + category=CR → Lynx pardinus y solo CR", $c === 200 && in_array(LYNX, ids($r), true) && $codes === ["CR"],
    "HTTP {$c} " . json_encode($codes));

[$c, $r] = http("GET", "/api/species?q=aquila&legal=rd139", null, $a);
$adalberti = array_values(array_filter($r["items"] ?? [], fn($i) => $i["taxonID"] === AQUILA_ADALBERTI))[0] ?? null;
check("«aquila» + legal=rd139 → Aquila adalberti con detalle", $c === 200 && $adalberti !== null && empty($adalberti["detailPending"]),
    "HTTP {$c} " . json_encode(ids($r)));
$pending = count(array_filter($r["items"] ?? [], fn($i) => !empty($i["detailPending"])));
$fetchedNow = (int) $pdo->query("SELECT COUNT(*) FROM species WHERE legal_fetched_at >= NOW() - INTERVAL 2 MINUTE
                                  AND detail_fetched_at IS NULL")->fetchColumn();
check("como mucho 5 estados legales pedidos por búsqueda", $fetchedNow <= 5, "{$fetchedNow} ({$pending} pendientes)");
$stmt = $pdo->prepare("SELECT legal_status FROM species WHERE taxonID = ?");
$stmt->execute([AQUILA_ADALBERTI]);
check("el estado legal queda en la caché", str_contains((string) $stmt->fetchColumn(), "139/2011"));

[$c] = http("GET", "/api/species?legal=nada", null, $a);
check("legal con código desconocido → 400", $c === 400, "HTTP {$c}");
[$c] = http("GET", "/api/species", null, $a);
check("sin q ni filtros → 400", $c === 400, "HTTP {$c}");
[$c, $r] = http("GET", "/api/species?category=CR&group=" . rawurlencode("Mamíferos"), null, $a);
check("sin q: filtros sobre la caché (Mamíferos CR incluye el lince)", $c === 200 && in_array(LYNX, ids($r), true), "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
section("Ficha");
$pdo->prepare("UPDATE species SET detail_fetched_at = NULL WHERE taxonID = ?")->execute([ARTEMISIA]);
[$c, $r] = http("GET", "/api/species/" . ARTEMISIA, null, $a);
$sp = $r["species"] ?? [];
check("Artemisia granatensis → 200", $c === 200 && str_starts_with($sp["scientificName"] ?? "", "Artemisia granatensis"), "HTTP {$c}");
check("…con descripción", mb_strlen((string) ($sp["description"] ?? "")) > 100);
check("…con UICN principal", !empty($sp["iucnPrimary"]["code"]) && !empty($sp["iucnPrimary"]["scope"]), json_encode($sp["iucnPrimary"] ?? null));
check("…y todas las evaluaciones", count($sp["iucnAssessments"] ?? []) >= 2);
check("…nivel sugerido 2", ($sp["suggestedSensitivity"]["level"] ?? null) === 2, json_encode($sp["suggestedSensitivity"] ?? null));
check("…estado legal nacional y de Andalucía", isset($sp["legalStatus"]["national"], $sp["legalStatus"]["andalucia"]));
check("…nombre común y clasificación", ($sp["vernacularName"] ?? null) !== null && ($sp["classification"]["family"] ?? null) === "Asteraceae");
check("…fotos citando la Fototeca MITECO", ($sp["photoAttribution"] ?? "") === "Fototeca MITECO (vía EIDOS)"
    && array_filter($sp["images"] ?? [], fn($i) => ($i["attribution"] ?? "") !== "Fototeca MITECO (vía EIDOS)") === []);
$fetched = $pdo->query("SELECT detail_fetched_at FROM species WHERE taxonID = " . ARTEMISIA)->fetchColumn();
check("la ficha queda en la caché con fecha", $fetched !== null && $fetched !== false);
[$c] = http("GET", "/api/species/999999999", null, $a);
check("taxón inexistente → 404", $c === 404, "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
section("Especies de usuario");
$new = ["scientificName" => "Inventadus falsus", "scientificNameAuthorship" => "Prueba, 2026", "taxonRank" => "Species",
        "vernacularName" => "Bicho inventado"];
[$c, $r] = http("POST", "/api/species", $new, $a);
$invented = $r["species"] ?? [];
check("crear Inventadus falsus → 201", $c === 201, "HTTP {$c} " . json_encode($r));
check("…taxonID biodex:<uuid>", (bool) preg_match('/^biodex:[0-9a-f-]{36}$/', $invented["taxonID"] ?? ""));
check("…source user y taxonRemarks", ($invented["source"] ?? "") === "user" && ($invented["taxonRemarks"] ?? "") === "Taxón no presente en EIDOS");
check("…con el bloque de clasificación (ni EIDOS ni GBIF conocen el género)",
    array_key_exists("classification", $invented) && $invented["classification"]["genus"] === "Inventadus"
    && $invented["classification"]["source"] === null, json_encode($invented["classification"] ?? null));
[$c, $r] = http("POST", "/api/species", $new, $a);
check("repetir → 409 con el taxonID existente", $c === 409 && ($r["taxonID"] ?? "") === ($invented["taxonID"] ?? "x"), "HTTP {$c}");
[$c, $r] = http("POST", "/api/species", ["scientificName" => "Lynx pardinus", "scientificNameAuthorship" => "(Temminck, 1825)",
    "taxonRank" => "Species"], $a);
check("«Lynx pardinus» → 409 con 11943", $c === 409 && ($r["taxonID"] ?? "") === LYNX, "HTTP {$c} " . json_encode($r));
[$c, $r] = http("POST", "/api/species", ["scientificName" => "Aquila inventada", "scientificNameAuthorship" => "Prueba, 2026",
    "taxonRank" => "Species"], $a);
$cls = $r["species"]["classification"] ?? [];
check("género en EIDOS → clasificación de EIDOS", $c === 201 && $cls["source"] === "eidos" && $cls["family"] === "Accipitridae"
    && $cls["kingdom"] === "Animalia", json_encode($cls));
[$c, $r] = http("POST", "/api/species", ["scientificName" => "Oophaga inventada", "scientificNameAuthorship" => "Prueba, 2026",
    "taxonRank" => "Species"], $a);
$cls = $r["species"]["classification"] ?? [];
check("género solo en GBIF → clasificación de GBIF", $c === 201 && $cls["source"] === "gbif" && $cls["family"] === "Dendrobatidae",
    json_encode($cls));
[$c] = http("POST", "/api/species", ["scientificName" => "inventadus", "scientificNameAuthorship" => "X", "taxonRank" => "Species"], $a);
check("nombre mal formado → 400", $c === 400, "HTTP {$c}");
[$c, $r] = http("GET", "/api/species?q=" . rawurlencode("inventadus"), null, $a);
check("la búsqueda encuentra la especie de usuario", in_array($invented["taxonID"] ?? "x", ids($r), true), json_encode(ids($r)));

// ------------------------------------------------------------------------------------------------
section("Catálogos");
[$c, $r] = http("POST", "/api/catalogs", ["name" => "test-f2b Sierra Nevada", "description" => "Prueba"], $a);
$cat = $r["catalog"]["id"] ?? "00000000-0000-4000-8000-000000000000";
check("A crea un catálogo → 201 y owner", $c === 201 && ($r["catalog"]["role"] ?? "") === "owner", "HTTP {$c} " . json_encode($r));
$stmt = $pdo->prepare("SELECT role FROM catalog_members WHERE catalog_id = ?");
$stmt->execute([$cat]);
check("…con A como owner en catalog_members", $stmt->fetchAll(PDO::FETCH_COLUMN) === ["owner"]);

$codes = [];
foreach ([LYNX, ARTEMISIA, AQUILA_ADALBERTI] as $t) {
    [$codes[]] = http("POST", "/api/catalogs/{$cat}/species", ["taxonID" => $t], $a);
}
check("A añade 3 especies → 201", $codes === [201, 201, 201], implode(",", $codes));
[$c, $r] = http("POST", "/api/catalogs/{$cat}/species", ["taxonID" => LYNX], $a);
check("añadir otra vez → 200 (idempotente)", $c === 200 && ($r["sensitivity"]["level"] ?? null) === 2, "HTTP {$c}");
[$c] = http("POST", "/api/catalogs/{$cat}/species", ["taxonID" => "999999999"], $a);
check("añadir un taxón inexistente → 404", $c === 404, "HTTP {$c}");
$levels = $pdo->query("SELECT taxonID, level FROM species_sensitivity WHERE catalog_id = " . $pdo->quote($cat))->fetchAll(PDO::FETCH_KEY_PAIR);
check("species_sensitivity con el nivel sugerido (los tres 2)",
    $levels == [LYNX => 2, ARTEMISIA => 2, AQUILA_ADALBERTI => 2], json_encode($levels));

[$c] = http("GET", "/api/catalogs/{$cat}", null, $b);
check("B no es miembro → GET 403", $c === 403, "HTTP {$c}");
[$c, $r] = http("GET", "/api/catalogs", null, $b);
check("B no lo ve en su lista", $c === 200 && !in_array($cat, array_column($r["catalogs"] ?? [], "id"), true));

$pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role) VALUES (?, ?, 'viewer')")->execute([$cat, $bId]);
[$c, $r] = http("GET", "/api/catalogs/{$cat}", null, $b);
check("B viewer → GET 200 con rol viewer", $c === 200 && ($r["catalog"]["role"] ?? "") === "viewer", "HTTP {$c}");
check("…y ve el nivel de sensibilidad de cada especie", count(array_filter($r["species"] ?? [], fn($s) => ($s["sensitivity"]["level"] ?? null) === 2)) === 3);
[$c] = http("POST", "/api/catalogs/{$cat}/species", ["taxonID" => "10888"], $b);
check("B viewer añade especie → 403", $c === 403, "HTTP {$c}");
[$c] = http("PUT", "/api/catalogs/{$cat}/species/" . LYNX . "/sensitivity", ["level" => 1, "reason" => "x"], $b);
check("B viewer cambia nivel → 403", $c === 403, "HTTP {$c}");
[$c] = http("DELETE", "/api/catalogs/{$cat}/species/" . LYNX, null, $b);
check("B viewer quita especie → 403", $c === 403, "HTTP {$c}");
[$c] = http("PATCH", "/api/catalogs/{$cat}", ["name" => "test-f2b hackeado"], $b);
check("B viewer edita el catálogo → 403", $c === 403, "HTTP {$c}");
[$c, $r] = http("GET", "/api/catalogs", null, $b);
$mine = array_values(array_filter($r["catalogs"] ?? [], fn($x) => $x["id"] === $cat))[0] ?? [];
check("B lo ve en su lista como viewer con 3 especies", ($mine["role"] ?? "") === "viewer" && ($mine["speciesCount"] ?? 0) === 3, json_encode($mine));

$sens = "/api/catalogs/{$cat}/species/" . ARTEMISIA . "/sensitivity";
[$c] = http("PUT", $sens, ["level" => 3], $a);
check("A baja un nivel (2 → 3) sin reason → 422", $c === 422, "HTTP {$c}");
[$c, $r] = http("PUT", $sens, ["level" => 3, "reason" => "Población conocida y vigilada"], $a);
check("…con reason → 200 y confirmado", $c === 200 && ($r["sensitivity"]["level"] ?? null) === 3
    && ($r["sensitivity"]["suggestedLevel"] ?? null) === 2 && ($r["sensitivity"]["confirmed"] ?? false) === true, "HTTP {$c} " . json_encode($r));
$logged = $pdo->prepare("SELECT COUNT(*) FROM access_log WHERE action = 'set_level' AND catalog_id = ? AND target LIKE ?");
$logged->execute([$cat, ARTEMISIA . ":%"]);
check("…y queda set_level en access_log", (int) $logged->fetchColumn() === 1);
[$c] = http("PUT", "/api/catalogs/{$cat}/species/" . LYNX . "/sensitivity", ["level" => 1], $a);
check("subir el nivel (2 → 1) no exige reason", $c === 200, "HTTP {$c}");
[$c] = http("PUT", $sens, ["level" => 7, "reason" => "x"], $a);
check("nivel fuera de 1–4 → 400", $c === 400, "HTTP {$c}");
[$c] = http("POST", "/api/catalogs/{$cat}/species", ["taxonID" => ARTEMISIA], $a);
$lvl = $pdo->query("SELECT level FROM species_sensitivity WHERE catalog_id = " . $pdo->quote($cat) . " AND taxonID = " . ARTEMISIA)->fetchColumn();
check("volver a añadir no pisa el nivel fijado por el owner", (int) $lvl === 3, "nivel {$lvl}");

// ------------------------------------------------------------------------------------------------
section("Resumen y árbol");
[$c, $r] = http("GET", "/api/catalogs/{$cat}", null, $a);
$summary = $r["summary"] ?? [];
$expectedCats = [];
foreach ($r["species"] ?? [] as $s) {
    $k = $s["iucnPrimary"]["code"] ?? "none";
    $expectedCats[$k] = ($expectedCats[$k] ?? 0) + 1;
}
check("resumen: 3 especies", ($summary["total"] ?? 0) === 3);
check("resumen por grupo taxonómico", ($summary["byTaxonomicGroup"] ?? []) == ["Aves" => 1, "Mamíferos" => 1, "Plantas vasculares" => 1],
    json_encode($summary["byTaxonomicGroup"] ?? null, JSON_UNESCAPED_UNICODE));
check("resumen por categoría UICN principal", ($summary["byIucnCategory"] ?? []) == $expectedCats && array_sum($expectedCats) === 3
    && ($summary["byIucnCategory"]["CR"] ?? 0) >= 1, json_encode($summary["byIucnCategory"] ?? null));

[$c, $r] = http("GET", "/api/catalogs/{$cat}/tree", null, $b);
$tree = $r["tree"] ?? [];
check("árbol → 200 (también para el viewer)", $c === 200);
check("dos reinos: Animalia (2) y Plantae (1)", array_column($tree, "count", "name") == ["Animalia" => 2, "Plantae" => 1],
    json_encode(array_column($tree, "count", "name")));
$felidae = treeNode($tree, ["Animalia", "Chordata", "Mammalia", "Carnivora", "Felidae"]);
check("Animalia › Chordata › Mammalia › Carnivora › Felidae › lince",
    $felidae && array_column($felidae["species"], "taxonID") === [LYNX]);
$accip = treeNode($tree, ["Animalia", "Chordata", "Aves", "Accipitriformes", "Accipitridae"]);
check("… › Aves › Accipitriformes › Accipitridae › Aquila adalberti",
    $accip && array_column($accip["species"], "taxonID") === [AQUILA_ADALBERTI]);
check("Chordata agrupa las dos (count 2)", (treeNode($tree, ["Animalia", "Chordata"])["count"] ?? 0) === 2);
$aster = treeNode($tree, ["Plantae"]);
check("Plantae › … › Asteraceae › Artemisia", $aster && str_contains(json_encode($aster), ARTEMISIA)
    && str_contains(json_encode($aster), "Asteraceae"));
check("nodos con id estable de EIDOS", ($tree[0]["id"] ?? "") === "200001", json_encode($tree[0]["id"] ?? null));

// ------------------------------------------------------------------------------------------------
section("Borrados");
[$c] = http("DELETE", "/api/catalogs/{$cat}/species/" . AQUILA_ADALBERTI, null, $a);
check("A quita una especie → 200", $c === 200, "HTTP {$c}");
[$c, $r] = http("GET", "/api/catalogs/{$cat}", null, $a);
check("…quedan 2", count($r["species"] ?? []) === 2);
$still = $pdo->query("SELECT deleted_at FROM catalog_species WHERE catalog_id = " . $pdo->quote($cat) . " AND taxonID = " . AQUILA_ADALBERTI)->fetchColumn();
check("…borrado lógico (la fila sigue con deleted_at)", $still !== false && $still !== null);
[$c, $r] = http("PATCH", "/api/catalogs/{$cat}", ["name" => "test-f2b Sierra Nevada (renombrado)"], $a);
check("A renombra → 200", $c === 200 && ($r["catalog"]["name"] ?? "") === "test-f2b Sierra Nevada (renombrado)", "HTTP {$c}");
[$c] = http("DELETE", "/api/catalogs/{$cat}", null, $b);
check("B viewer borra el catálogo → 403", $c === 403, "HTTP {$c}");
[$c] = http("DELETE", "/api/catalogs/{$cat}", null, $a);
check("A borra el catálogo → 200", $c === 200, "HTTP {$c}");
[$c] = http("GET", "/api/catalogs/{$cat}", null, $a);
check("catálogo borrado → 403 también para el owner", $c === 403, "HTTP {$c}");
check("…borrado lógico", $pdo->query("SELECT deleted_at FROM catalogs WHERE id = " . $pdo->quote($cat))->fetchColumn() !== null);

// ------------------------------------------------------------------------------------------------
cleanup($pdo);
echo "\n" . ($failures === 0 ? "TODO OK" : "{$failures} FALLO(S)") . "\n";
exit($failures === 0 ? 0 : 1);
