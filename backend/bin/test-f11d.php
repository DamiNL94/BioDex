<?php
// ================================================================================================
// Pruebas de F11d (fotos de respaldo de GBIF y Wikimedia Commons): docker compose exec php php bin/test-f11d.php
//   · GBIF con los fixtures de docs/gbif-samples/ (sin red): exacta en España CC BY con autor → ok; foto
//     «Copyright by the creator» en ocurrencia CC BY → se salta; foto sin autor → se salta; FUZZY → none;
//     SYNONYM → review; subespecie frente a especie → none; homónimo de otro reino → none. Licencias de la foto.
//   · Commons con los fixtures de docs/commons-samples/: P225 exacto con P18 CC BY-SA 4.0 → ok con licencia y
//     versión; dos elementos con el mismo P225 → none; otro rango → none; sin P18 → none; GFDL sola → none;
//     Artist con HTML → texto limpio.
//   · Copias: validación por contenido (tipo, tamaño, dimensiones) y etiquetas de licencia.
//   · Cascada con fuentes falsas: iNat none → GBIF ok; iNat error → pending sin pasar a GBIF; iNat rechazada
//     → se salta; todo none → none; cortocircuito tras 3 errores seguidos.
//   · Migración 013 sobre tablas temporales: rejected → pending + rejected_sources; none → pending.
//   · Servicio /img/respaldo/: ok → 200 con tipo, nosniff, caché inmutable y ETag (304); hash o extensión
//     que no son → 404; formas no válidas, %-codificados y «..» → 400; POST → 403; review sin sesión o de
//     editor → 404, de owner/custodio → 200 sin caché compartida. Tarjeta con thumbnail y crédito de GBIF.
//   · Revisión: rechazar una de GBIF → pending con gbif en rejected_sources y sin copia; la última fuente → none.
//   · Script: --source no válida → 2; --dry-run --source=commons acaba con el resumen.
// Crea usuarios f11d-*@biodex.test, el catálogo «test-f11d …» y especies 99001110x; los borra al terminar.
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/migrate.php";
require_once __DIR__ . "/../Lib/species.php";
require_once __DIR__ . "/../Lib/photos.php";
require_once __DIR__ . "/test-lib.php";

const GBIF_SAMPLES = __DIR__ . "/../../docs/gbif-samples";
const COMMONS_SAMPLES = __DIR__ . "/../../docs/commons-samples";

$pdo = db();
runMigrations($pdo);

function fixture(string $dir, string $name): ?array {
    $fx = json_decode((string) @file_get_contents("{$dir}/{$name}.json"), true);
    if (!is_array($fx)) check("{$name}.json existe", false, "¿está montado ./docs en el contenedor?");
    return is_array($fx) ? $fx : null;
}

/** Petición tal cual (sin normalizar la ruta). → [código, cuerpo, cabeceras en minúsculas]. */
function raw(string $method, string $path, array $headers = [], array $jar = []): array {
    global $BASE;
    $out = [];
    if ($jar) $headers[] = "Cookie: " . implode("; ", array_map(fn($k, $v) => "{$k}={$v}", array_keys($jar), $jar));
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $BASE . $path, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_PATH_AS_IS => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$out) {
            if (preg_match('/^([\w-]+):\s*(.*)$/', trim($line), $h)) $out[strtolower($h[1])] = $h[2];
            return strlen($line);
        }]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, (string) $body, $out];
}

/** PNG mínimo (cabecera + IHDR) de $w × $h: basta para finfo y getimagesize, sin GD. */
function fakePng(int $w, int $h, int $pad = 0): string {
    $chunk = fn(string $type, string $data) => pack("N", strlen($data)) . $type . $data . pack("N", crc32($type . $data));
    return "\x89PNG\r\n\x1a\n" . $chunk("IHDR", pack("NNCCCCC", $w, $h, 8, 2, 0, 0, 0))
        . ($pad ? $chunk("tEXt", "c\0" . str_repeat("x", $pad)) : "") . $chunk("IEND", "");
}

function cleanup(PDO $pdo): void {
    $in = "SELECT id FROM catalogs WHERE name LIKE 'test-f11d %'";
    $pdo->exec("DELETE FROM access_log WHERE catalog_id IN ({$in})");
    $pdo->exec("DELETE FROM catalogs WHERE name LIKE 'test-f11d %'");
    $pdo->exec("DELETE FROM species_photos WHERE taxonID LIKE '99001110_'");
    $pdo->exec("DELETE FROM species WHERE taxonID LIKE '99001110_'");
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f11d-%@biodex.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f11d-%@biodex.test'");
    $pdo->exec("DROP TABLE IF EXISTS tmp_f11d_photo_files");
    $pdo->exec("DROP TABLE IF EXISTS tmp_f11d_photos");
}
cleanup($pdo);

