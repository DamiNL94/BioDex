<?php
// ================================================================================================
// inat.php — foto de respaldo de iNaturalist para especies de EIDOS sin foto de la Fototeca (F11b).
// ------------------------------------------------------------------------------------------------
// Dos piezas separadas:
//   · inatSearch(): cliente de GET /v1/taxa. Solo desde el backend (como EIDOS), 1 petición/s como
//     mucho, timeout corto y reintentos con espera creciente ante 429/5xx/red. Si sigue fallando,
//     lanza InatException y la especie se queda 'pending'.
//   · inatDecide(): función PURA (sin red ni BD) que decide con el JSON. Regla de oro: mejor sin foto
//     que con la de otra especie. Solo dos salidas con foto:
//       exact   → ok      nombre igual (sin mayúsculas, espacios ni marcas de rango) y rango compatible
//       synonym → review  nombre distinto, pero matched_term = el nombre de EIDOS o un sinónimo suyo
//     Lo demás (aproximado, ambiguo, otro rango, otro reino) → none. También none si la foto por defecto
//     falta, tiene una licencia fuera de INAT_LICENSES (incluida null = «all rights reserved»), está
//     marcada (flags) o no está en el bucket de fotos abiertas.
// Reglas y ejemplos: docs/fotos-respaldo.md; casos de prueba: docs/inat-samples/.
// ================================================================================================

require_once __DIR__ . "/../Config/env.php";

const INAT_TAXA_URL = "https://api.inaturalist.org/v1/taxa";
const INAT_LICENSES = ["cc0", "cc-by", "cc-by-nc"];
// Bucket de iNat con las fotos de licencia abierta. El proxy /img/inat/ de nginx solo sirve de aquí.
const INAT_PHOTO_URL = '#^https://inaturalist-open-data\.s3\.amazonaws\.com/photos/([0-9]{1,12})/medium\.(jpe?g|png)$#';
const INAT_MIN_INTERVAL_S = 1.0;          // 1 petición por segundo como mucho
const INAT_TIMEOUT_S = 8;
const INAT_RETRY_WAITS_S = [2, 5, 15];    // espera antes de cada reintento (429, 5xx o error de red)
const INAT_PHOTO_PAGE = "https://www.inaturalist.org/photos/";
/** Rango de EIDOS → rango de iNat. Solo se busca foto para estos. */
const INAT_RANKS = ["Species" => "species", "Subspecies" => "subspecies", "Variety" => "variety", "Form" => "form"];
/** iconic_taxon_name de iNat → reino. Si los dos reinos se conocen y no coinciden, no es la misma especie. */
const INAT_ICONIC_KINGDOM = [
    "Plantae" => "Plantae", "Fungi" => "Fungi", "Chromista" => "Chromista", "Protozoa" => "Protozoa",
    "Animalia" => "Animalia", "Aves" => "Animalia", "Mammalia" => "Animalia", "Reptilia" => "Animalia",
    "Amphibia" => "Animalia", "Actinopterygii" => "Animalia", "Mollusca" => "Animalia",
    "Arachnida" => "Animalia", "Insecta" => "Animalia",
];

class InatException extends RuntimeException {}

/** «BioDex/1.2.0 (+https://…)»: iNat pide identificarse. */
function inatUserAgent(): string {
    $v = @file_get_contents(__DIR__ . "/../../VERSION");
    $url = biodex_env(["APP_URL"]) ?? "http://localhost:8090";
    return "BioDex/" . ($v === false ? "dev" : trim($v)) . " (+{$url})";
}

/**
 * Nombre para la consulta: sin marcas de rango. iNat escribe los trinomios sin ellas («Aquila chrysaetos
 * homeyeri») y con «subsp.» no encuentra nada (comprobado el 2026-10-01: 0 resultados frente a 1).
 */
function inatQueryName(string $name): string {
    $s = trim(preg_replace('/\s+/u', " ", $name));
    return trim(preg_replace('/ (?:subsp|ssp|var|subvar|f|forma|fo)\. /u', " ", " {$s} "));
}

/**
 * GET /v1/taxa?q=<nombre sin marcas de rango>&is_active=true&per_page=10 → JSON decodificado. Respeta INAT_MIN_INTERVAL_S
 * entre peticiones (también entre reintentos). Lanza InatException si tras los reintentos no hay respuesta.
 * $sleep es inyectable para las pruebas.
 */
function inatSearch(string $name, ?callable $sleep = null): array {
    static $lastCall = 0.0;
    $sleep ??= fn(float $s) => usleep((int) ($s * 1e6));
    $url = INAT_TAXA_URL . "?" . http_build_query(["q" => inatQueryName($name), "is_active" => "true", "per_page" => 10], "", "&", PHP_QUERY_RFC3986);
    $last = "";
    foreach ([0, ...INAT_RETRY_WAITS_S] as $retryWait) {
        if ($retryWait) $sleep($retryWait);
        $wait = INAT_MIN_INTERVAL_S - (microtime(true) - $lastCall);
        if ($wait > 0) $sleep($wait);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => INAT_TIMEOUT_S,
            CURLOPT_CONNECTTIMEOUT => INAT_TIMEOUT_S,
            CURLOPT_HTTPHEADER     => ["Accept: application/json"],
            CURLOPT_USERAGENT      => inatUserAgent(),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $lastCall = microtime(true);

        if ($body === false) { $last = "red: {$error}"; continue; }
        if ($status === 429 || $status >= 500) { $last = "HTTP {$status}"; continue; }
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data) || !isset($data["results"]) || !is_array($data["results"])) {
            // 4xx distinto de 429 o cuerpo raro: reintentar no lo arregla.
            throw new InatException("iNaturalist «{$name}»: HTTP {$status}");
        }
        return $data;
    }
    throw new InatException("iNaturalist «{$name}»: {$last} tras " . count(INAT_RETRY_WAITS_S) . " reintentos");
}

