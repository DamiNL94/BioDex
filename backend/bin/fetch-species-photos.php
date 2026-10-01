<?php
// ================================================================================================
// Fotos de respaldo de iNaturalist (F11b): docker compose exec php php bin/fetch-species-photos.php
// ------------------------------------------------------------------------------------------------
//   --limit=N                como mucho N consultas a iNat (por defecto, todas las pendientes)
//   --recheck-none-days=30   vuelve a mirar las «none» consultadas hace más de N días (iNat sube fotos
//                            nuevas); 0 = no volver a mirarlas
//   --dry-run                consulta y decide, pero no escribe nada
// 1. Pone en cola (pending) las especies de EIDOS de algún catálogo sin foto de la Fototeca y sin fila
//    (las dadas de alta antes de F11b; las nuevas ya entran pending al añadirlas al catálogo).
// 2. Consulta iNat a 1 petición/s y guarda la decisión de inatDecide() (Lib/inat.php). Una consulta que
//    falla tras los reintentos deja la especie pending para la próxima pasada. Una que ahora tiene foto de
//    la Fototeca sale de la cola. Una foto nueva con status ok sube content_seq de sus catálogos (el móvil
//    vuelve a bajar el paquete offline).
// Al acabar, una línea de resumen. Dos ejecuciones a la vez no se pisan (GET_LOCK de MySQL).
// En Railway lo lanza docker/railway/entrypoint.sh en segundo plano tras las migraciones.
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/species.php";

$opts = getopt("", ["limit:", "recheck-none-days:", "dry-run", "help"]);
if (isset($opts["help"])) {
    echo "Uso: php bin/fetch-species-photos.php [--limit=N] [--recheck-none-days=30] [--dry-run]\n";
    exit(0);
}
$limit = isset($opts["limit"]) ? filter_var($opts["limit"], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) : null;
$recheckDays = filter_var($opts["recheck-none-days"] ?? 30, FILTER_VALIDATE_INT, ["options" => ["min_range" => 0]]);
$dry = isset($opts["dry-run"]);
if ($limit === false || $recheckDays === false) {
    fwrite(STDERR, "--limit debe ser un entero ≥ 1 y --recheck-none-days un entero ≥ 0.\n");
    exit(2);
}

$pdo = db();
if (!(int) $pdo->query("SELECT GET_LOCK('biodex_fetch_species_photos', 0)")->fetchColumn()) {
    echo "BioDex fotos: ya hay otra ejecución en marcha; no hago nada.\n";
    exit(0);
}

// --- 1. Cola: especies de EIDOS de algún catálogo vigente sin fila -------------------------------
$missing = $pdo->query(
    "SELECT DISTINCT s.* FROM catalog_species cs
       JOIN catalogs c ON c.id = cs.catalog_id AND c.deleted_at IS NULL
       JOIN species s ON s.taxonID = cs.taxonID AND s.source = 'eidos'
       LEFT JOIN species_photos ph ON ph.taxonID = s.taxonID
      WHERE cs.deleted_at IS NULL AND ph.taxonID IS NULL"
)->fetchAll();
$queued = 0;
$queuedIds = [];
foreach ($missing as $row) {
    if (speciesImagesOut($row)) continue;
    $queuedIds[] = $row["taxonID"];
    if ($dry || speciesQueuePhoto($pdo, $row)) $queued++;
}

// --- 2. Lo que toca consultar: pendientes y «none» antiguas ---------------------------------------
$where = "status = 'pending'";
if ($recheckDays > 0) $where .= " OR (status = 'none' AND checked_at < NOW() - INTERVAL {$recheckDays} DAY)";
$todo = $pdo->query("SELECT taxonID, status FROM species_photos WHERE {$where} ORDER BY checked_at IS NOT NULL, checked_at, created_at")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
if ($dry) foreach ($queuedIds as $id) $todo[$id] ??= "pending";   // en seco no se insertaron
if ($limit !== null) $todo = array_slice($todo, 0, $limit, true);

$counts = ["ok" => 0, "review" => 0, "none" => 0, "errores" => 0, "con Fototeca" => 0];
$save = $pdo->prepare(
    "UPDATE species_photos SET status = ?, photo_id = ?, photo_ext = ?, inat_taxon_id = ?, matched_name = ?, match_type = ?,
            attribution = ?, license = ?, checked_at = NOW()
      WHERE taxonID = ? AND status IN ('pending', 'none')"   // una revisión hecha mientras tanto no se pisa
);
$bump = $pdo->prepare("UPDATE catalogs SET content_seq = content_seq + 1 WHERE deleted_at IS NULL AND id IN
                       (SELECT catalog_id FROM catalog_species WHERE taxonID = ? AND deleted_at IS NULL)");

foreach ($todo as $taxonID => $was) {
    $taxonID = (string) $taxonID;
    $row = speciesFind($pdo, $taxonID);
    if ($row === null || $row["source"] !== "eidos" || speciesImagesOut($row)) {
        // EIDOS ya tiene foto (o la especie no es de EIDOS): no hace falta respaldo.
        $counts["con Fototeca"]++;
        if (!$dry) $pdo->prepare("DELETE FROM species_photos WHERE taxonID = ? AND status IN ('pending', 'none')")->execute([$taxonID]);
        continue;
    }
    $name = $row["canonical_name"];
    try {
        $json = inatSearch($name);
    } catch (InatException $e) {
        $counts["errores"]++;
        echo "  error   {$taxonID} {$name}: {$e->getMessage()} (sigue pendiente)\n";
        continue;
    }
    $d = inatDecide([
        "name" => $name, "rank" => $row["taxonRank"], "kingdom" => $row["kingdom"],
        "synonyms" => json_decode((string) $row["synonyms"], true) ?: [],
    ], $json);
    $counts[$d["status"]]++;
    $what = $d["status"] === "none" ? $d["reason"]
        : "{$d["matched_name"]} · " . inatLicenseLabel($d["license"]) . " · foto {$d["photo_id"]}" . ($d["status"] === "review" ? " · {$d["reason"]}" : "");
    printf("  %-7s %s %s → %s\n", $d["status"], $taxonID, $name, $what);
    if ($dry) continue;
    $save->execute([$d["status"], $d["photo_id"] ?? null, $d["photo_ext"] ?? null, $d["inat_taxon_id"] ?? null, $d["matched_name"] ?? null,
                    $d["match_type"] ?? null, $d["attribution"] ?? null, $d["license"] ?? null, $taxonID]);
    if ($d["status"] === "ok" && $save->rowCount() > 0) $bump->execute([$taxonID]);
}

$pdo->query("SELECT RELEASE_LOCK('biodex_fetch_species_photos')");
$asked = $counts["ok"] + $counts["review"] + $counts["none"] + $counts["errores"];
echo "BioDex fotos" . ($dry ? " (en seco, sin escribir)" : "") . ": {$asked} consultas a iNaturalist · "
    . implode(" · ", array_map(fn($k, $v) => "{$k} {$v}", array_keys($counts), $counts))
    . " · {$queued} puestas en cola\n";
