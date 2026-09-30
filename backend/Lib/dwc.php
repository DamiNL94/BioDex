<?php
// ================================================================================================
// dwc.php — Darwin Core de BioDex (F10a): términos con su URI, fila de occurrence.txt, meta.xml y eml.xml.
// ------------------------------------------------------------------------------------------------
// Adaptado de NaturDex (Backend/Lib/dwc.php): una sola declaración término ↔ URI, de la que salen la
// cabecera del TSV y meta.xml (así no pueden discrepar). Los valores de campo pasan por dwcTsvValue():
// el texto delimitado de DwC no define escape, y un tabulador o salto dentro de un campo parte la fila.
//
// La coordenada que llega aquí YA es la que se puede publicar: la decide export.php con applyVisibility()
// (modo completo) o applyPublicVisibility() (modo «para publicar»). Este fichero no generaliza nada.
// Mapeo término a término: docs/dwc.md.
// ================================================================================================

const DWC_NS = "http://rs.tdwg.org/dwc/terms/";
const DC_NS  = "http://purl.org/dc/terms/";

/** Términos de occurrence.txt, en orden (docs/prompts/F10a §3). término → URI. */
const DWC_OCCURRENCE_TERMS = [
    "occurrenceID" => DWC_NS, "basisOfRecord" => DWC_NS, "occurrenceStatus" => DWC_NS, "eventDate" => DWC_NS,
    "recordedBy" => DWC_NS, "recordedByID" => DWC_NS, "individualCount" => DWC_NS, "sex" => DWC_NS,
    "lifeStage" => DWC_NS, "occurrenceRemarks" => DWC_NS, "habitat" => DWC_NS, "decimalLatitude" => DWC_NS,
    "decimalLongitude" => DWC_NS, "geodeticDatum" => DWC_NS, "coordinateUncertaintyInMeters" => DWC_NS,
    "georeferenceProtocol" => DWC_NS, "georeferenceRemarks" => DWC_NS, "dataGeneralizations" => DWC_NS,
    "informationWithheld" => DWC_NS, "countryCode" => DWC_NS, "scientificName" => DWC_NS,
    "scientificNameAuthorship" => DWC_NS, "taxonID" => DWC_NS, "nameAccordingTo" => DWC_NS, "kingdom" => DWC_NS,
    "phylum" => DWC_NS, "class" => DWC_NS, "order" => DWC_NS, "family" => DWC_NS, "genus" => DWC_NS,
    "specificEpithet" => DWC_NS, "infraspecificEpithet" => DWC_NS, "taxonRank" => DWC_NS,
    "vernacularName" => DWC_NS, "taxonRemarks" => DWC_NS, "datasetName" => DWC_NS, "license" => DC_NS,
    "rightsHolder" => DC_NS, "language" => DC_NS, "modified" => DC_NS,
];

/** Extensión MeasurementOrFact: la primera columna es el id del núcleo (coreid, occurrenceID). */
const DWC_MOF_TERMS = [
    "measurementID" => DWC_NS, "measurementType" => DWC_NS, "measurementValue" => DWC_NS,
    "measurementUnit" => DWC_NS, "measurementDeterminedBy" => DWC_NS, "measurementDeterminedDate" => DWC_NS,
    "measurementMethod" => DWC_NS,
];

const DWC_ROWTYPE_OCCURRENCE = "http://rs.tdwg.org/dwc/terms/Occurrence";
const DWC_ROWTYPE_MOF        = "http://rs.tdwg.org/dwc/terms/MeasurementOrFact";

const DWC_EIDOS_ACCORDING_TO = "EIDOS (IEPNB, MITECO)";
const DWC_USER_TAXON_REMARKS = "Taxón no presente en EIDOS";
const DWC_MATURE_TYPE        = "número de individuos maduros";