// ------------------------------------------------------------------------------------------------
section("GBIF (fixtures de docs/gbif-samples, sin red)");
foreach (["exacta-cc-by", "foto-copyright", "foto-sin-autor", "sinonimo", "fuzzy", "subespecie-vs-especie", "otro-reino"] as $name) {
    $fx = fixture(GBIF_SAMPLES, $name);
    if (!$fx) continue;
    $t = gbifMatchDecide($fx["species"], $fx["match"]);
    $exp = $fx["expected"];
    if ($exp["status"] === "none") {
        check("{$name} → none (" . ($t["reason"] ?? "—") . ")", ($t["status"] ?? "") === "none", json_encode($t, JSON_UNESCAPED_UNICODE));
        continue;
    }
    $occ = $fx["occurrences"]["ES"] ?? $fx["occurrences"]["all"];
    $c = gbifCandidates($occ, $t["key"] ?? 0, $fx["species"]["rank"]);
    $status = ($t["match_type"] ?? "") === "synonym" ? "review" : "ok";
    check("{$name} → {$exp["status"]} con la ocurrencia {$exp["gbifID"]}", $status === $exp["status"] && ($t["match_type"] ?? "") === $exp["match_type"]
        && (int) ($c[0]["gbifID"] ?? 0) === $exp["gbifID"], json_encode([$t, $c[0] ?? null], JSON_UNESCAPED_UNICODE));
    if (isset($exp["skipped"])) {
        check("{$name}: la ocurrencia {$exp["skipped"]} no es candidata", !in_array((string) $exp["skipped"], array_column($c, "gbifID"), true));
    }
}
$fx = fixture(GBIF_SAMPLES, "exacta-cc-by");
$c = gbifCandidates($fx["occurrences"]["ES"], 8011422, "Species")[0] ?? [];
check("exacta: licencia y versión de la FOTO, autor, md5 del identifier", $c["license"] === "cc-by" && $c["license_version"] === "4.0"
    && $c["attribution"] !== "" && $c["md5"] === md5($c["identifier"]), json_encode($c, JSON_UNESCAPED_UNICODE));
check("URL de la caché de imágenes de GBIF", gbifImageUrl("4923840410", "3f91f30492308e7e374c14b74e467fee")
    === "https://api.gbif.org/v1/image/cache/500x/occurrence/4923840410/media/3f91f30492308e7e374c14b74e467fee");
$fx = fixture(GBIF_SAMPLES, "sinonimo");
$t = gbifMatchDecide($fx["species"], $fx["match"]);
check("sinónimo: busca fotos del aceptado (Timon lepidus, 2468542)", $t["key"] === 2468542 && $t["matched_name"] === "Timon lepidus", json_encode($t));
$occ = $fx["occurrences"]["ES"];
check("una ocurrencia con TAXON_MATCH_FUZZY no es candidata",
    gbifCandidates(["results" => [["issues" => ["TAXON_MATCH_FUZZY"]] + $occ["results"][0]]], 2468542, "Species") === []);
check("una ocurrencia de otra especie no es candidata",
    gbifCandidates(["results" => [["speciesKey" => 1] + $occ["results"][0]]], 2468542, "Species") === []);
check("formato que no es jpeg/png no es candidata", gbifCandidates(["results" => [["media" => [["format" => "image/gif"] + $occ["results"][0]["media"][0]]]
    + $occ["results"][0]]], 2468542, "Species") === []);
check("sinónimo DOUBTFUL → review con su propia clave",
    (gbifMatchDecide(["name" => "Lacerta lepida", "rank" => "Species", "kingdom" => "Animalia"], ["status" => "DOUBTFUL"] + $fx["match"])["key"] ?? 0) === 8033162);
check("sinónimo «pro parte» → none", gbifMatchDecide($fx["species"], ["status" => "PROPARTE_SYNONYM"] + $fx["match"])["status"] === "none");
check("HIGHERRANK → none", gbifMatchDecide($fx["species"], ["matchType" => "HIGHERRANK"] + $fx["match"])["status"] === "none");

