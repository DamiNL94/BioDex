<?php
// ================================================================================================
// audit.php — registro en access_log de lo que docs/convenciones.md exige auditar: vistas de coordenadas
// exactas, exportaciones completas, cambios de nivel de sensibilidad y de rol, logins fallidos y (F6)
// invitaciones y altas y bajas de miembros; (F8b) la evaluación UICN de referencia de cada especie;
// (F11b) aceptar o rechazar una foto de iNaturalist propuesta por sinónimo.
// ================================================================================================

const AUDIT_ACTIONS = ["view_exact", "export_full", "set_level", "grant_role", "revoke_role", "login_failed",
                       "invite_created", "invite_revoked", "member_joined", "member_removed", "member_left",
                       "set_reference",    // F8b: evaluación UICN de referencia del catálogo
                       "photo_review"];    // F11b/F11d: revisión de una foto de respaldo (iNaturalist o GBIF)

/** IP del cliente tal como la ve PHP (nginx pasa REMOTE_ADDR). null en CLI. */
function clientIp(): ?string {
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;
    return is_string($ip) && $ip !== "" ? $ip : null;
}

/**
 * Añade una entrada a access_log. $target identifica el objeto (occurrenceID, taxonID, id de
 * usuario afectado, email en un login fallido...) y se recorta a 255 caracteres.
 */
function logAccess(PDO $pdo, ?int $userId, string $action, ?string $catalogId, ?string $target): void {
    if (!in_array($action, AUDIT_ACTIONS, true)) {
        throw new InvalidArgumentException("Acción de auditoría desconocida: {$action}");
    }
    $pdo->prepare(
        "INSERT INTO access_log (user_id, catalog_id, action, target, ip) VALUES (?, ?, ?, ?, ?)"
    )->execute([$userId, $catalogId, $action, $target === null ? null : mb_substr($target, 0, 255), clientIp()]);
}