/** Licencias que acepta GBIF. clave → [URI, nombre legible]. La primera es la de por defecto. */
const DWC_LICENSES = [
    "CC-BY-4.0"    => ["http://creativecommons.org/licenses/by/4.0/legalcode", "Creative Commons Attribution (CC-BY 4.0)"],
    "CC0-1.0"      => ["http://creativecommons.org/publicdomain/zero/1.0/legalcode", "Creative Commons Zero (CC0 1.0)"],
    "CC-BY-NC-4.0" => ["http://creativecommons.org/licenses/by-nc/4.0/legalcode", "Creative Commons Attribution Non Commercial (CC-BY-NC 4.0)"],
];

/** Rango de EIDOS → vocabulario de GBIF (minúsculas en inglés). Lo desconocido, en minúsculas tal cual. */
const DWC_TAXON_RANKS = [
    "especie" => "species", "species" => "species", "subespecie" => "subspecies", "subspecies" => "subspecies",
    "variedad" => "variety", "variety" => "variety", "forma" => "form", "form" => "form",
    "género" => "genus", "genus" => "genus", "familia" => "family", "family" => "family",
];

/** Valor listo para un campo TSV: tabuladores y saltos → un espacio; null → vacío. */
function dwcTsvValue(mixed $v): string {
    if ($v === null || $v === false) return "";
    if ($v === true) return "true";
    if (is_float($v)) return rtrim(rtrim(sprintf("%.7F", $v), "0"), ".");
    return trim(preg_replace('/[\t\r\n]+/u', " ", (string) $v) ?? "");
}

/** Filas → texto TSV (UTF-8, \n) con la cabecera de $terms. Cada fila: término → valor. */
function dwcTsv(array $terms, array $rows): string {
    $out = implode("\t", $terms) . "\n";
    foreach ($rows as $r) {
        $out .= implode("\t", array_map(fn($t) => dwcTsvValue($r[$t] ?? null), $terms)) . "\n";
    }
    return $out;
}

/** taxonID publicado: EIDOS:<idtaxon> para EIDOS; biodex:<uuid> tal cual para las propias. */
function dwcTaxonID(string $taxonID): string {
    return ctype_digit($taxonID) ? "EIDOS:{$taxonID}" : $taxonID;
}

function dwcTaxonRank(?string $rank): ?string {
    if ($rank === null || trim($rank) === "") return null;
    $k = mb_strtolower(trim($rank));
    return DWC_TAXON_RANKS[$k] ?? $k;
}

/** ORCID → URI (recordedByID, userId del EML). */
function dwcOrcidUri(?string $orcid): ?string {
    return $orcid ? "https://orcid.org/{$orcid}" : null;
}

/** Términos taxonómicos de una fila de `species` (comunes a occurrence.txt y a la hoja Especies). */
function dwcTaxonTerms(array $sp): array {
    $eidos = ($sp["source"] ?? "") === "eidos";
    $remarks = $eidos ? ($sp["taxonRemarks"] ?? null)
        : implode(". ", array_filter([DWC_USER_TAXON_REMARKS, $sp["taxonRemarks"] ?? null]));
    return [
        "scientificName"           => $sp["scientificName"],
        "scientificNameAuthorship" => $sp["scientificNameAuthorship"] ?? null,
        "taxonID"                  => dwcTaxonID($sp["taxonID"]),
        "nameAccordingTo"          => $eidos ? DWC_EIDOS_ACCORDING_TO : null,
        "kingdom"                  => $sp["kingdom"] ?? null,
        "phylum"                   => $sp["phylum"] ?? null,
        "class"                    => $sp["class"] ?? null,
        "order"                    => $sp["order"] ?? null,
        "family"                   => $sp["family"] ?? null,
        "genus"                    => $sp["genus"] ?? null,
        "specificEpithet"          => $sp["specificEpithet"] ?? null,
        "infraspecificEpithet"     => $sp["infraspecificEpithet"] ?? null,
        "taxonRank"                => dwcTaxonRank($sp["taxonRank"] ?? null),
        "vernacularName"           => $sp["vernacularName"] ?? null,
        "taxonRemarks"             => $remarks ?: null,
    ];
}

// --- meta.xml ------------------------------------------------------------------------------------

function dwcXml(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, "UTF-8");
}

