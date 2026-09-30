<?php
// ================================================================================================
// Pruebas de F6 (equipos): docker compose exec php php bin/test-f6.php
// Imprime OK/FAIL por comprobación y sale con código 1 si alguna falla. A es la propietaria; B, C y D,
// invitados. Consulta EIDOS (lince) y la bandeja de Mailpit (http://mailpit:8025). Crea usuarios
// f6-*@biodex.test y el catálogo «test-f6 …», y los borra al terminar (y al empezar, por si quedó algo).
// ================================================================================================

if (PHP_SAPI !== "cli") exit(1);

require_once __DIR__ . "/../Config/db.php";
require_once __DIR__ . "/../Lib/api.php";        // uuidV4()
require_once __DIR__ . "/../Lib/migrate.php";
require_once __DIR__ . "/../Lib/teams.php";      // orcidNormalize()
require_once __DIR__ . "/test-lib.php";

const LYNX = "11943";
const PASSWORD = "correcta-horse-battery";
const MAILPIT = "http://mailpit:8025";

$pdo = db();
runMigrations($pdo);

function cleanup(PDO $pdo): void {
    $pdo->exec("DELETE FROM occurrences WHERE catalog_id IN (SELECT id FROM catalogs WHERE name LIKE 'test-f6 %')");
    $pdo->exec("DELETE FROM access_log WHERE catalog_id IN (SELECT id FROM catalogs WHERE name LIKE 'test-f6 %')");
    $pdo->exec("DELETE FROM access_log WHERE target LIKE 'f6-%@biodex.test'");
    $pdo->exec("DELETE FROM catalogs WHERE name LIKE 'test-f6 %'");
    $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'f6-%@biodex.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'f6-%@biodex.test'");
}
cleanup($pdo);

function mark(float $lat, float $lon): array {
    return [
        "occurrenceID" => uuidV4(), "taxonID" => LYNX, "eventDate" => "2026-05-01T08:30:00+02:00",
        "decimalLatitude" => $lat, "decimalLongitude" => $lon, "coordinateUncertaintyInMeters" => 8,
        "clientUpdatedAt" => gmdate("Y-m-d\TH:i:s.v\Z"),
    ];
}

function epoch(PDO $pdo, string $cat, int $userId): ?int {
    $stmt = $pdo->prepare("SELECT visibility_epoch FROM catalog_members WHERE catalog_id = ? AND user_id = ?");
    $stmt->execute([$cat, $userId]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (int) $v;
}

function logged(PDO $pdo, string $cat, string $action, string $targetLike): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM access_log WHERE catalog_id = ? AND action = ? AND target LIKE ?");
    $stmt->execute([$cat, $action, $targetLike]);
    return (int) $stmt->fetchColumn();
}

/** Texto del último correo de Mailpit para $to, o null. */
function mailpitText(string $to): ?string {
    $get = function (string $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $raw = curl_exec($ch);
        curl_close($ch);
        return json_decode((string) $raw, true);
    };
    $list = $get(MAILPIT . "/api/v1/search?query=" . rawurlencode("to:\"{$to}\""));
    $id = $list["messages"][0]["ID"] ?? null;
    return $id ? ($get(MAILPIT . "/api/v1/message/{$id}")["Text"] ?? null) : null;
}

// ------------------------------------------------------------------------------------------------
section("Usuarios y catálogo");
$suffix = bin2hex(random_bytes(4));
$jars = ["a" => [], "b" => [], "c" => [], "d" => []];
$ids = [];
$emails = [];
foreach ($jars as $k => &$jar) {
    $emails[$k] = "f6-{$k}-{$suffix}@biodex.test";
    [$c] = http("POST", "/api/auth/register", ["email" => $emails[$k], "password" => PASSWORD, "name" => "F6 " . strtoupper($k)], $jar);
    check("registro de " . strtoupper($k), $c === 201, "HTTP {$c}");
    $ids[$k] = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($emails[$k]))->fetchColumn();
}
unset($jar);
[$a, $b, $c_, $d] = [&$jars["a"], &$jars["b"], &$jars["c"], &$jars["d"]];

