<?php
// ================================================================================================
// commons.php — foto de respaldo de Wikimedia Commons, vía la imagen (P18) del taxón en Wikidata (F11d).
// ------------------------------------------------------------------------------------------------
// Última fuente antes de la silueta. Solo coincidencia exacta (aquí no hay review):
//   · commonsTaxonDecide() (pura): UN solo elemento de Wikidata con P225 (nombre del taxón) igual al de
//     EIDOS, con P105 (rango) el de EIDOS, que sea un taxón (P31 = Q16521) y no un sinónimo, y con P18.
//   · commonsPhotoDecide() (pura): licencia de extmetadata.License (cc0, pd, cc-by-X, cc-by-sa-X; nada más:
//     Commons no admite NC ni ND, y GFDL sola no vale), autor de extmetadata.Artist sin HTML, tipo
//     jpeg/png/webp y la thumburl que da la API (nunca una URL construida a mano: desde 2026 Wikimedia
//     sirve las miniaturas en thumb.wikimedia.org solo a anchos fijos y no son una API pública).
// commonsFind() junta las piezas con la red. Cliente: Lib/photo-files.php (1 petición/s, maxlag=5 con
// reintentos, User-Agent con contacto: CONTACT_EMAIL).
// Reglas y ejemplos: docs/fotos-respaldo.md; casos de prueba: docs/commons-samples/.
// ================================================================================================

require_once __DIR__ . "/photo-files.php";
require_once __DIR__ . "/inat.php";   // inatNormName()

const WIKIDATA_API = "https://www.wikidata.org/w/api.php";
const COMMONS_API = "https://commons.wikimedia.org/w/api.php";
const COMMONS_LICENSES = ["cc0", "pd", "cc-by", "cc-by-sa"];
const COMMONS_MIMES = ["image/jpeg", "image/png", "image/webp"];
/** Rango de EIDOS → elemento de Wikidata para P105. */
const WIKIDATA_RANKS = ["Species" => "Q7432", "Subspecies" => "Q68947", "Variety" => "Q767728"];
const WIKIDATA_TAXON = "Q16521";
/** P31 que delatan un elemento de nombre, no de taxón: sinónimo, combinación no disponible. */
const WIKIDATA_NOT_TAXON = ["Q1040689", "Q17487588"];
const COMMONS_THUMB_URL = '#^https://(thumb|upload)\.wikimedia\.org/[^\s]+$#';
const COMMONS_PAGE_URL = '#^https://commons\.wikimedia\.org/wiki/File:[^\s]+$#';
const COMMONS_TIMEOUT_S = 15;
const WIKIDATA_ITEM_PAGE = "https://www.wikidata.org/wiki/";

/**
 * Nombre tal como lo escribe Wikidata en P225: los nombres zoológicos van sin marca de rango («Turdus merula
 * cabrerae»); los botánicos, con ella («Acer opalus subsp. granatense», como EIDOS).
 */
function commonsTaxonName(array $species): string {
    $name = trim(preg_replace('/\s+/u', " ", (string) ($species["name"] ?? "")));
    return ($species["kingdom"] ?? "") === "Animalia" ? inatQueryName($name) : $name;
}

function commonsApi(string $base, array $params): array {
    $params += ["format" => "json", "formatversion" => 1, "maxlag" => 5];
    return photoGetJson("commons", $base . "?" . http_build_query($params, "", "&", PHP_QUERY_RFC3986),
        ["timeout" => COMMONS_TIMEOUT_S, "maxlag" => true]);
}

/** Elementos de Wikidata con P225 = $name (búsqueda haswbstatement, exacta). */
function commonsSearchTaxon(string $name): array {
    return commonsApi(WIKIDATA_API, ["action" => "query", "list" => "search", "srsearch" => "haswbstatement:\"P225={$name}\"", "srlimit" => 10]);
}

/** Declaraciones (claims) de esos elementos. */
function commonsEntities(array $qids): array {
    return commonsApi(WIKIDATA_API, ["action" => "wbgetentities", "ids" => implode("|", $qids), "props" => "claims"]);
}

