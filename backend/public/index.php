<?php
// ================================================================================================
// Router único de /api. nginx envía aquí todo /api/*; cada recurso vive en Api/<recurso>.php.
// /api/health → Api/health.php. El nombre se valida con una regex, así que no hay path traversal.
// ================================================================================================

require_once __DIR__ . "/../Lib/api.php";

apiBoot();

$path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);

if (!preg_match('#^/api/([a-z][a-z0-9_-]*)/?#', $path, $m)) {
    apiError("Recurso no encontrado.", 404);
}

$file = __DIR__ . "/../Api/" . $m[1] . ".php";
if (!is_file($file)) {
    apiError("Recurso no encontrado.", 404);
}

require $file;