$licenses = [
    "http://creativecommons.org/licenses/by/4.0/" => ["cc-by", "4.0"],
    "https://creativecommons.org/licenses/by-nc/4.0/legalcode" => ["cc-by-nc", "4.0"],
    "http://creativecommons.org/licenses/by-sa/3.0/" => ["cc-by-sa", "3.0"],
    "http://creativecommons.org/publicdomain/zero/1.0/" => ["cc0", "1.0"],
    "CC_BY_NC_4_0" => ["cc-by-nc", "4.0"], "CC0_1_0" => ["cc0", "1.0"], "CC BY-SA 4.0" => ["cc-by-sa", "4.0"], "cc-by" => ["cc-by", null],
    "http://creativecommons.org/licenses/by-nc-nd/4.0/" => null, "http://creativecommons.org/licenses/by-nc-sa/4.0/" => null,
    "Copyright by the creator. For license and creator details, see https://nabu-naturgucker-beobachtungen.de/" => null,
    "Denis Bastianelli (cc-by-sa)" => null, "All rights reserved" => null, "" => null,
];
$bad = array_filter(array_keys($licenses), fn($s) => gbifLicense($s) !== $licenses[$s]);
check("licencias de la foto en GBIF: URL de CC o código; texto libre, NC-ND y NC-SA → no", !$bad,
    implode(" | ", array_map(fn($s) => "«{$s}» → " . json_encode(gbifLicense($s)), $bad)));

// ------------------------------------------------------------------------------------------------
section("Wikimedia Commons (fixtures de docs/commons-samples, sin red)");
$fx = fixture(COMMONS_SAMPLES, "exacta-cc-by-sa");
$t = commonsTaxonDecide($fx["species"], $fx["search"], $fx["entities"]);
$p = commonsPhotoDecide($fx["imageinfo"]);
check("P225 exacto con P18 → Q15544927 y su fichero", ($t["qid"] ?? "") === "Q15544927"
    && ($t["file"] ?? "") === "ACHILLEA PYRENAICA - GENTO - IB-907 (Camamilla de muntanya).jpg", json_encode($t, JSON_UNESCAPED_UNICODE));
check("foto CC BY-SA 4.0 → ok con licencia y versión, autor sin HTML", ($p["license"] ?? "") === "cc-by-sa" && ($p["license_version"] ?? "") === "4.0"
    && ($p["attribution"] ?? "") === "Isidre blanc" && photoLicenseLabel($p["license"], $p["license_version"]) === "CC BY-SA 4.0", json_encode($p, JSON_UNESCAPED_UNICODE));
check("la miniatura es la thumburl de la API (thumb.wikimedia.org, 500 px) y el crédito enlaza a descriptionurl",
    str_starts_with($p["thumburl"] ?? "", "https://thumb.wikimedia.org/wikipedia/commons/thumb/") && str_contains($p["thumburl"], "/500px-")
    && str_starts_with($p["page_url"] ?? "", "https://commons.wikimedia.org/wiki/File:"));
foreach (["dos-elementos", "rango-distinto", "sin-p18"] as $name) {
    $fx = fixture(COMMONS_SAMPLES, $name);
    if (!$fx) continue;
    $t = commonsTaxonDecide($fx["species"], $fx["search"], $fx["entities"] ?? []);
    check("{$name} → none (" . ($t["reason"] ?? "—") . ")", ($t["status"] ?? "") === "none", json_encode($t, JSON_UNESCAPED_UNICODE));
}
$fx = fixture(COMMONS_SAMPLES, "solo-gfdl");
$p = commonsPhotoDecide($fx["imageinfo"]);
check("GFDL sola → none (" . ($p["reason"] ?? "—") . ")", ($p["status"] ?? "") === "none");
$fx = fixture(COMMONS_SAMPLES, "artist-html");
$p = commonsPhotoDecide($fx["imageinfo"]);
check("Artist con HTML anidado → «{$fx["expected"]["attribution"]}»", ($p["attribution"] ?? "") === $fx["expected"]["attribution"], json_encode($p, JSON_UNESCAPED_UNICODE));
$base = fixture(COMMONS_SAMPLES, "exacta-cc-by-sa")["imageinfo"];
$withMeta = function (array $meta, array $info = []) use ($base) {
    $r = $base;
    $id = array_key_first($r["query"]["pages"]);
    $r["query"]["pages"][$id]["imageinfo"][0] = $info + $r["query"]["pages"][$id]["imageinfo"][0];
    foreach ($meta as $k => $v) {
        if ($v === null) unset($r["query"]["pages"][$id]["imageinfo"][0]["extmetadata"][$k]);
        else $r["query"]["pages"][$id]["imageinfo"][0]["extmetadata"][$k] = ["value" => $v];
    }
    return commonsPhotoDecide($r);
};
check("Commons no admite NC ni ND", ($withMeta(["License" => "cc-by-nc-sa-4.0"])["status"] ?? "") === "none"
    && ($withMeta(["License" => "cc-by-nd-4.0"])["status"] ?? "") === "none");
