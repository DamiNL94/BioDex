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

/** Como http(), pero devuelve el cuerpo sin decodificar (un ZIP): [código, cuerpo, cabeceras en minúsculas]. */
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

/** ZIP en memoria → [ruta interna → contenido]. Con $expandXlsx, los .xlsx también desempaquetados («x.xlsx!parte»). */
function unzipAll(string $raw, bool $expandXlsx = false): array {
    $tmp = tempnam(sys_get_temp_dir(), "biodex-test");
    file_put_contents($tmp, $raw);
    $zip = new ZipArchive();
    $out = [];
    if ($zip->open($tmp) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $out[$name] = $zip->getFromIndex($i);
            if ($expandXlsx && str_ends_with($name, ".xlsx")) foreach (unzipAll($out[$name]) as $p => $c) $out["{$name}!{$p}"] = $c;
        }
        $zip->close();
    }
    unlink($tmp);
    return $out;
}

/** Texto separado por tabuladores → [cabecera, filas como columna → valor, líneas con nº de columnas distinto]. */
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

/** Valor actual del contador común de sincronización. */
function syncSeq(PDO $pdo): int {
    return (int) $pdo->query("SELECT value FROM sync_seq WHERE id = 1")->fetchColumn();
}
