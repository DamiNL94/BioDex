<?php
// ================================================================================================
// authz.php — roles de catálogo (catalog_members) y matriz de permisos.
// ------------------------------------------------------------------------------------------------
// Cada endpoint de catálogo resuelve el rol del usuario con catalogRole()/requireCatalogRole() y
// decide con catalogCan(). Un catálogo con deleted_at no existe para nadie. Que una marca solo la
// edite su autor lo comprueba el endpoint de marcas (no depende del rol).
// ================================================================================================

const CATALOG_ROLES = ["owner", "custodian", "editor", "viewer"];

/** Acción → roles que pueden hacerla. Única fuente de verdad de la matriz de docs/prompts/F2a. */
const CATALOG_PERMISSIONS = [
    "view"           => ["owner", "custodian", "editor", "viewer"],  // ver catálogo y especies
    "add_species"    => ["owner", "custodian", "editor"],
    "remove_species" => ["owner"],
    "edit_catalog"   => ["owner"],
    "set_level"      => ["owner"],                                   // niveles de sensibilidad
    "manage_members" => ["owner"],
    "mark"           => ["owner", "custodian", "editor"],            // crear marcas
    "view_exact"     => ["owner", "custodian"],                      // coordenadas exactas ajenas
    "export_full"    => ["owner", "custodian"],
];

/** Rol del usuario en el catálogo, o null si no es miembro o el catálogo está borrado. */
function catalogRole(PDO $pdo, string $catalogId, int $userId): ?string {
    $stmt = $pdo->prepare(
        "SELECT m.role FROM catalog_members m
           JOIN catalogs c ON c.id = m.catalog_id AND c.deleted_at IS NULL
          WHERE m.catalog_id = ? AND m.user_id = ?"
    );
    $stmt->execute([$catalogId, $userId]);
    $role = $stmt->fetchColumn();
    return $role === false ? null : $role;
}

/**
 * Rol y época de visibilidad del usuario en el catálogo → {role, epoch}, o null si no es miembro o el
 * catálogo está borrado. La época la usa el pull (Lib/teams.php, docs/sync.md).
 */
function catalogMembership(PDO $pdo, string $catalogId, int $userId): ?array {
    $stmt = $pdo->prepare(
        "SELECT m.role, m.visibility_epoch FROM catalog_members m
           JOIN catalogs c ON c.id = m.catalog_id AND c.deleted_at IS NULL
          WHERE m.catalog_id = ? AND m.user_id = ?"
    );
    $stmt->execute([$catalogId, $userId]);
    $row = $stmt->fetch();
    return $row ? ["role" => $row["role"], "epoch" => (int) $row["visibility_epoch"]] : null;
}

/** Rol del usuario si está entre $roles; si no (o no es miembro), 403. */
function requireCatalogRole(PDO $pdo, string $catalogId, int $userId, array $roles): string {
    $role = catalogRole($pdo, $catalogId, $userId);
    if ($role === null || !in_array($role, $roles, true)) {
        apiError("No tienes permiso para esta acción en el catálogo.", 403);
    }
    return $role;
}

/** ¿Puede el rol hacer la acción? Una acción desconocida es un error de programación. */
function catalogCan(?string $role, string $action): bool {
    if (!isset(CATALOG_PERMISSIONS[$action])) {
        throw new InvalidArgumentException("Acción de catálogo desconocida: {$action}");
    }
    return $role !== null && in_array($role, CATALOG_PERMISSIONS[$action], true);
}

/** Atajo: rol del usuario si puede hacer $action en el catálogo; si no, 403. */
function requireCatalogPermission(PDO $pdo, string $catalogId, int $userId, string $action): string {
    if (!isset(CATALOG_PERMISSIONS[$action])) {
        throw new InvalidArgumentException("Acción de catálogo desconocida: {$action}");
    }
    return requireCatalogRole($pdo, $catalogId, $userId, CATALOG_PERMISSIONS[$action]);
}
