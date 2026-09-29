<?php
// ================================================================================================
// migrate.php — aplica en orden los ficheros backend/migrations/NNN_nombre.sql pendientes.
// ------------------------------------------------------------------------------------------------
// Cada fichero aplicado queda en schema_migrations; un segundo pase no hace nada. MySQL confirma
// solo cada DDL (no hay transacciones de esquema), así que las migraciones usan CREATE TABLE IF NOT
// EXISTS / INSERT IGNORE: si una falla a medias, se corrige y se vuelve a lanzar sin tocar nada a mano.
// La usan bin/migrate.php (CLI) y bin/test-f2a.php.
// ================================================================================================

const MIGRATIONS_DIR = __DIR__ . "/../migrations";

/**
 * Aplica las migraciones pendientes y devuelve sus nombres (vacío si no había ninguna).
 * $log recibe una línea por fichero aplicado.
 */
function runMigrations(PDO $pdo, ?callable $log = null): array {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version    VARCHAR(191) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

    $done = array_flip($pdo->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN));

    $files = glob(MIGRATIONS_DIR . "/[0-9][0-9][0-9]_*.sql");
    sort($files, SORT_STRING);

    $applied = [];
    foreach ($files as $file) {
        $version = basename($file, ".sql");
        if (isset($done[$version])) continue;

        foreach (splitSqlStatements(file_get_contents($file)) as $sql) {
            $pdo->exec($sql);
        }
        $pdo->prepare("INSERT INTO schema_migrations (version) VALUES (?)")->execute([$version]);
        $applied[] = $version;
        if ($log) $log($version);
    }
    return $applied;
}

/**
 * Parte un script SQL en sentencias por `;`, ignorando los que van dentro de comillas o de
 * comentarios `-- `. Basta para DDL e INSERT; no contempla DELIMITER (no usamos triggers ni rutinas).
 */
function splitSqlStatements(string $sql): array {
    $statements = [];
    $current = "";
    $quote = null;
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];

        if ($quote !== null) {
            $current .= $c;
            if ($c === "\\") { $current .= $sql[++$i] ?? ""; continue; }
            if ($c === $quote) $quote = null;
            continue;
        }
        if ($c === "-" && substr($sql, $i, 3) === "-- ") {
            $eol = strpos($sql, "\n", $i);
            $i = $eol === false ? $len : $eol - 1;
            continue;
        }
        if ($c === "'" || $c === '"' || $c === "`") $quote = $c;
        if ($c === ";") {
            if (trim($current) !== "") $statements[] = trim($current);
            $current = "";
            continue;
        }
        $current .= $c;
    }
    if (trim($current) !== "") $statements[] = trim($current);
    return $statements;
}