check("sin autor y CC BY-SA → none; sin autor y dominio público → ok", ($withMeta(["Artist" => null])["status"] ?? "") === "none"
    && ($withMeta(["Artist" => null, "License" => "pd"])["license"] ?? "") === "pd");
check("CC BY-SA 3.0 DE → versión «3.0-de», etiqueta «CC BY-SA 3.0 DE»", ($withMeta(["License" => "cc-by-sa-3.0-de"])["license_version"] ?? "") === "3.0-de"
    && photoLicenseLabel("cc-by-sa", "3.0-de") === "CC BY-SA 3.0 DE");
check("miniatura fuera de Wikimedia o tipo no admitido → none", ($withMeta([], ["thumburl" => "https://example.org/x.jpg"])["status"] ?? "") === "none"
    && ($withMeta([], ["mime" => "image/tiff"])["status"] ?? "") === "none");
check("nombre zoológico sin marca de rango para P225; botánico con ella",
    commonsTaxonName(["name" => "Turdus merula subsp. cabrerae", "kingdom" => "Animalia"]) === "Turdus merula cabrerae"
    && commonsTaxonName(["name" => "Acer opalus subsp. granatense", "kingdom" => "Plantae"]) === "Acer opalus subsp. granatense");

// ------------------------------------------------------------------------------------------------
section("Copias y etiquetas");
$png = fakePng(500, 375);
$v = photoValidateBytes($png, GBIF_MIMES);
check("PNG válido → tipo por contenido, dimensiones y sha256", ($v["mime"] ?? "") === "image/png" && ($v["width"] ?? 0) === 500
    && ($v["sha256"] ?? "") === hash("sha256", $png), json_encode($v));
check("HTML que dice ser imagen → no", isset(photoValidateBytes("<html><body>hola</body></html>", GBIF_MIMES)["reason"]));
check("webp no admitido en GBIF → no", isset(photoValidateBytes("RIFF\x24\0\0\0WEBPVP8 ", GBIF_MIMES)["reason"]));
check("demasiado pequeña (20 × 20) → no", isset(photoValidateBytes(fakePng(20, 20), GBIF_MIMES)["reason"]));
check("más de 1,5 MB → no", isset(photoValidateBytes(fakePng(500, 500, 1_600_000), GBIF_MIMES)["reason"]));
check("etiquetas: «CC BY-NC» (iNat, sin versión), «CC0», «Dominio público»", photoLicenseLabel("cc-by-nc") === "CC BY-NC"
    && photoLicenseLabel("cc0", "1.0") === "CC0" && photoLicenseLabel("pd") === "Dominio público" && photoLicenseLabel("cc-by", "4.0") === "CC BY 4.0");
check("crédito en texto plano con tope de 200 caracteres", photoPlainText("<b>Ana</b> &amp; <i>Luis</i>") === "Ana & Luis"
    && mb_strlen(photoPlainText(str_repeat("a", 300))) === 200);
check("User-Agent con versión y URL", (bool) preg_match('#^BioDex/[0-9.]+ \(https?://[^;)]+(; [^)]+)?\)$#', photoUserAgent()), photoUserAgent());

// ------------------------------------------------------------------------------------------------
section("Cascada (fuentes falsas)");
$calls = [];
$fake = function (string $src, array $result) use (&$calls) {
    return function () use ($src, $result, &$calls) {
        $calls[] = $src;
        if (isset($result["throw"])) throw new PhotoSourceException($result["throw"]);
        return $result;
    };
};
$okGbif = ["status" => "ok", "reason" => "coincidencia exacta", "matched_name" => "X y", "license" => "cc-by", "photo_ref" => "1/abc", "file" => []];
$sp = ["name" => "X y", "rank" => "Species", "kingdom" => "Plantae"];
$calls = [];
$d = photoCascade($sp, [], ["inaturalist" => $fake("inaturalist", ["status" => "none", "reason" => "iNat: sin coincidencia"]),
    "gbif" => $fake("gbif", $okGbif), "commons" => $fake("commons", ["status" => "ok"])]);
