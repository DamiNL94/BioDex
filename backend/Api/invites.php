<?php
// ================================================================================================
// /api/invites — unirse a un catálogo con un código de invitación (F6). Requiere sesión.
//   POST /api/invites/preview  {code}  → {catalog:{id, name, description, ownerName}, role, expiresAt, alreadyMember}
//   POST /api/invites/accept   {code}  → {catalog, role, joined}
// El código va en el cuerpo, nunca en la URL (no queda en los registros de nginx). Admite el enlace
// entero. Respuestas: 404 código que no existe · 403 invitación para otro email · 410 caducada,
// revocada, agotada o catálogo borrado · 200 sin cambios si ya eres miembro · 429 tras 10 códigos
// inexistentes por usuario y hora (también cuenta la vista previa: si no, sería un oráculo).
// ================================================================================================

require_once __DIR__ . "/../Lib/session.php";
require_once __DIR__ . "/../Lib/authz.php";
require_once __DIR__ . "/../Lib/audit.php";
require_once __DIR__ . "/../Lib/teams.php";

$pdo = db();
biodexSessionStart($pdo);
$userId = requireUserId();

$method = $_SERVER["REQUEST_METHOD"];
$path   = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
if (!preg_match('#^/api/invites/(preview|accept)/?$#', $path, $m)) apiError("Recurso no encontrado.", 404);
if ($method !== "POST") apiMethodNotAllowed("POST");
$accept = $m[1] === "accept";

if (inviteRecentFailures($pdo, $userId) >= INVITE_MAX_FAILURES) {
    header("Retry-After: 3600");
    apiError("Demasiados códigos no válidos. Prueba de nuevo dentro de una hora.", 429);
}

$code = inviteNormalizeCode(requireJsonBody("{code}")["code"] ?? null);

if ($accept) $pdo->beginTransaction();
$invite = false;
if ($code !== null) {
    $stmt = $pdo->prepare(
        "SELECT i.*, i.expires_at <= NOW() AS expired, c.name AS catalog_name, c.description AS catalog_description,
                c.deleted_at AS catalog_deleted_at, o.name AS owner_name
           FROM catalog_invites i
           JOIN catalogs c ON c.id = i.catalog_id
           JOIN users o ON o.id = c.owner_id
          WHERE i.code_hash = ?" . ($accept ? " FOR UPDATE" : "")
    );
    $stmt->execute([inviteCodeHash($code)]);
    $invite = $stmt->fetch();
}

/** Termina con error, deshaciendo la transacción de aceptar si la hay. */
function inviteFail(PDO $pdo, string $message, int $status): never {
    if ($pdo->inTransaction()) $pdo->rollBack();
    apiError($message, $status);
}

if (!$invite) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    inviteRecordFailure($pdo, $userId);
    apiError("Código de invitación no válido. Revisa que esté completo.", 404);
}
if ($invite["catalog_deleted_at"] !== null) inviteFail($pdo, "Ese catálogo ya no existe.", 410);

$catalog = [
    "id" => $invite["catalog_id"], "name" => $invite["catalog_name"],
    "description" => $invite["catalog_description"], "ownerName" => $invite["owner_name"],
];

// Ya miembro: nada que hacer (ni se gasta un uso), sea cual sea el estado de la invitación.
$current = catalogRole($pdo, $invite["catalog_id"], $userId);
if ($current !== null) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(["catalog" => $catalog, "role" => $current, "alreadyMember" => true, "joined" => false]);
}

if ($invite["email"] !== null) {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    if (mb_strtolower((string) $stmt->fetchColumn()) !== mb_strtolower($invite["email"])) {
        inviteFail($pdo, "Esta invitación es para otra dirección de correo: entra con la cuenta a la que se envió.", 403);
    }
}
if ($invite["revoked_at"] !== null) inviteFail($pdo, "La invitación se ha revocado. Pide otra a quien te la envió.", 410);
if ((int) $invite["expired"] === 1)  inviteFail($pdo, "La invitación ha caducado. Pide otra a quien te la envió.", 410);
if ((int) $invite["uses"] >= (int) $invite["max_uses"]) inviteFail($pdo, "La invitación ya se ha usado. Pide otra a quien te la envió.", 410);

if (!$accept) {
    respond(["catalog" => $catalog, "role" => $invite["role"], "expiresAt" => $invite["expires_at"], "alreadyMember" => false]);
}

try {
    $pdo->prepare("INSERT INTO catalog_members (catalog_id, user_id, role, granted_by) VALUES (?, ?, ?, ?)")
        ->execute([$invite["catalog_id"], $userId, $invite["role"], $invite["created_by"]]);
} catch (PDOException $e) {
    // Dos aceptaciones a la vez del mismo usuario: la otra ya le ha dado de alta.
    if (($e->errorInfo[1] ?? null) !== 1062) throw $e;
    $pdo->rollBack();
    respond(["catalog" => $catalog, "role" => catalogRole($pdo, $invite["catalog_id"], $userId), "alreadyMember" => true, "joined" => false]);
}
$pdo->prepare("UPDATE catalog_invites SET uses = uses + 1 WHERE id = ?")->execute([$invite["id"]]);
logAccess($pdo, $userId, "member_joined", $invite["catalog_id"], "user:{$userId} ({$invite["role"]}, invite:{$invite["id"]})");
$pdo->commit();

respond(["catalog" => $catalog, "role" => $invite["role"], "alreadyMember" => false, "joined" => true]);
