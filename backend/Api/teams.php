<?php
// ================================================================================================
// Equipo de un catálogo (F6). Lo incluye Api/catalogs.php para estas rutas (no se sirve solo):
//   GET    /api/catalogs/{id}/members                 view: miembros (email solo para el owner)
//   PUT    /api/catalogs/{id}/members/{userId}        manage_members {role}: custodian·editor·viewer
//   DELETE /api/catalogs/{id}/members/{userId}        manage_members: expulsar
//   POST   /api/catalogs/{id}/invites                 manage_members {email?, role, maxUses?, days?}
//   GET    /api/catalogs/{id}/invites                 manage_members: pendientes (sin el código)
//   DELETE /api/catalogs/{id}/invites/{inviteId}      manage_members: revocar
//   POST   /api/catalogs/{id}/leave                   view: salir (el owner no puede)
//   POST   /api/catalogs/{id}/transfer                owner {userId, password}: a un custodio
//   GET    /api/catalogs/{id}/audit?limit=50&before=  owner: registro legible del catálogo
// Aceptar invitaciones: Api/invites.php. Cada cambio de miembros, roles o invitaciones va a access_log
// en la misma transacción. Ganar o perder acceso a exactas sube la época de visibilidad (Lib/teams.php).
// ================================================================================================

if (!isset($teamRoute)) apiError("Recurso no encontrado.", 404);

require_once __DIR__ . "/../Lib/teams.php";
require_once __DIR__ . "/../Lib/mail.php";

[$catalogId, $sub, $itemId] = $teamRoute;

const ROLE_NAMES_ES = ["owner" => "propietario", "custodian" => "custodio", "editor" => "editor", "viewer" => "lector"];
const TRANSFER_MAX_FAILURES = 5;     // contraseña errónea al transferir: el mismo límite que el login
const TRANSFER_WINDOW_MINUTES = 15;

function memberOut(array $r, bool $withEmail): array {
    return [
        "userId"        => (int) $r["user_id"],
        "name"          => $r["name"],
        "orcid"         => $r["orcid"],
        "role"          => $r["role"],
        "emailVerified" => $r["email_verified_at"] !== null,
        "joinedAt"      => $r["joined_at"],
    ] + ($withEmail ? ["email" => $r["email"]] : []);
}

function memberRow(PDO $pdo, string $catalogId, int $memberId, bool $lock = false): ?array {
    $stmt = $pdo->prepare(
        "SELECT m.user_id, m.role, m.joined_at, u.name, u.email, u.orcid, u.email_verified_at
           FROM catalog_members m JOIN users u ON u.id = m.user_id
          WHERE m.catalog_id = ? AND m.user_id = ?" . ($lock ? " FOR UPDATE" : "")
    );
    $stmt->execute([$catalogId, $memberId]);
    return $stmt->fetch() ?: null;
}

function inviteOut(array $r): array {
    return [
        "id" => (int) $r["id"], "email" => $r["email"], "role" => $r["role"],
        "maxUses" => (int) $r["max_uses"], "uses" => (int) $r["uses"],
        "expiresAt" => $r["expires_at"], "createdAt" => $r["created_at"], "createdBy" => $r["created_by_name"] ?? null,
    ];
}

/** Id numérico del final de la ruta, o 404. */
function routeInt(?string $v): int {
    $n = filter_var($v, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if ($n === false) apiError("Recurso no encontrado.", 404);
    return $n;
}

/** Texto legible de una fila de access_log: nombres en vez de ids y roles en castellano. */
function auditDetail(array $r, array $names, array $species): string {
    $t = (string) $r["target"];
    // «user:12 editor → custodian» → «Ana: editor → custodio»
    if (in_array($r["action"], ["grant_role", "revoke_role"], true)) $t = preg_replace('/^(user:\d+) /', '$1: ', $t);
    $t = preg_replace_callback('/user:(\d+)/', fn($m) => $names[(int) $m[1]] ?? "usuario {$m[1]}", $t);
    $t = preg_replace_callback('/\b(owner|custodian|editor|viewer)\b/', fn($m) => ROLE_NAMES_ES[$m[1]], $t);
    $t = preg_replace('/invite:(\d+)/', 'invitación #$1', $t);
    $t = mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);   // «Invitación #3 …»
    return match ($r["action"]) {
        "view_exact"   => (int) $t === 1 ? "Recibió 1 coordenada exacta ajena" : "Recibió {$t} coordenadas exactas ajenas",
        "login_failed" => "Contraseña incorrecta al transferir la propiedad",
        "set_level", "set_reference", "photo_review" => preg_replace_callback('/^([^:]+):/', fn($m) => ($species[$m[1]] ?? $m[1]) . ":", $t),
        default        => $t,
    };
}