check("iNat none → GBIF ok (Commons no se consulta)", $d["status"] === "ok" && $d["source"] === "gbif" && $calls === ["inaturalist", "gbif"]
    && isset($d["log"]["inaturalist"]), json_encode($d));
$calls = [];
$d = photoCascade($sp, [], ["inaturalist" => $fake("inaturalist", ["throw" => "HTTP 503 tras 3 reintentos"]),
    "gbif" => $fake("gbif", $okGbif), "commons" => $fake("commons", ["status" => "ok"])]);
check("iNat con error de red → pending, sin pasar a GBIF", $d["status"] === "pending" && $d["source"] === "inaturalist" && $calls === ["inaturalist"], json_encode($d));
$calls = [];
$d = photoCascade($sp, ["inaturalist"], ["inaturalist" => $fake("inaturalist", ["status" => "ok"]),
    "gbif" => $fake("gbif", $okGbif), "commons" => $fake("commons", ["status" => "ok"])]);
check("iNat rechazada → se salta (ni se consulta)", $d["source"] === "gbif" && $calls === ["gbif"] && $d["log"]["inaturalist"] === "rechazada en la revisión");
$calls = [];
$none = fn(string $s) => $fake($s, ["status" => "none", "reason" => "sin foto"]);
$d = photoCascade($sp, [], ["inaturalist" => $none("inaturalist"), "gbif" => $none("gbif"), "commons" => $none("commons")]);
check("todo none → none, con el motivo de cada fuente", $d["status"] === "none" && $d["source"] === null && count($d["log"]) === 3 && count($calls) === 3);
$calls = [];
$d = photoCascade($sp, [], ["inaturalist" => $none("inaturalist"), "gbif" => $none("gbif"), "commons" => $none("commons")], "commons");
check("--source=commons: solo esa fuente", $calls === ["commons"]);
$d = photoCascade($sp, [], ["inaturalist" => $fake("inaturalist", ["status" => "review", "reason" => "sinónimo"])]);
check("review cierra la búsqueda", $d["status"] === "review" && $d["source"] === "inaturalist");
$calls = [];
$br = photoBreaker(["commons" => $fake("commons", ["throw" => "maxlag"])]);
for ($i = 0; $i < 5; $i++) { try { $br["commons"]($sp); } catch (PhotoSourceException $e) { $last = $e->getMessage(); } }
check("cortocircuito: tras 3 errores seguidos la fuente no se vuelve a llamar en la pasada", count($calls) === 3 && str_contains($last, "no responde"), $last);
$line = photoCascadeLine("1", "X y", ["source" => "gbif", "log" => ["inaturalist" => "iNat: sin coincidencia"]] + $okGbif + ["license_version" => "4.0"]);
check("línea del script: fuente que decide y por qué se descartaron las anteriores", str_contains($line, "GBIF: X y · CC BY 4.0 · 1/abc")
    && str_contains($line, "(iNat: sin coincidencia)"), $line);

// ------------------------------------------------------------------------------------------------
section("Migración 013 (sobre tablas temporales)");
$ren = fn(string $sql) => str_replace(["species_photo_files", "species_photos", "fk_photos_species", "fk_photos_reviewer", "fk_photo_files_photo", "ix_photos_status"],
    ["tmp_f11d_photo_files", "tmp_f11d_photos", "fk_tmpf11d_species", "fk_tmpf11d_reviewer", "fk_tmpf11d_files", "ix_tmpf11d_status"], $sql);
