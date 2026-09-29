<?php
// GET /api/health → {"ok":true,"db":true|false}. Comprueba que PHP responde y que MySQL acepta la conexión.

if ($_SERVER["REQUEST_METHOD"] !== "GET") apiMethodNotAllowed("GET");

try {
    db()->query("SELECT 1");
    $dbOk = true;
} catch (PDOException $e) {
    error_log("BioDex health: " . $e->getMessage());
    $dbOk = false;
}

respond(["ok" => true, "db" => $dbOk]);
