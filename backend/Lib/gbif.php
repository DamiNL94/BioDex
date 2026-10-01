<?php
// ================================================================================================
// gbif.php — foto de respaldo de GBIF para especies de EIDOS sin foto de la Fototeca ni de iNaturalist (F11d).
// ------------------------------------------------------------------------------------------------
// Tres piezas puras (sin red ni BD), probadas con las respuestas reales de docs/gbif-samples/:
//   · gbifMatchDecide(): ¿el taxón de GBIF es la especie de EIDOS?
//       EXACT + ACCEPTED, mismo rango y reino           → exact   (→ ok)
//       EXACT + SYNONYM/DOUBTFUL, mismo rango y reino   → synonym (→ review), con acceptedUsageKey
//       FUZZY, HIGHERRANK, NONE, otro rango, otro reino, sinónimo «pro parte» o mal aplicado → none
//   · gbifLicense(): licencia de la FOTO (media[].license; la de la ocurrencia no vale para la foto) →
//     [código, versión] si es una URL de Creative Commons o un código reconocible; texto libre → null.
//   · gbifCandidates(): fotos de ocurrencias que cumplen todo (taxón, sin issues de match dudoso, StillImage
//     jpeg/png, licencia admitida, autor).
// gbifFind() junta las piezas con la red: match → ocurrencias en España → (sin candidata) en todo el mundo
// → descarga de la miniatura de la caché de imágenes de GBIF, una candidata tras otra.
// Cliente: Lib/photo-files.php (1 petición/s, reintentos). Usa /v1/species/match: el /v2 tiene otra forma
// de respuesta y la v1 sigue funcionando (comprobado el 2026-10-01).
// Reglas y ejemplos: docs/fotos-respaldo.md.
// ================================================================================================

require_once __DIR__ . "/photo-files.php";
require_once __DIR__ . "/inat.php";   // inatStripAuthor()

const GBIF_API = "https://api.gbif.org/v1";
const GBIF_LICENSES = ["cc0", "cc-by", "cc-by-nc", "cc-by-sa"];
const GBIF_MIMES = ["image/jpeg", "image/png"];
/** Rango de EIDOS → rango de GBIF. */
const GBIF_RANKS = ["Species" => "SPECIES", "Subspecies" => "SUBSPECIES", "Variety" => "VARIETY", "Form" => "FORM"];
const GBIF_SYNONYM_STATUS = ["SYNONYM", "HETEROTYPIC_SYNONYM", "HOMOTYPIC_SYNONYM", "DOUBTFUL"];
const GBIF_BAD_ISSUES = ["TAXON_MATCH_FUZZY", "TAXON_MATCH_HIGHERRANK"];
const GBIF_TIMEOUT_S = 20;          // la API de GBIF tarda a veces 5–10 s
const GBIF_IMAGE_TIMEOUT_S = 30;    // la caché de imágenes, más si la foto no estaba ya cacheada
const GBIF_MAX_DOWNLOADS = 3;       // candidatas que se intentan descargar como mucho
const GBIF_OCCURRENCE_PAGE = "https://www.gbif.org/occurrence/";
const GBIF_SPECIES_PAGE = "https://www.gbif.org/species/";

/** GET /v1/species/match?name=…&kingdom=…&strict=true. Lanza PhotoSourceException si no hay respuesta. */
function gbifMatch(string $name, ?string $kingdom): array {
    $q = ["name" => $name, "strict" => "true"] + ($kingdom ? ["kingdom" => $kingdom] : []);
    return photoGetJson("gbif", GBIF_API . "/species/match?" . http_build_query($q, "", "&", PHP_QUERY_RFC3986), ["timeout" => GBIF_TIMEOUT_S]);
}

/** GET /v1/occurrence/search: observaciones con foto del taxón (en un país, o en todo el mundo). */
function gbifOccurrences(int $taxonKey, ?string $country): array {
    $q = ["taxonKey" => $taxonKey, "mediaType" => "StillImage", "basisOfRecord" => "HUMAN_OBSERVATION", "limit" => 50]
        + ($country ? ["country" => $country] : []);
    return photoGetJson("gbif", GBIF_API . "/occurrence/search?" . http_build_query($q), ["timeout" => GBIF_TIMEOUT_S]);
}

/**
 * ¿El taxón de GBIF es la especie de EIDOS? $species = {name, rank, kingdom?}.
 * → ["status" => "none", "reason"] o ["match_type" => exact|synonym, "key" => clave aceptada (para buscar
 *   fotos), "usage_key" => clave del nombre, "matched_name" => nombre aceptado si se sabe].
 */