// --- /members ------------------------------------------------------------------------------------

if ($sub === "members") {
    if ($itemId === null) {
        if ($method !== "GET") apiMethodNotAllowed("GET");
        $role = requireCatalogPermission($pdo, $catalogId, $userId, "view");
        $stmt = $pdo->prepare(
            "SELECT m.user_id, m.role, m.joined_at, u.name, u.email, u.orcid, u.email_verified_at
               FROM catalog_members m JOIN users u ON u.id = m.user_id
              WHERE m.catalog_id = ?
              ORDER BY FIELD(m.role, 'owner', 'custodian', 'editor', 'viewer'), u.name"
        );
        $stmt->execute([$catalogId]);
        // El email es dato personal: solo lo ve quien gestiona el equipo.
        $withEmail = catalogCan($role, "manage_members");
        respond(["role" => $role, "members" => array_map(fn($r) => memberOut($r, $withEmail), $stmt->fetchAll())]);
    }

    $memberId = routeInt($itemId);
    requireCatalogPermission($pdo, $catalogId, $userId, "manage_members");
    if ($memberId === $userId) {
        apiError($method === "DELETE" ? "Para irte del catálogo usa «Salir del catálogo»." : "No puedes cambiar tu propio rol.", 403);
    }

    if ($method === "PUT") {
        $newRole = requireJsonBody("{role}")["role"] ?? null;
        if (!in_array($newRole, MEMBER_ROLES, true)) apiError("«role» debe ser custodian, editor o viewer.", 400);

        $pdo->beginTransaction();
        $m = memberRow($pdo, $catalogId, $memberId, true);
        if (!$m) { $pdo->rollBack(); apiError("Esa persona no es miembro del catálogo.", 404); }
        if ($m["role"] === "owner") { $pdo->rollBack(); apiError("Al propietario no se le cambia el rol: se transfiere la propiedad.", 403); }
        if ($m["role"] === $newRole) { $pdo->rollBack(); respond(["member" => memberOut($m, true), "changed" => false]); }
        if ($newRole === "custodian" && $m["email_verified_at"] === null) {
            $pdo->rollBack();
            apiError("Para ser custodio hace falta el email verificado: que abra el enlace del correo de BioDex.", 422);
        }
        $pdo->prepare("UPDATE catalog_members SET role = ?, granted_by = ? WHERE catalog_id = ? AND user_id = ?")
            ->execute([$newRole, $userId, $catalogId, $memberId]);
        // Gana o pierde las exactas ajenas: su móvil tiene que rehacer las marcas del equipo.
        if (roleSeesExact($m["role"]) !== roleSeesExact($newRole)) bumpMemberEpoch($pdo, $catalogId, $memberId);
        $action = ROLE_RANK[$newRole] > ROLE_RANK[$m["role"]] ? "grant_role" : "revoke_role";
        logAccess($pdo, $userId, $action, $catalogId, "user:{$memberId} {$m["role"]} → {$newRole}");
        $pdo->commit();
        respond(["member" => memberOut(memberRow($pdo, $catalogId, $memberId), true), "changed" => true]);
    }

    if ($method === "DELETE") {
        $pdo->beginTransaction();
        $m = memberRow($pdo, $catalogId, $memberId, true);
        if (!$m) { $pdo->rollBack(); apiError("Esa persona no es miembro del catálogo.", 404); }
        if ($m["role"] === "owner") { $pdo->rollBack(); apiError("No se puede expulsar al propietario.", 403); }
        // Sus marcas se quedan (son del catálogo y llevan su autoría); su móvil borra el catálogo al sincronizar.
        $pdo->prepare("DELETE FROM catalog_members WHERE catalog_id = ? AND user_id = ?")->execute([$catalogId, $memberId]);
        logAccess($pdo, $userId, "member_removed", $catalogId, "user:{$memberId} ({$m["role"]})");
        $pdo->commit();
        respond(["ok" => true]);
    }
    apiMethodNotAllowed("PUT o DELETE");
}

