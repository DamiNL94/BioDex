-- F2a · Catálogos compartidos, miembros con rol, invitaciones, especies del catálogo y su nivel de sensibilidad.
-- Borrados lógicos (deleted_at) en catalogs y catalog_species.

CREATE TABLE IF NOT EXISTS catalogs (
    id          CHAR(36) NOT NULL,
    owner_id    INT UNSIGNED NOT NULL,
    name        VARCHAR(120) NOT NULL,
    description TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_catalogs_owner (owner_id),
    CONSTRAINT fk_catalogs_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS catalog_members (
    catalog_id CHAR(36) NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    role       ENUM('owner','custodian','editor','viewer') NOT NULL,
    granted_by INT UNSIGNED NULL,
    joined_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (catalog_id, user_id),
    KEY ix_members_user (user_id),
    CONSTRAINT fk_members_catalog FOREIGN KEY (catalog_id) REFERENCES catalogs (id) ON DELETE CASCADE,
    CONSTRAINT fk_members_user    FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE CASCADE,
    CONSTRAINT fk_members_granter FOREIGN KEY (granted_by) REFERENCES users (id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Solo se invita como editor o viewer; custodian lo concede el owner a alguien que ya es miembro.
CREATE TABLE IF NOT EXISTS catalog_invites (
    code       VARCHAR(32) NOT NULL,
    catalog_id CHAR(36) NOT NULL,
    email      VARCHAR(254) NULL,                           -- NULL = enlace abierto
    role       ENUM('editor','viewer') NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    used_by    INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (code),
    KEY ix_invites_catalog (catalog_id),
    KEY ix_invites_email (email),
    CONSTRAINT fk_invites_catalog FOREIGN KEY (catalog_id) REFERENCES catalogs (id) ON DELETE CASCADE,
    CONSTRAINT fk_invites_used_by FOREIGN KEY (used_by)    REFERENCES users (id)    ON DELETE SET NULL,
    CONSTRAINT fk_invites_creator FOREIGN KEY (created_by) REFERENCES users (id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS catalog_species (
    catalog_id CHAR(36) NOT NULL,
    taxonID    VARCHAR(64) NOT NULL,
    added_by   INT UNSIGNED NULL,
    added_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (catalog_id, taxonID),
    KEY ix_catspecies_taxon (taxonID),
    CONSTRAINT fk_catspecies_catalog FOREIGN KEY (catalog_id) REFERENCES catalogs (id)      ON DELETE CASCADE,
    CONSTRAINT fk_catspecies_species FOREIGN KEY (taxonID)    REFERENCES species (taxonID) ON DELETE RESTRICT,
    CONSTRAINT fk_catspecies_adder   FOREIGN KEY (added_by)   REFERENCES users (id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Nivel efectivo (level) frente al sugerido por BioDex desde EIDOS (suggested_level). Reglas: shared/sensitivity.json.
CREATE TABLE IF NOT EXISTS species_sensitivity (
    catalog_id      CHAR(36) NOT NULL,
    taxonID         VARCHAR(64) NOT NULL,
    level           TINYINT UNSIGNED NOT NULL,
    suggested_level TINYINT UNSIGNED NULL,
    reason          VARCHAR(255) NULL,
    set_by          INT UNSIGNED NULL,                      -- NULL = sugerencia automática sin confirmar
    set_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (catalog_id, taxonID),
    CONSTRAINT ck_sens_level     CHECK (level BETWEEN 1 AND 4),
    CONSTRAINT ck_sens_suggested CHECK (suggested_level IS NULL OR suggested_level BETWEEN 1 AND 4),
    CONSTRAINT fk_sens_catspecies FOREIGN KEY (catalog_id, taxonID)
        REFERENCES catalog_species (catalog_id, taxonID) ON DELETE CASCADE,
    CONSTRAINT fk_sens_setter FOREIGN KEY (set_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