/** Descriptor del archivo (esquema http://rs.tdwg.org/dwc/text/tdwg_dwc_text.xsd). */
function dwcMetaXml(bool $withMof): string {
    $fileAttrs = 'encoding="UTF-8" fieldsTerminatedBy="\t" linesTerminatedBy="\n" fieldsEnclosedBy="" ignoreHeaderLines="1"';
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
       . '<archive xmlns="http://rs.tdwg.org/dwc/text/" metadata="eml.xml">' . "\n"
       . "  <core {$fileAttrs} rowType=\"" . DWC_ROWTYPE_OCCURRENCE . "\">\n"
       . "    <files><location>occurrence.txt</location></files>\n"
       . "    <id index=\"0\"/>\n";
    $i = 0;
    foreach (DWC_OCCURRENCE_TERMS as $term => $ns) $x .= "    <field index=\"" . $i++ . "\" term=\"{$ns}{$term}\"/>\n";
    $x .= "  </core>\n";
    if ($withMof) {
        $x .= "  <extension {$fileAttrs} rowType=\"" . DWC_ROWTYPE_MOF . "\">\n"
            . "    <files><location>measurementorfact.txt</location></files>\n"
            . "    <coreid index=\"0\"/>\n";
        $i = 1;
        foreach (DWC_MOF_TERMS as $term => $ns) $x .= "    <field index=\"" . $i++ . "\" term=\"{$ns}{$term}\"/>\n";
        $x .= "  </extension>\n";
    }
    return $x . "</archive>\n";
}

// --- eml.xml (GBIF Metadata Profile 1.3, EML 2.2.0) ----------------------------------------------

function dwcEmlParty(string $tag, array $p, string $indent = "    "): string {
    $x = "{$indent}<{$tag}>\n{$indent}  <individualName><surName>" . dwcXml($p["name"]) . "</surName></individualName>\n";
    if (!empty($p["role"])) $x .= "{$indent}  <positionName>" . dwcXml($p["role"]) . "</positionName>\n";
    if (!empty($p["orcid"])) $x .= "{$indent}  <userId directory=\"https://orcid.org/\">" . dwcXml(dwcOrcidUri($p["orcid"])) . "</userId>\n";
    return $x . "{$indent}</{$tag}>\n";
}

function dwcParas(string $text, string $indent): string {
    $x = "";
    foreach (preg_split('/\n\s*\n/u', trim($text)) as $p) {
        if (trim($p) !== "") $x .= "{$indent}<para>" . dwcXml(trim(preg_replace('/\s+/u', " ", $p))) . "</para>\n";
    }
    return $x;
}

/**
 * $d: packageId, title, abstract, creators [{name, orcid}], contact {name, orcid}, license (clave),
 * bbox {west, east, south, north} | null, dates {begin, end} (AAAA-MM-DD) | null, families [..],
 * species [[scientificName, taxonRank]], methods, generalization (texto) | null, modeLabel, pubDate.
 */
