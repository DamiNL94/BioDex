<?php
// ================================================================================================
// Comprueba frontend/sw.js contra el disco (la lección de NaturDex: un módulo nuevo sin precachear
// deja la app rota sin cobertura y nadie se entera hasta estar en el monte).
//   · Cada entrada de SHELL_ASSETS existe y no está repetida.
//   · Todo .js/.css de frontend/ (salvo el propio sw.js) está en SHELL_ASSETS.
//   · El motor y los umbrales UICN de shared/ (F8b) también (rutas shared/…, servidas por nginx).
// Uso: docker compose exec php php bin/check-sw.php   (o `php backend/bin/check-sw.php` en el anfitrión)
// ================================================================================================

// Ficheros de shared/ que sirve nginx (location /shared/ de biodex-app.conf) y que la app usa sin red.
const SHARED_SERVED = ["shared/iucn-engine.js", "shared/iucn-criteria.json", "shared/geo-metrics.js"];

$front = realpath(__DIR__ . "/../../frontend");
if ($front === false || !is_file("{$front}/sw.js")) {
    fwrite(STDERR, "FAIL No encuentro frontend/sw.js (¿está montado ./frontend en el contenedor php?)\n");
    exit(1);
}

$sw = file_get_contents("{$front}/sw.js");
if (!preg_match('/SHELL_ASSETS\s*=\s*\[(.*?)\];/s', $sw, $m)) {
    fwrite(STDERR, "FAIL No encuentro la lista SHELL_ASSETS en sw.js\n");
    exit(1);
}
// Fuera los comentarios de línea antes de extraer las cadenas.
$list = preg_replace('#//[^\n]*#', '', $m[1]);
preg_match_all('/[\'"]([^\'"]+)[\'"]/', $list, $mm);
$assets = $mm[1];

$failures = 0;
$fail = function (string $msg) use (&$failures) { $failures++; echo "FAIL {$msg}\n"; };

preg_match('/VERSION\s*=\s*[\'"]([^\'"]+)/', $sw, $v);
echo "sw.js VERSION " . ($v[1] ?? "?") . " · " . count($assets) . " ficheros en SHELL_ASSETS\n";

foreach (array_count_values($assets) as $a => $n) {
    if ($n > 1) $fail("repetido en SHELL_ASSETS: {$a}");
}
// shared/ (F8b): el motor UICN y sus umbrales, servidos por nginx en /shared/ desde la carpeta shared/.
$shared = dirname($front) . "/shared";
foreach ($assets as $a) {
    $file = str_starts_with($a, "shared/") ? "{$shared}/" . substr($a, 7) : "{$front}/{$a}";
    if (!is_file($file)) $fail("en SHELL_ASSETS pero no existe: {$a}");
}
foreach (SHARED_SERVED as $a) {
    if (!in_array($a, $assets, true)) $fail("{$a} no está en SHELL_ASSETS (el asistente UICN tiene que abrir sin red)");
}

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($front, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $rel = str_replace("\\", "/", substr($file->getPathname(), strlen($front) + 1));
    if ($rel === "sw.js" || str_starts_with($rel, "public/tiles/")) continue;
    if (preg_match('/\.(js|css)$/', $rel) && !in_array($rel, $assets, true)) {
        $fail("{$rel} no está en SHELL_ASSETS (añádelo y sube VERSION)");
    }
}

echo $failures ? "\n{$failures} fallo(s)\n" : "OK   SHELL_ASSETS al día\n";
exit($failures ? 1 : 0);
