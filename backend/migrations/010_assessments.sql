-- F8b · Evaluaciones UICN de una especie en el ámbito de un catálogo (asistente del móvil).
-- Se sincronizan como las marcas y las notas: UUID del cliente, client_updated_at decide, solo el autor
-- las edita o borra, server_seq del contador común y tombstones (docs/sync.md).
-- BioDex PROPONE la categoría con el motor shared/iucn-engine.js en el dispositivo; aquí se guardan los
-- datos que introdujo el investigador (inputs) y lo que salió (result), sin recalcular.

CREATE TABLE IF NOT EXISTS species_assessments (
    assessmentID        CHAR(36) NOT NULL,          -- UUID v4 generado en el cliente
    catalog_id          CHAR(36) NOT NULL,
    taxonID             VARCHAR(64) NOT NULL,
    user_id             INT UNSIGNED NOT NULL,      -- autor: solo él la edita o la borra
    inputs              JSON NOT NULL,              -- datos del asistente, paso a paso (A–E, generación…)
    result              JSON NOT NULL,              -- {category, baseCategory, criteriaString, determining, byCriterion…}
    category            ENUM('CR','EN','VU','NT','LC','DD') NULL,   -- result.category (tras el ajuste), para listar
    regional_adjustment ENUM('down','up') NULL,     -- Directrices regionales: un escalón
    justification       TEXT NULL,                  -- ≤ 4000; obligatoria con ajuste regional
    status              ENUM('draft','final') NOT NULL DEFAULT 'draft',
    -- «Evaluación de referencia del catálogo»: la marca SOLO el owner (PUT .../reference-assessment),
    -- una por especie y catálogo (reference_key única), y queda en access_log. El push del autor no
    -- toca estas columnas.
    is_reference        TINYINT(1) NOT NULL DEFAULT 0,
    reference_set_by    INT UNSIGNED NULL,
    reference_set_at    DATETIME NULL,
    reference_key       VARCHAR(110) AS (IF(is_reference = 1, CONCAT(catalog_id, '|', taxonID), NULL)) STORED,
    client_updated_at   DATETIME(3) NOT NULL,
    device_id           CHAR(36) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME NULL,
    server_seq          BIGINT UNSIGNED NOT NULL,   -- de sync_seq, compartido con occurrences y species_notes
    PRIMARY KEY (assessmentID),
    UNIQUE KEY uq_assess_seq (server_seq),
    UNIQUE KEY uq_assess_reference (reference_key),
    KEY ix_assess_catalog_seq (catalog_id, server_seq),
    KEY ix_assess_catalog_taxon (catalog_id, taxonID),
    KEY ix_assess_user (user_id),
    CONSTRAINT fk_assess_catalog FOREIGN KEY (catalog_id)       REFERENCES catalogs (id)     ON DELETE RESTRICT,
    CONSTRAINT fk_assess_user    FOREIGN KEY (user_id)          REFERENCES users (id)        ON DELETE RESTRICT,
    CONSTRAINT fk_assess_ref_by  FOREIGN KEY (reference_set_by) REFERENCES users (id)        ON DELETE SET NULL,
    CONSTRAINT fk_assess_species FOREIGN KEY (taxonID)          REFERENCES species (taxonID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Marcar o quitar la evaluación de referencia va a access_log (CLAUDE.md: registrar cambios de nivel y rol,
-- y F8b: la referencia).
ALTER TABLE access_log
    MODIFY action ENUM('view_exact','export_full','set_level','grant_role','revoke_role','login_failed',
                       'invite_created','invite_revoked','member_joined','member_removed','member_left',
                       'set_reference') NOT NULL;