// --- /invites ------------------------------------------------------------------------------------

if ($sub === "invites") {
    requireCatalogPermission($pdo, $catalogId, $userId, "manage_members");

    if ($itemId !== null) {
        if ($method !== "DELETE") apiMethodNotAllowed("DELETE");
        $inviteId = routeInt($itemId);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT revoked_at FROM catalog_invites WHERE id = ? AND catalog_id = ? FOR UPDATE");
        $stmt->execute([$inviteId, $catalogId]);
        $inv = $stmt->fetch();
        if (!$inv) { $pdo->rollBack(); apiError("Invitación no encontrada.", 404); }
        if ($inv["revoked_at"] === null) {
            $pdo->prepare("UPDATE catalog_invites SET revoked_at = NOW() WHERE id = ?")->execute([$inviteId]);
            logAccess($pdo, $userId, "invite_revoked", $catalogId, "invite:{$inviteId}");
        }
        $pdo->commit();
        respond(["ok" => true]);
    }

    if ($method === "GET") {
        $stmt = $pdo->prepare(
            "SELECT i.*, u.name AS created_by_name FROM catalog_invites i LEFT JOIN users u ON u.id = i.created_by
              WHERE i.catalog_id = ? AND i.revoked_at IS NULL AND i.expires_at > NOW() AND i.uses < i.max_uses
              ORDER BY i.created_at DESC"
        );
        $stmt->execute([$catalogId]);
        respond(["invites" => array_map("inviteOut", $stmt->fetchAll())]);
    }

    if ($method !== "POST") apiMethodNotAllowed("GET o POST");
    $body = requireJsonBody("{email?, role, maxUses?, days?}");
    $role = $body["role"] ?? null;
    if (!in_array($role, INVITE_ROLES, true)) apiError("«role» debe ser editor o viewer (custodio se concede después, a un miembro).", 400);

    $email = $body["email"] ?? null;
    if ($email !== null) {
        $email = mb_strtolower(trim((string) $email));
        if ($email === "") $email = null;
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) apiError("Email no válido.", 400);
    }
    $maxUses = $body["maxUses"] ?? 1;
    $maxUses = filter_var($maxUses, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => INVITE_MAX_USES]]);
    if ($maxUses === false) apiError("«maxUses» debe estar entre 1 y " . INVITE_MAX_USES . ".", 400);
    if ($email !== null && $maxUses !== 1) apiError("Una invitación por email es para una sola persona: «maxUses» = 1.", 400);
    $days = filter_var($body["days"] ?? INVITE_DAYS_DEFAULT, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => INVITE_DAYS_MAX]]);
    if ($days === false) apiError("«days» debe estar entre 1 y " . INVITE_DAYS_MAX . ".", 400);

    if ($email !== null) {
        $stmt = $pdo->prepare("SELECT 1 FROM catalog_members m JOIN users u ON u.id = m.user_id WHERE m.catalog_id = ? AND u.email = ?");
        $stmt->execute([$catalogId, $email]);
        if ($stmt->fetchColumn()) apiError("Esa persona ya es miembro del catálogo.", 409);
    }

    $code = inviteNewCode();
    $pdo->beginTransaction();
    $pdo->prepare(
        "INSERT INTO catalog_invites (code_hash, catalog_id, email, role, max_uses, expires_at, created_by)
         VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL {$days} DAY), ?)"
    )->execute([inviteCodeHash($code), $catalogId, $email, $role, $maxUses, $userId]);
    $inviteId = (int) $pdo->lastInsertId();
    logAccess($pdo, $userId, "invite_created", $catalogId, "invite:{$inviteId} {$role}, "
        . ($email !== null ? "por email a {$email}" : "enlace de {$maxUses} " . ($maxUses === 1 ? "uso" : "usos")) . ", {$days} " . ($days === 1 ? "día" : "días"));
    $pdo->commit();

    $stmt = $pdo->prepare("SELECT i.*, u.name AS created_by_name FROM catalog_invites i LEFT JOIN users u ON u.id = i.created_by WHERE i.id = ?");
    $stmt->execute([$inviteId]);
    $invite = $stmt->fetch();
    $link = inviteLink($code);

    $out = ["invite" => inviteOut($invite), "code" => $code, "link" => $link];
    if ($email !== null) {
        $stmt = $pdo->prepare("SELECT name FROM catalogs WHERE id = ?");
        $stmt->execute([$catalogId]);
        $catalogName = (string) $stmt->fetchColumn();
        $inviter = $invite["created_by_name"] ?: "El propietario";
        $roleEs = ROLE_NAMES_ES[$role];
        $out["emailSent"] = sendMail($email, "Invitación al catálogo «{$catalogName}» en BioDex",
            "Hola:\n\n{$inviter} te invita al catálogo «{$catalogName}» de BioDex como {$roleEs}.\n\n"
            . "Para unirte abre este enlace (si no tienes sesión, entra o crea la cuenta con este mismo email):\n\n{$link}\n\n"
            . "Caduca en {$days} " . ($days === 1 ? "día" : "días") . " y vale para una sola persona. Si no esperabas esta invitación, ignora este mensaje.\n");
    }
    respond($out, 201);
}