/** imageinfo de un fichero de Commons, con la miniatura de 500 px que calcula la API. */
function commonsImageInfo(string $file): array {
    return commonsApi(COMMONS_API, ["action" => "query", "titles" => "File:{$file}", "prop" => "imageinfo",
        "iiprop" => "url|mime|size|extmetadata", "iiurlwidth" => 500]);
}

/** Valores (no obsoletos; los preferentes primero) de una propiedad de un elemento. */
function wikidataValues(array $entity, string $prop): array {
    $claims = array_filter($entity["claims"][$prop] ?? [], fn($c) => ($c["rank"] ?? "") !== "deprecated");
    usort($claims, fn($a, $b) => (($b["rank"] ?? "") === "preferred") <=> (($a["rank"] ?? "") === "preferred"));
    $out = [];
    foreach ($claims as $c) {
        $v = $c["mainsnak"]["datavalue"]["value"] ?? null;
        if (is_array($v) && isset($v["id"])) $v = $v["id"];
        if (is_string($v) && $v !== "") $out[] = $v;
    }
    return $out;
}

/** QIDs de la respuesta de commonsSearchTaxon(). */
function commonsSearchQids(array $search): array {
    return array_values(array_filter(array_map(fn($r) => (string) ($r["title"] ?? ""), $search["query"]["search"] ?? []),
        fn($q) => (bool) preg_match('/^Q[0-9]+$/', $q)));
}

/**
 * ¿Hay un elemento de Wikidata que sea la especie de EIDOS y tenga imagen? $species = {name, rank, kingdom?}.
 * → ["status" => "none", "reason"] o ["qid", "matched_name", "file"].
 */
function commonsTaxonDecide(array $species, array $search, array $entities): array {
    $none = fn(string $reason) => ["status" => "none", "reason" => "Commons: {$reason}"];
    $rankQ = WIKIDATA_RANKS[$species["rank"] ?? ""] ?? null;
    if ($rankQ === null) return $none("rango no admitido: " . ($species["rank"] ?? "—"));
    $target = inatNormName(commonsTaxonName($species));
    $hits = [];
    foreach (commonsSearchQids($search) as $qid) {
        $e = $entities["entities"][$qid] ?? null;
        if (!is_array($e) || isset($e["missing"]) || isset($e["redirects"])) continue;
        foreach (wikidataValues($e, "P225") as $name) {
            if (inatNormName($name) === $target) { $hits[$qid] = $e + ["_name" => $name]; break; }
        }
    }
    if (!$hits) return $none("sin elemento de Wikidata con ese nombre (P225)");
    if (count($hits) > 1) return $none("ambiguo: " . count($hits) . " elementos de Wikidata con el mismo nombre (" . implode(", ", array_keys($hits)) . ")");
    $qid = array_key_first($hits);
    $e = $hits[$qid];
    $ranks = wikidataValues($e, "P105");
    if ($ranks !== [$rankQ]) return $none("{$qid}: rango " . ($ranks ? implode("/", $ranks) : "sin indicar") . " frente a {$rankQ}");
    $types = wikidataValues($e, "P31");
    if (!in_array(WIKIDATA_TAXON, $types, true) || array_intersect(WIKIDATA_NOT_TAXON, $types)) {
        return $none("{$qid}: no es un taxón (P31 " . implode("/", $types) . ")");
    }
    $file = wikidataValues($e, "P18")[0] ?? null;
    if ($file === null) return $none("{$qid}: sin imagen (P18)");
    return ["qid" => $qid, "matched_name" => $e["_name"], "file" => $file];
}

/**
 * Licencia de extmetadata.License → [código, versión|null], o null si no se admite.
 * «cc-by-sa-4.0» → [cc-by-sa, 4.0]; «cc-by-sa-3.0-de» → [cc-by-sa, 3.0-de]; «cc0»; «pd». GFDL, NC, ND… → null.
 */