$m012 = splitSqlStatements(file_get_contents(MIGRATIONS_DIR . "/012_species_photos.sql"));
$pdo->exec($ren($m012[0]));   // solo el CREATE TABLE (el ALTER de access_log ya está aplicado)
$sp2 = $pdo->prepare("INSERT INTO species (taxonID, source, scientificName, canonical_name, taxonRank) VALUES (?, 'eidos', ?, ?, 'Species')");
foreach (["990011105", "990011106", "990011107"] as $t) $sp2->execute([$t, "Testia mig{$t}", "Testia mig{$t}"]);
$pdo->exec("INSERT INTO tmp_f11d_photos (taxonID, status, photo_id, photo_ext, license, attribution, matched_name) VALUES
    ('990011105', 'rejected', 11, 'jpg', 'cc-by', 'a', 'X'), ('990011106', 'none', NULL, NULL, NULL, NULL, NULL),
    ('990011107', 'ok', 12, 'jpg', 'cc-by-nc', 'b', 'Y')");
foreach (splitSqlStatements(file_get_contents(MIGRATIONS_DIR . "/013_species_photos_sources.sql")) as $sql) $pdo->exec($ren($sql));
$mig = $pdo->query("SELECT taxonID, status, rejected_sources, source, photo_id FROM tmp_f11d_photos ORDER BY taxonID")->fetchAll(PDO::FETCH_UNIQUE);
check("rejected → pending con rejected_sources = inaturalist y sin datos de foto", $mig["990011105"]["status"] === "pending"
    && $mig["990011105"]["rejected_sources"] === "inaturalist" && $mig["990011105"]["photo_id"] === null && $mig["990011105"]["source"] === null, json_encode($mig));
check("none → pending (el primer despliegue busca ya en GBIF y Commons)", $mig["990011106"]["status"] === "pending" && $mig["990011106"]["rejected_sources"] === "");
check("ok de iNat se queda tal cual", $mig["990011107"]["status"] === "ok" && $mig["990011107"]["source"] === "inaturalist" && (int) $mig["990011107"]["photo_id"] === 12);
$enum = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'species_photos' AND COLUMN_NAME = 'status'")->fetchColumn();
check("«rejected» ya no está en el ENUM de status (tabla real)", $enum === "enum('pending','ok','review','none')", (string) $enum);
$pdo->exec("DROP TABLE tmp_f11d_photo_files");
$pdo->exec("DROP TABLE tmp_f11d_photos");

// ------------------------------------------------------------------------------------------------
section("Servicio /img/respaldo/ y tarjeta");
$suffix = bin2hex(random_bytes(4));
$jars = ["o" => [], "c" => [], "e" => []];
$ids = [];
foreach ($jars as $k => &$jar) {
    $email = "f11d-{$k}-{$suffix}@biodex.test";
    [$code] = http("POST", "/api/auth/register", ["email" => $email, "password" => "correcta-horse-battery", "name" => "F11d " . strtoupper($k)], $jar);
    $ids[$k] = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();
    check("registro de " . strtoupper($k), $code === 201, "HTTP {$code}");
}
unset($jar);
[$o, $cu, $e] = [$jars["o"], $jars["c"], $jars["e"]];
[$code, $r] = http("POST", "/api/catalogs", ["name" => "test-f11d {$suffix}"], $o);
$cat = $r["catalog"]["id"] ?? "";
$ins = $pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role, granted_by) VALUES (?, ?, ?, ?)");
foreach (["c" => "custodian", "e" => "editor"] as $k => $role) $ins->execute([$cat, $ids[$k], $role, $ids["o"]]);
$spIns = $pdo->prepare("INSERT INTO species (taxonID, source, scientificName, canonical_name, taxonRank, kingdom, phylum, class, `order`,
                        genus, specificEpithet, images, detail_fetched_at) VALUES (?, 'eidos', ?, ?, 'Species', 'Plantae', 'Tracheophyta',
                        'Magnoliopsida', 'Asterales', 'Testia', ?, NULL, NOW())");