// --- /leave ----------------------------------------------------------------------------------------

if ($sub === "leave") {
    if ($method !== "POST" || $itemId !== null) apiMethodNotAllowed("POST");
    $role = requireCatalogPermission($pdo, $catalogId, $userId, "view");
    if ($role === "owner") apiError("El propietario no puede salir: transfiere antes la propiedad a un custodio.", 409);
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM catalog_members WHERE catalog_id = ? AND user_id = ?")->execute([$catalogId, $userId]);
    logAccess($pdo, $userId, "member_left", $catalogId, "user:{$userId} ({$role})");
    $pdo->commit();
    respond(["ok" => true]);
}

// --- /transfer -----------------------------------------------------------------------------------

if ($sub === "transfer") {
    if ($method !== "POST" || $itemId !== null) apiMethodNotAllowed("POST");
    requireCatalogRole($pdo, $catalogId, $userId, ["owner"]);
    $body = requireJsonBody("{userId, password}");
    $newOwner = filter_var($body["userId"] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if ($newOwner === false) apiError("«userId» debe ser el id de un miembro.", 400);
    if ($newOwner === $userId) apiError("Ya eres el propietario.", 400);

    // Contraseña otra vez: transferir no se deshace sin la otra persona. Mismo límite que el login.
    $stmt = $pdo->prepare("SELECT email, password_hash FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $me = $stmt->fetch();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL " . TRANSFER_WINDOW_MINUTES . " MINUTE)");
    $stmt->execute([$me["email"]]);
    if ((int) $stmt->fetchColumn() >= TRANSFER_MAX_FAILURES) {
        header("Retry-After: " . (TRANSFER_WINDOW_MINUTES * 60));
        apiError("Demasiados intentos fallidos. Prueba de nuevo en " . TRANSFER_WINDOW_MINUTES . " minutos.", 429);
    }
    if (!password_verify((string) ($body["password"] ?? ""), $me["password_hash"])) {
        $pdo->prepare("INSERT INTO login_attempts (email, ip) VALUES (?, ?)")->execute([$me["email"], clientIp() ?? ""]);
        logAccess($pdo, $userId, "login_failed", $catalogId, $me["email"]);
        // 403 y no 401: la sesión sigue siendo buena (un 401 haría que la app pidiese entrar de nuevo).
        apiError("La contraseña no es correcta.", 403);
    }

    $pdo->beginTransaction();
    $m = memberRow($pdo, $catalogId, $newOwner, true);
    if (!$m || $m["role"] !== "custodian") {
        $pdo->rollBack();
        apiError("La propiedad solo se transfiere a un custodio del catálogo.", 422);
    }
    $pdo->prepare("UPDATE catalogs SET owner_id = ?, content_seq = content_seq + 1 WHERE id = ?")->execute([$newOwner, $catalogId]);
    $pdo->prepare("UPDATE catalog_members SET role = 'owner', granted_by = ? WHERE catalog_id = ? AND user_id = ?")
        ->execute([$userId, $catalogId, $newOwner]);
    $pdo->prepare("UPDATE catalog_members SET role = 'custodian', granted_by = ? WHERE catalog_id = ? AND user_id = ?")
        ->execute([$newOwner, $catalogId, $userId]);
    // Los dos siguen viendo exactas: no cambia la época de nadie.
    logAccess($pdo, $userId, "grant_role", $catalogId, "user:{$newOwner} custodian → owner (transferencia)");
    logAccess($pdo, $userId, "revoke_role", $catalogId, "user:{$userId} owner → custodian (transferencia)");
    $pdo->commit();
    respond(["ok" => true, "ownerId" => $newOwner, "role" => "custodian"]);
}

// --- /audit ----------------------------------------------------------------------------------------

if ($method !== "GET" || $itemId !== null) apiMethodNotAllowed("GET");
requireCatalogRole($pdo, $catalogId, $userId, ["owner"]);
$limit = filter_var($_GET["limit"] ?? 50, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 200]]);
if ($limit === false) apiError("«limit» debe estar entre 1 y 200.", 400);
$before = isset($_GET["before"]) ? filter_var($_GET["before"], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) : null;
if ($before === false) apiError("«before» debe ser el id de una entrada.", 400);

