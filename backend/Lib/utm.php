<?php
// ================================================================================================
// utm.php — lat/lon ETRS89 → UTM (GRS80), port de frontend/js/geo/utm.js (toUtm) para F9.
// ------------------------------------------------------------------------------------------------
// Mercator Transversa con las series de Krüger hasta n⁶ (Karney 2011). Huso 30 por defecto =
// EPSG:25830 (fuera del huso se sigue proyectando en el 30, como los SIG con ese EPSG). Mismas
// constantes y mismas operaciones que la versión JS: los casos de shared/metrics-cases.json lo
// comprueban contra PROJ (bin/test-f9.php).
// ================================================================================================

const UTM_A  = 6378137.0;
const UTM_F  = 1 / 298.257222101;
const UTM_K0 = 0.9996;
const UTM_FALSE_EASTING = 500000.0;
const UTM_FALSE_NORTHING_SOUTH = 10000000.0;

/** Constantes derivadas (se calculan una vez). */
function utmConstants(): array {
    static $c = null;
    if ($c !== null) return $c;
    $n = UTM_F / (2 - UTM_F);
    $n2 = $n * $n; $n3 = $n2 * $n; $n4 = $n3 * $n; $n5 = $n4 * $n; $n6 = $n5 * $n;
    $c = [
        "e"  => sqrt(UTM_F * (2 - UTM_F)),
        "aa" => (UTM_A / (1 + $n)) * (1 + $n2 / 4 + $n4 / 64 + $n6 / 256),
        "alpha" => [
            $n / 2 - (2 / 3) * $n2 + (5 / 16) * $n3 + (41 / 180) * $n4 - (127 / 288) * $n5 + (7891 / 37800) * $n6,
            (13 / 48) * $n2 - (3 / 5) * $n3 + (557 / 1440) * $n4 + (281 / 630) * $n5 - (1983433 / 1935360) * $n6,
            (61 / 240) * $n3 - (103 / 140) * $n4 + (15061 / 26880) * $n5 + (167603 / 181440) * $n6,
            (49561 / 161280) * $n4 - (179 / 168) * $n5 + (6601661 / 7257600) * $n6,
            (34729 / 80640) * $n5 - (3418889 / 1995840) * $n6,
            (212378941 / 319334400) * $n6,
        ],
    ];
    return $c;
}

/** Latitud/longitud (grados) → ["easting" => m, "northing" => m] en el huso $zone (30 = EPSG:25830). */
function toUtm(float $lat, float $lon, int $zone = 30): array {
    if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90) throw new InvalidArgumentException("Coordenadas no válidas");
    $c = utmConstants();
    $e = $c["e"];
    $phi = deg2rad($lat);
    $lam = deg2rad($lon - (($zone - 1) * 6 - 180 + 3));
    $cosLam = cos($lam);
    $sinLam = sin($lam);

    $tau = tan($phi);
    $sigma = sinh($e * atanh(($e * $tau) / sqrt(1 + $tau * $tau)));
    $tauP = $tau * sqrt(1 + $sigma * $sigma) - $sigma * sqrt(1 + $tau * $tau);

    $xiP = atan2($tauP, $cosLam);
    $etaP = asinh($sinLam / sqrt($tauP * $tauP + $cosLam * $cosLam));

    $xi = $xiP;
    $eta = $etaP;
    for ($j = 1; $j <= 6; $j++) {
        $xi  += $c["alpha"][$j - 1] * sin(2 * $j * $xiP) * cosh(2 * $j * $etaP);
        $eta += $c["alpha"][$j - 1] * cos(2 * $j * $xiP) * sinh(2 * $j * $etaP);
    }
    return [
        "easting"  => UTM_FALSE_EASTING + UTM_K0 * $c["aa"] * $eta,
        "northing" => UTM_K0 * $c["aa"] * $xi + ($lat >= 0 ? 0 : UTM_FALSE_NORTHING_SOUTH),
    ];
}
