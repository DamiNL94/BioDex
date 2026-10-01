<?php
// ================================================================================================
// plinian.php — fichas de especie en Plinian Core 3.2.2.7 (F10c): pliniancore/especies.xml del ZIP.
// ------------------------------------------------------------------------------------------------
// Darwin Core describe observaciones; Plinian Core, especies. Aquí va lo que el DwC-A deja fuera a
// propósito: taxonomía, estado de amenaza (las categorías oficiales de EIDOS y, APARTE, la evaluación
// BioDex, rotulada como regional, preliminar y no oficial) y distribución en cifras (EOO, AOO, celdas).
// Un solo documento: la raíz Dataset admite un TaxonRecord por especie.
//
// Seguridad: el XML NUNCA lleva coordenadas, celdas ni cascos, en ningún modo. EOO/AOO se calculan
// aquí con las exactas de las presencias exportadas (mismos filtros que el DwC-A, que se cita como
// fuente) y solo salen las cifras. En «para publicar», las especies de nivel 1–3 llevan la nota de
// información retenida y su evaluación sale sin la justificación (texto libre, como las notas de campo).
//
// Cada documento se valida con schemaValidate contra el XSD vendorizado (plinian/README.md); si no
// valida, PlinianInvalidXml y no hay ZIP. Mapeo elemento a elemento: docs/plinian.md.
// ================================================================================================

// Lo carga export.php (usa sus funciones y las de dwc.php, metrics.php y sync.php).

const PLIC_VERSION  = "3.2.2.7";
const PLIC_XSD      = __DIR__ . "/plinian/xsd/PlinianCore_AbstractModel_v3.2.2.7.xsd";
const PLIC_FILE     = "pliniancore/especies.xml";
const PLIC_NS = [
    "dwc"     => "http://rs.tdwg.org/dwc/terms/",
    "tcs"     => "http://www.tdwg.org/schemas/tcs/1.01",
    "eol"     => "http://www.eol.org/transfer/content/0.3",
    "dc"      => "http://purl.org/dc/elements/1.1/",
    "dcterms" => "http://purl.org/dc/terms/",
];
const PLIC_TEXT_TYPE      = "http://purl.org/dc/dcmitype/Text";
const PLIC_SUBJECT_STATUS = "http://rs.tdwg.org/ontology/voc/SPMInfoItems#ConservationStatus";
const PLIC_SUBJECT_DISTR  = "http://rs.tdwg.org/ontology/voc/SPMInfoItems#Distribution";
const PLIC_BIODEX_AUTHORITY = "BioDex · evaluación regional preliminar NO oficial";
const PLIC_BIODEX_NOTE = "Evaluación regional preliminar hecha con el asistente UICN de BioDex (categorías y criterios de la UICN 3.1 "
    . "y directrices para el nivel regional). NO es una categoría oficial ni sustituye una evaluación de la Lista Roja.";
const PLIC_WITHHELD_TITLE = "Información retenida";
/** Rango (vocabulario de GBIF, dwcTaxonRank) → código TCS (TaxonomicRankEnum). */
const PLIC_TCS_RANKS = ["species" => "sp", "subspecies" => "ssp", "variety" => "var", "form" => "fm", "genus" => "gen", "family" => "fam"];
const PLIC_CLASSIFICATION = ["eidos" => "EIDOS", "gbif" => "GBIF", "user" => "usuario de BioDex"];

class PlinianInvalidXml extends RuntimeException {}

// --- Constructor de XML (DOMDocument) ------------------------------------------------------------

final class PlicXml {
    public DOMDocument $doc;

    public function __construct() {
        $this->doc = new DOMDocument("1.0", "UTF-8");
        $this->doc->formatOutput = true;
    }

    /** Hijo de $parent. $name con prefijo («dwc:country») va en su espacio de nombres; sin él, sin espacio. */
    public function el(DOMNode $parent, string $name, string|int|float|null $text = null, array $attrs = []): DOMElement {
        $prefix = strstr($name, ":", true);
        $e = $prefix !== false ? $this->doc->createElementNS(PLIC_NS[$prefix], $name) : $this->doc->createElement($name);
        if ($text !== null) $e->appendChild($this->doc->createTextNode(plicClean((string) $text)));
        foreach ($attrs as $k => $v) $e->setAttribute($k, plicClean((string) $v));
        $parent->appendChild($e);
        return $e;
    }
}

