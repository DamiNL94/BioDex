-- F2a · Especies: caché de EIDOS (source='eidos') y especies creadas por usuarios (source='user').
-- Columnas según «Mapeo EIDOS → tabla species» (docs/eidos.md), con nombres Darwin Core.
-- La colación ai_ci hace la búsqueda insensible a acentos (el ilike de EIDOS no lo es).
-- `order` es palabra reservada: siempre entre comillas invertidas.

CREATE TABLE IF NOT EXISTS species (
    taxonID                  VARCHAR(64) NOT NULL,          -- entero EIDOS como texto o biodex:<uuid>
    source                   ENUM('eidos','user') NOT NULL,
    scientificName           VARCHAR(255) NOT NULL,         -- con autor
    scientificNameAuthorship VARCHAR(255) NULL,
    canonical_name           VARCHAR(255) NOT NULL,         -- sin autor, para buscar
    taxonRank                VARCHAR(32) NULL,
    kingdom                  VARCHAR(64) NULL,
    phylum                   VARCHAR(64) NULL,
    class                    VARCHAR(64) NULL,
    `order`                  VARCHAR(64) NULL,
    family                   VARCHAR(64) NULL,
    genus                    VARCHAR(64) NULL,
    specificEpithet          VARCHAR(64) NULL,
    infraspecificEpithet     VARCHAR(64) NULL,
    taxonomicGroup           VARCHAR(64) NULL,
    establishmentMeans       VARCHAR(64) NULL,
    habitatScope             VARCHAR(4) NULL,               -- T / M / MT
    vernacularName           VARCHAR(255) NULL,
    synonyms                 JSON NULL,                     -- ["Salvia rosmarinus", ...]
    iucn_assessments         JSON NULL,                     -- todas las vigentes: code, scope, year, criteria
    iucn_primary_code        VARCHAR(3) NULL,
    iucn_primary_scope       VARCHAR(32) NULL,
    habitatsDirectiveStatus  VARCHAR(4) NULL,               -- FV / U1 / U2 / XX
    legal_status             JSON NULL,
    description              TEXT NULL,
    images                   JSON NULL,
    detail_fetched_at        DATETIME NULL,                 -- frescura de la ficha completa
    created_by               INT UNSIGNED NULL,             -- solo source='user'
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (taxonID),
    KEY ix_species_canonical (canonical_name),
    KEY ix_species_scientific (scientificName),
    KEY ix_species_vernacular (vernacularName),
    KEY ix_species_group (taxonomicGroup, canonical_name),
    KEY ix_species_family (family),
    KEY ix_species_source (source, created_by),
    CONSTRAINT fk_species_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
