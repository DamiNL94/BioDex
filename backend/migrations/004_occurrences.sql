-- F2a · Observaciones (términos Darwin Core con su nombre exacto), contador de sincronización y métricas EOO/AOO.
-- La coordenada exacta nunca se modifica: la generalización se aplica a copias (Lib/sensitivity.php).

CREATE TABLE IF NOT EXISTS occurrences (
    occurrenceID                  CHAR(36) NOT NULL,        -- UUID v4 generado en el cliente
    catalog_id                    CHAR(36) NOT NULL,
    user_id                       INT UNSIGNED NOT NULL,    -- autor
    taxonID                       VARCHAR(64) NOT NULL,
    eventDate                     DATETIME NOT NULL,        -- UTC
    decimalLatitude               DECIMAL(10,7) NOT NULL,
    decimalLongitude              DECIMAL(10,7) NOT NULL,
    coordinateUncertaintyInMeters INT UNSIGNED NULL,
    individualCount               INT UNSIGNED NULL,
    lifeStage                     VARCHAR(64) NULL,
    occurrenceRemarks             TEXT NULL,
    basisOfRecord                 VARCHAR(32) NOT NULL DEFAULT 'HumanObservation',
    geodeticDatum                 VARCHAR(16) NOT NULL DEFAULT 'EPSG:4326',
    mature_count                  INT UNSIGNED NULL,
    iucn_assessment               JSON NULL,
    created_at                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at                    DATETIME NULL,
    server_seq                    BIGINT UNSIGNED NOT NULL, -- de sync_seq en cada insert/update (F5)
    PRIMARY KEY (occurrenceID),
    UNIQUE KEY uq_occ_seq (server_seq),
    KEY ix_occ_catalog_taxon (catalog_id, taxonID),
    KEY ix_occ_catalog_seq (catalog_id, server_seq),
    KEY ix_occ_user (user_id),
    KEY ix_occ_taxon (taxonID),
    CONSTRAINT ck_occ_lat CHECK (decimalLatitude BETWEEN -90 AND 90),
    CONSTRAINT ck_occ_lon CHECK (decimalLongitude BETWEEN -180 AND 180),
    CONSTRAINT fk_occ_catalog FOREIGN KEY (catalog_id) REFERENCES catalogs (id)     ON DELETE RESTRICT,
    CONSTRAINT fk_occ_user    FOREIGN KEY (user_id)    REFERENCES users (id)        ON DELETE RESTRICT,
    CONSTRAINT fk_occ_species FOREIGN KEY (taxonID)    REFERENCES species (taxonID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Una sola fila. Uso (F5): UPDATE sync_seq SET value = LAST_INSERT_ID(value + 1) WHERE id = 1; SELECT LAST_INSERT_ID();
CREATE TABLE IF NOT EXISTS sync_seq (
    id    TINYINT UNSIGNED NOT NULL,
    value BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT ck_sync_seq_single CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO sync_seq (id, value) VALUES (1, 0);

-- EOO/AOO por especie y catálogo: scope 'me' (user_id = autor) o 'team' (user_id NULL).
-- owner_key sustituye al NULL en la clave única (un UNIQUE con NULL admitiría duplicados de 'team').
CREATE TABLE IF NOT EXISTS species_metrics (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    catalog_id   CHAR(36) NOT NULL,
    taxonID      VARCHAR(64) NOT NULL,
    scope        ENUM('me','team') NOT NULL,
    user_id      INT UNSIGNED NULL,
    owner_key    INT UNSIGNED AS (IFNULL(user_id, 0)) VIRTUAL,
    eoo_km2      DECIMAL(14,3) NULL,
    aoo_km2      DECIMAL(14,3) NULL,
    aoo_cells    INT UNSIGNED NULL,
    cell_size_km DECIMAL(6,3) NULL,
    n_records    INT UNSIGNED NOT NULL DEFAULT 0,
    computed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_metrics (catalog_id, taxonID, scope, owner_key),
    KEY ix_metrics_user (user_id),
    CONSTRAINT fk_metrics_catalog FOREIGN KEY (catalog_id) REFERENCES catalogs (id) ON DELETE CASCADE,
    CONSTRAINT fk_metrics_user    FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
