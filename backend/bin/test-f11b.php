<?php
// ================================================================================================
// Pruebas de F11b (fotos de respaldo y siluetas): docker compose exec php php bin/test-f11b.php
//   · Decisión de iNaturalist con los fixtures de docs/inat-samples/ (sin red): exacta CC BY → ok; licencia
//     null → none; sinónimo por matched_term → review; otra especie del género → none; subespecie frente a
//     especie → none; mayúsculas y espacios dobles → ok; sin default_photo → none; URL fuera del bucket → none.
//   · Mapeo taxonomía → silueta: un caso por grupo y uno sin regla; cada grupo tiene su <symbol>.
//   · Proxy /img/inat/: formas no válidas → 400; POST → 403.
//   · Revisión: editor y viewer → 403; el custodio acepta → la especie sale con thumbnail y crédito;
//     rechazada → solo silueta y, desde F11d, la fuente a rejected_sources y la especie a pending (la cascada
//     sigue con GBIF y Commons); access_log; content_seq.
// Crea usuarios f11b-*@biodex.test, el catálogo «test-f11b …» y especies 99001100x (ids numéricos, como los de EIDOS); los borra al terminar.
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/migrate.php";
require_once __DIR__ . "/../Lib/species.php";
require_once __DIR__ . "/test-lib.php";

const SAMPLES = __DIR__ . "/../../docs/inat-samples";
const SPRITE = __DIR__ . "/../../frontend/img/silhouettes.svg";

$pdo = db();
runMigrations($pdo);

/** Código HTTP de una ruta tal cual (sin normalizar %-codificados ni «..»). */
function rawStatus(string $method, string $path): int {
    global $BASE;
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $BASE . $path, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_PATH_AS_IS => true, CURLOPT_TIMEOUT => 30]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code;
}

function cleanup(PDO $pdo): void {
    $in = "SELECT id FROM catalogs WHERE name LIKE 'test-f11b %'";
    $pdo->exec("DELETE FROM access_log WHERE catalog_id IN ({$in})");
    $pdo->exec("DELETE FROM catalogs WHERE name LIKE 'test-f11b %'");
    $pdo->exec("DELETE FROM species_photos WHERE taxonID LIKE '99001100_'");
    $pdo->exec("DELETE FROM species WHERE taxonID LIKE '99001100_'");
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f11b-%@biodex.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f11b-%@biodex.test'");
}
cleanup($pdo);

// ------------------------------------------------------------------------------------------------
section("Decisión de iNaturalist (fixtures de docs/inat-samples, sin red)");
$required = ["exacta-cc-by", "exacta-licencia-null", "sinonimo-matched-term", "otra-especie-del-genero",
             "subespecie-vs-especie", "mayusculas-espacios", "sin-default-photo", "url-fuera-del-bucket", "exacta-jpeg"];
foreach ($required as $name) {
    $fx = json_decode((string) @file_get_contents(SAMPLES . "/{$name}.json"), true);
    if (!is_array($fx)) { check("{$name}.json existe", false, "¿está montado ./docs en el contenedor?"); continue; }
    $d = inatDecide($fx["species"], $fx["response"]);
    $ok = $d["status"] === $fx["expected"]["status"] && ($d["match_type"] ?? null) === $fx["expected"]["match_type"];
    check("{$name} → {$fx["expected"]["status"]}", $ok, json_encode($d, JSON_UNESCAPED_UNICODE));
}
$fx = json_decode(file_get_contents(SAMPLES . "/exacta-cc-by.json"), true);
$d = inatDecide($fx["species"], $fx["response"]);
check("exacta: id de foto, extensión, licencia y atribución tal cual", $d["photo_id"] === 356885346 && $d["photo_ext"] === "jpg"
    && $d["license"] === "cc-by" && str_starts_with($d["attribution"], "(c) Luiz Lapa"), json_encode($d, JSON_UNESCAPED_UNICODE));
$fx = json_decode(file_get_contents(SAMPLES . "/exacta-jpeg.json"), true);
check("foto .jpeg → photo_ext jpeg", (inatDecide($fx["species"], $fx["response"])["photo_ext"] ?? "") === "jpeg");
$fx = json_decode(file_get_contents(SAMPLES . "/sinonimo-matched-term.json"), true);
$d = inatDecide($fx["species"], $fx["response"]);
check("sinónimo: guarda el nombre de iNat (Timon lepidus)", ($d["matched_name"] ?? "") === "Timon lepidus");