$stmt = $pdo->prepare(
    "SELECT a.id, a.created_at, a.user_id, u.name AS user_name, a.action, a.target
       FROM access_log a LEFT JOIN users u ON u.id = a.user_id
      WHERE a.catalog_id = ?" . ($before !== null ? " AND a.id < ?" : "") . "
      ORDER BY a.id DESC LIMIT " . ($limit + 1)
);
$stmt->execute($before !== null ? [$catalogId, $before] : [$catalogId]);
$rows = $stmt->fetchAll();
$hasMore = count($rows) > $limit;
if ($hasMore) array_pop($rows);

// Nombres de las personas y especies que aparecen en los targets, de una vez.
$userIds = [];
$taxa = [];
foreach ($rows as $r) {
    preg_match_all('/user:(\d+)/', (string) $r["target"], $mm);
    foreach ($mm[1] as $id) $userIds[(int) $id] = true;
    if (in_array($r["action"], ["set_level", "set_reference", "photo_review"], true) && preg_match('/^([^:]+):/', (string) $r["target"], $t)) $taxa[$t[1]] = true;
}
$names = [];
if ($userIds) {
    $ids = array_keys($userIds);
    $q = $pdo->prepare("SELECT id, name FROM users WHERE id IN (" . implode(",", array_fill(0, count($ids), "?")) . ")");
    $q->execute($ids);
    foreach ($q->fetchAll() as $u) $names[(int) $u["id"]] = $u["name"];
}
$species = [];
if ($taxa) {
    $ids = array_keys($taxa);
    $q = $pdo->prepare("SELECT taxonID, canonical_name FROM species WHERE taxonID IN (" . implode(",", array_fill(0, count($ids), "?")) . ")");
    $q->execute(array_map("strval", $ids));
    foreach ($q->fetchAll() as $s) $species[$s["taxonID"]] = $s["canonical_name"];
}

respond([
    "items" => array_map(fn($r) => [
        "id" => (int) $r["id"], "at" => $r["created_at"], "userId" => $r["user_id"] !== null ? (int) $r["user_id"] : null,
        "userName" => $r["user_name"], "action" => $r["action"], "target" => $r["target"],
        "detail" => auditDetail($r, $names, $species),
    ], $rows),
    "hasMore" => $hasMore,
    "before"  => $rows ? (int) end($rows)["id"] : null,
]);
