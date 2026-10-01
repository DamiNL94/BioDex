<?php
// ================================================================================================
// photo-files.php — lo común a las fuentes de fotos de respaldo de GBIF y Wikimedia Commons (F11d).
// ------------------------------------------------------------------------------------------------
//   · photoHttpGet(): cliente HTTP con 1 petición/s POR FUENTE, timeout corto y reintentos con espera
//     creciente ante 429, 5xx, error de red o «maxlag» de Wikimedia. Si sigue fallando, lanza
//     PhotoSourceException y la especie se queda pending (no se salta a la fuente siguiente).
//   · photoDownload() + photoValidateBytes(): la miniatura elegida se descarga UNA vez y se guarda tal
//     cual (sin reescalar ni recomprimir) en species_photo_files. El tipo se comprueba por contenido
//     (finfo), no por la cabecera; tope de PHOTO_MAX_BYTES y dimensiones razonables.
//   · Etiquetas de licencia y nombres de las fuentes para el crédito.
// Reglas: docs/fotos-respaldo.md.
// ================================================================================================

require_once __DIR__ . "/../Config/env.php";

const PHOTO_MIN_INTERVAL_S = 1.0;            // 1 petición por segundo y fuente como mucho
const PHOTO_RETRY_WAITS_S = [2, 5, 15];      // espera antes de cada reintento
const PHOTO_MAX_RETRY_AFTER_S = 30;          // Retry-After de Wikimedia, con tope
const PHOTO_MAX_BYTES = 1_500_000;
const PHOTO_MIN_SIDE = 100;                  // px; más pequeña no sirve de miniatura
const PHOTO_MAX_SIDE = 4000;
/** Tipo → extensión de /img/respaldo/<taxonID>/<hash>.<ext>. */
const PHOTO_MIME_EXT = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
const PHOTO_BACKUP_URL = "/img/respaldo/";
const PHOTO_SOURCE_NAMES = ["inaturalist" => "iNaturalist", "gbif" => "GBIF", "commons" => "Wikimedia Commons"];

/** Fallo de red o del servicio tras los reintentos: la especie se queda pending. */
class PhotoSourceException extends RuntimeException {}

/**
 * «BioDex/1.4.0 (https://…; contacto@…)»: la política de User-Agent de Wikimedia exige un contacto
 * (CONTACT_EMAIL); sin él, solo la URL. También para GBIF.
 */
function photoUserAgent(): string {
    $v = @file_get_contents(__DIR__ . "/../../VERSION");
    $url = biodex_env(["APP_URL"]) ?? "http://localhost:8090";
    $email = biodex_env(["CONTACT_EMAIL"]);
    return "BioDex/" . ($v === false ? "dev" : trim($v)) . " ({$url}" . ($email ? "; {$email}" : "") . ")";
}

/**
 * GET con 1 petición/s por fuente ($source) y reintentos. → ["status" => int, "body" => string].
 *   timeout   segundos (por defecto 10)
 *   maxBytes  corta la descarga si pasa de ahí → ["status" => 0, "body" => "", "tooBig" => true]
 *   maxlag    true: una respuesta de error «maxlag» de la API de Wikimedia se reintenta (Retry-After)
 * 429, 5xx, red o maxlag → reintentos a los PHOTO_RETRY_WAITS_S s; si siguen fallando, PhotoSourceException.
 * Otros códigos (404, 400…) se devuelven: decide quien llama. $sleep es inyectable para las pruebas.
 */
function photoHttpGet(string $source, string $url, array $opt = [], ?callable $sleep = null): array {
    static $lastCall = [];
    $sleep ??= fn(float $s) => usleep((int) ($s * 1e6));
    $last = "";
    $retryAfter = 0;
    foreach ([0, ...PHOTO_RETRY_WAITS_S] as $retryWait) {
        if ($retryWait) $sleep(max($retryWait, min($retryAfter, PHOTO_MAX_RETRY_AFTER_S)));
        $wait = PHOTO_MIN_INTERVAL_S - (microtime(true) - ($lastCall[$source] ?? 0.0));
        if ($wait > 0) $sleep($wait);

        $headers = [];
        $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $opt["timeout"] ?? 10,
            CURLOPT_CONNECTTIMEOUT => min(15, $opt["timeout"] ?? 10),   // api.gbif.org tarda a veces > 8 s en aceptar
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => photoUserAgent(),
            CURLOPT_HTTPHEADER     => ["Accept: " . ($opt["accept"] ?? "application/json")],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                if (preg_match('/^([\w-]+):\s*(.*)$/', trim($line), $h)) $headers[strtolower($h[1])] = $h[2];
                return strlen($line);
            },
        ]);
        if (isset($opt["maxBytes"])) {
            curl_setopt_array($ch, [
                CURLOPT_NOPROGRESS       => false,
                CURLOPT_XFERINFOFUNCTION => function ($ch, $dlTotal, $dlNow) use ($opt, &$tooBig) {
                    if ($dlTotal > $opt["maxBytes"] || $dlNow > $opt["maxBytes"]) { $tooBig = true; return 1; }
                    return 0;
                },
            ]);
        }
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $lastCall[$source] = microtime(true);

        if ($tooBig) return ["status" => 0, "body" => "", "tooBig" => true];
        if ($body === false) { $last = "red: {$error}"; continue; }
        $retryAfter = (int) ($headers["retry-after"] ?? 0);
        if ($status === 429 || $status >= 500) { $last = "HTTP {$status}"; continue; }
        if (!empty($opt["maxlag"]) && ($headers["mediawiki-api-error"] ?? "") === "maxlag") {
            $last = "maxlag (retraso de réplica " . ($headers["x-database-lag"] ?? "?") . " s)";
            continue;
        }
        return ["status" => $status, "body" => $body];
    }
    throw new PhotoSourceException("{$last} tras " . count(PHOTO_RETRY_WAITS_S) . " reintentos");
}

