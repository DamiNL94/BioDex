<?php
// ================================================================================================
// Pruebas de F10a (exportación): docker compose exec php php bin/test-f10a.php
//   · Permisos: «completa» por editor → 403; por owner y custodio → 200 y export_full en access_log.
//   · «Para publicar»: nivel 2 al centro de la celda de 0,1° con dataGeneralizations; nivel 1 sin
//     coordenadas y con informationWithheld; nivel 4 exactas; también cuando exporta el owner.
//   · occurrence.txt: una fila por marca no borrada, occurrenceID únicos, sin tabuladores internos,
//     cabecera = meta.xml; meta.xml (contra el XSD de DwC si hay red) y eml.xml bien formados; XLSX
//     válido (openpyxl si está; si no, cada XML del paquete); CSV con BOM y «;»; filtros.
// Crea usuarios f10a-*@biodex.test, el catálogo «test-f10a …» y una especie propia; los borra al terminar.
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/api.php";        // uuidV4()
require_once __DIR__ . "/../Lib/migrate.php";
require_once __DIR__ . "/../Lib/export.php";     // DWC_OCCURRENCE_TERMS, generalizePoint()
require_once __DIR__ . "/test-lib.php";

const ARTEMISIA = "9918";

$pdo = db();
runMigrations($pdo);

/** Como http() de test-lib, pero devuelve el cuerpo sin decodificar (el ZIP). */
function httpRaw(string $method, string $path, ?array $body, array $jar): array {
    global $BASE;
    $headers = [];
    $ch = curl_init($BASE . $path);
    $h = ["Content-Type: application/json"];
    if ($jar) $h[] = "Cookie: " . implode("; ", array_map(fn($k, $v) => "{$k}={$v}", array_keys($jar), $jar));
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 120, CURLOPT_POSTFIELDS => json_encode($body ?? new stdClass()),
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
            if (preg_match("/^([\w-]+):\s*(.*)$/", trim($line), $m)) $headers[strtolower($m[1])] = $m[2];
            return strlen($line);
        }]);
    $raw = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $raw, $headers];
}

/** ZIP en memoria → [ruta interna → contenido]. */
function unzipAll(string $raw): array {
    $tmp = tempnam(sys_get_temp_dir(), "t10");
    file_put_contents($tmp, $raw);
    $zip = new ZipArchive();
    $out = [];
    if ($zip->open($tmp) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) $out[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        $zip->close();
    }
    unlink($tmp);
    return $out;
}

/** occurrence.txt → [cabecera, filas como término → valor, líneas con nº de columnas distinto]. */
function parseTsv(string $txt): array {
    $lines = explode("\n", rtrim($txt, "\n"));
    $head = explode("\t", array_shift($lines));
    $rows = [];
    $bad = 0;
    foreach ($lines as $l) {
        $cols = explode("\t", $l);
        if (count($cols) !== count($head)) { $bad++; continue; }
        $rows[] = array_combine($head, $cols);
    }
    return [$head, $rows, $bad];
}

function wellFormed(string $xml): ?DOMDocument {
    $d = new DOMDocument();
    return @$d->loadXML($xml) ? $d : null;
}

$userTaxon = "biodex:" . uuidV4();

function cleanup(PDO $pdo): void {
    $in = "SELECT id FROM catalogs WHERE name LIKE 'test-f10a %'";
    foreach (["occurrences", "species_notes", "species_assessments", "access_log", "species_metrics"] as $t) {
        $pdo->exec("DELETE FROM {$t} WHERE catalog_id IN ({$in})");
    }
    $pdo->exec("DELETE FROM catalogs WHERE name LIKE 'test-f10a %'");
    $pdo->exec("DELETE FROM species WHERE source = 'user' AND scientificName LIKE 'Testia f10a%'");
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f10a-%@biodex.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f10a-%@biodex.test'");
}
cleanup($pdo);

