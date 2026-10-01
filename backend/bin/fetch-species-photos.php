<?php
// ================================================================================================
// Fotos de respaldo (F11b, F11d): docker compose exec php php bin/fetch-species-photos.php
// ------------------------------------------------------------------------------------------------
//   --limit=N                como mucho N especies (por defecto, todas las pendientes)
//   --recheck-none-days=30   vuelve a mirar las «none» consultadas hace más de N días (las fuentes suben
//                            fotos nuevas); 0 = no volver a mirarlas
//   --source=inaturalist|gbif|commons
//                            prueba una sola fuente: guarda lo que encuentre (ok/review), pero un «none»
//                            no se escribe (las otras fuentes no se han consultado)
//   --dry-run                consulta y decide, pero no escribe nada
// 1. Pone en cola (pending) las especies de EIDOS de algún catálogo sin foto de la Fototeca y sin fila
//    (las dadas de alta antes de F11b; las nuevas ya entran pending al añadirlas al catálogo).
// 2. Cascada por especie (Lib/photos.php): iNaturalist > GBIF > Wikimedia Commons, saltando las fuentes
//    rechazadas en la revisión. La primera que da ok o review la cierra. Un error de red deja la especie
//    pending para la próxima pasada, sin pasar a la fuente siguiente. Una que ahora tiene foto de la
//    Fototeca sale de la cola. Una foto nueva con status ok sube content_seq de sus catálogos (el móvil
//    vuelve a bajar el paquete offline).
// Una línea por especie (qué fuente decidió y por qué se descartaron las anteriores) y un resumen por
// fuente al acabar. Una fuente con 3 errores de red seguidos se da por caída hasta el final de la vuelta
// (photoBreaker()); las especies con error se reintentan una vez al final, tras RETRY_PAUSE_S. Dos
// ejecuciones a la vez no se pisan (GET_LOCK de MySQL).
// En Railway lo lanza docker/railway/entrypoint.sh en segundo plano tras las migraciones.
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/species.php";
require_once __DIR__ . "/../Lib/photos.php";

const RETRY_PAUSE_S = 60;   // pausa antes de reintentar las que dieron error

$opts = getopt("", ["limit:", "recheck-none-days:", "source:", "dry-run", "help"]);
if (isset($opts["help"])) {
    echo "Uso: php bin/fetch-species-photos.php [--limit=N] [--recheck-none-days=30] [--source=inaturalist|gbif|commons] [--dry-run]\n";
    exit(0);
}
$limit = isset($opts["limit"]) ? filter_var($opts["limit"], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) : null;
$recheckDays = filter_var($opts["recheck-none-days"] ?? 30, FILTER_VALIDATE_INT, ["options" => ["min_range" => 0]]);
$only = $opts["source"] ?? null;
$dry = isset($opts["dry-run"]);
if ($limit === false || $recheckDays === false || ($only !== null && !in_array($only, PHOTO_SOURCES, true))) {
    fwrite(STDERR, "--limit debe ser un entero ≥ 1, --recheck-none-days un entero ≥ 0 y --source una de: " . implode(", ", PHOTO_SOURCES) . ".\n");
    exit(2);
}

$pdo = db();
if (!(int) $pdo->query("SELECT GET_LOCK('biodex_fetch_species_photos', 0)")->fetchColumn()) {
    echo "BioDex fotos: ya hay otra ejecución en marcha; no hago nada.\n";
    exit(0);
}
$started = microtime(true);

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
$todo = $pdo->query("SELECT taxonID, rejected_sources FROM species_photos WHERE {$where} ORDER BY checked_at IS NOT NULL, checked_at, created_at")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
if ($dry) foreach ($queuedIds as $id) $todo[$id] ??= "";   // en seco no se insertaron
if ($limit !== null) $todo = array_slice($todo, 0, $limit, true);
$queue = $todo;

$counts = ["ok" => array_fill_keys(PHOTO_SOURCES, 0), "review" => 0, "none" => 0, "errores" => 0, "con Fototeca" => 0];
$bump = $pdo->prepare("UPDATE catalogs SET content_seq = content_seq + 1 WHERE deleted_at IS NULL AND id IN
                       (SELECT catalog_id FROM catalog_species WHERE taxonID = ? AND deleted_at IS NULL)");
// Segunda vuelta: las que dieron error de red se reintentan una vez al final, tras una pausa y con el
// cortocircuito a cero (en Railway el script solo corre al desplegar; Wikidata en «maxlag» o GBIF lento suelen
// recuperarse en un par de minutos). En seco no hay segunda vuelta.
foreach ([1, 2] as $round) {
    if ($round === 2) {
        if (!$failed || $dry) break;   // en seco no se espera: es para probar
        echo "  … reintento de " . count($failed) . " con error dentro de " . RETRY_PAUSE_S . " s\n";
        sleep(RETRY_PAUSE_S);
        $todo = $failed;
        $counts["errores"] = 0;
    }
    $finders = photoBreaker(photoFinders());
    $failed = [];
    foreach ($todo as $taxonID => $rejected) {
        $taxonID = (string) $taxonID;
        $row = speciesFind($pdo, $taxonID);
        if ($row === null || $row["source"] !== "eidos" || speciesImagesOut($row)) {
            // EIDOS ya tiene foto (o la especie no es de EIDOS): no hace falta respaldo.
            $counts["con Fototeca"]++;
            if (!$dry) $pdo->prepare("DELETE FROM species_photos WHERE taxonID = ? AND status IN ('pending', 'none')")->execute([$taxonID]);
            continue;
        }
        $name = $row["canonical_name"];
        $d = photoCascade([
            "name" => $name, "rank" => $row["taxonRank"], "kingdom" => $row["kingdom"],
            "synonyms" => json_decode((string) $row["synonyms"], true) ?: [],
        ], array_filter(explode(",", (string) $rejected)), $finders, $only);
        echo photoCascadeLine($taxonID, $name, $d), "\n";

        if ($d["status"] === "pending") { $counts["errores"]++; $failed[$taxonID] = $rejected; continue; }
        if ($d["status"] === "ok") $counts["ok"][$d["source"]]++;
        else $counts[$d["status"]]++;
        if ($dry || ($only !== null && $d["status"] === "none")) continue;
        if (photoSaveDecision($pdo, $taxonID, $d) && $d["status"] === "ok") $bump->execute([$taxonID]);
    }
}

$pdo->query("SELECT RELEASE_LOCK('biodex_fetch_species_photos')");
$asked = count($queue) - $counts["con Fototeca"];
echo "BioDex fotos" . ($dry ? " (en seco, sin escribir)" : "") . ($only ? " (solo " . PHOTO_SOURCE_SHORT[$only] . ")" : "") . ": "
    . implode(" · ", array_map(fn($s) => PHOTO_SOURCE_SHORT[$s] . " ok {$counts["ok"][$s]}", PHOTO_SOURCES))
    . " · review {$counts["review"]} · none {$counts["none"]} · errores {$counts["errores"]} · con Fototeca {$counts["con Fototeca"]}"
    . " · {$asked} especies consultadas · {$queued} puestas en cola · " . (int) round((microtime(true) - $started) / 60) . " min\n";
