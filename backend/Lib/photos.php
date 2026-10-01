<?php
// ================================================================================================
// photos.php — la cascada de fotos de respaldo (F11d): iNaturalist > GBIF > Wikimedia Commons.
// ------------------------------------------------------------------------------------------------
// Una búsqueda por especie. La primera fuente que da ok o review la cierra; none pasa a la siguiente;
// las fuentes rechazadas en la revisión (species_photos.rejected_sources) se saltan. Un error de red en
// una fuente deja la especie pending SIN pasar a la siguiente: si no, se mostraría la de Commons solo
// porque iNat estaba caído. Regla de oro: mejor sin foto que con la de otra especie.
// photoCascade() recibe las fuentes como funciones (las pruebas le pasan fuentes falsas);
// photoSaveDecision() escribe la decisión (y la copia local de GBIF/Commons) en una transacción.
// La usa bin/fetch-species-photos.php. Reglas: docs/fotos-respaldo.md.
// ================================================================================================

require_once __DIR__ . "/inat.php";
require_once __DIR__ . "/gbif.php";
require_once __DIR__ . "/commons.php";

const PHOTO_SOURCES = ["inaturalist", "gbif", "commons"];
/** Nombre corto para el script. */
const PHOTO_SOURCE_SHORT = ["inaturalist" => "iNat", "gbif" => "GBIF", "commons" => "Commons"];

/** iNaturalist como fuente de la cascada: inatSearch() + inatDecide() sin cambios, con su error como el de las demás. */
function inatFind(array $species): array {
    try {
        $json = inatSearch($species["name"]);
    } catch (InatException $e) {
        throw new PhotoSourceException($e->getMessage(), 0, $e);
    }
    $d = inatDecide($species, $json);
    if ($d["status"] === "none") return ["status" => "none", "reason" => "iNat: {$d["reason"]}"];
    return $d + ["photo_page_url" => INAT_PHOTO_PAGE . $d["photo_id"]];
}

/** Las fuentes de verdad, en orden. */
function photoFinders(): array {
    return ["inaturalist" => "inatFind", "gbif" => "gbifFind", "commons" => "commonsFind"];
}

/**
 * Cortocircuito para una pasada del script: tras $max errores de red seguidos de una fuente (cada uno ya con
 * sus reintentos), esa fuente falla al momento hasta el final de la pasada. Las especies que lleguen a ella
 * se quedan pending, como con cualquier error, pero sin esperar ~1 min de reintentos cada una (p. ej. con
 * Wikidata devolviendo «maxlag» durante horas).
 */
function photoBreaker(array $finders, int $max = 3): array {
    $out = [];
    foreach ($finders as $src => $fn) {
        $errors = 0;
        $out[$src] = function (array $species) use ($src, $fn, $max, &$errors) {
            if ($errors >= $max) throw new PhotoSourceException(PHOTO_SOURCE_SHORT[$src] . " no responde ({$max} errores seguidos): se reintenta en la próxima pasada");
            try {
                $d = $fn($species);
            } catch (InatException | PhotoSourceException $e) {
                $errors++;
                throw $e;
            }
            $errors = 0;
            return $d;
        };
    }
    return $out;
}

/**
 * Cascada para una especie {name, rank, kingdom, synonyms}.
 *   $rejected  fuentes rechazadas en la revisión (se saltan)
 *   $finders   fuente → callable(species): decisión (PHOTO_SOURCES en orden; las que falten no se consultan)
 *   $only      consulta solo esa fuente (opción --source del script)
 * → ["status" => ok|review|none|pending, "source" => fuente que decidió o null, "log" => [fuente => motivo
 *   del descarte], y con ok/review los campos de la foto; con pending, "error"].
 */