function gbifMatchDecide(array $species, array $match): array {
    $none = fn(string $reason) => ["status" => "none", "reason" => $reason];
    $rank = GBIF_RANKS[$species["rank"] ?? ""] ?? null;
    if ($rank === null) return $none("rango no admitido: " . ($species["rank"] ?? "—"));
    $type = $match["matchType"] ?? "NONE";
    if ($type !== "EXACT") return $none("GBIF: coincidencia {$type}");
    if (($match["rank"] ?? "") !== $rank) return $none("GBIF: rango " . ($match["rank"] ?? "—") . " frente a {$rank}");
    if (!empty($species["kingdom"]) && ($match["kingdom"] ?? null) !== $species["kingdom"]) {
        return $none("GBIF: otro reino (" . ($match["kingdom"] ?? "—") . ")");
    }
    $status = $match["status"] ?? "";
    $usageKey = (int) ($match["usageKey"] ?? 0);
    if ($usageKey <= 0) return $none("GBIF: sin clave de taxón");
    if ($status === "ACCEPTED") {
        return ["match_type" => "exact", "key" => $usageKey, "usage_key" => $usageKey, "matched_name" => (string) ($match["canonicalName"] ?? $species["name"])];
    }
    if (!in_array($status, GBIF_SYNONYM_STATUS, true)) return $none("GBIF: estado {$status}");
    $key = $status === "DOUBTFUL" ? $usageKey : (int) ($match["acceptedUsageKey"] ?? 0);
    if ($key <= 0) return $none("GBIF: sinónimo sin taxón aceptado");
    // El aceptado tiene que ser del mismo rango: si EIDOS da una especie, el aceptado es su speciesKey; si da
    // una subespecie o variedad, el aceptado NO puede ser la especie (sus fotos podrían ser de otra subespecie).
    $speciesKey = (int) ($match["speciesKey"] ?? 0);
    if ($status !== "DOUBTFUL" && ($rank === "SPECIES") !== ($speciesKey === $key)) {
        return $none("GBIF: sinónimo de un taxón de otro rango");
    }
    return ["match_type" => "synonym", "key" => $key, "usage_key" => $usageKey,
            "matched_name" => $rank === "SPECIES" ? (string) ($match["species"] ?? "") : ""];
}

/**
 * Licencia de la foto → [código, versión|null]; null si no se admite o no se reconoce.
 * URL: http(s)://creativecommons.org/licenses/by[-nc|-sa]/4.0/ (…/legalcode) o /publicdomain/zero/1.0/.
 * Código: «CC0», «CC0_1_0», «CC-BY-4.0», «CC BY-NC 4.0», «cc-by-sa»… Cualquier otro texto → null
 * (p. ej. «Copyright by the creator…» o «Denis Bastianelli (cc-by-sa)»).
 */
function gbifLicense(?string $s): ?array {
    $s = trim((string) $s);
    if (preg_match('#^https?://creativecommons\.org/publicdomain/zero/(1\.0)/?(legalcode(\.[a-z-]+)?)?$#i', $s, $m)) return ["cc0", $m[1]];
    if (preg_match('#^https?://creativecommons\.org/licenses/(by|by-nc|by-sa)/([0-9]\.[0-9])/?(legalcode(\.[a-z-]+)?)?$#i', $s, $m)) {
        $code = "cc-" . strtolower($m[1]);
    } elseif (preg_match('/^cc[ _-]?(0|by(?:[ _-](?:nc|sa))?)(?:[ _-]([0-9])[._]([0-9]))?$/i', $s, $m)) {
        $code = $m[1] === "0" ? "cc0" : "cc-" . strtolower(preg_replace('/[ _]/', "-", $m[1]));
        $m[2] = isset($m[2]) && $m[2] !== "" ? "{$m[2]}.{$m[3]}" : null;
    } else {
        return null;
    }
    return in_array($code, GBIF_LICENSES, true) ? [$code, $m[2] ?? null] : null;
}

/**
 * Fotos candidatas de una respuesta de /occurrence/search, en orden. Una por ocurrencia (su primer media que
 * cumple todo). $key = clave aceptada; $rank = rango de EIDOS.
 * → [{gbifID, md5, identifier, license, license_version, attribution, accepted_name}]
 */