/** GET de una API JSON → array. Código distinto de 200 o cuerpo que no es JSON → PhotoSourceException. */
function photoGetJson(string $source, string $url, array $opt = []): array {
    $r = photoHttpGet($source, $url, $opt);
    $data = json_decode($r["body"], true);
    if ($r["status"] !== 200 || !is_array($data)) throw new PhotoSourceException("HTTP {$r["status"]} en {$url}");
    return $data;
}

/**
 * Comprueba unos bytes de imagen POR CONTENIDO. → ["mime", "width", "height", "sha256"] o ["reason"].
 * $allowed: tipos admitidos por la fuente (claves de PHOTO_MIME_EXT).
 */
function photoValidateBytes(string $bytes, array $allowed): array {
    $n = strlen($bytes);
    if ($n === 0) return ["reason" => "descarga vacía"];
    if ($n > PHOTO_MAX_BYTES) return ["reason" => "pesa " . round($n / 1e6, 1) . " MB (máx. 1,5)"];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if (!in_array($mime, $allowed, true)) return ["reason" => "tipo no admitido: {$mime}"];
    $size = @getimagesizefromstring($bytes);
    if (!$size || ($size["mime"] ?? "") !== $mime) return ["reason" => "imagen no válida"];
    [$w, $h] = $size;
    if (min($w, $h) < PHOTO_MIN_SIDE || max($w, $h) > PHOTO_MAX_SIDE) return ["reason" => "dimensiones fuera de rango: {$w}×{$h}"];
    return ["mime" => $mime, "width" => $w, "height" => $h, "sha256" => hash("sha256", $bytes)];
}

/**
 * Descarga y valida una miniatura. → ["file" => ["bytes", "mime", "width", "height", "sha256"]] o ["reason"].
 * Error de red tras los reintentos → PhotoSourceException (quien llama decide si probar otra candidata).
 */
function photoDownload(string $source, string $url, array $allowed, int $timeout = 30): array {
    $r = photoHttpGet($source, $url, ["timeout" => $timeout, "accept" => "image/*", "maxBytes" => PHOTO_MAX_BYTES]);
    if (!empty($r["tooBig"])) return ["reason" => "pesa más de 1,5 MB"];
    if ($r["status"] !== 200) return ["reason" => "HTTP {$r["status"]} al descargar"];
    $v = photoValidateBytes($r["body"], $allowed);
    if (isset($v["reason"])) return $v;
    return ["file" => ["bytes" => $r["body"]] + $v];
}

/** URL de la copia local: /img/respaldo/<taxonID>/<16 primeros hex del sha256>.<ext>. */
function photoBackupUrl(string $taxonID, string $sha256, string $mime): string {
    return PHOTO_BACKUP_URL . rawurlencode($taxonID) . "/" . substr($sha256, 0, 16) . "." . PHOTO_MIME_EXT[$mime];
}

/** Etiqueta completa de la licencia: «CC BY-SA 4.0», «CC BY-SA 3.0 DE», «CC BY-NC», «CC0», «Dominio público». */
function photoLicenseLabel(?string $license, ?string $version = null): ?string {
    $base = match ($license) {
        "cc0" => "CC0", "pd" => "Dominio público", "cc-by" => "CC BY", "cc-by-nc" => "CC BY-NC", "cc-by-sa" => "CC BY-SA",
        default => null,
    };
    if ($base === null || in_array($license, ["cc0", "pd"], true) || !$version) return $base;
    return $base . " " . strtoupper(str_replace("-", " ", $version));
}

/** Texto plano para un crédito: sin HTML, entidades decodificadas, espacios normalizados y como mucho $max caracteres. */
function photoPlainText(?string $s, int $max = 200): string {
    $s = preg_replace('#<br\s*/?>#i', " ", (string) $s);
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, "UTF-8");
    $s = trim(preg_replace('/\s+/u', " ", $s));
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . "…" : $s;
}