// ------------------------------------------------------------------------------------------------
section("Usuarios, catálogo, especies de nivel 1, 2 y 4 y marcas");
$suffix = bin2hex(random_bytes(4));
$jars = ["o" => [], "c" => [], "e" => [], "v" => [], "x" => []];
$ids = [];
foreach ($jars as $k => &$jar) {
    $email = "f10a-{$k}-{$suffix}@biodex.test";
    [$code] = http("POST", "/api/auth/register", ["email" => $email, "password" => "correcta-horse-battery", "name" => "F10a " . strtoupper($k)], $jar);
    check("registro de " . strtoupper($k), $code === 201, "HTTP {$code}");
    $ids[$k] = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();
}
unset($jar);
$pdo->prepare("UPDATE users SET orcid = '0000-0002-1825-0097' WHERE id = ?")->execute([$ids["o"]]);
[$o, $cu, $e, $v, $x] = [$jars["o"], $jars["c"], $jars["e"], $jars["v"], $jars["x"]];

[$code, $r] = http("POST", "/api/catalogs", ["name" => "test-f10a {$suffix}", "description" => "Catálogo de prueba de la exportación.",
    "taxonIDs" => [ARTEMISIA]], $o);
check("O crea el catálogo con la Artemisia", $code === 201, "HTTP {$code}");
$cat = $r["catalog"]["id"] ?? "";
$ins = $pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role, granted_by) VALUES (?, ?, ?, ?)");
foreach (["c" => "custodian", "e" => "editor", "v" => "viewer"] as $k => $role) $ins->execute([$cat, $ids[$k], $role, $ids["o"]]);

