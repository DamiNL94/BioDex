-- F11g · Cuantificadores UICN (B, C, D) e impactos (docs/iucn.md, docs/db.md).
--   impacts            impacto registrado en campo (también sin red): amenaza del esquema de la UICN (v3.3), punto + radio o
--                      polígono, fecha, intensidad, alcance, temporalidad y nota. Se sincroniza como las marcas (UUID del
--                      cliente, client_updated_at decide, solo el autor edita o borra, server_seq común y tombstones).
--   impact_species     especies del catálogo afectadas por el impacto (por defecto, las presentes en el radio).
--   species_locations  nº de localidades (definidas por amenaza) de una especie en un catálogo: la agrupación que propone
--                      BioDex tal como la confirmó el usuario, su justificación (obligatoria), calidad y fuente.
--   gbif_occurrence_cache  registros de GBIF para COMPARAR EOO/AOO/B (nunca son ocurrencias del catálogo ni se exportan).
-- species_parameters gana `justification`: los hechos de declive y fluctuación de B y C (b(i)–(v), c(i)–(iv)) y las
-- preguntas del ajuste regional son parámetros de tipo «sí · no · desconocido» con su justificación (Lib/parameters.php).

CREATE TABLE IF NOT EXISTS impacts (
    impactID          CHAR(36) NOT NULL,              -- UUID v4 del cliente
    catalog_id        CHAR(36) NOT NULL,
    user_id           INT UNSIGNED NOT NULL,          -- autor: solo él lo edita o lo borra
    threat_code       VARCHAR(8) NOT NULL,            -- «2.3» (shared/iucn-threats.json, dos niveles)
    quick_key         VARCHAR(20) NULL,               -- botón rápido usado (trampling, waste…), informativo
    decimalLatitude   DECIMAL(9,6) NOT NULL,          -- centro (punto) o centroide del polígono
    decimalLongitude  DECIMAL(9,6) NOT NULL,
    radius_m          INT UNSIGNED NULL,              -- punto + radio
    polygon           JSON NULL,                      -- [[lat, lon], …] (≥ 3 vértices) en vez de radio
    event_date        DATETIME NOT NULL,              -- UTC
    intensity         ENUM('low','medium','high') NOT NULL,
    scope_pct         DECIMAL(5,2) NULL,              -- fracción afectada estimada (0–100 %)
    timing            ENUM('past','ongoing','future') NOT NULL,
    note              TEXT NULL,                      -- ≤ 4000
    client_updated_at DATETIME(3) NOT NULL,
    device_id         CHAR(36) NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at        DATETIME NULL,
    server_seq        BIGINT UNSIGNED NOT NULL,       -- de sync_seq, compartido con marcas, notas, evaluaciones y parámetros
    PRIMARY KEY (impactID),
    UNIQUE KEY uq_impact_seq (server_seq),
    KEY ix_impact_catalog_seq (catalog_id, server_seq),
    CONSTRAINT fk_impact_catalog FOREIGN KEY (catalog_id) REFERENCES catalogs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_impact_user    FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS impact_species (
    impactID CHAR(36) NOT NULL,
    taxonID  VARCHAR(64) NOT NULL,
    PRIMARY KEY (impactID, taxonID),
    KEY ix_impact_species_taxon (taxonID),
    CONSTRAINT fk_impsp_impact  FOREIGN KEY (impactID) REFERENCES impacts (impactID) ON DELETE CASCADE,
    CONSTRAINT fk_impsp_species FOREIGN KEY (taxonID)  REFERENCES species (taxonID)  ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS species_locations (
    catalog_id    CHAR(36) NOT NULL,
    taxonID       VARCHAR(64) NOT NULL,
    n_locations   INT UNSIGNED NOT NULL,
    location_groups JSON NOT NULL,                     -- [{label, subpops: ["S1", …], impactID|null, threatCode|null, noThreat}]
    justification TEXT NOT NULL,
    quality       ENUM('observed','estimated','inferred','suspected','projected') NULL,
    source        VARCHAR(1000) NULL,
    separation_km DECIMAL(10,3) NULL,                 -- distancia con la que se formaron las subpoblaciones al confirmar
    set_by        INT UNSIGNED NULL,
    set_at        DATETIME NOT NULL,
    deleted_at    DATETIME NULL,
    server_seq    BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (catalog_id, taxonID),
    UNIQUE KEY uq_loc_seq (server_seq),
    CONSTRAINT fk_loc_catalog FOREIGN KEY (catalog_id) REFERENCES catalogs (id)     ON DELETE CASCADE,
    CONSTRAINT fk_loc_species FOREIGN KEY (taxonID)    REFERENCES species (taxonID) ON DELETE RESTRICT,
    CONSTRAINT fk_loc_set_by  FOREIGN KEY (set_by)     REFERENCES users (id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS gbif_occurrence_cache (
    cache_key   CHAR(64) NOT NULL,                    -- sha256 de usageKey + recuadro
    usage_key   BIGINT UNSIGNED NOT NULL,
    bbox        VARCHAR(120) NOT NULL,                -- «minLat,minLon,maxLat,maxLon»
    n_records   INT UNSIGNED NOT NULL,
    records     MEDIUMTEXT NOT NULL,                  -- JSON [[lat, lon, incertidumbre|null, gbifID, año|null], …]
    fetched_at  DATETIME NOT NULL,
    PRIMARY KEY (cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE species_parameters ADD COLUMN justification TEXT NULL AFTER source;

ALTER TABLE access_log
    MODIFY action ENUM('view_exact','export_full','set_level','grant_role','revoke_role','login_failed',
                       'invite_created','invite_revoked','member_joined','member_removed','member_left',
                       'set_reference','photo_review','set_parameter','set_locations') NOT NULL;

-- Marca con superficie contada (DwC sampleSizeValue, en m²; sampleSizeUnit «square metre» fija): es una PARCELA de
-- superficie conocida, y su densidad de maduros sirve para estimar el total (densidad × hábitat ocupado, docs/iucn.md).
ALTER TABLE occurrences ADD COLUMN sampleSizeValue DECIMAL(12,3) NULL AFTER mature_count;
