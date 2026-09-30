<?php
// ================================================================================================
// Común a bin/seed-demo.php y bin/reset-demo.php (F10b): cuentas, catálogo y borrado de la demo.
// Solo con APP_ENV=dev. Las cuentas usan el dominio @biodex.demo (no @biodex.test: test-f2a.php
// borra todos los usuarios de ese dominio).
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../../Config/db.php";
require_once __DIR__ . "/../../Lib/migrate.php";

const DEMO_CATALOG_NAME = "DEMO — Flora amenazada de Sierra Nevada (datos ficticios)";
const DEMO_NOTICE = "Datos ficticios para demostración: no publicar.";

// Contraseñas fijas a propósito: van en el README y el guion (docs/demo.md). Solo existen con APP_ENV=dev.
const DEMO_USERS = [
    "owner"     => ["email" => "owner@biodex.demo",    "name" => "Lucía Ortega",  "password" => "SierraNevada-owner",    "role" => "owner"],
    "custodian" => ["email" => "custodio@biodex.demo", "name" => "Carlos Molina", "password" => "SierraNevada-custodio", "role" => "custodian"],
    "editor"    => ["email" => "editor@biodex.demo",   "name" => "Elena Ruiz",    "password" => "SierraNevada-editor",   "role" => "editor"],
];

function demoRequireDev(): void {
    if (getenv("APP_ENV") !== "dev") {
        fwrite(STDERR, "Solo con APP_ENV=dev (ahora: «" . (getenv("APP_ENV") ?: "sin definir") . "»). No se ha tocado nada.\n");
        exit(1);
    }
}

/** Ids de las cuentas de demo que existen ahora. */
function demoUserIds(PDO $pdo): array {
    $emails = array_column(DEMO_USERS, "email");
    $st = $pdo->prepare("SELECT id FROM users WHERE email IN (" . implode(",", array_fill(0, count($emails), "?")) . ")");
    $st->execute($emails);
    return array_map("intval", $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Catálogos de la demo: el del seed y cualquiera que las cuentas de demo hayan creado al ensayar. */
function demoCatalogIds(PDO $pdo, array $userIds): array {
    $sql = "SELECT id FROM catalogs WHERE name = ?";
    $args = [DEMO_CATALOG_NAME];
    if ($userIds) {
        $sql .= " OR owner_id IN (" . implode(",", array_fill(0, count($userIds), "?")) . ")";
        $args = [...$args, ...$userIds];
    }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Borra lo de la demo, y solo eso: sus catálogos (con marcas, notas, evaluaciones, métricas, miembros,
 * invitaciones y su rastro en access_log), lo que las cuentas de demo hayan escrito en otros catálogos,
 * las especies propias que crearon (si nadie más las usa) y las tres cuentas. La caché de EIDOS se queda.
 * → [catálogos, marcas, cuentas] borrados.
 */
function demoReset(PDO $pdo): array {
    $users = demoUserIds($pdo);
    $cats = demoCatalogIds($pdo, $users);
    $in = fn(array $ids) => implode(",", array_fill(0, count($ids), "?"));
    $marks = 0;

    $pdo->beginTransaction();
    try {
        foreach ([[$cats, "catalog_id"], [$users, "user_id"]] as [$ids, $col]) {
            if (!$ids) continue;
            foreach (["occurrences", "species_notes", "species_assessments", "access_log"] as $t) {
                $st = $pdo->prepare("DELETE FROM {$t} WHERE {$col} IN ({$in($ids)})");
                $st->execute($ids);
                if ($t === "occurrences") $marks += $st->rowCount();
            }
        }
        if ($cats) $pdo->prepare("DELETE FROM catalogs WHERE id IN ({$in($cats)})")->execute($cats);
        if ($users) {
            $pdo->prepare("DELETE FROM species WHERE source = 'user' AND created_by IN ({$in($users)})
                             AND taxonID NOT IN (SELECT taxonID FROM catalog_species)
                             AND taxonID NOT IN (SELECT taxonID FROM occurrences)
                             AND taxonID NOT IN (SELECT taxonID FROM species_notes)
                             AND taxonID NOT IN (SELECT taxonID FROM species_assessments)")->execute($users);
            $pdo->prepare("DELETE FROM users WHERE id IN ({$in($users)})")->execute($users);
        }
        $emails = array_column(DEMO_USERS, "email");
        $pdo->prepare("DELETE FROM login_attempts WHERE email IN ({$in($emails)})")->execute($emails);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [count($cats), $marks, count($users)];
}

function demoPrintAccounts(): void {
    echo "\nCuentas de demo (verificadas):\n";
    foreach (DEMO_USERS as $u) {
        printf("  %-10s %-22s %-24s %s\n", $u["role"], $u["email"], $u["password"], $u["name"]);
    }
}
