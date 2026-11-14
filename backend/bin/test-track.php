<?php
// ================================================================================================
// Pruebas del recorrido GPS de una visita, SIN base de datos:
//   php backend/bin/test-track.php
// Pasa shared/track-cases.json con el port en PHP (Lib/track.php): mismo análisis y mismo esfuerzo que
// node shared/track.test.js (que además comprueba el filtrado de los fixes, que solo existe en JS).
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Lib/track.php";
require_once __DIR__ . "/test-lib.php";

function trackNumEq(mixed $a, mixed $b): bool {
    if (is_array($a) && is_array($b)) {
        if (array_keys($a) !== array_keys($b)) return false;
        foreach ($a as $k => $v) if (!trackNumEq($v, $b[$k])) return false;
        return true;
    }
    if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) return abs($a - $b) < 1e-9;
    return $a === $b;
}

$data = json_decode(file_get_contents(__DIR__ . "/../../shared/track-cases.json"), true);

section("Recorrido: análisis y esfuerzo (mismos casos que JS)");
foreach ($data["cases"] as $c) {
    $a = trackAnalyze($c["track"], $c["pauses"]);
    check("Análisis: {$c["name"]}", trackNumEq($a, $c["expect"]), json_encode($a));
    check("Tramos: {$c["name"]}", count(trackSegments($c["track"], $c["pauses"])) === $c["segments"]);
    if (isset($c["visit"])) {
        $e = trackVisitEffort($c["visit"], $a);
        check("Esfuerzo: {$c["name"]}", trackNumEq($e, $c["expectEffort"]), json_encode($e));
    }
}

section("WKT del recorrido");
$pts = [[37.1, -3.5, 0, 5, 0], [37.1, -3.499, 10, 5, 10], [37.1, -3.49, 1000, 5, 1000], [37.1, -3.489, 1010, 5, 1010]];
check("Sin cortes: LINESTRING en «lon lat»", trackWkt(array_slice($pts, 0, 2)) === "LINESTRING(-3.5 37.1, -3.499 37.1)", (string) trackWkt(array_slice($pts, 0, 2)));
check("Con un hueco: MULTILINESTRING de dos tramos", trackWkt($pts) === "MULTILINESTRING((-3.5 37.1, -3.499 37.1), (-3.49 37.1, -3.489 37.1))", (string) trackWkt($pts));
check("Un solo punto: sin geometría", trackWkt([$pts[0]]) === null);

echo $failures ? "\n{$failures} fallo(s)\n" : "\nTodo OK\n";
exit($failures ? 1 : 0);
