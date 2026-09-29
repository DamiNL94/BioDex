<?php
// ================================================================================================
// /api/auth/* — registro, login, logout, sesión actual y verificación de email.
// ------------------------------------------------------------------------------------------------
//   POST /api/auth/register  {email, password, name?}  → 201 {user, verificationSent}
//   POST /api/auth/login     {email, password}         → 200 {user} · 401 · 429
//   POST /api/auth/logout                              → 200 {ok}
//   GET  /api/auth/me                                  → 200 {user} · 401
//   GET  /api/auth/verify?token=...                    → 200 {ok, user} · 400
//
// Sesión: Lib/session.php (cookie de sesión + token "seguir conectado" de 90 días).
// Límite: 5 intentos fallidos por email o por IP en 15 min → 429; cada fallo va a access_log.
// Una cuenta sin verificar puede entrar; lo que exija verificación lo comprueba cada fase (F6: custodio).
// ================================================================================================

require_once __DIR__ . "/../Lib/session.php";
require_once __DIR__ . "/../Lib/audit.php";
require_once __DIR__ . "/../Lib/mail.php";

const PASSWORD_MIN_LENGTH   = 10;
const LOGIN_MAX_FAILURES    = 5;
const LOGIN_WINDOW_MINUTES  = 15;
const VERIFY_TOKEN_HOURS    = 48;

$pdo = db();
biodexSessionStart($pdo);

$method = $_SERVER["REQUEST_METHOD"];
$path   = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
$action = preg_match('#^/api/auth/([a-z]+)/?$#', $path, $m) ? $m[1] : "";

/** Usuario público (sin hash) o null. */
function authUser(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT id, email, name, orcid, email_verified_at FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) return null;
    return [
        "id"            => (int) $u["id"],
        "email"         => $u["email"],
        "name"          => $u["name"],
        "orcid"         => $u["orcid"],
        "emailVerified" => $u["email_verified_at"] !== null,
    ];
}

function normalizeEmail($value): string {
    return mb_strtolower(trim((string) $value));
}

function passwordAlgo(): string|int|null {
    return defined("PASSWORD_ARGON2ID") ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

/** Crea un token de verificación, manda el enlace y dice si el correo salió. */
function sendVerificationEmail(PDO $pdo, int $userId, string $email, string $name): bool {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        "INSERT INTO email_verifications (token_hash, user_id, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . VERIFY_TOKEN_HOURS . " HOUR))"
    )->execute([hash("sha256", $token), $userId]);

    $base = rtrim(biodex_env(["APP_URL"]) ?? "http://localhost:8090", "/");
    $link = $base . "/api/auth/verify?token=" . $token;
    $body = "Hola, {$name}:\n\n"
          . "Para confirmar tu dirección de correo en BioDex abre este enlace:\n\n{$link}\n\n"
          . "Caduca en " . VERIFY_TOKEN_HOURS . " horas. Si no has creado la cuenta, ignora este mensaje.\n";
    return sendMail($email, "Confirma tu correo en BioDex", $body);
}

// ---------------------------------------------------------------------------------------- GET
if ($action === "me") {
    if ($method !== "GET") apiMethodNotAllowed("GET");
    $user = authUser($pdo, requireUserId());
    if (!$user) { biodexLogOut($pdo); apiError("No autenticado.", 401); }
    respond(["user" => $user]);
}

if ($action === "verify") {
    if ($method !== "GET") apiMethodNotAllowed("GET");
    $token = (string) ($_GET["token"] ?? "");
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) apiError("Enlace de verificación no válido.", 400);

    $stmt = $pdo->prepare(
        "SELECT user_id FROM email_verifications
          WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()"
    );
    $stmt->execute([hash("sha256", $token)]);
    $userId = $stmt->fetchColumn();
    if ($userId === false) apiError("Enlace de verificación no válido o caducado.", 400);

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE email_verifications SET used_at = NOW() WHERE token_hash = ?")
        ->execute([hash("sha256", $token)]);
    $pdo->prepare("UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?")
        ->execute([$userId]);
    $pdo->commit();
    respond(["ok" => true, "user" => authUser($pdo, (int) $userId)]);
}