function dwcEmlXml(array $d): string {
    [$licUri, $licName] = DWC_LICENSES[$d["license"]];
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
       . '<eml:eml xmlns:eml="https://eml.ecoinformatics.org/eml-2.2.0" xmlns:dc="http://purl.org/dc/terms/" '
       . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
       . 'xsi:schemaLocation="https://eml.ecoinformatics.org/eml-2.2.0 https://rs.gbif.org/schema/eml-gbif-profile/1.3/eml.xsd" '
       . 'packageId="' . dwcXml($d["packageId"]) . '" system="http://gbif.org" scope="system" xml:lang="spa">' . "\n"
       . "  <dataset>\n"
       . "    <alternateIdentifier>" . dwcXml($d["packageId"]) . "</alternateIdentifier>\n"
       . '    <title xml:lang="spa">' . dwcXml($d["title"]) . "</title>\n";
    foreach ($d["creators"] ?: [$d["contact"]] as $c) $x .= dwcEmlParty("creator", $c);
    $x .= dwcEmlParty("metadataProvider", $d["contact"]);
    $x .= "    <pubDate>" . dwcXml($d["pubDate"]) . "</pubDate>\n"
        . "    <language>spa</language>\n"
        . "    <abstract>\n" . dwcParas($d["abstract"], "      ") . "    </abstract>\n"
        . "    <keywordSet>\n      <keyword>Occurrence</keyword>\n"
        . "      <keywordThesaurus>GBIF Dataset Type Vocabulary: http://rs.gbif.org/vocabulary/gbif/dataset_type_2015-07-10.xml</keywordThesaurus>\n"
        . "    </keywordSet>\n";
    if (!empty($d["generalization"])) $x .= "    <additionalInfo>\n" . dwcParas($d["generalization"], "      ") . "    </additionalInfo>\n";
    $x .= "    <intellectualRights>\n      <para>Esta obra está bajo una licencia <ulink url=\"" . dwcXml($licUri) . "\"><citetitle>"
        . dwcXml($licName) . "</citetitle></ulink>.</para>\n    </intellectualRights>\n";

    $cov = "";
    if ($d["bbox"]) {
        $b = $d["bbox"];
        $f = fn($v) => rtrim(rtrim(sprintf("%.6F", $v), "0"), ".");
        $cov .= "      <geographicCoverage>\n        <geographicDescription>" . dwcXml($d["geoDescription"]) . "</geographicDescription>\n"
              . "        <boundingCoordinates>\n"
              . "          <westBoundingCoordinate>{$f($b["west"])}</westBoundingCoordinate>\n"
              . "          <eastBoundingCoordinate>{$f($b["east"])}</eastBoundingCoordinate>\n"
              . "          <northBoundingCoordinate>{$f($b["north"])}</northBoundingCoordinate>\n"
              . "          <southBoundingCoordinate>{$f($b["south"])}</southBoundingCoordinate>\n"
              . "        </boundingCoordinates>\n      </geographicCoverage>\n";
    }
    if ($d["dates"]) {
        $cov .= "      <temporalCoverage>\n        <rangeOfDates>\n"
              . "          <beginDate><calendarDate>{$d["dates"]["begin"]}</calendarDate></beginDate>\n"
              . "          <endDate><calendarDate>{$d["dates"]["end"]}</calendarDate></endDate>\n"
              . "        </rangeOfDates>\n      </temporalCoverage>\n";
    }
    if ($d["families"] || $d["species"]) {
        $cov .= "      <taxonomicCoverage>\n        <generalTaxonomicCoverage>" . dwcXml(count($d["species"]) . " taxones de "
              . count($d["families"]) . " familias") . "</generalTaxonomicCoverage>\n";
        foreach ($d["families"] as $fam) {
            $cov .= "        <taxonomicClassification><taxonRankName>family</taxonRankName><taxonRankValue>" . dwcXml($fam) . "</taxonRankValue></taxonomicClassification>\n";
        }
        foreach ($d["species"] as [$name, $rank]) {
            $cov .= "        <taxonomicClassification><taxonRankName>" . dwcXml($rank ?: "species") . "</taxonRankName><taxonRankValue>"
                  . dwcXml($name) . "</taxonRankValue></taxonomicClassification>\n";
        }
        $cov .= "      </taxonomicCoverage>\n";
    }
    if ($cov !== "") $x .= "    <coverage>\n{$cov}    </coverage>\n";
    $x .= "    <maintenance>\n      <description>\n        <para>Exportación puntual de BioDex (" . dwcXml($d["modeLabel"])
        . "); se regenera desde la app.</para>\n      </description>\n      <maintenanceUpdateFrequency>unkown</maintenanceUpdateFrequency>\n    </maintenance>\n";
    $x .= dwcEmlParty("contact", $d["contact"]);
    $x .= "    <methods>\n      <methodStep>\n        <description>\n" . dwcParas($d["methods"], "          ")
        . "        </description>\n      </methodStep>\n    </methods>\n";
    $x .= "  </dataset>\n  <additionalMetadata>\n    <metadata>\n      <gbif>\n"
        . "        <dateStamp>" . dwcXml($d["dateStamp"]) . "</dateStamp>\n"
        . "        <hierarchyLevel>dataset</hierarchyLevel>\n"
        . "      </gbif>\n    </metadata>\n  </additionalMetadata>\n</eml:eml>\n";
    return $x;
}