function commonsLicense(?string $s): ?array {
    $s = strtolower(trim((string) $s));
    if ($s === "cc0" || $s === "pd") return [$s, null];
    if (preg_match('/^(cc-by(?:-sa)?)-([0-9]\.[0-9])(-[a-z]{2})?$/', $s, $m)) return [$m[1], $m[2] . ($m[3] ?? "")];
    return null;
}

/**
 * Decide con la respuesta de commonsImageInfo(). → ["status" => "none", "reason"] o
 * ["license", "license_version", "attribution", "thumburl", "page_url", "file"].
 */
function commonsPhotoDecide(array $response): array {
    $none = fn(string $reason) => ["status" => "none", "reason" => "Commons: {$reason}"];
    $page = array_values($response["query"]["pages"] ?? [])[0] ?? null;
    if (!is_array($page) || isset($page["missing"]) || isset($page["invalid"])) return $none("el fichero no existe");
    $info = $page["imageinfo"][0] ?? null;
    if (!is_array($info)) return $none("sin imageinfo");
    $meta = $info["extmetadata"] ?? [];
    $licenseRaw = $meta["License"]["value"] ?? null;
    $lic = commonsLicense($licenseRaw);
    if ($lic === null) return $none("licencia no admitida: " . ($licenseRaw ?: ($meta["LicenseShortName"]["value"] ?? "sin licencia")));
    if (!in_array($info["mime"] ?? "", COMMONS_MIMES, true)) return $none("tipo no admitido: " . ($info["mime"] ?? "—"));
    $author = photoPlainText($meta["Artist"]["value"] ?? "");
    if ($author === "" && !in_array($lic[0], ["cc0", "pd"], true)) return $none("foto sin autor");
    $thumb = (string) ($info["thumburl"] ?? "");
    if (!preg_match(COMMONS_THUMB_URL, $thumb)) return $none("sin miniatura de Wikimedia");
    $pageUrl = (string) ($info["descriptionurl"] ?? "");
    if (!preg_match(COMMONS_PAGE_URL, $pageUrl)) return $none("sin página del fichero");
    return [
        "license"         => $lic[0],
        "license_version" => $lic[1],
        "attribution"     => $author !== "" ? $author : "autor no indicado",
        "thumburl"        => $thumb,
        "page_url"        => $pageUrl,
        "file"            => preg_replace('/^File:/', "", (string) ($page["title"] ?? "")),
    ];
}

/**
 * Busca la foto de Commons de una especie: {name, rank, kingdom}. → decisión común de las fuentes (ok o none;
 * con foto, los campos de species_photos y "file"). Error de red tras los reintentos → PhotoSourceException.
 */
function commonsFind(array $species): array {
    $search = commonsSearchTaxon(commonsTaxonName($species));
    $qids = array_slice(commonsSearchQids($search), 0, 10);
    if (!$qids) return ["status" => "none", "reason" => "Commons: sin elemento de Wikidata con ese nombre (P225)"];
    $taxon = commonsTaxonDecide($species, $search, commonsEntities($qids));
    if (isset($taxon["status"])) return $taxon;
    $photo = commonsPhotoDecide(commonsImageInfo($taxon["file"]));
    if (isset($photo["status"])) return $photo;
    $dl = photoDownload("commons", $photo["thumburl"], COMMONS_MIMES, COMMONS_TIMEOUT_S);
    if (isset($dl["reason"])) return ["status" => "none", "reason" => "Commons: {$dl["reason"]}"];
    return [
        "status"          => "ok",
        "reason"          => "coincidencia exacta ({$taxon["qid"]})",
        "matched_name"    => mb_substr($taxon["matched_name"], 0, 255),
        "match_type"      => "exact",
        "source_ref"      => $taxon["qid"],
        "photo_ref"       => mb_substr($photo["file"], 0, 255),
        "photo_page_url"  => mb_substr($photo["page_url"], 0, 512),
        "attribution"     => $photo["attribution"],
        "license"         => $photo["license"],
        "license_version" => $photo["license_version"],
        "file"            => $dl["file"],
    ];
}
