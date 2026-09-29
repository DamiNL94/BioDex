<?php
// ================================================================================================
// Genera los iconos de la PWA en frontend/img/ a partir de UNA sola geometría (lienzo de 512):
//   icon.svg · icon-192.png · icon-512.png · icon-maskable-512.png
// Hoja crema con nervio y punto de geolocalización en latón, sobre el verde del cartel (--leaf-fill)
// con filete de latón (--brass). El maskable no lleva filete (la máscara lo recortaría) y encoge el
// dibujo al 60 % central: margen de seguridad del 20 % por lado.
//
// Requiere ext-gd. El contenedor php no la trae; se ejecuta en el anfitrión:
//   php -d extension=gd backend/bin/make-icons.php
// ================================================================================================

if (!function_exists("imagecreatetruecolor")) {
    fwrite(STDERR, "Falta ext-gd: php -d extension=gd backend/bin/make-icons.php\n");
    exit(1);
}

const LEAF_FILL = "#1F4D34";
const BRASS = "#C4A15E";
const CREAM = "#FBF7EE";

// Contorno de la hoja: dos curvas de Bézier cúbicas de la base (abajo-izq.) a la punta (arriba-der.).
const LEAF = [
    [[176, 348], [168, 214], [258, 124], [404, 116]],
    [[404, 116], [396, 262], [306, 352], [176, 348]],
];
const RIB = [[176, 348], [352, 172]];         // nervio central
const PIN = ["x" => 150, "y" => 374, "r" => 30, "ring" => 52]; // punto de geolocalización
const FRAME = ["inset" => 22, "width" => 12, "radius" => 44];

$outDir = __DIR__ . "/../../frontend/img";

// --- SVG ----------------------------------------------------------------------------------------
function svgIcon(): string {
    [$a, $b] = LEAF;
    $p = fn($pt) => $pt[0] . " " . $pt[1];
    $leaf = "M{$p($a[0])} C{$p($a[1])} {$p($a[2])} {$p($a[3])} C{$p($b[1])} {$p($b[2])} {$p($b[3])}Z";
    $f = FRAME;
    $in = $f["inset"] + $f["width"] / 2;
    $size = 512 - 2 * $in;
    $pin = PIN;
    [$leafFill, $brass, $cream] = [LEAF_FILL, BRASS, CREAM];
    $rib = "M{$p(RIB[0])} L{$p(RIB[1])}";
    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
  <title>BioDex</title>
  <rect width="512" height="512" rx="64" fill="{$leafFill}"/>
  <rect x="{$in}" y="{$in}" width="{$size}" height="{$size}" rx="{$f['radius']}" fill="none" stroke="{$brass}" stroke-width="{$f['width']}"/>
  <path d="{$leaf}" fill="{$cream}"/>
  <path d="{$rib}" stroke="{$leafFill}" stroke-width="10" stroke-linecap="round"/>
  <circle cx="{$pin['x']}" cy="{$pin['y']}" r="{$pin['ring']}" fill="none" stroke="{$brass}" stroke-width="10" opacity=".55"/>
  <circle cx="{$pin['x']}" cy="{$pin['y']}" r="{$pin['r']}" fill="{$brass}"/>
</svg>

SVG;
}

// --- PNG con GD (supermuestreo ×4 y reducción: GD no suaviza los polígonos) ----------------------
function bezier(array $p, int $steps = 64): array {
    $out = [];
    for ($i = 0; $i <= $steps; $i++) {
        $t = $i / $steps; $u = 1 - $t;
        $out[] = [
            $u ** 3 * $p[0][0] + 3 * $u * $u * $t * $p[1][0] + 3 * $u * $t * $t * $p[2][0] + $t ** 3 * $p[3][0],
            $u ** 3 * $p[0][1] + 3 * $u * $u * $t * $p[1][1] + 3 * $u * $t * $t * $p[2][1] + $t ** 3 * $p[3][1],
        ];
    }
    return $out;
}

