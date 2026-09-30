<?php
// ================================================================================================
// Pruebas de F10c (Plinian Core): docker compose exec php php bin/test-f10c.php
//   · «formats»: lista inválida o vacía → 400; sin «formats», el ZIP de F10a.
//   · Plinian Core en los dos modos: valida contra el XSD vendorizado; categorías oficiales de EIDOS y
//     evaluación BioDex en entradas separadas (la BioDex rotulada no oficial); EOO/AOO = computeMetrics
//     de las presencias exportadas; filtros de especies y fechas; referencia al DwC-A solo si va.
//   · «Para publicar»: ninguna coordenada (exacta ni generalizada) de las especies sensibles, nota de
//     información retenida y justificación retenida; «completa»: export_full en access_log.
//   · Regresión: sin «formats» = ["dwc", "sheets"] = el ZIP de F10a (salvo marcas de tiempo), y
//     «solo DwC» da el mismo dwca/.
//   · El catálogo DEMO (si existe) exporta Plinian Core sin errores.
// Crea usuarios f10c-*@biodex.test, el catálogo «test-f10c …» y una especie propia; los borra al terminar.
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/api.php";        // uuidV4()
require_once __DIR__ . "/../Lib/migrate.php";
require_once __DIR__ . "/../Lib/export.php";     // plinian.php, computeMetrics()
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

/** ZIP en memoria → [ruta interna → contenido]. Los .xlsx, desempaquetados («x.xlsx!parte»). */
function unzipAll(string $raw): array {
    $tmp = tempnam(sys_get_temp_dir(), "t10c");
    file_put_contents($tmp, $raw);
    $zip = new ZipArchive();
    $out = [];
    if ($zip->open($tmp) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $out[$name] = $zip->getFromIndex($i);
            if (str_ends_with($name, ".xlsx")) foreach (unzipAll($out[$name]) as $p => $c) $out["{$name}!{$p}"] = $c;
        }
        $zip->close();
    }
    unlink($tmp);
    return $out;
}

/** Contenido sin lo que cambia entre dos descargas: UUID, fechas y horas. */
function normalized(string $s): string {
    return preg_replace(['/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/', '/\d{4}-\d{2}-\d{2}T[\d:.]+(Z|[+-]\d{2}:\d{2})/',
        '#\d{2}/\d{2}/\d{4} \d{2}:\d{2}#', '/\d{4}-\d{2}-\d{2}/'], ["UUID", "TS", "FECHA", "DIA"], $s);
}

/** Dos ZIP iguales salvo marcas de tiempo (el .xlsx binario se compara por sus partes). → ficheros distintos. */
function zipDiff(array $a, array $b): array {
    $key = fn($k) => normalized($k);
    $na = []; foreach ($a as $k => $v) if (!str_ends_with($k, ".xlsx")) $na[$key($k)] = normalized($v);
    $nb = []; foreach ($b as $k => $v) if (!str_ends_with($k, ".xlsx")) $nb[$key($k)] = normalized($v);
    $diff = array_merge(array_keys(array_diff_key($na, $nb)), array_keys(array_diff_key($nb, $na)));
    foreach (array_intersect_key($na, $nb) as $k => $v) if ($v !== $nb[$k]) $diff[] = $k;
    return $diff;
}

function plicXPath(string $xml): ?DOMXPath {
    $d = new DOMDocument();
    if (!@$d->loadXML($xml)) return null;
    $xp = new DOMXPath($d);
    foreach (PLIC_NS as $p => $ns) $xp->registerNamespace($p, $ns);
    return $xp;
}

/** TaxonRecord de un taxonID publicado (EIDOS:9918, biodex:…). */
function plicRecord(DOMXPath $xp, string $id): ?DOMElement {
    return $xp->query("/Dataset/TaxonRecord[BaseElements/TaxonRecordID = '{$id}']")->item(0);
}

$userTaxon = "biodex:" . uuidV4();

