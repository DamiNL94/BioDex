<?php
// ================================================================================================
// Pruebas de F10b (datos de demostración): docker compose exec php php bin/test-f10b.php
//   · seed-demo.php y reset-demo.php dos veces seguidas cada uno; con APP_ENV distinto de dev, no hacen nada.
//   · Contenido: 3 cuentas verificadas con su rol, 10 especies, 60 marcas (5 ausencias, 2 en el mapa, con
//     individuos y maduros) de los tres, notas y la evaluación de referencia EN B1ab(iii)+2ab(iii).
//   · El editor recibe generalizadas las marcas ajenas de la Artemisia (nivel 2); la owner, exactas.
//   · La exportación «para publicar» avisa de que los datos son ficticios (datasetName y EML).
// Deja la demo cargada al terminar.
// ================================================================================================

require_once __DIR__ . "/demo/demo-lib.php";
require_once __DIR__ . "/test-lib.php";

demoRequireDev();
$pdo = db();

function run(string $script, string $env = "dev"): array {
    exec("APP_ENV={$env} php " . escapeshellarg(__DIR__ . "/{$script}") . " 2>&1", $out, $code);
    return [$code, implode("\n", $out)];
}

// ------------------------------------------------------------------------------------------------
section("Seed y reset, dos veces seguidas");
[$c1, $o1] = run("reset-demo.php");
[$c2, $o2] = run("reset-demo.php");
check("reset ×2", $c1 === 0 && $c2 === 0 && str_contains($o2, "No había datos de demo"), $o1 . "\n" . $o2);
check("tras el reset no quedan cuentas ni catálogo", !demoUserIds($pdo) && !demoCatalogIds($pdo, []));
[$c1, $o1] = run("seed-demo.php");
[$c2, $o2] = run("seed-demo.php");
check("seed ×2", $c1 === 0 && $c2 === 0 && str_contains($o1, "60 marcas ficticias") && str_contains($o2, "ya existe (60 marcas)"), $o1 . "\n" . $o2);
check("el seed imprime las contraseñas", str_contains($o1, DEMO_USERS["editor"]["password"]));
[$c3, $o3] = run("seed-demo.php", "prod");
[$c4] = run("reset-demo.php", "prod");
check("con APP_ENV=prod no hacen nada", $c3 === 1 && $c4 === 1 && str_contains($o3, "Solo con APP_ENV=dev") && count(demoCatalogIds($pdo, [])) === 1, $o3);

