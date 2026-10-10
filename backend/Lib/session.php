<?php
// ================================================================================================
// session.php — sesión de usuario y "seguir conectado" (portado de NaturDex, de mysqli a PDO).
// ------------------------------------------------------------------------------------------------
// La cookie de sesión de PHP muere al cerrar el navegador y la app de campo se abre y cierra sin
// parar: sin un token persistente habría que volver a entrar cada vez.
//
// Patrón selector/validador:
//   · Al entrar se emite un token en la cookie `biodex_remember` = "<selector>:<validador>". En la BD
//     (user_auth_tokens) queda el selector en claro —para buscar por índice— y SOLO el SHA-256 del
//     validador: quien se lleve la tabla no puede fabricar la cookie.
//   · Sin sesión, se busca por selector y se compara el validador con hash_equals() (tiempo constante).
//   · El validador ROTA en cada uso: una cookie robada deja de valer en cuanto el dueño vuelve a abrir
//     la app. El anterior se acepta BIODEX_ROTATION_GRACE s más (peticiones simultáneas al arrancar).
//   · Caducidad deslizante de 90 días.
//
// Todas las marcas de tiempo se calculan en SQL (NOW(), INTERVAL), nunca con date()/strtotime():
// PHP y MySQL pueden estar en zonas horarias distintas.
//
// SHA-256 a secas (no password_hash) para el validador: son 32 bytes aleatorios, no una contraseña
// elegida por una persona; no hay nada que sacar por fuerza bruta y la comprobación debe ser rápida.
// ================================================================================================

const BIODEX_REMEMBER_COOKIE = "biodex_remember";
const BIODEX_REMEMBER_DAYS   = 90;   // caducidad deslizante del token
const BIODEX_ROTATION_GRACE  = 60;   // segundos que sigue valiendo el validador anterior