function cleanup(PDO $pdo): void {
    $in = "SELECT id FROM catalogs WHERE name LIKE 'test-f10c %'";
    foreach (["occurrences", "species_notes", "species_assessments", "access_log", "species_metrics"] as $t) {
        $pdo->exec("DELETE FROM {$t} WHERE catalog_id IN ({$in})");
    }
    $pdo->exec("DELETE FROM catalogs WHERE name LIKE 'test-f10c %'");
    $pdo->exec("DELETE FROM species WHERE source = 'user' AND scientificName LIKE 'Testia f10c%'");
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f10c-%@biodex.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f10c-%@biodex.test'");
}
cleanup($pdo);

// ------------------------------------------------------------------------------------------------
section("Catálogo de prueba: especies de nivel 2, 1 y 4, marcas y evaluación de referencia");
$suffix = bin2hex(random_bytes(4));
$jars = ["o" => [], "e" => []];
$ids = [];
foreach ($jars as $k => &$jar) {
    $email = "f10c-{$k}-{$suffix}@biodex.test";
    [$code] = http("POST", "/api/auth/register", ["email" => $email, "password" => "correcta-horse-battery", "name" => "F10c " . strtoupper($k)], $jar);
    check("registro de " . strtoupper($k), $code === 201, "HTTP {$code}");
    $ids[$k] = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();
}
unset($jar);
[$o, $e] = [$jars["o"], $jars["e"]];
$pdo->prepare("UPDATE users SET orcid = '0000-0002-1825-0097' WHERE id = ?")->execute([$ids["o"]]);

[$code, $r] = http("POST", "/api/catalogs", ["name" => "test-f10c {$suffix}", "description" => "Catálogo de prueba de Plinian Core.",
    "taxonIDs" => [ARTEMISIA]], $o);
check("O crea el catálogo con la Artemisia", $code === 201, "HTTP {$code}");
$cat = $r["catalog"]["id"] ?? "";
$pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role, granted_by) VALUES (?, ?, 'editor', ?)")->execute([$cat, $ids["e"], $ids["o"]]);