// Otra especie de EIDOS ya en caché (nivel 1) y una propia (nivel 4), dadas de alta en el catálogo a mano.
$other = (string) $pdo->query("SELECT taxonID FROM species WHERE source = 'eidos' AND taxonID <> '" . ARTEMISIA . "'
                                  AND taxonRank = 'Species' ORDER BY taxonID LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO species (taxonID, source, scientificName, scientificNameAuthorship, canonical_name, taxonRank, kingdom, genus,
               specificEpithet, classification_source, created_by) VALUES (?, 'user', 'Testia f10a Autor', 'Autor', 'Testia f10a', 'Species',
               'Plantae', 'Testia', 'f10a', 'user', ?)")->execute([$userTaxon, $ids["o"]]);
$cs = $pdo->prepare("INSERT INTO catalog_species (catalog_id, taxonID, added_by) VALUES (?, ?, ?)");
$lv = $pdo->prepare("INSERT INTO species_sensitivity (catalog_id, taxonID, level, suggested_level, reason, set_by) VALUES (?, ?, ?, ?, 'prueba', ?)
                     ON DUPLICATE KEY UPDATE level = VALUES(level), set_by = VALUES(set_by)");
foreach ([[$other, 1], [$userTaxon, 4]] as [$t, $n]) { $cs->execute([$cat, $t, $ids["o"]]); $lv->execute([$cat, $t, $n, $n, $ids["o"]]); }
$lv->execute([$cat, ARTEMISIA, 2, 2, $ids["o"]]);
check("especies: Artemisia (2), {$other} (1), propia (4)", $other !== "");

$mark = fn(string $taxon, float $lat, float $lon, array $patch = []) => $patch + ["occurrenceID" => uuidV4(), "taxonID" => $taxon,
    "eventDate" => "2026-06-15T10:00:00+02:00", "decimalLatitude" => $lat, "decimalLongitude" => $lon,
    "coordinateUncertaintyInMeters" => 8, "clientUpdatedAt" => "2026-06-15T10:05:00.000Z"];
$l2 = [$mark(ARTEMISIA, 37.0931234, -3.3987654, ["occurrenceRemarks" => "junto a la fuente\tdel collado\nsegunda línea",
        "individualCount" => 12, "mature_count" => 7, "habitat" => "Pastizal\tpsicroxerófilo"]),
       $mark(ARTEMISIA, 37.1011, -3.3801, ["eventDate" => "2026-07-20T09:00:00+02:00"]),
       $mark(ARTEMISIA, 37.12, -3.41, ["occurrenceStatus" => "absent", "individualCount" => 0])];
$l1 = [$mark($other, 37.2, -3.6, ["georeferenceRemarks" => "piedra con marca roja", "georeferenceProtocol" => "Punto marcado en el mapa",
        "coordinateUncertaintyInMeters" => 30])];
$l4 = [$mark($userTaxon, 37.15, -3.5, ["individualCount" => 3, "mature_count" => 2, "sex" => "female", "lifeStage" => "adult"])];
$gone = $mark(ARTEMISIA, 37.05, -3.35);
$push = fn(array $jar, array $body) => http("POST", "/api/sync/push", ["catalogId" => $cat] + $body, $jar);
[$c1, $r1] = $push($o, ["occurrences" => [...$l2, $gone]]);
[$c2, $r2] = $push($e, ["occurrences" => [...$l1, ...$l4]]);
[$c3] = $push($o, ["deletes" => [["occurrenceID" => $gone["occurrenceID"], "clientUpdatedAt" => "2026-06-16T10:00:00.000Z"]]]);
$accepted = count(array_filter([...$r1["results"], ...$r2["results"]], fn($z) => $z["status"] === "accepted"));
check("6 marcas subidas y 1 borrada", $c1 === 200 && $c2 === 200 && $c3 === 200 && $accepted === 6, "{$accepted} aceptadas");
[$c4] = $push($e, ["notes" => [["noteID" => uuidV4(), "taxonID" => ARTEMISIA, "text" => "Floración tardía este año.", "clientUpdatedAt" => "2026-07-01T08:00:00.000Z"]],
    "assessments" => [["assessmentID" => uuidV4(), "taxonID" => ARTEMISIA, "status" => "final", "clientUpdatedAt" => "2026-07-02T08:00:00.000Z",
        "inputs" => ["B" => ["eoo" => 80, "aoo" => 12, "locations" => 3, "decline" => ["iii"]]],
        "result" => ["category" => "EN", "baseCategory" => "EN", "criteriaString" => "EN B1ab(iii)+2ab(iii)"]]]]);
check("una nota y una evaluación UICN", $c4 === 200);
$live = [...$l2, ...$l1, ...$l4];

// ------------------------------------------------------------------------------------------------
section("Permisos");
$exp = fn(array $jar, array $body, string $sub = "") => httpRaw("POST", "/api/catalogs/{$cat}/export{$sub}", $body, $jar);
$logFull = fn() => (int) $pdo->query("SELECT COUNT(*) FROM access_log WHERE action = 'export_full' AND catalog_id = " . $pdo->quote($cat))->fetchColumn();
[$code] = $exp($e, ["mode" => "full"]);
check("«completa» por editor → 403", $code === 403, "HTTP {$code}");
[$code] = $exp($e, ["mode" => "full"], "/preview");
check("resumen de «completa» por editor → 403", $code === 403, "HTTP {$code}");
[$code] = $exp($x, ["mode" => "public"]);
check("no miembro → 403", $code === 403, "HTTP {$code}");
[$code] = httpRaw("POST", "/api/exports", [], $o);
check("/api/exports a secas → 404", $code === 404, "HTTP {$code}");
[$code] = $exp($e, ["mode" => "public", "license" => "GPL"]);
check("licencia no admitida → 400", $code === 400, "HTTP {$code}");
[$code] = $exp($e, ["mode" => "public", "dateFrom" => "2026-08-01", "dateTo" => "2026-07-01"]);
check("fechas al revés → 400", $code === 400, "HTTP {$code}");

$before = $logFull();
[$code, $raw, $hd] = $exp($o, ["mode" => "full"]);
check("«completa» por owner → 200 application/zip", $code === 200 && str_starts_with($hd["content-type"] ?? "", "application/zip"), "HTTP {$code}");
check("nombre BioDex_<catálogo>_completa_<fecha>.zip", (bool) preg_match('/filename="BioDex_test_f10a_[0-9a-f]+_completa_\d{4}-\d{2}-\d{2}\.zip"/', $hd["content-disposition"] ?? ""),
    $hd["content-disposition"] ?? "");
check("una fila export_full por la descarga del owner", $logFull() === $before + 1);
$full = unzipAll($raw);
[$code] = $exp($cu, ["mode" => "full"]);
check("«completa» por custodio → 200 y otra fila export_full", $code === 200 && $logFull() === $before + 2, "HTTP {$code}");
[$code, $pv] = http("POST", "/api/catalogs/{$cat}/export/preview", ["mode" => "full"], $o);
check("el resumen no registra export_full", $code === 200 && $logFull() === $before + 2);

// ------------------------------------------------------------------------------------------------
section("Resumen (preview)");
[$code, $pv] = http("POST", "/api/catalogs/{$cat}/export/preview", ["mode" => "public"], $v);
$s = $pv["summary"] ?? [];
check("lector: 200 con recuentos", $code === 200 && $s["occurrences"] === 5 && $s["absences"] === 1 && $s["species"] === 3
    && $s["generalized"] === 4 && $s["withheld"] === 1 && $s["measurements"] === 2, json_encode($s));
check("lector: sin «completa», licencias y valores por defecto", $pv["canFull"] === false && count($pv["licenses"]) === 3
    && $pv["defaults"]["license"] === "CC-BY-4.0" && $pv["defaults"]["description"] === "Catálogo de prueba de la exportación.");
check("especies y observadores disponibles, rango de fechas", count($pv["available"]["observers"]) === 2
    && count(array_filter($pv["available"]["species"], fn($z) => $z["count"] > 0)) === 3
    && $pv["available"]["dateMin"] === "2026-06-15" && $pv["available"]["dateMax"] === "2026-07-20");
[, $pv2] = http("POST", "/api/catalogs/{$cat}/export/preview", ["mode" => "public", "includeAbsences" => false, "observers" => [$ids["e"]]], $v);
check("filtros: sin ausencias y solo E → 2 marcas", ($pv2["summary"]["occurrences"] ?? null) === 2, json_encode($pv2["summary"] ?? null));
[, $pv3] = http("POST", "/api/catalogs/{$cat}/export/preview", ["mode" => "public", "dateFrom" => "2026-07-01", "taxonIDs" => [ARTEMISIA]], $v);
check("filtros: desde julio y solo Artemisia → 1 marca", ($pv3["summary"]["occurrences"] ?? null) === 1, json_encode($pv3["summary"] ?? null));

// ------------------------------------------------------------------------------------------------
section("«Para publicar» (editor)");
[$code, $raw, $hd] = $exp($e, ["mode" => "public"]);
check("200 y nombre …_publicar_….zip", $code === 200 && str_contains($hd["content-disposition"] ?? "", "_publicar_"), "HTTP {$code} " . substr($raw, 0, 200));
$pub = unzipAll($raw);
$names = array_keys($pub);
foreach (["dwca/occurrence.txt", "dwca/measurementorfact.txt", "dwca/meta.xml", "dwca/eml.xml", "LEEME.txt",
          "hojas/observaciones.csv", "hojas/especies.csv", "hojas/evaluaciones_uicn.csv", "hojas/distribucion.csv", "hojas/notas.csv"] as $f) {
    check("contiene {$f}", isset($pub[$f]));
}
$xlsxName = current(array_filter($names, fn($n) => preg_match('#^hojas/BioDex_test_f10a_[0-9a-f]+_\d{4}-\d{2}-\d{2}\.xlsx$#', $n)));
check("contiene hojas/BioDex_<catálogo>_<fecha>.xlsx", (bool) $xlsxName, implode(", ", $names));

$occTxt = $pub["dwca/occurrence.txt"] ?? "";
[$head, $rows, $bad] = parseTsv($occTxt);
check("cabecera en el orden de F10a", $head === array_keys(DWC_OCCURRENCE_TERMS));
check("UTF-8 sin BOM y saltos \\n", mb_check_encoding($occTxt, "UTF-8") && !str_starts_with($occTxt, "\xEF\xBB\xBF") && !str_contains($occTxt, "\r"));
check("sin tabuladores internos: todas las filas con " . count($head) . " columnas", $bad === 0, "{$bad} filas mal");
check("una fila por marca no borrada (5)", count($rows) === count($live), count($rows) . " filas");
$ids10 = array_column($rows, "occurrenceID");
check("occurrenceID únicos y sin la borrada", count(array_unique($ids10)) === count($ids10) && !in_array($gone["occurrenceID"], $ids10, true)
    && !array_diff(array_column($live, "occurrenceID"), $ids10));
$byId = array_column($rows, null, "occurrenceID");

$r2 = $byId[$l2[0]["occurrenceID"]];
$g = generalizePoint($l2[0]["decimalLatitude"], $l2[0]["decimalLongitude"], 2, 8);
check("nivel 2: centro de la celda de 0,1° (37.05, -3.35)", (float) $r2["decimalLatitude"] === 37.05 && (float) $r2["decimalLongitude"] === -3.35
    && (float) $r2["decimalLatitude"] === $g["decimalLatitude"], "{$r2["decimalLatitude"]}, {$r2["decimalLongitude"]}");
check("nivel 2: dataGeneralizations e incertidumbre de la celda", $r2["dataGeneralizations"] === "Coordenadas generalizadas a 0,1°"
    && (int) $r2["coordinateUncertaintyInMeters"] === $g["coordinateUncertaintyInMeters"] && (int) $r2["coordinateUncertaintyInMeters"] > 5000);
check("nivel 2: sin las notas libres y dicho en informationWithheld", $r2["occurrenceRemarks"] === "" && str_contains($r2["informationWithheld"], "retenidas"));
check("nivel 2: hábitat con el tabulador cambiado por espacio", $r2["habitat"] === "Pastizal psicroxerófilo");
$r1 = $byId[$l1[0]["occurrenceID"]];
check("nivel 1: sin coordenadas, con informationWithheld", $r1["decimalLatitude"] === "" && $r1["decimalLongitude"] === ""
    && $r1["coordinateUncertaintyInMeters"] === "" && $r1["geodeticDatum"] === "" && str_contains($r1["informationWithheld"], "Coordenadas retenidas")
    && $r1["georeferenceRemarks"] === "");
$r4 = $byId[$l4[0]["occurrenceID"]];
check("nivel 4: exactas y sin generalización", (float) $r4["decimalLatitude"] === 37.15 && (float) $r4["decimalLongitude"] === -3.5
    && $r4["dataGeneralizations"] === "" && (int) $r4["coordinateUncertaintyInMeters"] === 8);
check("especie propia: biodex:<uuid> y «Taxón no presente en EIDOS»", $r4["taxonID"] === $userTaxon
    && str_starts_with($r4["taxonRemarks"], "Taxón no presente en EIDOS") && $r4["nameAccordingTo"] === "");
check("EIDOS: taxonID EIDOS:<id> y nameAccordingTo", $r2["taxonID"] === "EIDOS:" . ARTEMISIA && $r2["nameAccordingTo"] === "EIDOS (IEPNB, MITECO)");
$ab = $byId[$l2[2]["occurrenceID"]];
check("ausencia: absent e individualCount 0", $ab["occurrenceStatus"] === "absent" && $ab["individualCount"] === "0");
check("eventDate ISO 8601 con zona, en UTC", $r2["eventDate"] === "2026-06-15T08:00:00Z");
check("constantes: EPSG:4326, ES, es, licencia CC BY 4.0, rango en minúsculas", $r4["geodeticDatum"] === "EPSG:4326" && $r4["countryCode"] === "ES"
    && $r4["language"] === "es" && $r4["license"] === "http://creativecommons.org/licenses/by/4.0/legalcode" && $r2["taxonRank"] === "species");
check("recordedByID con el ORCID del autor", $r2["recordedByID"] === "https://orcid.org/0000-0002-1825-0097" && $r4["recordedByID"] === "");

// meta.xml
$meta = wellFormed($pub["dwca/meta.xml"] ?? "");
check("meta.xml bien formado", $meta !== null);
if ($meta) {
    $xp = new DOMXPath($meta);
    $xp->registerNamespace("t", "http://rs.tdwg.org/dwc/text/");
    $terms = array_map(fn($n) => substr(strrchr($n->getAttribute("term"), "/"), 1), iterator_to_array($xp->query("//t:core/t:field")));
    check("cabecera de occurrence.txt = campos de meta.xml", $terms === $head);
    check("rowType Occurrence y MeasurementOrFact", $xp->query("//t:core[@rowType='http://rs.tdwg.org/dwc/terms/Occurrence']")->length === 1
        && $xp->query("//t:extension[@rowType='http://rs.tdwg.org/dwc/terms/MeasurementOrFact']")->length === 1);
    $xsd = @file_get_contents("http://rs.tdwg.org/dwc/text/tdwg_dwc_text.xsd", false, stream_context_create(["http" => ["timeout" => 10]]));
    if ($xsd) {
        // El XSD importa tdwg_dwcterms.xsd, que libxml no compila (usa chrono: sin importarlo), y no lo usa
        // para nada: la estructura del archivo (archive/core/extension/field) no depende de él. Fuera.
        $xsd = preg_replace('#<xs:import namespace="http://rs\.tdwg\.org/dwc/terms/"[^>]*/>#', "", $xsd);
        $tmp = tempnam(sys_get_temp_dir(), "xsd");
        file_put_contents($tmp, $xsd);
        libxml_use_internal_errors(true);
        $ok = @$meta->schemaValidate($tmp);   // xml.xsd de GBIF responde 403: no hace falta para esta estructura
        $err = implode(" | ", array_map(fn($z) => trim($z->message), libxml_get_errors()));
        libxml_clear_errors();
        unlink($tmp);
        check("meta.xml válido contra tdwg_dwc_text.xsd", $ok, $err);
    } else {
        echo "--   sin red: meta.xml no se valida contra el XSD de DwC\n";
    }
}
[$mh, $mrows, $mbad] = parseTsv($pub["dwca/measurementorfact.txt"] ?? "");
check("measurementorfact.txt: 2 filas de maduros enlazadas por occurrenceID", count($mrows) === 2 && $mbad === 0 && $mh[0] === "coreid"
    && !array_diff(array_column($mrows, "coreid"), $ids10) && $mrows[0]["measurementType"] === "número de individuos maduros");
$eml = wellFormed($pub["dwca/eml.xml"] ?? "");
check("eml.xml bien formado", $eml !== null);
if ($eml) {
    $t = $eml->textContent;
    check("eml.xml: título, licencia, contacto (owner) y ORCID de creador", str_contains($t, "test-f10a {$suffix}")
        && $eml->getElementsByTagName("contact")->length === 1 && str_contains($t, "F10a O")
        && str_contains($eml->saveXML(), "licenses/by/4.0") && str_contains($t, "https://orcid.org/0000-0002-1825-0097"));
    $w = (float) $eml->getElementsByTagName("westBoundingCoordinate")->item(0)?->textContent;
    check("eml.xml: bbox de las coordenadas generalizadas (oeste -3.5)", $w === -3.5, (string) $w);
    check("eml.xml: cobertura temporal y nota de generalización", str_contains($t, "2026-06-15") && str_contains($t, "2026-07-20")
        && str_contains($t, "generalizado"));
}

// XLSX
$xlsxRaw = $pub[$xlsxName] ?? "";
$tmpX = tempnam(sys_get_temp_dir(), "x10") . ".xlsx";
file_put_contents($tmpX, $xlsxRaw);
$py = trim((string) shell_exec("python3 -c \"import openpyxl\" 2>&1 && echo si"));
if ($py === "si") {
    $out = shell_exec("python3 -c \"import openpyxl,sys; wb=openpyxl.load_workbook(sys.argv[1]); print('|'.join(wb.sheetnames)); print(wb['Observaciones'].max_row)\" " . escapeshellarg($tmpX));
    check("openpyxl abre el XLSX", str_starts_with((string) $out, "Léeme|Observaciones|Especies|Evaluaciones UICN|Distribución|Notas"), (string) $out);
} else {
    $parts = unzipAll($xlsxRaw);
    $xmls = array_filter($parts, fn($k) => str_ends_with($k, ".xml") || str_ends_with($k, ".rels"), ARRAY_FILTER_USE_KEY);
    $broken = array_keys(array_filter($xmls, fn($c) => wellFormed($c) === null));
    check("XLSX: " . count($xmls) . " partes XML, todas bien formadas (sin openpyxl)", count($xmls) >= 11 && !$broken, implode(", ", $broken));
    $wb = wellFormed($parts["xl/workbook.xml"] ?? "");
    $sheets = $wb ? array_map(fn($n) => $n->getAttribute("name"), iterator_to_array($wb->getElementsByTagName("sheet"))) : [];
    check("XLSX: pestañas Léeme · Observaciones · Especies · Evaluaciones UICN · Distribución · Notas",
        $sheets === ["Léeme", "Observaciones", "Especies", "Evaluaciones UICN", "Distribución", "Notas"], implode(", ", $sheets));
    $obs = $parts["xl/worksheets/sheet2.xml"] ?? "";
    check("Observaciones: 2 filas de cabecera + 5, congeladas y con autofiltro", substr_count($obs, "<row ") === 7
        && str_contains($obs, 'state="frozen"') && str_contains($obs, "<autoFilter ref=\"A2:"));
    check("Observaciones: término DwC bajo la cabecera", str_contains($obs, ">decimalLatitude<") && str_contains($obs, ">Latitud<"));
    check("Evaluaciones: rotulada «Evaluación BioDex»", str_contains($parts["xl/worksheets/sheet4.xml"] ?? "", "Evaluación BioDex"));
    check("Distribución: una fila por especie con marcas (+ cabecera)", substr_count($parts["xl/worksheets/sheet5.xml"] ?? "", "<row ") === 4);
    check("Léeme: modo para publicar", str_contains($parts["xl/worksheets/sheet1.xml"] ?? "", "PARA PUBLICAR"));
}
@unlink($tmpX);

// CSV
$csv = $pub["hojas/observaciones.csv"] ?? "";
$csvLines = explode("\r\n", rtrim($csv, "\r\n"));
check("CSV: BOM, «;», cabecera en castellano y 5 filas", str_starts_with($csv, "\xEF\xBB\xBFID de la observación;Especie;") && count($csvLines) === 6);
check("CSV: coma decimal en las coordenadas", str_contains($csv, ";37,050000;-3,350000;"));
check("LEEME.txt dice el modo", str_contains($pub["LEEME.txt"] ?? "", "PARA PUBLICAR"));

// ------------------------------------------------------------------------------------------------
section("«Para publicar» por el owner y «completa»");
[$code, $raw] = $exp($o, ["mode" => "public", "license" => "CC0-1.0", "title" => "Título propio"]);
[, $orows] = parseTsv(unzipAll($raw)["dwca/occurrence.txt"] ?? "");
$ob = array_column($orows, null, "occurrenceID");
check("owner «para publicar»: también generalizada (nivel 2) y sin coordenadas (nivel 1)", $code === 200
    && (float) ($ob[$l2[0]["occurrenceID"]]["decimalLatitude"] ?? 0) === 37.05 && ($ob[$l1[0]["occurrenceID"]]["decimalLatitude"] ?? "x") === "");
check("licencia CC0 y título propio", ($ob[$l2[0]["occurrenceID"]]["license"] ?? "") === "http://creativecommons.org/publicdomain/zero/1.0/legalcode"
    && ($ob[$l2[0]["occurrenceID"]]["datasetName"] ?? "") === "Título propio");
[, $frows] = parseTsv($full["dwca/occurrence.txt"] ?? "");
$fb = array_column($frows, null, "occurrenceID");
$f2 = $fb[$l2[0]["occurrenceID"]] ?? [];
check("«completa»: exactas, notas y sin generalización", (float) ($f2["decimalLatitude"] ?? 0) === 37.0931234
    && (float) ($fb[$l1[0]["occurrenceID"]]["decimalLatitude"] ?? 0) === 37.2 && ($f2["dataGeneralizations"] ?? "x") === ""
    && ($f2["occurrenceRemarks"] ?? "") === "junto a la fuente del collado segunda línea");
check("«completa»: marca de agua en EML y Léeme", str_contains($full["dwca/eml.xml"] ?? "", "no publicar") && str_contains($full["LEEME.txt"] ?? "", "EXACTAS"));
[, $arows] = parseTsv(unzipAll($exp($e, ["mode" => "public", "includeAbsences" => false])[1])["dwca/occurrence.txt"] ?? "");
check("sin ausencias: 4 filas y ninguna absent", count($arows) === 4 && !in_array("absent", array_column($arows, "occurrenceStatus"), true));

// El original, intacto
$stmt = $pdo->prepare("SELECT decimalLatitude, occurrenceRemarks FROM occurrences WHERE occurrenceID = ?");
$stmt->execute([$l2[0]["occurrenceID"]]);
$orig = $stmt->fetch();
check("la marca original no cambia", (float) $orig["decimalLatitude"] === 37.0931234 && str_contains($orig["occurrenceRemarks"], "fuente"));

cleanup($pdo);
echo "\n" . ($failures ? "{$failures} comprobación(es) fallida(s)." : "Todo OK.") . "\n";
exit($failures ? 1 : 0);
