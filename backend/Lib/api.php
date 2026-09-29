<?php
// ================================================================================================
// api.php — arranque y respuestas comunes de los endpoints de backend/Api/ (adaptado de NaturDex).
// ------------------------------------------------------------------------------------------------
// Sin CORS: frontend y API se sirven desde el mismo origen (nginx). La sesión se añade en F2.
//
// respond() detecta el fallo de json_encode (p. ej. un byte inválido en un texto libre), que si no
// se imprimiría como cuerpo VACÍO con un 200, y responde un 500 con el motivo real.
// ================================================================================================

require_once __DIR__ . "/../Config/env.php";
require_once __DIR__ . "/../Config/db.php";

/** Prólogo común: cabecera JSON y errores no capturados convertidos en 500 JSON. */
function apiBoot(): void {
    header("Content-Type: application/json; charset=utf-8");

    set_exception_handler(function (Throwable $e) {
        // El detalle va al log del servidor, nunca al cliente (revelaría infraestructura).
        error_log("BioDex: " . get_class($e) . ": " . $e->getMessage());
        $message = biodex_env(["APP_ENV"]) === "dev" ? $e->getMessage() : "Error interno.";
        http_response_code(500);
        echo json_encode(["error" => $message], JSON_UNESCAPED_UNICODE);
    });
}

/** Responde con JSON y termina. Nunca deja un cuerpo vacío con un 200 por un fallo de codificación. */
function respond($data, int $code = 200): never {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        http_response_code(500);
        echo json_encode([
            "error" => "No se pudo serializar la respuesta: " . json_last_error_msg(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code($code);
    echo $json;
    exit;
}

/** Atajo para los errores: `apiError("No autenticado.", 401)`. */
function apiError(string $message, int $code = 400): never {
    respond(["error" => $message], $code);
}

/**
 * Cuerpo JSON de la petición como array asociativo, o 400. `$expected` describe la forma esperada,
 * para que el error sea accionable.
 */
function requireJsonBody(string $expected = "un objeto JSON"): array {
    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) apiError("Cuerpo JSON inválido. Se espera " . $expected . ".", 400);
    return $body;
}

/** Cierre estándar de un endpoint: el método no está contemplado. */
function apiMethodNotAllowed(string $allowed = "GET o POST"): never {
    apiError("Método no permitido. Usa " . $allowed . ".", 405);
}
