<?php
// ================================================================================================
// GET /img/respaldo/<taxonID>/<16 primeros hex del sha256>.<jpg|png|webp> — copia local de la foto de
// respaldo de GBIF o Wikimedia Commons (F11d, docs/fotos-respaldo.md). No pasa por el router de /api.
// ------------------------------------------------------------------------------------------------
// nginx (biodex-app.conf) solo manda aquí las URL con esa forma exacta (guarda previa: lo demás → 400) y
// solo GET (limit_except: POST → 403); las cabeceras de seguridad (CSP, nosniff…) las pone nginx.
//   · Hash o extensión que no son los de la copia actual → 404: al cambiar de foto cambia la URL y una
//     caché no sirve la vieja (por eso la respuesta puede ser «immutable»).
//   · Se sirve con status ok. Con status review (sinónimo de GBIF pendiente de revisión) solo a quien sea
//     owner o custodio de un catálogo con la especie (como POST /api/species/{id}/photo-review), sin caché
//     compartida; a los demás, 404.
// ================================================================================================

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/session.php";
require_once __DIR__ . "/../Lib/authz.php";
require_once __DIR__ . "/../Lib/photo-files.php";

const RESPALDO_PATH = '#^/img/respaldo/([0-9]{1,12})/([0-9a-f]{16})\.(jpg|png|webp)$#';

function respaldoFail(int $code): never {
    http_response_code($code);
    header("Content-Type: text/plain; charset=utf-8");
    header("Cache-Control: no-store");
    echo $code === 404 ? "No encontrada\n" : "Petición no válida\n";
    exit;
}

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
if ($method !== "GET" && $method !== "HEAD") respaldoFail(405);
if (!preg_match(RESPALDO_PATH, (string) ($_SERVER["REQUEST_URI"] ?? ""), $m)) respaldoFail(400);
[, $taxonID, $hash, $ext] = $m;

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT ph.status, f.mime, f.sha256, f.bytes
       FROM species_photos ph
       JOIN species_photo_files f ON f.taxonID = ph.taxonID
      WHERE ph.taxonID = ? AND ph.source IN ('gbif', 'commons') AND ph.status IN ('ok', 'review')"
);
$stmt->execute([$taxonID]);
$row = $stmt->fetch();
if (!$row || !hash_equals(substr($row["sha256"], 0, 16), $hash) || (PHOTO_MIME_EXT[$row["mime"]] ?? "") !== $ext) respaldoFail(404);

if ($row["status"] === "review") {
    biodexSessionStart($pdo);
    $userId = sessionUserId();
    if ($userId === null) respaldoFail(404);
    $roles = CATALOG_PERMISSIONS["review_photos"];
    $stmt = $pdo->prepare(
        "SELECT 1 FROM catalog_members m
           JOIN catalogs c ON c.id = m.catalog_id AND c.deleted_at IS NULL
           JOIN catalog_species cs ON cs.catalog_id = m.catalog_id AND cs.taxonID = ? AND cs.deleted_at IS NULL
          WHERE m.user_id = ? AND m.role IN (" . implode(",", array_fill(0, count($roles), "?")) . ") LIMIT 1"
    );
    $stmt->execute([$taxonID, $userId, ...$roles]);
    if (!$stmt->fetchColumn()) respaldoFail(404);
    header("Cache-Control: private, no-store");
} else {
    header("Cache-Control: public, max-age=31536000, immutable");
}

$etag = "\"{$row["sha256"]}\"";
header("ETag: {$etag}");
header("Content-Type: {$row["mime"]}");
if (trim((string) ($_SERVER["HTTP_IF_NONE_MATCH"] ?? "")) === $etag) {
    http_response_code(304);
    exit;
}
header("Content-Length: " . strlen($row["bytes"]));
if ($method === "GET") echo $row["bytes"];