// Variantes sin fixture: lo que la regla de oro exige descartar.
$base = json_decode(file_get_contents(SAMPLES . "/exacta-cc-by.json"), true);
$turdus = $base["response"]["results"][0];
$resp = fn(array ...$results) => ["results" => $results];
check("dos taxones exactos con el mismo nombre → none (ambiguo)",
    inatDecide($base["species"], $resp($turdus, ["id" => 1] + $turdus))["status"] === "none");
check("otro reino (iconic Plantae para un ave) → none",
    inatDecide($base["species"], $resp(["iconic_taxon_name" => "Plantae"] + $turdus))["status"] === "none");
check("taxón inactivo → none", inatDecide($base["species"], $resp(["is_active" => false] + $turdus))["status"] === "none");
check("licencia cc-by-sa (fuera de la lista) → none",
    inatDecide($base["species"], $resp(["default_photo" => ["license_code" => "cc-by-sa"] + $turdus["default_photo"]] + $turdus))["status"] === "none");
check("foto marcada (flags) → none",
    inatDecide($base["species"], $resp(["default_photo" => ["flags" => [["flag" => "copyright infringement"]]] + $turdus["default_photo"]] + $turdus))["status"] === "none");
check("rango de EIDOS sin búsqueda (Genus) → none", inatDecide(["name" => "Turdus", "rank" => "Genus"], $resp($turdus))["status"] === "none");
check("subespecie de EIDOS con «subsp.» casa con el trinomio de iNat",
    inatDecide(["name" => "Turdus merula subsp. cabrerae", "rank" => "Subspecies", "kingdom" => "Animalia"],
        $resp(["id" => 342088, "name" => "Turdus merula cabrerae", "rank" => "subspecies", "matched_term" => "Turdus merula cabrerae"] + $turdus))["status"] === "ok");
check("la consulta a iNat va sin marca de rango («subsp.» da 0 resultados)",
    inatQueryName("Aquila chrysaetos subsp. homeyeri") === "Aquila chrysaetos homeyeri"
    && inatQueryName("Ulex  parviflorus var. funkii") === "Ulex parviflorus funkii" && inatQueryName("Turdus merula") === "Turdus merula");
check("sinónimo de EIDOS sin autor: «Atropa belladonna subsp. baetica (Willk.) Font Quer»",
    inatStripAuthor("Atropa belladonna subsp. baetica (Willk.) Font Quer") === "Atropa belladonna subsp. baetica"
    && inatStripAuthor("Sempervivum nevadense Wale") === "Sempervivum nevadense"
    && inatStripAuthor("Capra pyrenaica victoriae Cabrera, 1911") === "Capra pyrenaica victoriae");

// ------------------------------------------------------------------------------------------------
section("Siluetas: taxonomía de EIDOS → grupo");
$cases = [
    "flowering-plant" => ["Plantae", "Tracheophyta", "Magnoliopsida", "Asterales"],
    "fern"            => ["Plantae", "Tracheophyta", "Polypodiopsida", "Polypodiales"],
    "gymnosperm"      => ["Plantae", "Tracheophyta", "Pinopsida", "Pinales"],
    "bryophyte"       => ["Plantae", "Bryophyta", "Bryopsida", "Hypnales"],
    "fungus"          => ["Fungi", "Basidiomycota", "Agaricomycetes", "Agaricales"],
    "lichen"          => ["Fungi", "Ascomycota", "Lecanoromycetes", "Lecanorales"],
    "alga"            => ["Plantae", "Rhodophyta", "Florideophyceae", "Corallinales"],
    "bird"            => ["Animalia", "Chordata", "Aves", "Accipitriformes"],
    "mammal"          => ["Animalia", "Chordata", "Mammalia", "Carnivora"],
    "reptile"         => ["Animalia", "Chordata", "Reptilia", "Squamata"],
    "amphibian"       => ["Animalia", "Chordata", "Amphibia", "Anura"],
    "fish"            => ["Animalia", "Chordata", "Actinopterygii", "Cypriniformes"],
    "insect"          => ["Animalia", "Arthropoda", "Insecta", "Lepidoptera"],
    "arachnid"        => ["Animalia", "Arthropoda", "Arachnida", "Araneae"],
    "mollusc"         => ["Animalia", "Mollusca", "Gastropoda", "Stylommatophora"],
    "crustacean"      => ["Animalia", "Arthropoda", "Malacostraca", "Decapoda"],
    "invertebrate"    => ["Animalia", "Cnidaria", "Anthozoa", "Actiniaria"],
    "generic"         => ["Bacteria", "Cyanobacteria", "Cyanophyceae", "Nostocales"],
];
$sprite = (string) @file_get_contents(SPRITE);
$groups = speciesGroupsConfig()["groups"];
foreach ($cases as $want => [$k, $p, $c, $o]) {
    $got = speciesSilhouette(["kingdom" => $k, "phylum" => $p, "class" => $c, "order" => $o]);
    check("{$c} → {$want} («{$got["label"]}»)", $got["id"] === $want, json_encode($got, JSON_UNESCAPED_UNICODE));
}
check("sin taxonomía → generic", speciesSilhouette(["kingdom" => null, "phylum" => null, "class" => null, "order" => null])["id"] === "generic");
check("18 grupos, todos con su <symbol> en silhouettes.svg", count($groups) === 18
    && !array_filter(array_keys($groups), fn($g) => !str_contains($sprite, "id=\"sil-{$g}\"")), implode(", ", array_keys($groups)));