[$c, $r] = http("POST", "/api/catalogs", ["name" => "test-f6 {$suffix}", "taxonIDs" => [LYNX]], $a);
check("A crea el catálogo con el lince", $c === 201, "HTTP {$c} " . json_encode($r));
$cat = $r["catalog"]["id"] ?? "";
[$c] = http("PUT", "/api/catalogs/{$cat}/species/" . LYNX . "/sensitivity", ["level" => 2, "reason" => ""], $a);
check("lince en nivel 2", $c === 200, "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
section("Invitación por email");
foreach ([
    [["role" => "custodian"], "rol custodian"],
    [["role" => "editor", "email" => "no-es-email"], "email mal formado"],
    [["role" => "editor", "email" => $emails["b"], "maxUses" => 3], "email con maxUses 3"],
    [["role" => "viewer", "maxUses" => 21], "enlace con 21 usos"],
    [["role" => "viewer", "days" => 31], "31 días"],
] as [$body, $label]) {
    [$c] = http("POST", "/api/catalogs/{$cat}/invites", $body, $a);
    check("{$label} → 400", $c === 400, "HTTP {$c}");
}
[$c] = http("POST", "/api/catalogs/{$cat}/invites", ["role" => "editor"], $b);
check("B (sin rol) no puede invitar → 403", $c === 403, "HTTP {$c}");

[$c, $r] = http("POST", "/api/catalogs/{$cat}/invites", ["role" => "editor", "email" => strtoupper($emails["b"])], $a);
check("A invita a B por email → 201", $c === 201, "HTTP {$c} " . json_encode($r));
$codeB = $r["code"] ?? "";
check("…código de 128 bits en base32 (26 caracteres)", preg_match('/^[A-Z2-7]{26}$/', $codeB) === 1, $codeB);
check("…enlace #/unirse/<código> y correo enviado", str_ends_with($r["link"] ?? "", "/#/unirse/{$codeB}") && ($r["emailSent"] ?? false) === true, json_encode($r));
check("…email normalizado y 1 uso", ($r["invite"]["email"] ?? null) === $emails["b"] && ($r["invite"]["maxUses"] ?? null) === 1, json_encode($r["invite"] ?? null));
$stored = $pdo->query("SELECT code_hash FROM catalog_invites WHERE catalog_id = " . $pdo->quote($cat))->fetchColumn();
check("…en la BD solo el SHA-256 del código", $stored === hash("sha256", $codeB));
$mail = mailpitText($emails["b"]);
check("…y el correo en Mailpit lleva el enlace", $mail !== null && str_contains($mail, "/#/unirse/{$codeB}"), (string) $mail);
check("invite_created en el registro", logged($pdo, $cat, "invite_created", "%por email a {$emails["b"]}%") === 1);

[$c, $r] = http("GET", "/api/catalogs/{$cat}/invites", null, $a);
check("pendientes: 1, sin el código", $c === 200 && count($r["invites"] ?? []) === 1 && !str_contains(json_encode($r), $codeB)
    && !array_key_exists("code", $r["invites"][0] ?? []), json_encode($r));

[$c, $r] = http("POST", "/api/invites/preview", ["code" => $codeB], $b);
check("B ve la invitación antes de aceptar (catálogo, propietaria, rol)", $c === 200 && ($r["catalog"]["name"] ?? null) === "test-f6 {$suffix}"
    && ($r["catalog"]["ownerName"] ?? null) === "F6 A" && ($r["role"] ?? null) === "editor", "HTTP {$c} " . json_encode($r));
[$c, $r] = http("POST", "/api/invites/accept", ["code" => "https://biodex.example/#/unirse/" . strtolower($codeB)], $b);
check("B acepta (con el enlace entero, en minúsculas) → editor", $c === 200 && ($r["joined"] ?? null) === true && ($r["role"] ?? null) === "editor", "HTTP {$c} " . json_encode($r));
[$c, $r] = http("POST", "/api/invites/accept", ["code" => $codeB], $b);
check("B otra vez → 200 sin cambios", $c === 200 && ($r["alreadyMember"] ?? null) === true && ($r["joined"] ?? null) === false, "HTTP {$c} " . json_encode($r));
[$c] = http("POST", "/api/invites/accept", ["code" => $codeB], $c_);
check("C con el código de B → 403 (email no coincide)", $c === 403, "HTTP {$c}");
check("member_joined en el registro", logged($pdo, $cat, "member_joined", "user:{$ids["b"]} (editor%") === 1);
[$c] = http("POST", "/api/catalogs/{$cat}/invites", ["role" => "viewer", "email" => $emails["b"]], $a);
check("invitar por email a quien ya es miembro → 409", $c === 409, "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
section("Enlaces: usos, revocación y límite de intentos");
[$c, $r] = http("POST", "/api/catalogs/{$cat}/invites", ["role" => "viewer", "maxUses" => 1, "days" => 1], $a);
check("A crea un enlace de 1 uso → 201", $c === 201 && array_key_exists("email", $r["invite"] ?? []) && $r["invite"]["email"] === null, "HTTP {$c}");
$link1 = $r["code"] ?? "";
[$c, $r] = http("POST", "/api/invites/accept", ["code" => $link1], $c_);
check("C lo acepta → viewer", $c === 200 && ($r["role"] ?? null) === "viewer", "HTTP {$c} " . json_encode($r));
[$c] = http("POST", "/api/invites/accept", ["code" => $link1], $d);
check("segundo uso (D) → 410", $c === 410, "HTTP {$c}");

[$c, $r] = http("POST", "/api/catalogs/{$cat}/invites", ["role" => "editor", "maxUses" => 5], $a);
$link5 = $r["code"] ?? "";
$inviteId = $r["invite"]["id"] ?? 0;
[$c] = http("DELETE", "/api/catalogs/{$cat}/invites/{$inviteId}", null, $a);
check("A revoca un enlace de 5 usos", $c === 200, "HTTP {$c}");
check("…invite_revoked en el registro", logged($pdo, $cat, "invite_revoked", "invite:{$inviteId}") === 1);
[$c] = http("POST", "/api/invites/accept", ["code" => $link5], $d);
check("código revocado → 410", $c === 410, "HTTP {$c}");
[$c, $r] = http("GET", "/api/catalogs/{$cat}/invites", null, $a);
check("pendientes: ni el agotado ni el revocado", $c === 200 && count($r["invites"] ?? []) === 0, json_encode($r));
$pdo->prepare("UPDATE catalog_invites SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?")->execute([$inviteId]);

[$c, $r] = http("POST", "/api/catalogs/{$cat}/invites", ["role" => "editor", "maxUses" => 3], $a);
$pdo->prepare("UPDATE catalog_invites SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?")->execute([$r["invite"]["id"] ?? 0]);
[$c] = http("POST", "/api/invites/accept", ["code" => $r["code"] ?? ""], $d);
check("código caducado → 410", $c === 410, "HTTP {$c}");

$codes = [];
for ($i = 1; $i <= 11; $i++) {
    [$c] = http("POST", "/api/invites/accept", ["code" => inviteNewCode()], $d);
    $codes[] = $c;
}
check("10 códigos inexistentes → 404; el 11.º → 429", array_slice($codes, 0, 10) === array_fill(0, 10, 404) && $codes[10] === 429, json_encode($codes));
[$c, $r, $h] = http("POST", "/api/invites/preview", ["code" => $link5], $d);
check("…y ya no deja ni ver una invitación (429 con Retry-After)", $c === 429 && isset($h["retry-after"]), "HTTP {$c}");
[$c] = http("POST", "/api/invites/preview", ["code" => "basura"], $b);
check("el límite es por usuario: B aún puede (código mal formado → 404)", $c === 404, "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
section("Miembros y roles");
[$c, $r] = http("GET", "/api/catalogs/{$cat}/members", null, $a);
$members = array_column($r["members"] ?? [], null, "userId");
check("A ve 3 miembros con email, rol y verificación", $c === 200 && count($members) === 3
    && ($members[$ids["b"]]["email"] ?? null) === $emails["b"] && ($members[$ids["c"]]["role"] ?? null) === "viewer"
    && ($members[$ids["b"]]["emailVerified"] ?? null) === false && array_key_exists("joinedAt", $members[$ids["a"]] ?? []), json_encode($r));
check("…la propietaria la primera", ($r["members"][0]["role"] ?? null) === "owner");
[$c, $r] = http("GET", "/api/catalogs/{$cat}/members", null, $c_);
check("C (lector) ve los miembros sin emails", $c === 200 && count($r["members"] ?? []) === 3 && !str_contains(json_encode($r), "@biodex.test"), json_encode($r));

$epochB0 = epoch($pdo, $cat, $ids["b"]);
[$c] = http("PUT", "/api/catalogs/{$cat}/members/{$ids["b"]}", ["role" => "custodian"], $a);
check("B a custodio sin email verificado → 422", $c === 422, "HTTP {$c}");
$pdo->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = ?")->execute([$ids["b"]]);
[$c, $r] = http("PUT", "/api/catalogs/{$cat}/members/{$ids["b"]}", ["role" => "custodian"], $a);
check("…verificado → 200", $c === 200 && ($r["member"]["role"] ?? null) === "custodian", "HTTP {$c} " . json_encode($r));
check("…grant_role en el registro", logged($pdo, $cat, "grant_role", "user:{$ids["b"]} editor → custodian") === 1);
check("…y su época sube (gana exactas)", epoch($pdo, $cat, $ids["b"]) === $epochB0 + 1, $epochB0 . " → " . epoch($pdo, $cat, $ids["b"]));
[$c] = http("PUT", "/api/catalogs/{$cat}/members/{$ids["a"]}", ["role" => "editor"], $a);
check("A no puede cambiar su propio rol → 403", $c === 403, "HTTP {$c}");
[$c] = http("PUT", "/api/catalogs/{$cat}/members/{$ids["a"]}", ["role" => "viewer"], $b);
check("B (custodio) no gestiona miembros → 403", $c === 403, "HTTP {$c}");
[$c] = http("PUT", "/api/catalogs/{$cat}/members/{$ids["c"]}", ["role" => "owner"], $a);
check("rol owner por PUT → 400", $c === 400, "HTTP {$c}");
[$c] = http("PUT", "/api/catalogs/{$cat}/members/{$ids["d"]}", ["role" => "editor"], $a);
check("quien no es miembro → 404", $c === 404, "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
section("Confianza y época de visibilidad");
$m1 = mark(37.1234567, -3.4567891);
[$c, $r] = http("POST", "/api/sync/push", ["catalogId" => $cat, "occurrences" => [$m1]], $a);
check("A marca un lince (nivel 2)", $c === 200 && ($r["results"][0]["status"] ?? null) === "accepted", json_encode($r));

$pullB = function () use ($cat, &$b) {
    [$c, $r] = http("GET", "/api/sync/pull?catalogId={$cat}&since=0", null, $b);
    $item = array_column($r["items"] ?? [], null, "occurrenceID");
    return [$c, $r, $item];
};
[$c, $r, $items] = $pullB();
$x = $items[$m1["occurrenceID"]] ?? [];
check("B (custodio) recibe la exacta y su visibilityEpoch", $c === 200 && ($x["generalized"] ?? null) === false
    && ($x["decimalLatitude"] ?? null) === 37.1234567 && ($r["visibilityEpoch"] ?? null) === epoch($pdo, $cat, $ids["b"]), json_encode($r));
$epochCustodian = $r["visibilityEpoch"] ?? 0;

[$c] = http("PUT", "/api/catalogs/{$cat}/members/{$ids["b"]}", ["role" => "editor"], $a);
check("A baja a B a editor", $c === 200, "HTTP {$c}");
check("…revoke_role en el registro", logged($pdo, $cat, "revoke_role", "user:{$ids["b"]} custodian → editor") === 1);
[$c, $r, $items] = $pullB();
$x = $items[$m1["occurrenceID"]] ?? [];
check("…el siguiente pull trae otra visibilityEpoch", ($r["visibilityEpoch"] ?? null) === $epochCustodian + 1, json_encode($r["visibilityEpoch"] ?? null));
check("…y solo la generalizada", ($x["generalized"] ?? null) === true && ($x["decimalLatitude"] ?? null) === 37.15 && ($x["decimalLongitude"] ?? null) === -3.45, json_encode($x));
[$c] = http("PUT", "/api/catalogs/{$cat}/members/{$ids["c"]}", ["role" => "editor"], $a);
check("lector → editor no toca la época (ninguno ve exactas)", $c === 200 && epoch($pdo, $cat, $ids["c"]) === 1, (string) epoch($pdo, $cat, $ids["c"]));

[$c] = http("PUT", "/api/catalogs/{$cat}/species/" . LYNX . "/sensitivity", ["level" => 4, "reason" => "prueba f6: bajada"], $a);
check("A baja el lince a nivel 4 (con motivo)", $c === 200, "HTTP {$c}");
$before = ["a" => epoch($pdo, $cat, $ids["a"]), "b" => epoch($pdo, $cat, $ids["b"]), "c" => epoch($pdo, $cat, $ids["c"])];
[$c, $r, $items] = $pullB();
check("…B recibe ya la exacta (nivel 4)", ($items[$m1["occurrenceID"]]["generalized"] ?? null) === false, json_encode($items[$m1["occurrenceID"]] ?? null));
[$c] = http("PUT", "/api/catalogs/{$cat}/species/" . LYNX . "/sensitivity", ["level" => 2, "reason" => ""], $a);
check("A sube el lince de nivel 4 a 2", $c === 200, "HTTP {$c}");
check("…la época de los no confiados (B, C) sube", epoch($pdo, $cat, $ids["b"]) === $before["b"] + 1 && epoch($pdo, $cat, $ids["c"]) === $before["c"] + 1,
    json_encode($before) . " → " . epoch($pdo, $cat, $ids["b"]) . "/" . epoch($pdo, $cat, $ids["c"]));
check("…la de la propietaria no", epoch($pdo, $cat, $ids["a"]) === $before["a"]);
[$c, $r, $items] = $pullB();
check("…y B vuelve a recibir la generalizada con la época nueva", ($items[$m1["occurrenceID"]]["generalized"] ?? null) === true
    && ($r["visibilityEpoch"] ?? null) === $before["b"] + 1, json_encode($r));

// ------------------------------------------------------------------------------------------------
section("Salir, transferir y expulsar");
[$c] = http("POST", "/api/catalogs/{$cat}/leave", null, $a);
check("la propietaria no puede salir → 409", $c === 409, "HTTP {$c}");
[$c] = http("POST", "/api/catalogs/{$cat}/leave", null, $c_);
check("C sale del catálogo", $c === 200, "HTTP {$c}");
check("…member_left en el registro", logged($pdo, $cat, "member_left", "user:{$ids["c"]} (editor)") === 1);
[$c, $r] = http("GET", "/api/sync/pull?catalogId={$cat}&since=0", null, $c_);
check("…y su pull da 403 not_member", $c === 403 && ($r["code"] ?? null) === "not_member", "HTTP {$c}");

[$c] = http("POST", "/api/catalogs/{$cat}/transfer", ["userId" => $ids["b"], "password" => PASSWORD], $a);
check("transferir a un editor → 422", $c === 422, "HTTP {$c}");
http("PUT", "/api/catalogs/{$cat}/members/{$ids["b"]}", ["role" => "custodian"], $a);
[$c] = http("POST", "/api/catalogs/{$cat}/transfer", ["userId" => $ids["b"], "password" => "otra-cosa-distinta"], $a);
check("contraseña incorrecta → 403 (no 401: la sesión sigue)", $c === 403, "HTTP {$c}");
[$c] = http("POST", "/api/catalogs/{$cat}/transfer", ["userId" => $ids["b"], "password" => PASSWORD], $b);
check("un custodio no puede transferir → 403", $c === 403, "HTTP {$c}");
[$c, $r] = http("POST", "/api/catalogs/{$cat}/transfer", ["userId" => $ids["b"], "password" => PASSWORD], $a);
check("A transfiere a B (custodio) con su contraseña → 200", $c === 200, "HTTP {$c} " . json_encode($r));
$owner = (int) $pdo->query("SELECT owner_id FROM catalogs WHERE id = " . $pdo->quote($cat))->fetchColumn();
[$c, $r] = http("GET", "/api/catalogs/{$cat}/members", null, $b);
$roles = array_column($r["members"] ?? [], "role", "userId");
check("…B es owner y A custodio", $owner === $ids["b"] && ($roles[$ids["b"]] ?? null) === "owner" && ($roles[$ids["a"]] ?? null) === "custodian", json_encode($roles));
[$c] = http("POST", "/api/catalogs/{$cat}/leave", null, $b);
check("…ahora es B quien no puede salir", $c === 409, "HTTP {$c}");
[$c] = http("DELETE", "/api/catalogs/{$cat}/members/{$ids["b"]}", null, $a);
check("A (custodio) no puede expulsar → 403", $c === 403, "HTTP {$c}");
[$c] = http("DELETE", "/api/catalogs/{$cat}/members/{$ids["a"]}", null, $b);
check("B expulsa a A → 200", $c === 200, "HTTP {$c}");
check("…member_removed en el registro", logged($pdo, $cat, "member_removed", "user:{$ids["a"]} (custodian)") === 1);
[$c, $r] = http("GET", "/api/sync/pull?catalogId={$cat}&since=0", null, $a);
check("…y el pull de A da 403 not_member", $c === 403 && ($r["code"] ?? null) === "not_member", "HTTP {$c}");
[$c] = http("DELETE", "/api/catalogs/{$cat}/members/{$ids["b"]}", null, $b);
check("B no se expulsa a sí mismo → 403", $c === 403, "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
section("Registro");
[$c, $r] = http("GET", "/api/catalogs/{$cat}/audit?limit=50", null, $b);
$actions = array_count_values(array_column($r["items"] ?? [], "action"));
check("B (owner) lee el registro", $c === 200 && isset($actions["invite_created"], $actions["invite_revoked"], $actions["member_joined"],
    $actions["grant_role"], $actions["revoke_role"], $actions["member_left"], $actions["member_removed"], $actions["set_level"]), json_encode($actions));
$details = implode(" | ", array_column($r["items"] ?? [], "detail"));
check("…legible: nombres y roles en castellano, especie por su nombre", str_contains($details, "F6 B: editor → custodio")
    && str_contains($details, "Lynx pardinus: 4 → 2") && !preg_match('/user:\d/', $details), $details);
check("…lo más reciente primero", ($r["items"][0]["action"] ?? null) === "member_removed", json_encode($r["items"][0] ?? null));
[$c, $r] = http("GET", "/api/catalogs/{$cat}/audit?limit=2", null, $b);
[$c2, $r2] = http("GET", "/api/catalogs/{$cat}/audit?limit=2&before=" . ($r["before"] ?? 0), null, $b);
check("…paginado con before", $c2 === 200 && ($r["hasMore"] ?? false) && count($r2["items"] ?? []) === 2
    && $r2["items"][0]["id"] < $r["items"][1]["id"], json_encode([$r["before"] ?? null, $r2["items"][0]["id"] ?? null]));
[$c] = http("GET", "/api/catalogs/{$cat}/audit", null, $a);
check("quien ya no es miembro → 403", $c === 403, "HTTP {$c}");

// ------------------------------------------------------------------------------------------------
section("Perfil y ORCID");
check("ORCID 0000-0002-1825-0097 válido", orcidNormalize("0000-0002-1825-0097") === "0000-0002-1825-0097");
check("ORCID 0000-0002-1825-0098 inválido", orcidNormalize("0000-0002-1825-0098") === null);
check("ORCID con X de control (0000-0002-9079-593X)", orcidNormalize("0000-0002-9079-593x") === "0000-0002-9079-593X");
[$c, $r] = http("PATCH", "/api/auth/me", ["orcid" => "0000-0002-1825-0097", "name" => "  Bea Custodia "], $b);
check("PATCH /auth/me con ORCID válido → 200", $c === 200 && ($r["user"]["orcid"] ?? null) === "0000-0002-1825-0097"
    && ($r["user"]["name"] ?? null) === "Bea Custodia", "HTTP {$c} " . json_encode($r));
[$c] = http("PATCH", "/api/auth/me", ["orcid" => "0000-0002-1825-0098"], $b);
check("…inválido → 400", $c === 400, "HTTP {$c}");
[$c] = http("PATCH", "/api/auth/me", ["name" => ""], $b);
check("…nombre vacío → 400", $c === 400, "HTTP {$c}");
[$c, $r] = http("PATCH", "/api/auth/me", ["orcid" => null], $b);
check("…ORCID null lo borra", $c === 200 && array_key_exists("orcid", $r["user"] ?? []) && $r["user"]["orcid"] === null, json_encode($r));
try {
    $pdo->prepare("UPDATE users SET orcid = 'no-es-orcid' WHERE id = ?")->execute([$ids["b"]]);
    check("la BD rechaza un ORCID mal formado (CHECK)", false);
} catch (PDOException $e) {
    check("la BD rechaza un ORCID mal formado (CHECK)", true);
}

// ------------------------------------------------------------------------------------------------
cleanup($pdo);
echo "\n" . ($failures ? "{$failures} comprobación(es) fallida(s)." : "Todo OK.") . "\n";
exit($failures ? 1 : 0);
