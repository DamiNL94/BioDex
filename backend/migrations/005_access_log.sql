-- F2a · Auditoría: vistas exactas, exportaciones completas, cambios de nivel y de rol, logins fallidos.
-- Sin borrado en cascada: el rastro sobrevive a usuarios y catálogos (FK a NULL).

CREATE TABLE IF NOT EXISTS access_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NULL,
    catalog_id CHAR(36) NULL,
    action     ENUM('view_exact','export_full','set_level','grant_role','revoke_role','login_failed') NOT NULL,
    target     VARCHAR(255) NULL,
    ip         VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_access_catalog (catalog_id, created_at),
    KEY ix_access_user (user_id, created_at),
    KEY ix_access_action (action, created_at),
    CONSTRAINT fk_access_user    FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE SET NULL,
    CONSTRAINT fk_access_catalog FOREIGN KEY (catalog_id) REFERENCES catalogs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