foreach (["990011101" => "gbif", "990011102" => "revision", "990011103" => "ultima"] as $t => $ep) {
    $spIns->execute([$t, "Testia {$ep} Autor", "Testia {$ep}", $ep]);
    [$code] = http("POST", "/api/catalogs/{$cat}/species", ["taxonID" => $t], $o);
    check("alta de {$t} en el catálogo (queda pending)", $code === 201 && $pdo->query("SELECT status FROM species_photos WHERE taxonID = '{$t}'")->fetchColumn() === "pending");
}
$save = function (string $t, string $status, string $source, string $bytes, string $rejected = "") use ($pdo) {
    $v = photoValidateBytes($bytes, COMMONS_MIMES);
    $ok = photoSaveDecision($pdo, $t, ["status" => $status, "source" => $source, "matched_name" => "Testia otra", "match_type" => $status === "review" ? "synonym" : "exact",
        "source_ref" => $source === "gbif" ? "2468542" : "Q1", "photo_ref" => $source === "gbif" ? "4923840410/3f91f30492308e7e374c14b74e467fee" : "Testia.jpg",
        "photo_page_url" => $source === "gbif" ? "https://www.gbif.org/occurrence/4923840410" : "https://commons.wikimedia.org/wiki/File:Testia.jpg",
        "attribution" => "Ana Garcia", "license" => "cc-by-sa", "license_version" => "4.0", "file" => ["bytes" => $bytes] + $v]);
    if ($rejected !== "") $pdo->prepare("UPDATE species_photos SET rejected_sources = ? WHERE taxonID = ?")->execute([$rejected, $t]);
    return [$ok, photoBackupUrl($t, $v["sha256"], $v["mime"])];
};
$pngA = fakePng(500, 375);
[$saved, $urlA] = $save("990011101", "ok", "gbif", $pngA);
check("photoSaveDecision guarda la decisión y la copia", $saved && (int) $pdo->query("SELECT LENGTH(bytes) FROM species_photo_files WHERE taxonID = '990011101'")->fetchColumn() === strlen($pngA));
[$code, $body, $h] = raw("GET", $urlA);
check("GET {$urlA} → 200 con los bytes", $code === 200 && $body === $pngA, "HTTP {$code}");
check("Content-Type image/png, nosniff, CSP y caché inmutable", ($h["content-type"] ?? "") === "image/png" && ($h["x-content-type-options"] ?? "") === "nosniff"
    && str_contains($h["content-security-policy"] ?? "", "img-src 'self' data:") && ($h["cache-control"] ?? "") === "public, max-age=31536000, immutable",
    json_encode($h));
[$code] = raw("GET", $urlA, ["If-None-Match: " . ($h["etag"] ?? "")]);
check("If-None-Match con su ETag → 304", $code === 304, "HTTP {$code}");
$bad = substr($urlA, 0, -20) . "0123456789abcdef.png";
[$code] = raw("GET", $bad);
check("hash que no es el de la copia actual → 404", $code === 404, "HTTP {$code}");
[$code] = raw("GET", preg_replace('/\.png$/', ".jpg", $urlA));
check("extensión que no es la de la copia → 404", $code === 404, "HTTP {$code}");
[$code] = raw("GET", "/img/respaldo/990011199/0123456789abcdef.jpg");
check("especie sin copia → 404", $code === 404, "HTTP {$code}");
$hash = substr($urlA, -20, 16);
foreach (["/img/respaldo/abc/{$hash}.png", "/img/respaldo/990011101/" . strtoupper($hash) . ".png", "/img/respaldo/990011101/{$hash}.png?x=1",
          "/img/respaldo/990011101/{$hash}.gif", "/img/respaldo/990011101/{$hash}0.png", "/img/respaldo/%39%39/{$hash}.png",
          "/img/%72espaldo/990011101/{$hash}.png", "/img/respaldo/990011101/{$hash}%2Epng", "/img/respaldo/../inat/1/medium.jpg",
          "/img/respaldo/990011101/../990011101/{$hash}.png", "/img/respaldo/1234567890123/{$hash}.png", "/img/respaldo/990011101/"] as $p) {
    [$code] = raw("GET", $p);
    check("{$p} → 400", $code === 400, "HTTP {$code}");
}
[$code] = raw("POST", $urlA);
check("POST → 403 (solo GET)", $code === 403, "HTTP {$code}");

$card = fn(array $jar, string $t) => array_values(array_filter(http("GET", "/api/catalogs/{$cat}", null, $jar)[1]["species"] ?? [],
    fn($s) => $s["taxonID"] === $t))[0] ?? [];
