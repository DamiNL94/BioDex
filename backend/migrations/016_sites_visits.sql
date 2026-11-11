-- F11h · Sitios de muestreo, visitas (= Event de Darwin Core) y occurrences.visit_id (docs/db.md, docs/sync.md).
--   sites          sitio de muestreo permanente: punto + radio, polígono o línea (transecto). Se crea en el móvil (también sin
--                  red) o desde casa (pestaña Sitios). Se sincroniza como las marcas (UUID del cliente, client_updated_at
--                  decide, solo el autor edita o borra, server_seq común y tombstones). Su posición sigue la regla de
--                  applyVisibility() con el nivel MÁS restrictivo de las especies objetivo y de las registradas en sus visitas.
--   site_species   especies objetivo que se recuerdan del sitio (las propone la siguiente visita).
--   visits         un muestreo: sitio (NULL = oportunista), protocolo, esfuerzo, observadores, especies objetivo, inicio, fin,
--                  condiciones y estado. Las marcas hechas durante la visita llevan su visit_id; al cerrarla, las especies
--                  objetivo sin contar quedan como ausencias (occurrenceStatus = absent, 0 individuos).
--   visit_species  especies objetivo de la visita (censo completo: lo no contado es un 0).
-- occurrences.visit_id: sin clave foránea a propósito. Un push puede traer la marca antes que su visita (otro lote); el
-- servidor solo comprueba que, si la visita ya existe, sea del mismo catálogo (Lib/sync.php).

CREATE TABLE IF NOT EXISTS sites (
    siteID            CHAR(36) NOT NULL,              -- UUID v4 del cliente
    catalog_id        CHAR(36) NOT NULL,
    user_id           INT UNSIGNED NOT NULL,          -- quien lo creó: solo él lo edita o lo borra
    name              VARCHAR(120) NOT NULL,
    site_type         ENUM('plot','transect','point') NOT NULL,   -- parcela · transecto · punto de conteo
    decimalLatitude   DECIMAL(9,6) NOT NULL,          -- centro (punto), centroide (polígono) o punto medio (línea)
    decimalLongitude  DECIMAL(9,6) NOT NULL,
    radius_m          INT UNSIGNED NULL,              -- punto + radio
    footprint_wkt     MEDIUMTEXT NULL,                -- POLYGON o LINESTRING en WGS84 («lon lat»), en vez de radio
    band_m            DECIMAL(8,2) NULL,              -- transecto: ancho TOTAL de la banda de conteo (m)
    area_m2           DECIMAL(14,2) NULL,             -- superficie (calculada o dada)
    area_given        TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = la dio el usuario; 0 = calculada de la geometría
    length_m          DECIMAL(12,2) NULL,             -- transecto: longitud de la línea
    protocol          ENUM('target_census','plot_count','transect','timed_search') NULL,   -- el de la última visita
    client_updated_at DATETIME(3) NOT NULL,
    device_id         CHAR(36) NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at        DATETIME NULL,
    server_seq        BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (siteID),
    UNIQUE KEY uq_site_seq (server_seq),
    KEY ix_site_catalog_seq (catalog_id, server_seq),
    CONSTRAINT fk_site_catalog FOREIGN KEY (catalog_id) REFERENCES catalogs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_site_user    FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS site_species (
    siteID  CHAR(36) NOT NULL,
    taxonID VARCHAR(64) NOT NULL,
    PRIMARY KEY (siteID, taxonID),
    KEY ix_site_species_taxon (taxonID),
    CONSTRAINT fk_sitesp_site    FOREIGN KEY (siteID)  REFERENCES sites (siteID)    ON DELETE CASCADE,
    CONSTRAINT fk_sitesp_species FOREIGN KEY (taxonID) REFERENCES species (taxonID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS visits (
    visitID           CHAR(36) NOT NULL,              -- UUID v4 del cliente (= eventID en el DwC-A)
    catalog_id        CHAR(36) NOT NULL,
    user_id           INT UNSIGNED NOT NULL,          -- quien la empezó: solo él la edita o la borra
    site_id           CHAR(36) NULL,                  -- NULL = visita oportunista (sin sitio)
    protocol          ENUM('target_census','plot_count','transect','timed_search') NOT NULL,
    band_m            DECIMAL(8,2) NULL,              -- transecto con banda de N m (si no, la del sitio)
    effort_minutes    INT UNSIGNED NULL,              -- calculado entre inicio y fin; editable
    observers         VARCHAR(500) NULL,              -- nombres, separados por « | »
    event_start       DATETIME NOT NULL,              -- UTC
    event_end         DATETIME NULL,                  -- UTC (NULL mientras está abierta)
    conditions        TEXT NULL,                      -- ≤ 2000: tiempo, visibilidad… (no sale si se generaliza)
    status            ENUM('open','closed') NOT NULL,
    decimalLatitude   DECIMAL(9,6) NULL,              -- posición al empezar (sobre todo, oportunistas)
    decimalLongitude  DECIMAL(9,6) NULL,
    client_updated_at DATETIME(3) NOT NULL,
    device_id         CHAR(36) NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at        DATETIME NULL,
    server_seq        BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (visitID),
    UNIQUE KEY uq_visit_seq (server_seq),
    KEY ix_visit_catalog_seq (catalog_id, server_seq),
    KEY ix_visit_site (site_id),
    CONSTRAINT ck_visit_end CHECK (event_end IS NULL OR event_end >= event_start),
    CONSTRAINT fk_visit_catalog FOREIGN KEY (catalog_id) REFERENCES catalogs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_visit_user    FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS visit_species (
    visitID CHAR(36) NOT NULL,
    taxonID VARCHAR(64) NOT NULL,
    PRIMARY KEY (visitID, taxonID),
    KEY ix_visit_species_taxon (taxonID),
    CONSTRAINT fk_visitsp_visit   FOREIGN KEY (visitID) REFERENCES visits (visitID)  ON DELETE CASCADE,
    CONSTRAINT fk_visitsp_species FOREIGN KEY (taxonID) REFERENCES species (taxonID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE occurrences
    ADD COLUMN visit_id CHAR(36) NULL AFTER taxonID,
    ADD KEY ix_occ_visit (visit_id);