check("el sprite usa currentColor y no trae colores fijos", str_contains($sprite, "currentColor") && !preg_match('/(fill|stroke)="#/', $sprite));

// ------------------------------------------------------------------------------------------------
section("Proxy /img/inat/");
foreach (["/img/inat/abc/medium.jpg", "/img/inat/1/huge.jpg", "/img/inat/1/medium.jpg?x=1", "/img/inat/%31/medium.jpg",
          "/img/%69nat/1/medium.jpg", "/img/inat/1/medium%2Ejpg", "/img/inat/1/MEDIUM.JPG", "/img/inat/1/medium.gif",
          "/img/inat/../fototeca/x.jpg", "/img/inat/1234567890123/medium.jpg"] as $p) {
    $code = rawStatus("GET", $p);
    check("{$p} → 400", $code === 400, "HTTP {$code}");
}
$code = rawStatus("POST", "/img/inat/1/medium.jpg");
check("POST → 403 (solo GET)", $code === 403, "HTTP {$code}");

// ------------------------------------------------------------------------------------------------
section("Cola y revisión");
$suffix = bin2hex(random_bytes(4));
$jars = ["o" => [], "c" => [], "e" => [], "v" => []];
$ids = [];
foreach ($jars as $k => &$jar) {
    $email = "f11b-{$k}-{$suffix}@biodex.test";
    [$code] = http("POST", "/api/auth/register", ["email" => $email, "password" => "correcta-horse-battery", "name" => "F11b " . strtoupper($k)], $jar);
    $ids[$k] = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();
    check("registro de " . strtoupper($k), $code === 201, "HTTP {$code}");
}
unset($jar);
[$o, $cu, $e, $v] = [$jars["o"], $jars["c"], $jars["e"], $jars["v"]];
[$code, $r] = http("POST", "/api/catalogs", ["name" => "test-f11b {$suffix}"], $o);
$cat = $r["catalog"]["id"] ?? "";
check("O crea el catálogo", $code === 201, "HTTP {$code}");
$ins = $pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role, granted_by) VALUES (?, ?, ?, ?)");
foreach (["c" => "custodian", "e" => "editor", "v" => "viewer"] as $k => $role) $ins->execute([$cat, $ids[$k], $role, $ids["o"]]);

// Dos especies «de EIDOS» sin foto de la Fototeca (ficha ya al día: no se llama a EIDOS) y una de usuario.
$sp = $pdo->prepare("INSERT INTO species (taxonID, source, scientificName, canonical_name, taxonRank, kingdom, phylum, class, `order`,
                     genus, specificEpithet, images, detail_fetched_at) VALUES (?, ?, ?, ?, 'Species', 'Animalia', 'Chordata', 'Reptilia',
                     'Squamata', 'Testia', ?, ?, NOW())");
$sp->execute(["990011001", "eidos", "Testia acepta Autor", "Testia acepta", "acepta", null]);
$sp->execute(["990011002", "eidos", "Testia rechaza Autor", "Testia rechaza", "rechaza", null]);
$sp->execute(["990011003", "user", "Testia usuaria Autor", "Testia usuaria", "usuaria", null]);
$sp->execute(["990011004", "eidos", "Testia fototeca Autor", "Testia fototeca", "fototeca",
              json_encode([["url" => "https://www.miteco.gob.es/content/dam/fototeca/test/f11b.jpg", "id" => 1]])]);
foreach (["990011001", "990011002", "990011003", "990011004"] as $t) {
    [$code] = http("POST", "/api/catalogs/{$cat}/species", ["taxonID" => $t], $o);
    check("alta de {$t} en el catálogo", $code === 201, "HTTP {$code}");
}
$status = fn(string $t) => $pdo->query("SELECT status FROM species_photos WHERE taxonID = " . $pdo->quote($t))->fetchColumn();
check("especie de EIDOS sin Fototeca → pending al darla de alta", $status("990011001") === "pending" && $status("990011002") === "pending");
check("especie de usuario y especie con Fototeca → sin fila (silueta / Fototeca)", $status("990011003") === false && $status("990011004") === false);

