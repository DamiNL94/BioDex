<?php
// ================================================================================================
// Pruebas de F7 (mapa): docker compose exec php php bin/test-f7.php
// Proxy /tiles/ de nginx hacia el WMTS del IGN (necesita salida a Internet la primera vez; después
// sirve la caché) y los niveles de sensibilidad con gridDeg (el mapa dibuja la celda, no un punto).
// Crea un usuario f7-*@biodex.test y lo borra al terminar.
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/test-lib.php";

/** Petición cruda (las teselas no son JSON): [código, cuerpo, cabeceras en minúsculas]. */
function raw(string $method, string $path): array {
    global $BASE;
    $headers = [];
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_PATH_AS_IS     => true,   // que curl no normalice «..» ni %-codificados
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
            if (preg_match("/^([\w-]+):\s*(.*)$/", trim($line), $h)) $headers[strtolower($h[1])] = $h[2];
            return strlen($line);
        },
    ]);
    $body = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body, $headers];
}

/** Tesela GoogleMapsCompatible que contiene (lat, lon) en el zoom z. */
function tileAt(float $lat, float $lon, int $z): array {
    $n = 2 ** $z;
    $r = deg2rad($lat);
    return [(int) floor(($lon + 180) / 360 * $n), (int) floor((1 - log(tan($r) + 1 / cos($r)) / M_PI) / 2 * $n)];
}

const ALHAMBRA = [37.1761, -3.5881];

// ------------------------------------------------------------------------------------------------
section("Teselas válidas de cada capa");
foreach (["base" => 16, "pnoa" => 16, "mtn" => 15] as $key => $z) {
    [$x, $y] = tileAt(ALHAMBRA[0], ALHAMBRA[1], $z);
    $path = "/tiles/{$key}/{$z}/{$x}/{$y}.jpg";
    [$c, $body, $h] = raw("GET", $path);
    check("{$key} z{$z} → 200 con image/*", $c === 200 && str_starts_with($h["content-type"] ?? "", "image/"),
        "HTTP {$c} " . ($h["content-type"] ?? "sin tipo"));
    check("…es una imagen de verdad (JPEG)", substr($body, 0, 3) === "\xFF\xD8\xFF", bin2hex(substr($body, 0, 8)));
    check("…con nuestras cabeceras: caché 30 días, CSP y sin cookies ni rastro del IGN",
        str_contains($h["cache-control"] ?? "", "max-age=2592000") && isset($h["content-security-policy"])
        && !isset($h["set-cookie"]) && !isset($h["x-amz-cf-id"]) && !isset($h["geowebcache-gridset"]), json_encode($h));
    [$c2, , $h2] = raw("GET", $path);
    check("…segunda petición → X-Cache-Status: HIT", $c2 === 200 && ($h2["x-cache-status"] ?? "") === "HIT",
        "HTTP {$c2} " . ($h2["x-cache-status"] ?? "sin cabecera"));
}

// ------------------------------------------------------------------------------------------------
section("Zoom fuera del máximo de cada capa → 404 (sin llamar al IGN)");
foreach (["base" => 21, "pnoa" => 20, "mtn" => 18] as $key => $z) {
    [$x, $y] = tileAt(ALHAMBRA[0], ALHAMBRA[1], $z);
    [$c] = raw("GET", "/tiles/{$key}/{$z}/{$x}/{$y}.jpg");
    check("{$key} z{$z} → 404", $c === 404, "HTTP {$c}");
}
foreach (["base" => 20, "pnoa" => 19, "mtn" => 17] as $key => $z) {
    [$x, $y] = tileAt(ALHAMBRA[0], ALHAMBRA[1], $z);
    [$c, , $h] = raw("GET", "/tiles/{$key}/{$z}/{$x}/{$y}.jpg");
    check("{$key} z{$z} (su máximo) → 200", $c === 200 && str_starts_with($h["content-type"] ?? "", "image/"), "HTTP {$c}");
}

// ------------------------------------------------------------------------------------------------
section("Peticiones que no son una tesela");
[$x, $y] = tileAt(ALHAMBRA[0], ALHAMBRA[1], 15);
foreach ([
    ["/tiles/osm/15/{$x}/{$y}.jpg", [404], "clave desconocida"],
    ["/tiles/base/15/{$x}/{$y}.png", [404], "extensión que no es la de la capa"],
    ["/tiles/base/5/3/99.jpg", [404], "fila fuera de la rejilla (el IGN contesta 400)"],
    ["/tiles/base/15/abc/{$y}.jpg", [400], "coordenada no numérica"],
    ["/tiles/base/15/-1/{$y}.jpg", [400], "coordenada negativa"],
    ["/tiles/base/150/{$x}/{$y}.jpg", [400], "zoom de 3 cifras"],
    ["/tiles/base/15/{$x}/{$y}.jpg?LAYER=otra", [400], "con parámetros"],
    ["/tiles/base/15/%31{$x}/{$y}.jpg", [400], "%-codificado"],
    ["/tiles/base/../base/15/{$x}/{$y}.jpg", [400], "con «..»"],
    ["/tiles/", [400], "solo /tiles/"],
    ["/tiles/base/15/{$x}/{$y}.jpg/extra", [400], "con ruta de más"],
] as [$path, $codes, $label]) {
    [$c, $body, $h] = raw("GET", $path);
    check("{$label} → " . implode("/", $codes), in_array($c, $codes, true) && !str_starts_with($h["content-type"] ?? "", "image/"),
        "HTTP {$c} " . ($h["content-type"] ?? ""));
}
[$c] = raw("POST", "/tiles/base/15/{$x}/{$y}.jpg");
check("POST → no se sirve", $c >= 400, "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
section("Niveles de sensibilidad con gridDeg");
$pdo = db();
$pdo->exec("DELETE FROM users WHERE email LIKE 'f7-%@biodex.test'");
$jar = [];
$email = "f7-" . bin2hex(random_bytes(4)) . "@biodex.test";
[$c] = http("POST", "/api/auth/register", ["email" => $email, "password" => "correcta-horse-battery", "name" => "F7"], $jar);
check("registro", $c === 201, "HTTP {$c}");
[$c, $r] = http("GET", "/api/species/filters", null, $jar);
$levels = [];
foreach ($r["sensitivityLevels"] ?? [] as $l) $levels[$l["level"]] = $l;
check("/species/filters → niveles 1–4", $c === 200 && count($levels) === 4, "HTTP {$c}");
check("nivel 2: gridDeg 0,1 (~10 km)", ($levels[2]["gridDeg"] ?? null) == 0.1 && ($levels[2]["approxKm"] ?? null) == 10, json_encode($levels[2] ?? null));
check("nivel 3: gridDeg 0,01 (~1 km)", ($levels[3]["gridDeg"] ?? null) == 0.01, json_encode($levels[3] ?? null));
check("niveles 1 y 4: sin rejilla", array_key_exists("gridDeg", $levels[1] ?? []) && $levels[1]["gridDeg"] === null && $levels[4]["gridDeg"] === null);
$pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f7-%@biodex.test'");
$pdo->exec("DELETE FROM users WHERE email LIKE 'f7-%@biodex.test'");

echo $failures ? "\n{$failures} fallo(s)\n" : "\nTodo OK\n";
exit($failures ? 1 : 0);
