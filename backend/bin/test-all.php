<?php
// ================================================================================================
// test-all.php — ejecuta todas las baterías bin/test-*.php en el orden en que se construyó la app
// (cada fase se apoya en lo que dejan las anteriores, p. ej. fichas de EIDOS ya en caché) y resume.
//   docker compose exec php php bin/test-all.php            → todas
//   docker compose exec php php bin/test-all.php f8a f9     → solo esas
// Sale con 1 si alguna batería falla. Las pruebas de JS van aparte: ver «Comprobaciones» en el README.
// ================================================================================================

$only = array_slice($argv, 1);
$files = glob(__DIR__ . "/test-f*.php");
natsort($files);   // f2a, f2b, f4 … f10a … f11d (no alfabético: f10 iría antes que f2)

$results = [];
foreach ($files as $file) {
    $name = basename($file, ".php");
    if ($only && !in_array(substr($name, 5), $only, true)) continue;
    echo "\n######## {$name}\n";
    passthru(escapeshellarg(PHP_BINARY) . " " . escapeshellarg($file), $code);
    $results[$name] = $code;
}

echo "\n======== Resumen\n";
foreach ($results as $name => $code) echo ($code === 0 ? "OK   " : "FAIL ") . $name . "\n";
$failed = count(array_filter($results));
echo $failed ? "\n{$failed} batería(s) con fallos.\n" : "\nTodo OK.\n";
exit($failed ? 1 : 0);
