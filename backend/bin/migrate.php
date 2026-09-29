<?php
// Aplica las migraciones pendientes: docker compose exec php php bin/migrate.php
// Idempotente: un segundo pase dice «Nada que aplicar».

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/migrate.php";

try {
    $applied = runMigrations(db(), fn($v) => print("Aplicada {$v}\n"));
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

echo $applied ? count($applied) . " migración(es) aplicada(s).\n" : "Nada que aplicar.\n";