$a = $card($e, "990011101");
check("tarjeta: thumbnail /img/respaldo/… y crédito {text, «CC BY-SA 4.0», enlace a la ocurrencia, gbif}", ($a["thumbnail"] ?? "") === $urlA
    && ($a["photoCredit"] ?? null) === ["text" => "Ana Garcia", "license" => "CC BY-SA 4.0", "url" => "https://www.gbif.org/occurrence/4923840410", "source" => "gbif"],
    json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
[, $off] = http("GET", "/api/catalogs/{$cat}/offline", null, $e);
$offA = array_values(array_filter($off["species"] ?? [], fn($s) => $s["taxonID"] === "990011101"))[0] ?? [];
check("y en el paquete offline", ($offA["thumbnail"] ?? "") === $urlA);

// Una de GBIF por sinónimo, pendiente de revisión.
$pngB = fakePng(400, 300);
[, $urlB] = $save("990011102", "review", "gbif", $pngB);
$b = $card($e, "990011102");
check("review: la tarjeta no la enseña", array_key_exists("thumbnail", $b) && $b["thumbnail"] === null && $b["photoCredit"] === null);
[$code, , $h] = raw("GET", $urlB);
check("review sin sesión → 404", $code === 404, "HTTP {$code}");
[$code] = raw("GET", $urlB, [], $e);
check("review con sesión de editor → 404", $code === 404, "HTTP {$code}");
[$code, $body, $h] = raw("GET", $urlB, [], $cu);
check("review con sesión de custodio → 200, sin caché compartida", $code === 200 && $body === $pngB && ($h["cache-control"] ?? "") === "private, no-store",
    "HTTP {$code} " . json_encode($h));
[, $rc] = http("GET", "/api/catalogs/{$cat}", null, $cu);
$pr = array_values(array_filter($rc["photoReviews"] ?? [], fn($x) => $x["taxonID"] === "990011102"))[0] ?? [];
check("photoReviews: fuente GBIF, enlace al taxón en GBIF y foto de /img/respaldo/", ($pr["sourceName"] ?? "") === "GBIF"
    && ($pr["sourceTaxonUrl"] ?? "") === "https://www.gbif.org/species/2468542" && ($pr["photo"] ?? "") === $urlB
    && ($pr["photoCredit"]["license"] ?? "") === "CC BY-SA 4.0", json_encode($pr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// ------------------------------------------------------------------------------------------------
section("Revisión");
$review = fn(array $jar, string $t, string $decision) => http("POST", "/api/species/{$t}/photo-review", ["catalogId" => $cat, "decision" => $decision], $jar);
[$code, $r] = $review($cu, "990011102", "reject");
$row = $pdo->query("SELECT status, rejected_sources, source, photo_ref FROM species_photos WHERE taxonID = '990011102'")->fetch();
check("rechazar la de GBIF → pending con gbif en rejected_sources", $code === 200 && ($r["status"] ?? "") === "pending" && $row["status"] === "pending"
    && $row["rejected_sources"] === "gbif" && $row["source"] === null && $row["photo_ref"] === null, "HTTP {$code} " . json_encode($row));
check("y se borra su copia local", !$pdo->query("SELECT 1 FROM species_photo_files WHERE taxonID = '990011102'")->fetchColumn());
[$code] = raw("GET", $urlB, [], $cu);
check("su URL deja de servirse (404)", $code === 404, "HTTP {$code}");
$log = $pdo->query("SELECT target FROM access_log WHERE action = 'photo_review' AND catalog_id = " . $pdo->quote($cat))->fetchColumn();
check("access_log: «foto de GBIF … rechazada»", str_contains((string) $log, "foto de GBIF 4923840410/") && str_contains((string) $log, "rechazada"), (string) $log);

[, $urlC] = $save("990011103", "review", "gbif", fakePng(300, 300), "inaturalist,commons");
[$code, $r] = $review($o, "990011103", "reject");
check("rechazar la última fuente que quedaba → none", $code === 200 && ($r["status"] ?? "") === "none"
    && $pdo->query("SELECT CONCAT(status, '|', rejected_sources) FROM species_photos WHERE taxonID = '990011103'")->fetchColumn() === "none|inaturalist,gbif,commons");

// ------------------------------------------------------------------------------------------------
section("Script");
$out = [];
exec("php " . escapeshellarg(__DIR__ . "/fetch-species-photos.php") . " --source=flickr 2>&1", $out, $rc1);
check("--source no válida → código 2", $rc1 === 2, implode("\n", $out));
$pdo->exec("UPDATE species_photos SET checked_at = NOW() WHERE status = 'pending' AND taxonID LIKE '99001110_'");   // que la pasada no las coja
$out = [];
exec("php " . escapeshellarg(__DIR__ . "/fetch-species-photos.php") . " --dry-run --limit=1 --source=commons 2>&1", $out, $rc2);
check("--dry-run --source=commons acaba con el resumen por fuente", $rc2 === 0 && (bool) preg_match('/^BioDex fotos \(en seco, sin escribir\) \(solo Commons\): iNat ok \d+ · GBIF ok \d+ · Commons ok \d+ · review/', end($out) ?: ""),
    implode("\n", $out));

cleanup($pdo);
echo $failures ? "\n{$failures} fallo(s)\n" : "\nTodo OK\n";
exit($failures ? 1 : 0);