// --------------------------------------------------------------------------------------- POST
if (!in_array($action, ["register", "login", "logout"], true)) apiError("Recurso no encontrado.", 404);
if ($method !== "POST") apiMethodNotAllowed("POST");

if ($action === "logout") {
    biodexLogOut($pdo);
    respond(["ok" => true]);
}

$body     = requireJsonBody("{email, password}");
$email    = normalizeEmail($body["email"] ?? "");
$password = (string) ($body["password"] ?? "");

if ($action === "register") {
    $name = trim((string) ($body["name"] ?? ""));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) apiError("Email no válido.", 400);
    if (mb_strlen($password) < PASSWORD_MIN_LENGTH) {
        apiError("La contraseña debe tener al menos " . PASSWORD_MIN_LENGTH . " caracteres.", 400);
    }
    if ($name === "") $name = explode("@", $email)[0];
    $name = mb_substr($name, 0, 120);

    try {
        $pdo->prepare("INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)")
            ->execute([$email, password_hash($password, passwordAlgo()), $name]);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] === 1062) apiError("Ya existe una cuenta con ese email.", 409);
        throw $e;
    }
    $userId = (int) $pdo->lastInsertId();

    biodexLogIn($pdo, $userId);
    $sent = sendVerificationEmail($pdo, $userId, $email, $name);
    respond(["user" => authUser($pdo, $userId), "verificationSent" => $sent], 201);
}

// login
$ip = clientIp() ?? "";
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(email = ?), 0) AS by_email, COALESCE(SUM(ip = ?), 0) AS by_ip
       FROM login_attempts
      WHERE attempted_at > DATE_SUB(NOW(), INTERVAL " . LOGIN_WINDOW_MINUTES . " MINUTE)
        AND (email = ? OR ip = ?)"
);
$stmt->execute([$email, $ip, $email, $ip]);
$counts = $stmt->fetch();
if ((int) $counts["by_email"] >= LOGIN_MAX_FAILURES || (int) $counts["by_ip"] >= LOGIN_MAX_FAILURES) {
    header("Retry-After: " . (LOGIN_WINDOW_MINUTES * 60));
    apiError("Demasiados intentos fallidos. Prueba de nuevo en " . LOGIN_WINDOW_MINUTES . " minutos.", 429);
}

$stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE email = ?");
$stmt->execute([$email]);
$row = $stmt->fetch();

// Con email desconocido se verifica igualmente contra un hash cualquiera: así el tiempo de respuesta
// no revela qué emails tienen cuenta (hash argon2id con los mismos parámetros que los reales).
$dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$ek52VHh1VWk2QjQucmR5Qw$POZGClt8o1WsbndIRiOIM1599W9GLlPpYSEX5lEStvE';
$ok = password_verify($password, $row ? $row["password_hash"] : $dummyHash) && $row;

if (!$ok) {
    $pdo->prepare("INSERT INTO login_attempts (email, ip) VALUES (?, ?)")->execute([$email, $ip]);
    logAccess($pdo, $row ? (int) $row["id"] : null, "login_failed", null, $email);
    // Limpieza oportunista: los intentos de hace más de un día ya no cuentan para nada.
    $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    apiError("Email o contraseña incorrectos.", 401);
}

$userId = (int) $row["id"];
if (password_needs_rehash($row["password_hash"], passwordAlgo())) {
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
        ->execute([password_hash($password, passwordAlgo()), $userId]);
}
$pdo->prepare("DELETE FROM login_attempts WHERE email = ?")->execute([$email]);

biodexLogIn($pdo, $userId);
respond(["user" => authUser($pdo, $userId)]);