function color($im, string $hex, int $alpha = 0): int {
    [$r, $g, $b] = sscanf($hex, "#%02x%02x%02x");
    return imagecolorallocatealpha($im, $r, $g, $b, $alpha);
}

function roundedRect($im, float $x1, float $y1, float $x2, float $y2, float $r, int $col): void {
    imagefilledrectangle($im, (int) ($x1 + $r), (int) $y1, (int) ($x2 - $r), (int) $y2, $col);
    imagefilledrectangle($im, (int) $x1, (int) ($y1 + $r), (int) $x2, (int) ($y2 - $r), $col);
    foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
        imagefilledellipse($im, (int) $cx, (int) $cy, (int) (2 * $r), (int) (2 * $r), $col);
    }
}

function pngIcon(int $size, bool $maskable): GdImage {
    $ss = 4;
    $big = 512 * $ss;
    $im = imagecreatetruecolor($big, $big);
    imagealphablending($im, true);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, color($im, "#000000", 127));

    $leaf = color($im, LEAF_FILL);
    $brass = color($im, BRASS);
    $cream = color($im, CREAM);
    $ring = color($im, BRASS, 57);   // ~55 % de opacidad, como en el SVG

    // Transformación del lienzo de 512: el maskable encoge el dibujo al 60 % central.
    $k = $maskable ? 0.6 : 1.0;
    $off = (512 - 512 * $k) / 2;
    $tx = fn($v) => ($off + $v * $k) * $ss;

    if ($maskable) {
        imagefilledrectangle($im, 0, 0, $big, $big, $leaf);   // a sangre: la máscara la pone el sistema
    } else {
        roundedRect($im, 0, 0, $big - 1, $big - 1, 64 * $ss, $leaf);
        $f = FRAME;
        $o = $f["inset"] * $ss; $w = $f["width"] * $ss; $r = $f["radius"] * $ss;
        roundedRect($im, $o, $o, $big - 1 - $o, $big - 1 - $o, $r + $w / 2, $brass);
        roundedRect($im, $o + $w, $o + $w, $big - 1 - $o - $w, $big - 1 - $o - $w, max(1, $r - $w / 2), $leaf);
    }

    // Hoja
    $pts = [];
    foreach (LEAF as $curve) foreach (bezier($curve) as [$x, $y]) { $pts[] = (int) $tx($x); $pts[] = (int) $tx($y); }
    imagefilledpolygon($im, $pts, $cream);

    // Nervio
    imagesetthickness($im, (int) round(10 * $k * $ss));
    imageline($im, (int) $tx(RIB[0][0]), (int) $tx(RIB[0][1]), (int) $tx(RIB[1][0]), (int) $tx(RIB[1][1]), $leaf);
    imagesetthickness($im, 1);

    // Punto de geolocalización: anillo translúcido + punto
    $p = PIN;
    $cx = (int) $tx($p["x"]); $cy = (int) $tx($p["y"]);
    $outer = (int) (2 * ($p["ring"] + 5) * $k * $ss); $inner = (int) (2 * ($p["ring"] - 5) * $k * $ss);
    for ($d = $inner; $d <= $outer; $d += 2) imageellipse($im, $cx, $cy, $d, $d, $ring);
    imagefilledellipse($im, $cx, $cy, (int) (2 * $p["r"] * $k * $ss), (int) (2 * $p["r"] * $k * $ss), $brass);

    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, color($out, "#000000", 127));
    imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $big, $big);
    return $out;
}

file_put_contents("{$outDir}/icon.svg", svgIcon());
foreach ([["icon-192.png", 192, false], ["icon-512.png", 512, false], ["icon-maskable-512.png", 512, true]] as [$name, $size, $mask]) {
    imagepng(pngIcon($size, $mask), "{$outDir}/{$name}", 9);
    echo "OK   frontend/img/{$name}\n";
}
echo "OK   frontend/img/icon.svg\n";
