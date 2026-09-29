<?php
// ================================================================================================
// Utilidades comunes de bin/test-*.php: check(), section() y http() contra nginx con tarro de cookies.
// TEST_BASE_URL (por defecto http://nginx, dentro de la red de Docker).
// ================================================================================================

$BASE = getenv("TEST_BASE_URL") ?: "http://nginx";
$failures = 0;

function check(string $label, bool $ok, string $detail = ""): void {
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? "OK   " : "FAIL ") . $label . ($ok || $detail === "" ? "" : "  → " . $detail) . "\n";
}

function section(string $title): void {
    echo "\n== {$title}\n";
}

/**
 * Petición HTTP con un tarro de cookies propio. Devuelve [código, cuerpo JSON decodificado, cabeceras]
 * (cabeceras con el nombre en minúsculas).
 */
function http(string $method, string $path, ?array $body, array &$jar): array {
    global $BASE;
    $headersOut = [];
    $ch = curl_init($BASE . $path);
    $headers = ["Accept: application/json"];
    if ($body !== null) $headers[] = "Content-Type: application/json";
    if ($jar) {
        $headers[] = "Cookie: " . implode("; ", array_map(fn($k, $v) => "{$k}={$v}", array_keys($jar), $jar));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,   // las rutas que consultan EIDOS tardan varios segundos
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$jar, &$headersOut) {
            if (preg_match("/^([\w-]+):\s*(.*)$/", trim($line), $h)) $headersOut[strtolower($h[1])] = $h[2];
            if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)(.*)$/i', trim($line), $m)) {
                $expired = preg_match('/expires=([^;]+)/i', $m[3], $e) && strtotime($e[1]) < time();
                if ($m[2] === "" || $m[2] === "deleted" || $expired) unset($jar[$m[1]]);
                else $jar[$m[1]] = urldecode($m[2]);   // setcookie() codifica el ":" del token
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $raw, true), $headersOut];
}