// ------------------------------------------------------------------------------------------------
section("Contenido");
$cat = demoCatalogIds($pdo, [])[0] ?? "";
$q = fn(string $sql) => $pdo->query(str_replace(":cat", $pdo->quote($cat), $sql));
$roles = $q("SELECT u.email, m.role, u.email_verified_at IS NOT NULL AS v FROM catalog_members m JOIN users u ON u.id = m.user_id
             WHERE m.catalog_id = :cat")->fetchAll(PDO::FETCH_ASSOC);
$byEmail = array_column($roles, null, "email");
check("3 miembros verificados con su rol", count($roles) === 3 && !array_filter(DEMO_USERS, fn($u) => ($byEmail[$u["email"]]["role"] ?? "") !== $u["role"]
    || !$byEmail[$u["email"]]["v"]), json_encode($roles));
$sp = $q("SELECT COUNT(*) FROM catalog_species WHERE catalog_id = :cat AND deleted_at IS NULL")->fetchColumn();
$names = $q("SELECT s.canonical_name FROM catalog_species cs JOIN species s USING (taxonID) WHERE cs.catalog_id = :cat")->fetchAll(PDO::FETCH_COLUMN);
check("10 especies de EIDOS, con Artemisia granatensis y Atropa baetica", (int) $sp === 10
    && in_array("Artemisia granatensis", $names, true) && in_array("Atropa baetica", $names, true), implode(", ", $names));
check("niveles confirmados por la owner", (int) $q("SELECT COUNT(*) FROM species_sensitivity WHERE catalog_id = :cat AND set_by IS NOT NULL")->fetchColumn() === 10);
$s = $q("SELECT COUNT(*) n, SUM(occurrenceStatus = 'absent') a, SUM(georeferenceProtocol = 'Punto marcado en el mapa') m,
                SUM(mature_count IS NOT NULL) mat, COUNT(DISTINCT user_id) u, COUNT(DISTINCT DATE(eventDate)) d,
                MIN(decimalLatitude) lat0, MAX(decimalLatitude) lat1, MIN(decimalLongitude) lon0, MAX(decimalLongitude) lon1
         FROM occurrences WHERE catalog_id = :cat AND deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
check("60 marcas, 5 ausencias, 2 marcadas en el mapa", (int) $s["n"] === 60 && (int) $s["a"] === 5 && (int) $s["m"] === 2, json_encode($s));
check("de los tres usuarios, en varias fechas, con maduros", (int) $s["u"] === 3 && (int) $s["d"] >= 20 && (int) $s["mat"] >= 30, json_encode($s));
check("todas en Sierra Nevada", $s["lat0"] > 36.9 && $s["lat1"] < 37.2 && $s["lon0"] > -3.5 && $s["lon1"] < -3.1, json_encode($s));
check("notas de especie", (int) $q("SELECT COUNT(*) FROM species_notes WHERE catalog_id = :cat")->fetchColumn() >= 3);
$ref = $q("SELECT category, JSON_UNQUOTE(JSON_EXTRACT(result, '$.criteriaString')) cs FROM species_assessments
           WHERE catalog_id = :cat AND is_reference = 1 AND taxonID = '9918'")->fetch(PDO::FETCH_ASSOC);
check("evaluación de referencia de la Artemisia: EN B1ab(iii)+2ab(iii)", ($ref["cs"] ?? "") === "EN B1ab(iii)+2ab(iii)", json_encode($ref));

// ------------------------------------------------------------------------------------------------
section("Visibilidad y exportación");
$jars = [];
foreach (["owner", "editor"] as $k) {
    $jars[$k] = [];
    [$code] = http("POST", "/api/auth/login", ["email" => DEMO_USERS[$k]["email"], "password" => DEMO_USERS[$k]["password"]], $jars[$k]);
    check("login de {$k} con la contraseña impresa", $code === 200, "HTTP {$code}");
}
$editorId = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote(DEMO_USERS["editor"]["email"]))->fetchColumn();
$artemisia = fn(array $jar) => array_values(array_filter(http("GET", "/api/sync/pull?catalogId={$cat}&since=0&limit=500", null, $jar)[1]["items"] ?? [],
    fn($i) => $i["taxonID"] === "9918"));
$ed = $artemisia($jars["editor"]);
$foreign = array_filter($ed, fn($i) => $i["userId"] !== $editorId);
check("el editor ve generalizadas las Artemisias ajenas", $foreign && !array_filter($foreign, fn($i) => empty($i["generalized"])),
    json_encode(array_slice($foreign, 0, 1)));
$ow = $artemisia($jars["owner"]);
check("la owner las ve exactas", $ow && !array_filter($ow, fn($i) => !empty($i["generalized"])));

$ch = curl_init("{$BASE}/api/catalogs/{$cat}/export");
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => json_encode(["mode" => "public"]),
    CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Cookie: " . implode("; ", array_map(fn($k, $v) => "{$k}={$v}", array_keys($jars["editor"]), $jars["editor"]))]]);
$zipRaw = (string) curl_exec($ch);
$tmp = tempnam(sys_get_temp_dir(), "f10b");
file_put_contents($tmp, $zipRaw);
$zip = new ZipArchive();
$okZip = $zip->open($tmp) === true;
$eml = $okZip ? (string) $zip->getFromName("dwca/eml.xml") : "";
$occ = $okZip ? (string) $zip->getFromName("dwca/occurrence.txt") : "";
if ($okZip) $zip->close();
unlink($tmp);
$rows = explode("\n", rtrim($occ, "\n"));
$head = explode("\t", $rows[0] ?? "");
$first = array_combine($head, explode("\t", $rows[1] ?? "") + array_fill(0, count($head), ""));
check("exportación «para publicar» del editor: 60 filas", $okZip && count($rows) === 61, count($rows) - 1 . " filas");
check("datasetName dice «datos ficticios»", str_contains($first["datasetName"] ?? "", "(datos ficticios)"), $first["datasetName"] ?? "");
check("el EML dice «" . DEMO_NOTICE . "»", str_contains($eml, DEMO_NOTICE));

foreach ($jars as $k => $j) http("POST", "/api/auth/logout", null, $jars[$k]);

echo "\n" . ($failures ? "{$failures} FALLO(S)" : "Todo OK") . "\n";
exit($failures ? 1 : 0);