/** ¿La petición llegó por HTTPS? Detrás de un proxy TLS el tramo interno es HTTP: se mira X-Forwarded-Proto. */
function biodexHttps(): bool {
    return (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
        || (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https");
}

/**
 * Arranca la sesión y, si no hay usuario, intenta recuperarlo del token de "seguir conectado".
 * Sustituye a session_start(). Hay que llamarla ANTES de imprimir nada (manda Set-Cookie).
 */
function biodexSessionStart(PDO $pdo): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    // Modo estricto: un identificador de sesión que el servidor no emitió se descarta (fijación de sesión).
    ini_set("session.use_strict_mode", "1");
    session_name("biodex_session");
    session_set_cookie_params([
        // Cookie "de navegador" a propósito: la persistencia la da el token, que sí se puede revocar.
        "lifetime" => 0,
        "path"     => "/",
        "httponly" => true,
        "secure"   => biodexHttps(),
        "samesite" => "Lax",
    ]);
    session_start();

    if (empty($_SESSION["user_id"])) biodexRestoreRemembered($pdo);
}

/** Id del usuario con sesión, o null. Requiere biodexSessionStart() antes. */
function sessionUserId(): ?int {
    return empty($_SESSION["user_id"]) ? null : (int) $_SESSION["user_id"];
}

/** Id del usuario con sesión, o 401. */
function requireUserId(): int {
    $id = sessionUserId();
    if ($id === null) apiError("No autenticado.", 401);
    return $id;
}

/** Abre sesión para $userId (tras login o registro): id de sesión nuevo y token para este dispositivo. */
function biodexLogIn(PDO $pdo, int $userId): void {
    $_SESSION["user_id"] = $userId;
    session_regenerate_id(true);
    biodexRememberUser($pdo, $userId);
}

/** Cierra la sesión de ESTE dispositivo; los demás siguen conectados. */
function biodexLogOut(PDO $pdo): void {
    biodexForgetRemembered($pdo);
    $_SESSION = [];
    session_destroy();
    setcookie(session_name(), "", biodexCookieOptions(0));
}

/** Opciones de cookie comunes. $maxAge <= 0 la borra (fecha en el pasado). */
function biodexCookieOptions(int $maxAge): array {
    return [
        "expires"  => $maxAge > 0 ? time() + $maxAge : 1,
        "path"     => "/",
        "httponly" => true,
        "secure"   => biodexHttps(),
        "samesite" => "Lax",
    ];
}

function biodexSetRememberCookie(string $selector, string $validator): void {
    setcookie(BIODEX_REMEMBER_COOKIE, $selector . ":" . $validator, biodexCookieOptions(BIODEX_REMEMBER_DAYS * 86400));
}

function biodexClearRememberCookie(): void {
    setcookie(BIODEX_REMEMBER_COOKIE, "", biodexCookieOptions(0));
    unset($_COOKIE[BIODEX_REMEMBER_COOKIE]);
}

/** [selector, validador] de la cookie, o null si no hay o está mal formada. */
function biodexRememberCookieParts(): ?array {
    $raw = (string) ($_COOKIE[BIODEX_REMEMBER_COOKIE] ?? "");
    if (!str_contains($raw, ":")) return null;
    [$selector, $validator] = explode(":", $raw, 2);
    if ($selector === "" || $validator === "") return null;
    return [$selector, $validator];
}

/** Emite un token nuevo para este dispositivo (al entrar y al registrarse). */
function biodexRememberUser(PDO $pdo, int $userId): void {
    $selector  = bin2hex(random_bytes(9));   // 18 caracteres, en claro y con índice único
    $validator = bin2hex(random_bytes(32));  // 64 caracteres, de él solo se guarda el hash

    $pdo->prepare(
        "INSERT INTO user_auth_tokens (user_id, selector, validator_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL " . BIODEX_REMEMBER_DAYS . " DAY))"
    )->execute([$userId, $selector, hash("sha256", $validator)]);

    biodexSetRememberCookie($selector, $validator);

    // Limpieza oportunista de tokens caducados: ocurre una vez por inicio de sesión, sin tarea programada.
    $pdo->exec("DELETE FROM user_auth_tokens WHERE expires_at < NOW()");
}

/**
 * Intenta abrir sesión a partir de la cookie del token. Silenciosa: si algo no cuadra, se retira la
 * cookie y la petición sigue como anónima (cada endpoint responde 401 si le hace falta).
 */
function biodexRestoreRemembered(PDO $pdo): void {
    $parts = biodexRememberCookieParts();
    if (!$parts) return;
    [$selector, $validator] = $parts;

    $stmt = $pdo->prepare(
        "SELECT id, user_id, validator_hash, previous_hash,
                (previous_hash IS NOT NULL
                 AND rotated_at > DATE_SUB(NOW(), INTERVAL " . BIODEX_ROTATION_GRACE . " SECOND)) AS grace
           FROM user_auth_tokens
          WHERE selector = ? AND expires_at > NOW() LIMIT 1"
    );
    $stmt->execute([$selector]);
    $row = $stmt->fetch();

    // Selector desconocido o caducado: cookie inservible, se retira para no repetir la consulta.
    if (!$row) { biodexClearRememberCookie(); return; }

    $hash    = hash("sha256", $validator);
    $current = hash_equals($row["validator_hash"], $hash);
    // Validador anterior dentro de la ventana de gracia: el frontend lanza varias peticiones a la vez
    // al abrir la app y todas canjean la misma cookie; sin la ventana, las siguientes parecerían un robo.
    $graced  = !$current && (int) $row["grace"] === 1 && hash_equals((string) $row["previous_hash"], $hash);

    if (!$current && !$graced) {
        // Selector válido con validador que no cuadra: cookie vieja o copiada. El token deja de valer.
        $pdo->prepare("DELETE FROM user_auth_tokens WHERE id = ?")->execute([$row["id"]]);
        biodexClearRememberCookie();
        return;
    }

    $_SESSION["user_id"] = (int) $row["user_id"];
    session_regenerate_id(true);

    // Solo rota quien presentó el validador vigente; los de la ventana de gracia no tocan nada.
    if ($current) biodexRotateToken($pdo, (int) $row["id"], $selector, $row["validator_hash"]);
}

/** Cambia el validador del token y renueva cookie y caducidad. */
function biodexRotateToken(PDO $pdo, int $tokenId, string $selector, string $previousHash): void {
    $validator = bin2hex(random_bytes(32));
    $pdo->prepare(
        "UPDATE user_auth_tokens
            SET validator_hash = ?, previous_hash = ?, rotated_at = NOW(), last_used_at = NOW(),
                expires_at = DATE_ADD(NOW(), INTERVAL " . BIODEX_REMEMBER_DAYS . " DAY)
          WHERE id = ?"
    )->execute([hash("sha256", $validator), $previousHash, $tokenId]);
    biodexSetRememberCookie($selector, $validator);
}

/** Olvida SOLO este dispositivo. */
function biodexForgetRemembered(PDO $pdo): void {
    $parts = biodexRememberCookieParts();
    if ($parts) $pdo->prepare("DELETE FROM user_auth_tokens WHERE selector = ?")->execute([$parts[0]]);
    biodexClearRememberCookie();
}
