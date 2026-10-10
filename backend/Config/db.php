<?php
// ================================================================================================
// db.php — conexión PDO a MySQL (adaptado de NaturDex, que usaba mysqli).
// ------------------------------------------------------------------------------------------------
// A diferencia de NaturDex, NO responde ni hace exit si falla: lanza PDOException y decide quien
// llama (health la captura para contestar db:false; Lib/api.php la convierte en un 500 genérico).
// La conexión es perezosa y única por petición.
// ================================================================================================

require_once __DIR__ . "/env.php";

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $host = biodex_env(["DB_HOST"]) ?? "localhost";
    $name = biodex_env(["DB_NAME"]) ?? "biodex";
    $user = biodex_env(["DB_USER"]) ?? "root";
    $pass = biodex_env(["DB_PASS"]) ?? "";
    $port = (int) (biodex_env(["DB_PORT"]) ?? 3306);

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 3,
        ]
    );
    return $pdo;
}

/**
 * Marcadores para un `IN (...)` preparado: 3 → "?,?,?". Con 0 valores devuelve "NULL", porque
 * `IN ()` es un error de sintaxis y `IN (NULL)` simplemente no casa con nada.
 */
function sqlPlaceholders(int $n): string {
    return $n > 0 ? implode(",", array_fill(0, $n, "?")) : "NULL";
}