/** Nombre para comparar: minúsculas, espacios normalizados y sin marcas de rango («subsp.», «var.», «f.»). */
function inatNormName(?string $name): string {
    $s = preg_replace('/\s+/u', " ", mb_strtolower(trim((string) $name)));
    $s = preg_replace('/ (subsp|ssp|var|subvar|f|forma|fo)\.? /u', " ", " {$s} ");
    return trim(preg_replace('/\s+/u', " ", $s));
}

/**
 * Sinónimo de EIDOS (con autor) → nombre sin autor: el género y luego las palabras en minúscula (epítetos
 * y marcas de rango) hasta la primera que no lo sea. «Atropa belladonna subsp. baetica (Willk.) Font Quer»
 * → «Atropa belladonna subsp. baetica»; «Sempervivum nevadense Wale» → «Sempervivum nevadense».
 */
function inatStripAuthor(string $name): string {
    $words = preg_split('/\s+/u', trim($name));
    $out = [array_shift($words)];
    foreach ($words as $w) {
        if (!preg_match('/^[\p{Ll}×][\p{Ll}\-]*\.?$/u', $w)) break;
        $out[] = $w;
    }
    return implode(" ", $out);
}

/**
 * Decide la foto de una especie con la respuesta de inatSearch().
 *   $species = {name (sin autor), rank (de EIDOS), kingdom?, synonyms? (de EIDOS, con autor)}
 * → {status ok|review|none, reason, y con ok/review: inat_taxon_id, matched_name, match_type, photo_id,
 *    photo_ext, attribution, license}
 */
function inatDecide(array $species, array $response): array {
    $none = fn(string $reason) => ["status" => "none", "reason" => $reason];
    $rank = INAT_RANKS[$species["rank"] ?? ""] ?? null;
    if ($rank === null) return $none("rango no admitido: " . ($species["rank"] ?? "—"));
    $target = inatNormName($species["name"] ?? "");
    if ($target === "") return $none("sin nombre");

    $names = [$target => true];
    foreach ($species["synonyms"] ?? [] as $syn) {
        if (is_string($syn) && trim($syn) !== "") $names[inatNormName(inatStripAuthor($syn))] = true;
    }
    $kingdom = $species["kingdom"] ?? null;

    $exact = [];
    $synonym = [];
    foreach ($response["results"] ?? [] as $r) {
        if (!is_array($r) || ($r["is_active"] ?? false) !== true) continue;
        if (mb_strtolower((string) ($r["rank"] ?? "")) !== $rank) continue;
        $iconic = INAT_ICONIC_KINGDOM[$r["iconic_taxon_name"] ?? ""] ?? null;
        if ($kingdom && $iconic && $iconic !== $kingdom) continue;
        $id = (int) ($r["id"] ?? 0);
        if (inatNormName($r["name"] ?? "") === $target) $exact[$id] = $r;
        elseif (isset($names[inatNormName($r["matched_term"] ?? "")])) $synonym[$id] = $r;
    }

    if (count($exact) > 1) return $none("ambiguo: " . count($exact) . " taxones con el mismo nombre");
    if (count($exact) === 1) { $hit = reset($exact); $type = "exact"; }
    elseif (count($synonym) === 1) { $hit = reset($synonym); $type = "synonym"; }
    elseif (count($synonym) > 1) return $none("ambiguo: " . count($synonym) . " taxones por sinónimo");
    else return $none("sin coincidencia exacta ni por sinónimo");

    $photo = $hit["default_photo"] ?? null;
    if (!is_array($photo)) return $none("el taxón no tiene foto por defecto");
    $license = $photo["license_code"] ?? null;
    if (!in_array($license, INAT_LICENSES, true)) return $none("licencia no admitida: " . ($license ?? "todos los derechos reservados"));
    if (!empty($photo["flags"])) return $none("foto marcada en iNaturalist");
    if (!preg_match(INAT_PHOTO_URL, (string) ($photo["medium_url"] ?? ""), $m)) return $none("foto fuera del bucket de licencias abiertas");
    $attribution = trim((string) ($photo["attribution"] ?? ""));
    if ($attribution === "") return $none("foto sin atribución");

    return [
        "status"        => $type === "exact" ? "ok" : "review",
        "reason"        => $type === "exact" ? "coincidencia exacta" : "sinónimo: «{$hit["matched_term"]}» → {$hit["name"]}",
        "inat_taxon_id" => (int) $hit["id"],
        "matched_name"  => (string) $hit["name"],
        "match_type"    => $type,
        "photo_id"      => (int) $m[1],
        "photo_ext"     => $m[2],
        "attribution"   => mb_substr($attribution, 0, 512),
        "license"       => $license,
    ];
}