$other = (string) $pdo->query("SELECT taxonID FROM species WHERE source = 'eidos' AND taxonID <> '" . ARTEMISIA . "'
                                  AND taxonRank = 'Species' ORDER BY taxonID LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO species (taxonID, source, scientificName, scientificNameAuthorship, canonical_name, taxonRank, kingdom, genus,
               specificEpithet, vernacularName, classification_source, created_by) VALUES (?, 'user', 'Testia f10c Autor', 'Autor', 'Testia f10c',
               'Species', 'Plantae', 'Testia', 'f10c', 'testia común', 'user', ?)")->execute([$userTaxon, $ids["o"]]);
$cs = $pdo->prepare("INSERT INTO catalog_species (catalog_id, taxonID, added_by) VALUES (?, ?, ?)");
$lv = $pdo->prepare("INSERT INTO species_sensitivity (catalog_id, taxonID, level, suggested_level, reason, set_by) VALUES (?, ?, ?, ?, 'prueba', ?)
                     ON DUPLICATE KEY UPDATE level = VALUES(level), set_by = VALUES(set_by)");
foreach ([[$other, 1], [$userTaxon, 4]] as [$t, $n]) { $cs->execute([$cat, $t, $ids["o"]]); $lv->execute([$cat, $t, $n, $n, $ids["o"]]); }
$lv->execute([$cat, ARTEMISIA, 2, 2, $ids["o"]]);
check("especies: Artemisia (2), {$other} (1), propia (4)", $other !== "");

$mark = fn(string $taxon, float $lat, float $lon, array $patch = []) => $patch + ["occurrenceID" => uuidV4(), "taxonID" => $taxon,
    "eventDate" => "2026-06-15T10:00:00+02:00", "decimalLatitude" => $lat, "decimalLongitude" => $lon,
    "coordinateUncertaintyInMeters" => 8, "clientUpdatedAt" => "2026-06-15T10:05:00.000Z"];
$art = [$mark(ARTEMISIA, 37.0931234, -3.3987654), $mark(ARTEMISIA, 37.1011567, -3.3801234, ["eventDate" => "2026-07-20T09:00:00+02:00"]),
        $mark(ARTEMISIA, 37.0712345, -3.3512345), $mark(ARTEMISIA, 37.1234567, -3.4123456, ["occurrenceStatus" => "absent", "individualCount" => 0])];
$oth = [$mark($other, 37.2045678, -3.6034567), $mark($other, 37.2156789, -3.6145678)];
$own = [$mark($userTaxon, 37.1543210, -3.5043210)];
$push = fn(array $jar, array $body) => http("POST", "/api/sync/push", ["catalogId" => $cat] + $body, $jar);
[$c1, $r1] = $push($o, ["occurrences" => [...$art, ...$own]]);
[$c2, $r2] = $push($e, ["occurrences" => $oth]);
$accepted = count(array_filter([...$r1["results"], ...$r2["results"]], fn($z) => $z["status"] === "accepted"));
check("7 marcas subidas", $c1 === 200 && $c2 === 200 && $accepted === 7, "{$accepted} aceptadas");

$secret = "junto a la fuente del collado";
$assessmentID = uuidV4();
[$c3, $r3] = $push($e, ["assessments" => [["assessmentID" => $assessmentID, "taxonID" => ARTEMISIA, "status" => "final",
    "clientUpdatedAt" => "2026-07-02T08:00:00.000Z", "justification" => "Tres localidades; la mayor {$secret}.",
    "inputs" => ["B" => ["eoo" => 80, "aoo" => 12, "locations" => 3, "decline" => ["iii"]]],
    "result" => ["category" => "EN", "baseCategory" => "EN", "criteriaString" => "EN B1ab(iii)+2ab(iii)"]]]]);
[$c4] = http("PUT", "/api/catalogs/{$cat}/species/" . ARTEMISIA . "/reference-assessment", ["assessmentID" => $assessmentID], $o);
check("evaluación de E, marcada de referencia por O", $c3 === 200 && ($r3["assessmentResults"][0]["status"] ?? "") === "accepted" && $c4 === 200, "HTTP {$c3}/{$c4}");

$exp = fn(array $jar, array $body) => httpRaw("POST", "/api/catalogs/{$cat}/export", $body, $jar);
$logFull = fn() => (int) $pdo->query("SELECT COUNT(*) FROM access_log WHERE action = 'export_full' AND catalog_id = " . $pdo->quote($cat))->fetchColumn();
$all = ["dwc", "plinian", "sheets"];

// ------------------------------------------------------------------------------------------------
section("Opción «formats»");
[$code] = $exp($e, ["mode" => "public", "formats" => []]);
check("lista vacía → 400", $code === 400, "HTTP {$code}");
[$code] = $exp($e, ["mode" => "public", "formats" => ["dwc", "pdf"]]);
check("formato desconocido → 400", $code === 400, "HTTP {$code}");
[$code] = $exp($e, ["mode" => "public", "formats" => "plinian"]);
check("no es una lista → 400", $code === 400, "HTTP {$code}");

// ------------------------------------------------------------------------------------------------
section("«Para publicar» con Plinian Core (editor)");
[$code, $raw] = $exp($e, ["mode" => "public", "formats" => $all]);
$pub = unzipAll($raw);
check("200 con dwca/, hojas/, pliniancore/especies.xml y LEEME.txt", $code === 200 && isset($pub["dwca/occurrence.txt"], $pub["hojas/especies.csv"],
    $pub[PLIC_FILE], $pub["LEEME.txt"]), "HTTP {$code} " . implode(", ", array_keys($pub)));
$xml = $pub[PLIC_FILE] ?? "";
check("valida contra el XSD vendorizado (" . PLIC_VERSION . ")", plinianValidate($xml) === [], implode(" | ", array_slice(plinianValidate($xml), 0, 3)));
$d = new DOMDocument();
libxml_use_internal_errors(true);
check("y con schemaValidate directo", @$d->loadXML($xml) && $d->schemaValidate(PLIC_XSD));
libxml_clear_errors();
$xp = plicXPath($xml);
check("un TaxonRecord por especie del catálogo (3)", $xp && $xp->query("/Dataset/TaxonRecord")->length === 3);

$ra = $xp ? plicRecord($xp, "EIDOS:" . ARTEMISIA) : null;
$entries = $ra ? iterator_to_array($xp->query("DemographyAndThreat/ThreatStatus/ThreatStatusAtomized", $ra)) : [];
$auth = fn(DOMElement $x) => array_map(fn($n) => $n->textContent, iterator_to_array($xp->query("Authority", $x)));
$biodex = array_values(array_filter($entries, fn($x) => in_array(PLIC_BIODEX_AUTHORITY, $auth($x), true)));
$official = array_values(array_filter($entries, fn($x) => !in_array(PLIC_BIODEX_AUTHORITY, $auth($x), true)));
$eidosIucn = array_filter(json_decode((string) $pdo->query("SELECT iucn_assessments FROM species WHERE taxonID = '" . ARTEMISIA . "'")->fetchColumn(), true) ?: [],
    fn($a) => !empty($a["code"]));
check("una entrada oficial por ámbito de EIDOS (" . count($eidosIucn) . ") y una BioDex, separadas",
    count($official) === count($eidosIucn) && count($biodex) === 1);
check("las oficiales: autoridad EIDOS «categoría oficial», ámbito de EIDOS y nada de BioDex",
    !array_filter($official, fn($x) => !str_starts_with($auth($x)[0], "EIDOS (IEPNB, MITECO) · categoría oficial") || str_contains(implode(" ", $auth($x)), "BioDex"))
    && array_map(fn($x) => $xp->query("AppliesTo/DistributionUnstructured", $x)->item(0)->textContent, $official) === array_column(array_values($eidosIucn), "scope"));
$b = $biodex[0] ?? null;
check("BioDex: «EN B1ab(iii)+2ab(iii)», evaluadora y ámbito del catálogo", $b
    && $xp->query("ThreatCategory", $b)->item(0)->textContent === "EN B1ab(iii)+2ab(iii)"
    && in_array("Evaluador/a: F10c E", $auth($b), true)
    && str_contains($xp->query("AppliesTo/DistributionUnstructured", $b)->item(0)->textContent, "test-f10c {$suffix}"));
$note = $ra ? $xp->query("DemographyAndThreat/ThreatStatus/AncillaryData", $ra)->item(0) : null;
check("BioDex: nota «no oficial» con fecha, autor y localidades", $note && str_contains($xp->query("dc:title", $note)->item(0)->textContent, "no oficial")
    && str_contains($note->textContent, "NO es una categoría oficial") && str_contains($note->textContent, "Localidades (las indica quien evalúa): 3")
    && str_starts_with($xp->query("dcterms:created", $note)->item(0)?->textContent ?? "", "2026-07-02T08:00:00") && $xp->query("eol:agent", $note)->item(0)?->textContent === "F10c E");
check("BioDex: justificación retenida (especie sensible)", !str_contains($xml, $secret) && str_contains($note?->textContent ?? "", "Justificación retenida"));

// EOO/AOO = computeMetrics de las presencias exportadas (las exactas, en el servidor)
$expected = computeMetrics(array_map(fn($m) => ["decimalLatitude" => $m["decimalLatitude"], "decimalLongitude" => $m["decimalLongitude"],
    "coordinateUncertaintyInMeters" => 8, "occurrenceStatus" => $m["occurrenceStatus"] ?? "present"], $art));
$mof = fn(DOMElement $rec, string $path, string $type) => $xp->query("DemographyAndThreat/Territory/TerritoryAtomized/{$path}/dwc:MeasurementOrFact[dwc:measurementType = '{$type}']/dwc:measurementValue", $rec)->item(0)?->textContent;
check("EOO y AOO de las 3 presencias exportadas", $ra && (float) $mof($ra, "ExtentOfOccurrence", "extensión de presencia (EOO)") === (float) $expected["eooKm2"]
    && (float) $mof($ra, "AreaOfOccupancy", "área de ocupación (AOO)") === (float) $expected["aooKm2"],
    "esperado EOO {$expected["eooKm2"]}, AOO {$expected["aooKm2"]}");
check("nº de cuadrículas y tamaño de celda (2 km)", $ra && (int) $mof($ra, "AreaOfOccupancy", "número de cuadrículas ocupadas") === $expected["aooCells"]
    && $mof($ra, "AreaOfOccupancy", "tamaño de celda") === "2");
check("periodo de las observaciones de la especie", $ra && $xp->query("HabitatAndDistribution/Distribution/temporalCoverage/rangeOfDates/beginDate/calendarDate", $ra)->item(0)?->textContent === "2026-06-15"
    && $xp->query("HabitatAndDistribution/Distribution/temporalCoverage/rangeOfDates/endDate/calendarDate", $ra)->item(0)?->textContent === "2026-07-20");
check("fuente: dwca/ del mismo ZIP (dc:source y References)", $ra && str_contains($xp->query("HabitatAndDistribution/Distribution/AncillaryData/dc:source", $ra)->item(0)?->textContent ?? "", "dwca/occurrence.txt")
    && $xp->query("References", $ra)->length === 1);

// Ninguna coordenada de especie sensible, ni exacta ni generalizada
$leaks = [];
foreach ([...$art, ...$oth] as $m) {
    foreach ([$m["decimalLatitude"], $m["decimalLongitude"]] as $c) {
        foreach ([(string) $c, sprintf("%.6F", $c), sprintf("%.4F", $c), str_replace(".", ",", sprintf("%.6F", $c))] as $f) {
            if (str_contains($xml, $f)) $leaks[] = $f;
        }
    }
    $g = generalizePoint($m["decimalLatitude"], $m["decimalLongitude"], 2, 8);
    foreach ([$g["decimalLatitude"], $g["decimalLongitude"]] as $c) if (preg_match('/[>\s"]' . preg_quote((string) $c, "/") . '[<\s"]/', $xml)) $leaks[] = "celda {$c}";
}
check("ninguna coordenada (exacta ni de celda) de las especies sensibles", !$leaks, implode(", ", array_unique($leaks)));
check("ningún elemento de coordenadas en el XML", $xp && $xp->query("//dwc:decimalLatitude | //dwc:decimalLongitude | //boundingCoordinates")->length === 0);
$withheld = fn(?DOMElement $rec) => $rec ? $xp->query("DemographyAndThreat/Territory/TerritoryAtomized/AncillaryData[dc:title = '" . PLIC_WITHHELD_TITLE . "']", $rec)->length : -1;
check("nota de información retenida en nivel 2 y nivel 1, no en nivel 4", $withheld($ra) === 1 && $withheld($xp ? plicRecord($xp, "EIDOS:{$other}") : null) === 1
    && $withheld($xp ? plicRecord($xp, $userTaxon) : null) === 0);
$ru = $xp ? plicRecord($xp, $userTaxon) : null;
check("especie propia: biodex:<uuid>, sin nameAccordingTo, nombre común y clasificación de usuario", $ru
    && $xp->query("BaseElements/dwc:nameAccordingTo", $ru)->length === 0
    && $xp->query("NomenclatureAndClassification/CommonNames/CommonNamesAtomized/CommonName", $ru)->item(0)?->textContent === "testia común"
    && $xp->query("NomenclatureAndClassification/Hierarchy/@classification", $ru)->item(0)?->textContent === "usuario de BioDex");
check("nombre con autor, rango TCS «sp» y código botánico", $ru && $xp->query("NomenclatureAndClassification/TaxonRecordName/ScientificName[@nomenclaturalCode = 'Botanical']/tcs:Rank[@code = 'sp']", $ru)->length === 1
    && $xp->query("NomenclatureAndClassification/TaxonRecordName/ScientificName/tcs:Simple", $ru)->item(0)?->textContent === "Testia f10c Autor");
check("metadatos: licencia, ORCID de la owner, versión de BioDex y DwC-A citado", $xp
    && str_contains($xp->query("/Dataset/Metadata/dataset/intellectualRights/para")->item(0)?->textContent ?? "", "CC-BY 4.0")
    && $xp->query("/Dataset/Metadata/dataset/creator/onlineUrl")->item(0)?->textContent === "https://orcid.org/0000-0002-1825-0097"
    && str_contains($xp->query("/Dataset/Metadata/dataset/additionalInfo/para")->item(0)?->textContent ?? "", "BioDex " . biodexVersion())
    && str_contains($xp->query("/Dataset/Metadata/dataset/additionalInfo/para")->item(0)?->textContent ?? "", "dwca/"));
check("LEEME.txt y la hoja «Léeme» describen pliniancore/", str_contains($pub["LEEME.txt"] ?? "", "pliniancore/")
    && str_contains(implode("", array_filter($pub, fn($k) => str_ends_with($k, "sheet1.xml"), ARRAY_FILTER_USE_KEY)), "pliniancore/"));

// ------------------------------------------------------------------------------------------------
section("«Completa» con Plinian Core (owner)");
$before = $logFull();
[$code, $raw] = $exp($o, ["mode" => "full", "formats" => $all, "license" => "CC0-1.0"]);
$full = unzipAll($raw);
$fxml = $full[PLIC_FILE] ?? "";
check("200, valida y una fila export_full", $code === 200 && plinianValidate($fxml) === [] && $logFull() === $before + 1, "HTTP {$code}");
$fxp = plicXPath($fxml);
check("con la justificación y sin notas de información retenida", str_contains($fxml, $secret)
    && $fxp && $fxp->query("//AncillaryData[dc:title = '" . PLIC_WITHHELD_TITLE . "']")->length === 0);
check("tampoco lleva coordenadas", $fxp && !str_contains($fxml, "37.0931234") && $fxp->query("//dwc:decimalLatitude | //boundingCoordinates")->length === 0);
check("título «no publicar» y licencia CC0", str_contains($fxml, "no publicar") && str_contains($fxml, "CC0 1.0"));

// ------------------------------------------------------------------------------------------------
section("Filtros y combinaciones de formatos");
[$code, $raw] = $exp($e, ["mode" => "public", "formats" => ["plinian"], "taxonIDs" => [ARTEMISIA], "dateFrom" => "2026-07-01"]);
$only = unzipAll($raw);
$onlyNames = array_keys($only);
sort($onlyNames);
check("solo Plinian Core: pliniancore/ y LEEME.txt, nada más", $code === 200 && $onlyNames === ["LEEME.txt", PLIC_FILE], implode(", ", $onlyNames));
$oxp = plicXPath($only[PLIC_FILE] ?? "");
$oa = $oxp ? plicRecord($oxp, "EIDOS:" . ARTEMISIA) : null;
check("filtro de especies: 1 TaxonRecord", $oxp && $oxp->query("/Dataset/TaxonRecord")->length === 1 && $oa);
check("filtro de fechas: periodo desde julio y 1 cuadrícula", $oa
    && $oxp->query("HabitatAndDistribution/Distribution/temporalCoverage/rangeOfDates/beginDate/calendarDate", $oa)->item(0)?->textContent === "2026-07-20"
    && $oxp->query("DemographyAndThreat/Territory/TerritoryAtomized/AreaOfOccupancy/dwc:MeasurementOrFact[dwc:measurementType = 'número de cuadrículas ocupadas']/dwc:measurementValue", $oa)->item(0)?->textContent === "1");
check("sin DwC-A: no lo cita (sin References y fuente «no incluidas»)", $oa && $oxp->query("References", $oa)->length === 0
    && str_contains($oxp->query("HabitatAndDistribution/Distribution/AncillaryData/dc:source", $oa)->item(0)?->textContent ?? "", "no incluidas"));
check("LEEME.txt sin dwca/ ni hojas/", !str_contains($only["LEEME.txt"] ?? "", "dwca/") && !str_contains($only["LEEME.txt"] ?? "", "hojas/"));
check("plinianValidate rechaza un elemento inventado", plinianValidate(str_replace("<References>", "<InformationWithheld>x</InformationWithheld><References>", $xml)) !== []);

// ------------------------------------------------------------------------------------------------
section("Regresión: sin Plinian Core, el ZIP de F10a");
foreach (["public" => $e, "full" => $o] as $mode => $jar) {
    $zip = fn(array $extra) => unzipAll($exp($jar, ["mode" => $mode] + $extra)[1]);
    $default = $zip([]);
    $explicit = $zip(["formats" => ["dwc", "sheets"]]);
    check("{$mode}: sin «formats», los ficheros de F10a y ni rastro de pliniancore", !array_filter(array_keys($default), fn($k) => str_starts_with($k, "pliniancore/"))
        && !str_contains(implode("", $default), "pliniancore") && isset($default["dwca/eml.xml"], $default["hojas/notas.csv"]));
    check("{$mode}: sin «formats» = [dwc, sheets] (salvo marcas de tiempo)", zipDiff($default, $explicit) === [], implode(", ", zipDiff($default, $explicit)));
    $dwcOnly = $zip(["formats" => ["dwc"]]);
    $dwca = fn(array $z) => array_filter($z, fn($k) => str_starts_with($k, "dwca/"), ARRAY_FILTER_USE_KEY);
    check("{$mode}: «solo DwC» da el mismo dwca/ y nada de hojas/", zipDiff($dwca($default), $dwca($dwcOnly)) === []
        && !array_filter(array_keys($dwcOnly), fn($k) => str_starts_with($k, "hojas/")));
}

// ------------------------------------------------------------------------------------------------
section("Catálogo DEMO");
$demo = $pdo->query("SELECT * FROM catalogs WHERE name LIKE 'DEMO%' AND deleted_at IS NULL LIMIT 1")->fetch();
if ($demo) {
    foreach (["public", "full"] as $mode) {
        $opt = exportOptions(["mode" => $mode, "formats" => ["plinian"]], $demo);
        $prep = exportPrepare($pdo, $demo["id"], $demo, $opt, "owner", (int) $demo["owner_id"]);
        try {
            [$zipPath] = exportBuild($pdo, $demo["id"], $demo, $opt, $prep, (int) $demo["owner_id"], "test-f10c");
            $dz = unzipAll((string) file_get_contents($zipPath));
            unlink($zipPath);
            check("DEMO «{$mode}»: Plinian Core válido con " . count($prep["species"]) . " especies y la evaluación de la Artemisia",
                plinianValidate($dz[PLIC_FILE] ?? "") === [] && str_contains($dz[PLIC_FILE] ?? "", PLIC_BIODEX_AUTHORITY));
        } catch (PlinianInvalidXml $ex) {
            check("DEMO «{$mode}»: Plinian Core válido", false, $ex->getMessage());
        }
    }
} else {
    echo "--   sin catálogo DEMO (bin/seed-demo.php): no se comprueba\n";
}

cleanup($pdo);
echo "\n" . ($failures ? "{$failures} comprobación(es) fallida(s)." : "Todo OK.") . "\n";
exit($failures ? 1 : 0);