/** Fuera los caracteres de control que XML 1.0 no admite; espacios a los lados. */
function plicClean(string $s): string {
    return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', "", $s) ?? "");
}

/** «1 ausencia», «3 ausencias». */
function plural(int $n, string $one, string $many): string {
    return $n . " " . ($n === 1 ? $one : $many);
}

function plicNum(?float $v): ?string {
    return $v === null ? null : rtrim(rtrim(sprintf("%.3F", $v), "0"), ".");
}

// --- Datos ---------------------------------------------------------------------------------------

/**
 * Lo que las fichas necesitan y el DwC-A no: marcas EXACTAS agrupadas por especie (solo para calcular
 * cifras aquí; no salen), la evaluación de referencia de cada especie y los ORCID.
 */
function plicData(PDO $pdo, string $catalogId, array $o, array $taxa): array {
    $byTaxon = [];
    foreach (exportOccurrences($pdo, $catalogId, $o) as $r) $byTaxon[$r["taxonID"]][] = $r;

    $refs = [];
    if ($taxa) {
        $in = implode(",", array_fill(0, count($taxa), "?"));
        $stmt = $pdo->prepare("SELECT a.*, u.name AS recorded_by, u.orcid AS recorded_by_orcid FROM species_assessments a
                                 JOIN users u ON u.id = a.user_id
                                WHERE a.catalog_id = ? AND a.deleted_at IS NULL AND a.is_reference = 1 AND a.taxonID IN ({$in})
                                ORDER BY a.client_updated_at");
        $stmt->execute(array_merge([$catalogId], $taxa));
        foreach ($stmt->fetchAll() as $a) $refs[$a["taxonID"]] = $a;   // si hubiera dos, la más reciente
    }
    return ["occurrences" => $byTaxon, "references" => $refs];
}

/** Personas (nombre + ORCID) sin repetir, en orden de aparición. */
function plicPeople(array $rows, string $nameKey, string $orcidKey): array {
    $out = [];
    foreach ($rows as $r) $out[$r[$nameKey]] ??= ["name" => $r[$nameKey], "orcid" => $r[$orcidKey] ?? null];
    return array_values($out);
}

function plicNomenclaturalCode(?string $kingdom): string {
    return match (mb_strtolower(trim((string) $kingdom))) {
        "plantae", "fungi" => "Botanical",
        "animalia" => "Zoological",
        "bacteria", "archaea" => "Bacteriological",
        default => "Indeterminate",
    };
}

// --- Piezas del XML ------------------------------------------------------------------------------

/** Agente del perfil EML de PliC: individualName/surName, ORCID en onlineUrl (no hay userId) y rol. */
function plicAgent(PlicXml $x, DOMNode $parent, string $tag, array $p, ?string $role = null): DOMElement {
    $a = $x->el($parent, $tag);
    $x->el($x->el($a, "individualName"), "surName", $p["name"]);
    if (!empty($p["orcid"])) $x->el($a, "onlineUrl", dwcOrcidUri($p["orcid"]));
    if ($role !== null) $x->el($a, "role", $role);
    return $a;
}

/** Nota (eol:dataObjectBaseType). Los hijos van en el orden del XSD de EOL 0.3. */
function plicNote(PlicXml $x, DOMNode $parent, string $title, string $text, array $opt = []): DOMElement {
    $n = $x->el($parent, "AncillaryData");
    $x->el($n, "eol:dataType", PLIC_TEXT_TYPE);
    foreach ($opt["agents"] ?? [] as $p) {
        $x->el($n, "eol:agent", $p["name"], ["role" => "author"] + (!empty($p["orcid"]) ? ["homepage" => dwcOrcidUri($p["orcid"])] : []));
    }
    if (!empty($opt["created"])) $x->el($n, "dcterms:created", $opt["created"]);
    $x->el($n, "dc:title", $title);
    $x->el($n, "dc:language", "es");
    if (!empty($opt["source"])) $x->el($n, "dc:source", $opt["source"]);
    if (!empty($opt["subject"])) $x->el($n, "eol:subject", $opt["subject"]);
    $x->el($n, "dc:description", $text);
    return $n;
}

function plicMeasurement(PlicXml $x, DOMNode $parent, array $m): void {
    $f = $x->el($parent, "dwc:MeasurementOrFact");
    foreach (["measurementType", "measurementValue", "measurementAccuracy", "measurementUnit", "measurementDeterminedBy",
              "measurementDeterminedDate", "measurementMethod", "measurementRemarks"] as $k) {
        if (isset($m[$k]) && $m[$k] !== "") $x->el($f, "dwc:{$k}", $m[$k]);
    }
}

function plicDateRange(PlicXml $x, DOMNode $parent, string $begin, string $end): void {
    $r = $x->el($x->el($parent, "temporalCoverage"), "rangeOfDates");
    $x->el($x->el($r, "beginDate"), "calendarDate", $begin);
    $x->el($x->el($r, "endDate"), "calendarDate", $end);
}

/** Metadata del documento (perfil EML-GBIF de PliC). */
function plicMetadata(PlicXml $x, DOMNode $root, array $catalog, array $o, array $prep, array $meta, array $ctx): void {
    $md = $x->el($root, "Metadata");
    $x->el($md, "Dataset_ID", $meta["packageId"]);
    $x->el($md, "dateStamp", $meta["date"]);
    $ds = $x->el($md, "dataset");
    $x->el($ds, "alternateIdentifier", $meta["packageId"]);
    $full = $o["mode"] === "full";
    $x->el($ds, "title", $o["title"] . " — fichas de especie" . ($full ? " (exportación completa, privada: no publicar)" : ""));
    $owner = ["name" => $prep["owner"]["name"], "orcid" => $prep["owner"]["orcid"]];
    plicAgent($x, $ds, "creator", $owner);
    plicAgent($x, $ds, "metadataProvider", $owner);
    foreach ($ctx["evaluators"] as $p) plicAgent($x, $ds, "associatedParty", $p, "Evaluador/a (evaluación BioDex)");
    foreach ($prep["observers"] as $p) plicAgent($x, $ds, "associatedParty", $p, "Observador/a (registros de distribución)");
    $x->el($ds, "pubDate", $meta["date"]);
    $x->el($ds, "language", "spa");
    $n = count($prep["species"]);
    $x->el($x->el($ds, "abstract"), "para", ($o["description"] !== "" ? $o["description"] . " " : "")
        . "Fichas de {$n} " . ($n === 1 ? "especie" : "especies") . " del catálogo BioDex «{$catalog["name"]}»: taxonomía según EIDOS "
        . "(IEPNB, MITECO), categorías de amenaza oficiales recogidas en EIDOS, la evaluación regional preliminar hecha en BioDex "
        . "(no oficial) y la distribución en cifras (EOO, AOO) según las observaciones exportadas. No incluye observaciones individuales.");
    $x->el($x->el($ds, "additionalInfo"), "para", "Generado con BioDex {$meta["version"]} el {$meta["generatedLocal"]} (hora peninsular), "
        . "exportación " . EXPORT_MODE_LABEL[$o["mode"]] . ". Plinian Core " . PLIC_VERSION . ", validado contra su XSD. "
        . ($ctx["withDwc"] ? "Fuente de los datos de distribución: el Darwin Core Archive dwca/ de esta misma descarga ({$ctx["zipName"]})."
                           : "Los registros de los que salen las cifras de distribución no van en esta descarga (no se eligió Darwin Core).")
        . ($full ? "" : " Ninguna ficha lleva coordenadas ni cuadrículas: solo cifras agregadas."));
    [$licUri, $licName] = DWC_LICENSES[$o["license"]];
    $x->el($x->el($ds, "intellectualRights"), "para", "{$licName} · {$licUri}");
    $cov = $x->el($ds, "coverage");
    if ($ctx["dates"]) plicDateRange($x, $cov, $ctx["dates"]["begin"], $ctx["dates"]["end"]);
    if ($prep["species"]) {
        $tc = $x->el($cov, "taxonomicCoverage");
        $fams = array_unique(array_filter(array_column($prep["species"], "family")));
        sort($fams);
        $x->el($tc, "generalTaxonomicCoverage", "{$n} " . ($n === 1 ? "taxón" : "taxones") . " de " . count($fams) . " " . (count($fams) === 1 ? "familia" : "familias"));
        foreach ($fams as $f) {
            $c = $x->el($tc, "taxonomicClassification");
            $x->el($c, "taxonRankName", "family");
            $x->el($c, "taxonRankValue", $f);
        }
        foreach ($prep["species"] as $s) {
            $c = $x->el($tc, "taxonomicClassification");
            $x->el($c, "taxonRankName", dwcTaxonRank($s["taxonRank"]) ?: "species");
            $x->el($c, "taxonRankValue", $s["canonical_name"] ?: $s["scientificName"]);
        }
    }
    if (!$cov->hasChildNodes()) $ds->removeChild($cov);
    plicAgent($x, $ds, "contact", $owner);
    $ms = $x->el($x->el($ds, "methods"), "methodStep");
    $x->el($x->el($ms, "description"), "para", "Taxonomía y categorías oficiales: EIDOS (IEPNB, MITECO). Evaluación BioDex: asistente UICN 3.1 "
        . "de la app, revisado por quien evalúa; solo se incluye la de referencia del catálogo. EOO: polígono convexo mínimo de las "
        . "presencias exportadas con precisión no peor que la celda; AOO: celdas de " . METRICS_DEFAULT_CELL_KM . "×" . METRICS_DEFAULT_CELL_KM
        . " km ocupadas; ambas en ETRS89 / UTM 30N (EPSG:25830), calculadas en el servidor con las coordenadas exactas.");
}

/** Estado de amenaza: una entrada por ámbito oficial de EIDOS y, aparte, la evaluación BioDex. */
function plicThreatStatus(PlicXml $x, DOMNode $dt, array $s, ?array $ref, array $catalog, bool $withhold): void {
    $official = array_filter(json_decode((string) $s["iucn_assessments"], true) ?: [], fn($a) => !empty($a["code"]));
    if (!$official && !$ref) return;
    $ts = $x->el($dt, "ThreatStatus");
    foreach ($official as $a) {
        $e = $x->el($ts, "ThreatStatusAtomized");
        $x->el($e, "ThreatCategory", trim($a["code"] . (!empty($a["criteria"]) ? " {$a["criteria"]}" : "")));
        $x->el($e, "Authority", DWC_EIDOS_ACCORDING_TO . " · categoría oficial" . (!empty($a["year"]) ? " de {$a["year"]}" : ""));
        $x->el($x->el($e, "AppliesTo"), "DistributionUnstructured", $a["scope"] ?: "Ámbito sin indicar en EIDOS");
    }
    if (!$ref) return;

    $result = json_decode((string) $ref["result"], true) ?: [];
    $inputs = json_decode((string) $ref["inputs"], true) ?: [];
    $evaluator = ["name" => $ref["recorded_by"], "orcid" => $ref["recorded_by_orcid"]];
    $e = $x->el($ts, "ThreatStatusAtomized");
    $x->el($e, "ThreatCategory", ($result["criteriaString"] ?? "") ?: $ref["category"]);
    $x->el($e, "Authority", PLIC_BIODEX_AUTHORITY);
    $x->el($e, "Authority", "Evaluador/a: {$evaluator["name"]}" . ($evaluator["orcid"] ? " (" . dwcOrcidUri($evaluator["orcid"]) . ")" : ""));
    $x->el($x->el($e, "AppliesTo"), "DistributionUnstructured", "Catálogo BioDex «{$catalog["name"]}» (evaluación regional)");

    $adj = ["down" => "un escalón abajo", "up" => "un escalón arriba"][$ref["regional_adjustment"]] ?? "ninguno";
    $parts = [PLIC_BIODEX_NOTE,
        "Categoría: {$ref["category"]}" . (($result["baseCategory"] ?? $ref["category"]) !== $ref["category"] ? " (antes del ajuste regional: {$result["baseCategory"]})" : "") . ".",
        "Criterios: " . ($result["criteriaString"] ?? "—") . ".",
        "Estado: " . ($ref["status"] === "final" ? "final" : "borrador") . ". Ajuste regional: {$adj}.",
    ];
    if (($inputs["B"]["locations"] ?? null) !== null) $parts[] = "Localidades (las indica quien evalúa): {$inputs["B"]["locations"]}.";
    $summary = exportAssessmentInputs($inputs);
    if ($summary !== "") $parts[] = "Datos usados: {$summary}.";
    if ($withhold) $parts[] = "Justificación retenida por la sensibilidad de la especie (puede describir dónde está).";
    elseif (trim((string) $ref["justification"]) !== "") $parts[] = "Justificación: " . trim($ref["justification"]);
    plicNote($x, $ts, "Evaluación BioDex — regional, preliminar, no oficial", implode(" ", $parts),
        ["agents" => [$evaluator], "created" => syncIsoUtc($ref["client_updated_at"]), "subject" => PLIC_SUBJECT_STATUS]);
}

/** EOO, AOO y celdas (solo cifras) de las presencias exportadas de la especie. */
function plicTerritory(PlicXml $x, DOMNode $dt, array $m, array $meta, ?string $withheldNote): void {
    $ta = $x->el($x->el($dt, "Territory"), "TerritoryAtomized");
    $cell = "{$m["cellSizeKm"]}×{$m["cellSizeKm"]} km";
    $common = ["measurementDeterminedBy" => "BioDex {$meta["version"]} (servidor)", "measurementDeterminedDate" => $meta["date"]];
    $scope = "Solo con las presencias de esta exportación del catálogo ({$m["nRecords"]} "
        . ($m["nRecords"] === 1 ? "registro" : "registros") . "), no con la distribución completa de la especie.";
    if ($m["eooKm2"] !== null) {
        plicMeasurement($x, $x->el($ta, "ExtentOfOccurrence"), $common + [
            "measurementType" => "extensión de presencia (EOO)", "measurementValue" => plicNum($m["eooKm2"]), "measurementUnit" => "km²",
            "measurementMethod" => "Polígono convexo mínimo de las presencias con precisión no peor que {$m["cellSizeKm"]} km, en ETRS89 / UTM 30N (EPSG:25830)",
            "measurementRemarks" => $scope . ($m["eooAdjusted"] ? " EOO menor que AOO: se toma EOO = AOO (UICN)." : ""),
        ]);
    }
    if ($m["aooCells"] > 0) {
        $aoo = $x->el($ta, "AreaOfOccupancy");
        $r = $m["aooRange"];
        $range = !$r ? null : ($r["min"] === $r["max"] ? "sin cambio al desplazar la rejilla media celda"
            : plicNum($r["min"]) . "–" . plicNum($r["max"]) . " km² al desplazar la rejilla media celda");
        plicMeasurement($x, $aoo, $common + [
            "measurementType" => "área de ocupación (AOO)", "measurementValue" => plicNum($m["aooKm2"]), "measurementUnit" => "km²",
            "measurementAccuracy" => $range, "measurementMethod" => "Celdas de {$cell} ocupadas, rejilla anclada al origen UTM (EPSG:25830)",
            "measurementRemarks" => $scope,
        ]);
        plicMeasurement($x, $aoo, $common + ["measurementType" => "número de cuadrículas ocupadas", "measurementValue" => $m["aooCells"],
            "measurementUnit" => "cuadrículas de {$cell}"]);
        plicMeasurement($x, $aoo, $common + ["measurementType" => "tamaño de celda", "measurementValue" => $m["cellSizeKm"], "measurementUnit" => "km"]);
    }
    $warnings = array_values(array_filter($m["warnings"], fn($w) => $w !== METRICS_CELL_1KM_WARNING));
    if ($warnings) plicNote($x, $ta, "Avisos del cálculo", implode(" ", $warnings), ["subject" => PLIC_SUBJECT_DISTR]);
    if ($withheldNote) plicNote($x, $ta, PLIC_WITHHELD_TITLE, $withheldNote, ["subject" => PLIC_SUBJECT_DISTR]);
}

/** Una especie → TaxonRecord. */
function plicTaxonRecord(PlicXml $x, DOMNode $root, array $s, array $rows, ?array $ref, array $catalog, array $o, array $meta, array $ctx): void {
    $t = dwcTaxonTerms($s);
    $level = normalizeSensitivityLevel($s["sensitivity_level"]);
    $sensitive = $o["mode"] === "public" && $level < 4;
    $tr = $x->el($root, "TaxonRecord");

    $be = $x->el($tr, "BaseElements");
    $x->el($be, "TaxonRecordID", $t["taxonID"]);
    if ($t["nameAccordingTo"]) $x->el($be, "dwc:nameAccordingTo", $t["nameAccordingTo"]);

    $rm = $x->el($tr, "RecordMetadata");
    $x->el($rm, "Language", "es");
    $v = $x->el($rm, "Version");
    $x->el($v, "Modifier", "BioDex {$meta["version"]}");
    $x->el($v, "DateIssued", $meta["date"]);
    $rv = $x->el($rm, "Revision");
    plicAgent($x, $rv, "associatedParty", ["name" => $meta["by"], "orcid" => null], "Generó la ficha");
    $x->el($rv, "pubDate", $meta["date"]);
    $x->el($rv, "dcterms:created", $meta["generatedIso"]);

    // Nomenclatura y clasificación
    $nc = $x->el($tr, "NomenclatureAndClassification");
    $code = plicNomenclaturalCode($s["kingdom"]);
    $nameId = "n-" . preg_replace('/[^A-Za-z0-9._-]/', "-", $t["taxonID"]);
    $sn = $x->el($x->el($nc, "TaxonRecordName"), "ScientificName", null, ["id" => $nameId, "nomenclaturalCode" => $code]);
    $x->el($sn, "tcs:Simple", $s["scientificName"]);
    if ($t["taxonRank"]) {
        $x->el($sn, "tcs:Rank", $t["taxonRank"], isset(PLIC_TCS_RANKS[$t["taxonRank"]]) ? ["code" => PLIC_TCS_RANKS[$t["taxonRank"]]] : []);
    }
    if ($s["canonical_name"]) $x->el($x->el($sn, "tcs:CanonicalName"), "tcs:Simple", $s["canonical_name"]);
    if ($t["scientificNameAuthorship"]) $x->el($x->el($sn, "tcs:CanonicalAuthorship"), "tcs:Simple", $t["scientificNameAuthorship"]);
    $synonyms = array_values(array_filter(json_decode((string) ($s["synonyms"] ?? ""), true) ?: [], "is_string"));
    if ($synonyms) {
        $sy = $x->el($nc, "Synonyms");
        foreach ($synonyms as $i => $name) {
            $syn = $x->el($x->el($sy, "SynonymsAtomized"), "SynonymName", null, ["id" => "{$nameId}-s" . ($i + 1), "nomenclaturalCode" => $code]);
            $x->el($syn, "tcs:Simple", $name);
        }
    }
    if ($t["vernacularName"]) {
        $cn = $x->el($x->el($nc, "CommonNames"), "CommonNamesAtomized");
        $x->el($cn, "CommonName", $t["vernacularName"]);
        $x->el($cn, "Language", "es");
    }
    $h = $x->el($nc, "Hierarchy", null, isset(PLIC_CLASSIFICATION[$s["classification_source"] ?? ""])
        ? ["classification" => PLIC_CLASSIFICATION[$s["classification_source"]]] : []);
    foreach (["kingdom", "phylum", "class", "order", "family", "genus", "taxonRank", "specificEpithet", "infraspecificEpithet"] as $k) {
        if ($t[$k]) $x->el($h, "dwc:{$k}", $t[$k]);
    }

    // Distribución: periodo y ámbito de los registros exportados; su fuente, el DwC-A
    $days = array_map(fn($r) => exportLocal($r["eventDate"])->format("Y-m-d"), $rows);
    if ($rows) {
        $d = $x->el($x->el($tr, "HabitatAndDistribution"), "Distribution");
        plicDateRange($x, $d, min($days), max($days));
        $da = $x->el($d, "DistributionAtomized");
        $x->el($da, "dwc:country", "España");
        $x->el($da, "dwc:locality", "Catálogo BioDex «{$catalog["name"]}»");
        $pres = count(array_filter($rows, fn($r) => $r["occurrenceStatus"] === "present"));
        plicNote($x, $d, "Fuente de los datos de distribución",
            plural(count($rows), "registro exportado", "registros exportados") . " (" . plural($pres, "presencia", "presencias") . ", "
            . plural(count($rows) - $pres, "ausencia", "ausencias") . ") entre el " . min($days)
            . " y el " . max($days) . ". Reflejan las observaciones del catálogo, no la distribución completa de la especie.",
            ["source" => $ctx["withDwc"] ? "dwca/occurrence.txt de esta descarga ({$ctx["zipName"]}), taxonID {$t["taxonID"]}"
                                         : "Observaciones del catálogo BioDex «{$catalog["name"]}» (no incluidas en esta descarga)",
             "subject" => PLIC_SUBJECT_DISTR]);
    }

    // Demografía y amenaza: Territory (EOO/AOO) antes que ThreatStatus, como pide el XSD
    $m = $rows ? computeMetrics($rows, METRICS_DEFAULT_CELL_KM) : null;
    $withheld = null;
    if ($sensitive) {
        $withheld = "Especie sensible en este catálogo (nivel " . exportLevelLabel($level) . "): no se publican coordenadas, cuadrículas "
            . "concretas ni el contorno de la EOO; solo cifras agregadas (EOO, AOO, número de cuadrículas). "
            . ($ref ? "La justificación de la evaluación BioDex también se retiene." : "");
    }
    $dt = $x->el($tr, "DemographyAndThreat");
    if ($m && $m["nRecords"] > 0) plicTerritory($x, $dt, $m, $meta, $withheld);
    plicThreatStatus($x, $dt, $s, $ref, $catalog, $sensitive);
    if (!$dt->hasChildNodes()) $tr->removeChild($dt);

    // Autoría de la ficha: quien evaluó y quien observó (con ORCID)
    if ($ref) plicAgent($x, $tr, "associatedParty", ["name" => $ref["recorded_by"], "orcid" => $ref["recorded_by_orcid"]], "Evaluador/a (evaluación BioDex)");
    foreach (plicPeople($rows, "recorded_by", "recorded_by_orcid") as $p) plicAgent($x, $tr, "associatedParty", $p, "Observador/a (registros de distribución)");
    if ($rows && $ctx["withDwc"]) {
        $x->el($tr, "References", "Darwin Core Archive dwca/ de esta descarga ({$ctx["zipName"]}): observaciones con taxonID {$t["taxonID"]}.");
    }
}

// --- Documento y validación ----------------------------------------------------------------------

/** Errores de validación contra el XSD vendorizado ([] = válido). Valida el texto tal cual se entrega. */
function plinianValidate(string $xml): array {
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $d = new DOMDocument();
    $errors = [];
    if (!$d->loadXML($xml, LIBXML_NONET)) $errors[] = "XML mal formado";
    elseif (!$d->schemaValidate(PLIC_XSD)) $errors[] = "no valida";
    if ($errors) {
        foreach (libxml_get_errors() as $e) $errors[] = "línea {$e->line}: " . trim($e->message);
        $errors = array_values(array_diff($errors, ["no valida"])) ?: ["no valida"];
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $errors;
}

/**
 * pliniancore/especies.xml de la exportación. $ctx: withDwc (se incluye dwca/), zipName.
 * Lanza PlinianInvalidXml si el documento no valida contra el XSD.
 */
function plinianXml(PDO $pdo, string $catalogId, array $catalog, array $o, array $prep, array $meta, array $ctx): string {
    $data = plicData($pdo, $catalogId, $o, array_keys($prep["species"]));
    $allDays = [];
    foreach ($data["occurrences"] as $rows) foreach ($rows as $r) $allDays[] = exportLocal($r["eventDate"])->format("Y-m-d");
    $ctx += [
        "dates" => $allDays ? ["begin" => min($allDays), "end" => max($allDays)] : null,
        "evaluators" => plicPeople($data["references"], "recorded_by", "recorded_by_orcid"),
    ];

    $x = new PlicXml();
    $root = $x->el($x->doc, "Dataset");
    foreach (PLIC_NS as $p => $ns) $root->setAttributeNS("http://www.w3.org/2000/xmlns/", "xmlns:{$p}", $ns);
    plicMetadata($x, $root, $catalog, $o, $prep, $meta, $ctx);
    foreach ($prep["species"] as $taxonID => $s) {
        plicTaxonRecord($x, $root, $s, $data["occurrences"][$taxonID] ?? [], $data["references"][$taxonID] ?? null, $catalog, $o, $meta, $ctx);
    }
    $xml = $x->doc->saveXML();

    $errors = plinianValidate($xml);
    if ($errors) {
        error_log("BioDex: Plinian Core no valida: " . implode(" | ", array_slice($errors, 0, 5)));
        throw new PlinianInvalidXml($errors[0]);
    }
    return $xml;
}