// Propuestas por sinónimo (lo que dejaría bin/fetch-species-photos.php).
$rev = $pdo->prepare("UPDATE species_photos SET status = 'review', source = 'inaturalist', photo_id = ?, photo_ext = 'jpg', inat_taxon_id = 73972,
                      matched_name = 'Timon lepidus', match_type = 'synonym', license = 'cc-by-nc',
                      attribution = '(c) Samuel Duarte, some rights reserved (CC BY-NC), uploaded by Samuel Duarte', checked_at = NOW()
                      WHERE taxonID = ?");
$rev->execute([238812385, "990011001"]);
$rev->execute([238812386, "990011002"]);

$card = fn(array $jar, string $t) => array_values(array_filter(http("GET", "/api/catalogs/{$cat}", null, $jar)[1]["species"] ?? [],
    fn($s) => $s["taxonID"] === $t))[0] ?? [];
$a0 = $card($e, "990011001");
check("en revisión no se expone la foto: thumbnail null y silueta de reptil",
    array_key_exists("thumbnail", $a0) && $a0["thumbnail"] === null && $a0["photoCredit"] === null
    && ($a0["silhouette"]["id"] ?? "") === "reptile" && ($a0["silhouette"]["label"] ?? "") === "reptil", json_encode($a0, JSON_UNESCAPED_UNICODE));
$f0 = $card($e, "990011004");
check("con Fototeca: thumbnail del proxy y crédito de la Fototeca (sin cambios)", ($f0["thumbnail"] ?? "") === "/img/fototeca/test/f11b.jpg"
    && ($f0["photoCredit"]["source"] ?? "") === "fototeca", json_encode($f0, JSON_UNESCAPED_UNICODE));

[, $ro] = http("GET", "/api/catalogs/{$cat}", null, $o);
[, $rc] = http("GET", "/api/catalogs/{$cat}", null, $cu);
[, $re] = http("GET", "/api/catalogs/{$cat}", null, $e);
check("owner y custodio reciben photoReviews (2); el editor no", count($ro["photoReviews"] ?? []) === 2 && count($rc["photoReviews"] ?? []) === 2
    && !array_key_exists("photoReviews", $re ?? []), json_encode(array_keys($re ?? [])));
$pr = $rc["photoReviews"][0] ?? [];
check("cada propuesta: nombre de EIDOS e iNat, foto del proxy y crédito con enlace", ($pr["canonicalName"] ?? "") === "Testia acepta"
    && ($pr["sourceTaxonName"] ?? "") === "Timon lepidus" && ($pr["sourceName"] ?? "") === "iNaturalist" && ($pr["photo"] ?? "") === "/img/inat/238812385/medium.jpg"
    && ($pr["photoCredit"]["url"] ?? "") === "https://www.inaturalist.org/photos/238812385" && ($pr["photoCredit"]["license"] ?? "") === "CC BY-NC",
    json_encode($pr, JSON_UNESCAPED_UNICODE));

$review = fn(array $jar, string $t, $body) => http("POST", "/api/species/" . rawurlencode($t) . "/photo-review", $body, $jar);
foreach (["e" => $e, "v" => $v] as $k => $jar) {
    [$code] = $review($jar, "990011001", ["catalogId" => $cat, "decision" => "accept"]);
    check(strtoupper($k) . " acepta → 403", $code === 403, "HTTP {$code}");
}
[$code] = $review($cu, "990011001", ["catalogId" => $cat, "decision" => "maybe"]);
check("decisión no válida → 400", $code === 400, "HTTP {$code}");
[$code] = $review($cu, "990011003", ["catalogId" => $cat, "decision" => "accept"]);
check("especie sin foto en revisión → 409", $code === 409, "HTTP {$code}");
[$code] = http("GET", "/api/species/990011001/photo-review", null, $cu);
check("GET a la ruta de revisión → 405", $code === 405, "HTTP {$code}");
check("nadie ha cambiado nada todavía", $status("990011001") === "review");

$seq0 = (int) $pdo->query("SELECT content_seq FROM catalogs WHERE id = " . $pdo->quote($cat))->fetchColumn();
[$code, $r] = $review($cu, "990011001", ["catalogId" => $cat, "decision" => "accept"]);
check("el custodio acepta → 200 ok", $code === 200 && ($r["status"] ?? "") === "ok", "HTTP {$code} " . json_encode($r, JSON_UNESCAPED_UNICODE));
$a1 = $card($e, "990011001");
check("aceptada: la especie sale con thumbnail para todos (también el editor)", ($a1["thumbnail"] ?? "") === "/img/inat/238812385/medium.jpg"
    && ($a1["photoCredit"]["text"] ?? "") === "(c) Samuel Duarte, some rights reserved (CC BY-NC), uploaded by Samuel Duarte"
    && ($a1["photoCredit"]["source"] ?? "") === "inaturalist", json_encode($a1, JSON_UNESCAPED_UNICODE));
[, $one] = http("GET", "/api/species/990011001", null, $e);
check("la ficha también (y images sigue siendo solo la Fototeca)", ($one["species"]["thumbnail"] ?? "") === "/img/inat/238812385/medium.jpg"
    && ($one["species"]["images"] ?? null) === []);
[, $off] = http("GET", "/api/catalogs/{$cat}/offline", null, $e);
$offA = array_values(array_filter($off["species"] ?? [], fn($s) => $s["taxonID"] === "990011001"))[0] ?? [];
check("y el paquete offline", ($offA["thumbnail"] ?? "") === "/img/inat/238812385/medium.jpg");
$seq1 = (int) $pdo->query("SELECT content_seq FROM catalogs WHERE id = " . $pdo->quote($cat))->fetchColumn();
check("content_seq sube (el móvil vuelve a bajar el paquete)", $seq1 > $seq0, "{$seq0} → {$seq1}");

[$code] = $review($o, "990011001", ["catalogId" => $cat, "decision" => "reject"]);
check("ya revisada → 409", $code === 409, "HTTP {$code}");

[$code, $r] = $review($o, "990011002", ["catalogId" => $cat, "decision" => "reject"]);
check("la owner rechaza la otra → 200 pending (F11d: la cascada sigue con GBIF y Commons)", $code === 200 && ($r["status"] ?? "") === "pending", "HTTP {$code}");
$b1 = $card($e, "990011002");
check("rechazada: sin thumbnail, con silueta", $b1["thumbnail"] === null && ($b1["silhouette"]["id"] ?? "") === "reptile");
speciesQueuePhoto($pdo, speciesFind($pdo, "990011002"));
check("rechazada: iNaturalist en rejected_sources, sin datos de foto (F11d)", $status("990011002") === "pending"
    && $pdo->query("SELECT CONCAT(rejected_sources, '|', source IS NULL, photo_id IS NULL) FROM species_photos WHERE taxonID = '990011002'")->fetchColumn() === "inaturalist|11");
[, $rc] = http("GET", "/api/catalogs/{$cat}", null, $cu);
check("photoReviews queda vacío", ($rc["photoReviews"] ?? null) === []);

$log = $pdo->query("SELECT user_id, target FROM access_log WHERE action = 'photo_review' AND catalog_id = " . $pdo->quote($cat) . " ORDER BY id")
    ->fetchAll(PDO::FETCH_ASSOC);
check("access_log: quién y qué en cada revisión", count($log) === 2 && (int) $log[0]["user_id"] === $ids["c"]
    && str_contains($log[0]["target"], "aceptada") && (int) $log[1]["user_id"] === $ids["o"] && str_contains($log[1]["target"], "rechazada"),
    json_encode($log, JSON_UNESCAPED_UNICODE));
[, $audit] = http("GET", "/api/catalogs/{$cat}/audit", null, $o);
check("el registro del catálogo lo enseña con el nombre de la especie",
    (bool) array_filter($audit["items"] ?? [], fn($i) => $i["action"] === "photo_review" && str_starts_with($i["detail"], "Testia acepta:")),
    json_encode(array_slice($audit["items"] ?? [], 0, 2), JSON_UNESCAPED_UNICODE));

// ------------------------------------------------------------------------------------------------
section("Script");
exec("php " . escapeshellarg(__DIR__ . "/fetch-species-photos.php") . " --limit=x 2>&1", $out, $rc1);
check("opción no válida → código 2", $rc1 === 2, implode("\n", $out));
$out = [];
exec("php " . escapeshellarg(__DIR__ . "/fetch-species-photos.php") . " --dry-run --limit=1 2>&1", $out, $rc2);
check("--dry-run --limit=1 acaba con la línea de resumen", $rc2 === 0 && str_contains(end($out) ?: "", "BioDex fotos (en seco"), implode("\n", $out));

cleanup($pdo);
echo $failures ? "\n{$failures} fallo(s)\n" : "\nTodo OK\n";
exit($failures ? 1 : 0);