function gbifCandidates(array $response, int $key, string $rank): array {
    $out = [];
    foreach ($response["results"] ?? [] as $o) {
        if (!is_array($o)) continue;
        // Especie: su speciesKey. Subespecie o variedad: acceptedTaxonKey (su speciesKey es el de la especie).
        $oKey = (int) ($rank === "Species" ? ($o["speciesKey"] ?? 0) : ($o["acceptedTaxonKey"] ?? 0));
        if ($oKey !== $key) continue;
        if (array_intersect(GBIF_BAD_ISSUES, $o["issues"] ?? [])) continue;
        $gbifID = (string) ($o["key"] ?? "");
        if (!ctype_digit($gbifID)) continue;
        foreach ($o["media"] ?? [] as $m) {
            if (($m["type"] ?? "") !== "StillImage" || !in_array($m["format"] ?? "", GBIF_MIMES, true)) continue;
            $lic = gbifLicense($m["license"] ?? null);
            if ($lic === null) continue;
            $author = photoPlainText($m["creator"] ?? "") ?: photoPlainText($m["rightsHolder"] ?? "");
            if ($author === "") continue;
            $id = (string) ($m["identifier"] ?? "");
            if (!preg_match('#^https?://#i', $id)) continue;
            $out[] = ["gbifID" => $gbifID, "md5" => md5($id), "identifier" => $id, "license" => $lic[0], "license_version" => $lic[1],
                      "attribution" => $author, "accepted_name" => inatStripAuthor((string) ($o["acceptedScientificName"] ?? ""))];
            break;
        }
    }
    return $out;
}

/** Miniatura de 500 px de ancho de la caché de imágenes de GBIF (techdocs.gbif.org › Occurrence image API). */
function gbifImageUrl(string $gbifID, string $md5): string {
    return GBIF_API . "/image/cache/500x/occurrence/{$gbifID}/media/{$md5}";
}

/**
 * Busca la foto de GBIF de una especie: {name, rank, kingdom}. → decisión común de las fuentes
 * (status ok|review|none, reason y, con foto, los campos de species_photos y "file").
 * Error de red tras los reintentos (en el match, la búsqueda o TODAS las descargas) → PhotoSourceException.
 */
function gbifFind(array $species): array {
    $match = gbifMatch($species["name"], $species["kingdom"] ?? null);
    $taxon = gbifMatchDecide($species, $match);
    if (isset($taxon["status"])) return $taxon;

    $tried = [];
    $netError = null;
    $reasons = [];
    foreach (["ES", null] as $country) {
        $candidates = array_filter(gbifCandidates(gbifOccurrences($taxon["key"], $country), $taxon["key"], $species["rank"]),
            fn($c) => !isset($tried["{$c["gbifID"]}/{$c["md5"]}"]));
        foreach ($candidates as $c) {
            if (count($tried) >= GBIF_MAX_DOWNLOADS) break 2;
            $tried["{$c["gbifID"]}/{$c["md5"]}"] = true;
            try {
                $dl = photoDownload("gbif", gbifImageUrl($c["gbifID"], $c["md5"]), GBIF_MIMES, GBIF_IMAGE_TIMEOUT_S);
            } catch (PhotoSourceException $e) {
                $netError = $e;
                continue;
            }
            if (isset($dl["reason"])) { $reasons[] = $dl["reason"]; continue; }
            $synonym = $taxon["match_type"] === "synonym";
            return [
                "status"         => $synonym ? "review" : "ok",
                "reason"         => $synonym ? "sinónimo: «{$species["name"]}» → " . ($taxon["matched_name"] ?: $c["accepted_name"]) : "coincidencia exacta",
                "matched_name"   => mb_substr($taxon["matched_name"] ?: $c["accepted_name"] ?: $species["name"], 0, 255),
                "match_type"     => $taxon["match_type"],
                "source_ref"     => (string) $taxon["key"],
                "photo_ref"      => "{$c["gbifID"]}/{$c["md5"]}",
                "photo_page_url" => GBIF_OCCURRENCE_PAGE . $c["gbifID"],
                "attribution"    => $c["attribution"],
                "license"        => $c["license"],
                "license_version"=> $c["license_version"],
                "file"           => $dl["file"],
            ];
        }
        if ($tried) break;   // había candidatas en España: no se mira el resto del mundo
    }
    if ($netError && !$reasons) throw new PhotoSourceException("GBIF: descarga de la foto: " . $netError->getMessage());
    if (!$tried) return ["status" => "none", "reason" => "GBIF: sin fotos con licencia admitida y autor"];
    return ["status" => "none", "reason" => "GBIF: ninguna foto descargable (" . implode("; ", array_unique($reasons)) . ")"];
}
