<?php
// ================================================================================================
// teams.php — equipos (F6): códigos de invitación, ORCID, orden de roles y época de visibilidad.
// ------------------------------------------------------------------------------------------------
// Códigos: 128 bits aleatorios en base32 (RFC 4648, sin relleno: 26 caracteres). En la BD solo su
// SHA-256; el código en claro sale UNA vez, en la respuesta que crea la invitación (y en el correo).
//
// Época de visibilidad (catalog_members.visibility_epoch): sube cuando lo que un miembro puede ver de
// las marcas AJENAS cambia —gana o pierde acceso a exactas, o cambia el nivel de una especie y él no
// es de confianza—. El pull la devuelve y el móvil, si no coincide con la suya, borra las marcas ajenas
// de ese catálogo y las vuelve a bajar desde 0: así una coordenada exacta que ya no le toca no se
// queda en el dispositivo (docs/sync.md). Las propias nunca se tocan.
// ================================================================================================

require_once __DIR__ . "/../Config/db.php";   // sqlPlaceholders()
require_once __DIR__ . "/sensitivity.php";   // exactAccess.roles de shared/sensitivity.json

const INVITE_CODE_BYTES   = 16;     // 128 bits
const INVITE_MAX_USES     = 20;     // enlace; una invitación por email vale una vez
const INVITE_DAYS_DEFAULT = 7;
const INVITE_DAYS_MAX     = 30;
const INVITE_MAX_FAILURES = 10;     // códigos inexistentes por usuario y hora → 429
const INVITE_ROLES        = ["editor", "viewer"];
const MEMBER_ROLES        = ["custodian", "editor", "viewer"];   // los que el owner puede asignar

/** Rango de poder de un rol: subir de rango es grant_role; bajar, revoke_role. */
const ROLE_RANK = ["viewer" => 1, "editor" => 2, "custodian" => 3, "owner" => 4];

/** Base32 RFC 4648 sin relleno. */
function base32Encode(string $bytes): string {
    $alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
    $bits = "";
    foreach (str_split($bytes) as $b) $bits .= str_pad(decbin(ord($b)), 8, "0", STR_PAD_LEFT);
    $out = "";
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, "0"))];
    return $out;
}

function inviteNewCode(): string {
    return base32Encode(random_bytes(INVITE_CODE_BYTES));
}

/**
 * Código tal como lo teclea o pega alguien → forma canónica (mayúsculas, sin espacios ni guiones), o
 * null si no puede ser un código. Admite también el enlace entero (…/#/unirse/<código>).
 */
function inviteNormalizeCode(mixed $raw): ?string {
    if (!is_string($raw)) return null;
    if (preg_match('#/unirse/([^/?\s]+)#', $raw, $m)) $raw = rawurldecode($m[1]);
    $code = strtoupper(preg_replace('/[\s-]+/', "", $raw));
    return preg_match('/^[A-Z2-7]{26}$/', $code) ? $code : null;
}

function inviteCodeHash(string $code): string {
    return hash("sha256", $code);
}

/** Enlace de invitación de la PWA. */
function inviteLink(string $code): string {
    $base = rtrim(biodex_env(["APP_URL"]) ?? "http://localhost:8090", "/");
    return "{$base}/#/unirse/{$code}";
}

/**
 * ORCID válido: 0000-0000-0000-000X con el dígito de control ISO 7064 MOD 11-2 (el último carácter; X =
 * 10). Devuelve la forma canónica (X en mayúscula) o null.
 */
function orcidNormalize(mixed $raw): ?string {
    if (!is_string($raw)) return null;
    $orcid = strtoupper(trim($raw));
    if (preg_match('#^https?://orcid\.org/(.+)$#i', $orcid, $m)) $orcid = strtoupper($m[1]);
    if (!preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/', $orcid)) return null;
    $digits = str_replace("-", "", $orcid);
    $total = 0;
    for ($i = 0; $i < 15; $i++) $total = ($total + (int) $digits[$i]) * 2;
    $check = (12 - $total % 11) % 11;
    $expected = $check === 10 ? "X" : (string) $check;
    return $digits[15] === $expected ? $orcid : null;
}

/** ¿Ve el rol las coordenadas exactas ajenas? (exactAccess.roles de shared/sensitivity.json) */
function roleSeesExact(string $role): bool {
    return canSeeExact($role, false);
}

/** Sube la época de un miembro. Dentro de la transacción del cambio que la provoca. */
function bumpMemberEpoch(PDO $pdo, string $catalogId, int $userId): void {
    $pdo->prepare("UPDATE catalog_members SET visibility_epoch = visibility_epoch + 1 WHERE catalog_id = ? AND user_id = ?")
        ->execute([$catalogId, $userId]);
}

/** Sube la época de todos los miembros SIN acceso a exactas (cambio de nivel de una especie). */
function bumpUntrustedEpochs(PDO $pdo, string $catalogId): int {
    $trusted = sensitivityConfig()["exactAccess"]["roles"];
    $in = sqlPlaceholders(count($trusted));
    $stmt = $pdo->prepare("UPDATE catalog_members SET visibility_epoch = visibility_epoch + 1
                            WHERE catalog_id = ? AND role NOT IN ({$in})");
    $stmt->execute([$catalogId, ...$trusted]);
    return $stmt->rowCount();
}

/** Fallos de código de invitación del usuario en la última hora. */
function inviteRecentFailures(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invite_attempts
                            WHERE user_id = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function inviteRecordFailure(PDO $pdo, int $userId): void {
    $pdo->prepare("INSERT INTO invite_attempts (user_id) VALUES (?)")->execute([$userId]);
    // Limpieza oportunista: lo de hace más de un día ya no cuenta para nada.
    $pdo->exec("DELETE FROM invite_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
}
