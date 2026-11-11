<?php
// ================================================================================================
// audit.php — registro en access_log de lo que docs/convenciones.md exige auditar: vistas de coordenadas
// exactas, exportaciones completas, cambios de nivel de sensibilidad y de rol, logins fallidos y
// invitaciones y altas y bajas de miembros; la evaluación UICN de referencia de cada especie;
// aceptar o rechazar una foto de iNaturalist propuesta por sinónimo.
// ================================================================================================

const AUDIT_ACTIONS = ["view_exact", "export_full", "set_level", "grant_role", "revoke_role", "login_failed",
                       "invite_created", "invite_revoked", "member_joined", "member_removed", "member_left",
                       "set_reference",    // Evaluación UICN de referencia del catálogo
                       "photo_review",     // Revisión de una foto de respaldo (iNaturalist o GBIF)
                       "set_parameter",    // Fijar, cambiar o borrar un parámetro de especie
                       "set_locations"];   // Confirmar o cambiar el nº de localidades de una especie

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

/**
 * Vista de coordenadas exactas ajenas: UNA fila por usuario, catálogo, objeto y día. $subject = "" para el pull
 * (target = nº de registros) o «EOO/AOO <taxonID>» / «Subpoblaciones <taxonID>» (target = «<subject>: n»).
 * Otra vista del mismo objeto en el mismo día no añade fila: se queda con el mayor nº de registros recibidos.
 */
function logViewExact(PDO $pdo, int $userId, string $catalogId, string $subject, int $count): void {
    $target = $subject === "" ? (string) $count : "{$subject}: {$count}";
    $match = $subject === "" ? "target REGEXP '^[0-9]+$'" : "target LIKE ?";
    $params = [$userId, $catalogId];
    if ($subject !== "") $params[] = addcslashes($subject, "%_\\") . ": %";
    $stmt = $pdo->prepare(
        "SELECT id, target FROM access_log
          WHERE user_id = ? AND catalog_id = ? AND action = 'view_exact' AND created_at >= CURDATE() AND {$match}
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) {
        logAccess($pdo, $userId, "view_exact", $catalogId, $target);
        return;
    }
    $previous = (int) preg_replace('/^.*: /', '', (string) $row["target"]);
    if ($count > $previous) $pdo->prepare("UPDATE access_log SET target = ? WHERE id = ?")->execute([$target, $row["id"]]);
}