function photoCascade(array $species, array $rejected, array $finders, ?string $only = null): array {
    $log = [];
    foreach (PHOTO_SOURCES as $src) {
        if ($only !== null && $src !== $only) continue;
        if (in_array($src, $rejected, true)) { $log[$src] = "rechazada en la revisión"; continue; }
        if (!isset($finders[$src])) continue;
        try {
            $d = $finders[$src]($species);
        } catch (InatException | PhotoSourceException $e) {
            return ["status" => "pending", "source" => $src, "error" => $e->getMessage(), "log" => $log];
        }
        if ($d["status"] === "ok" || $d["status"] === "review") return ["source" => $src, "log" => $log] + $d;
        $log[$src] = $d["reason"] ?? "sin foto";
    }
    return ["status" => "none", "source" => null, "log" => $log];
}

/**
 * Guarda la decisión de la cascada (ok/review/none) de una especie pending o none. Una revisión hecha
 * mientras tanto no se pisa. ok/review de GBIF o Commons → copia local en species_photo_files; cualquier
 * otra decisión borra la que hubiera. → true si ha escrito.
 */
function photoSaveDecision(PDO $pdo, string $taxonID, array $d): bool {
    $photo = $d["status"] === "ok" || $d["status"] === "review";
    $f = fn(string $k) => $photo ? ($d[$k] ?? null) : null;
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "UPDATE species_photos SET status = ?, source = ?, photo_id = ?, photo_ext = ?, inat_taxon_id = ?, source_ref = ?, photo_ref = ?,
                photo_page_url = ?, matched_name = ?, match_type = ?, attribution = ?, license = ?, license_version = ?,
                checked_at = NOW(), reviewed_by = NULL, reviewed_at = NULL
          WHERE taxonID = ? AND status IN ('pending', 'none')"
    );
    $stmt->execute([$d["status"], $photo ? $d["source"] : null, $f("photo_id"), $f("photo_ext"), $f("inat_taxon_id"), $f("source_ref"),
                    $f("photo_ref"), $f("photo_page_url"), $f("matched_name"), $f("match_type"), $f("attribution"), $f("license"),
                    $f("license_version"), $taxonID]);
    $written = $stmt->rowCount() > 0;
    if ($written && $photo && isset($d["file"])) {
        $file = $d["file"];
        $ins = $pdo->prepare("REPLACE INTO species_photo_files (taxonID, mime, bytes, sha256, width, height, fetched_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $ins->bindValue(1, $taxonID);
        $ins->bindValue(2, $file["mime"]);
        $ins->bindValue(3, $file["bytes"], PDO::PARAM_LOB);
        $ins->bindValue(4, $file["sha256"]);
        $ins->bindValue(5, $file["width"], PDO::PARAM_INT);
        $ins->bindValue(6, $file["height"], PDO::PARAM_INT);
        $ins->execute();
    } elseif ($written) {
        $pdo->prepare("DELETE FROM species_photo_files WHERE taxonID = ?")->execute([$taxonID]);
    }
    $pdo->commit();
    return $written;
}

/** Línea del script para una especie: qué fuente decidió y por qué se descartaron las anteriores. */
function photoCascadeLine(string $taxonID, string $name, array $d): string {
    $before = $d["log"] ? " (" . implode("; ", array_map(fn($s, $r) => PHOTO_SOURCE_SHORT[$s] . ": " . preg_replace('/^(iNat|GBIF|Commons): /', "", $r),
        array_keys($d["log"]), $d["log"])) . ")" : "";
    $what = match ($d["status"]) {
        "pending" => PHOTO_SOURCE_SHORT[$d["source"]] . ": error: {$d["error"]} (sigue pendiente)",
        "none"    => "sin foto",
        default   => PHOTO_SOURCE_SHORT[$d["source"]] . ": {$d["matched_name"]} · " . photoLicenseLabel($d["license"], $d["license_version"] ?? null)
                     . " · " . ($d["source"] === "inaturalist" ? "foto {$d["photo_id"]}" : $d["photo_ref"])
                     . ($d["status"] === "review" ? " · {$d["reason"]}" : ""),
    };
    return sprintf("  %-7s %s %s → %s%s", $d["status"] === "pending" ? "error" : $d["status"], $taxonID, $name, $what, $before);
}
