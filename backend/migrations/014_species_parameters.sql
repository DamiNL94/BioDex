-- Parámetros de una especie en un catálogo (base de los cuantificadores y las series UICN; docs/parametros.md).
-- Características que necesitan los criterios UICN y no salen de las marcas: duración de la generación,
-- distancias de separación y de dispersión, definición de individuo maduro, tamaño mínimo viable de una
-- subpoblación y forma de vida. Una fila por (catálogo, especie, clave). Los editan owner y custodio
-- (PUT/DELETE /api/catalogs/{id}/species/{taxonID}/parameters/{key}); el resto los lee.
-- Bajan al móvil por el pull de /api/sync con el contador común server_seq; borrar deja la fila con
-- deleted_at (tombstone) y server_seq nuevo, para que el borrado también llegue.
-- La clave se llama param_key porque KEY es palabra reservada de MySQL; la lista cerrada de claves y sus
-- reglas están en backend/Lib/parameters.php (SPECIES_PARAMETERS): añadir una no exige migración.

CREATE TABLE IF NOT EXISTS species_parameters (
    catalog_id  CHAR(36) NOT NULL,
    taxonID     VARCHAR(64) NOT NULL,
    param_key   VARCHAR(40) NOT NULL,
    value       VARCHAR(2000) NULL,              -- número (como texto, sin unidad) o texto; NULL con solo intervalo
    value_min   DECIMAL(16,6) NULL,              -- intervalo opcional (los dos o ninguno)
    value_max   DECIMAL(16,6) NULL,
    quality     ENUM('observed','estimated','inferred','suspected','projected') NULL,
    source      VARCHAR(1000) NULL,              -- texto libre o referencia bibliográfica
    set_by      INT UNSIGNED NULL,
    set_at      DATETIME NOT NULL,
    deleted_at  DATETIME NULL,
    server_seq  BIGINT UNSIGNED NOT NULL,        -- de sync_seq, compartido con marcas, notas y evaluaciones
    PRIMARY KEY (catalog_id, taxonID, param_key),
    UNIQUE KEY uq_param_seq (server_seq),
    KEY ix_param_catalog_seq (catalog_id, server_seq),
    CONSTRAINT fk_param_catalog FOREIGN KEY (catalog_id) REFERENCES catalogs (id)     ON DELETE CASCADE,
    CONSTRAINT fk_param_species FOREIGN KEY (taxonID)    REFERENCES species (taxonID) ON DELETE RESTRICT,
    CONSTRAINT fk_param_set_by  FOREIGN KEY (set_by)     REFERENCES users (id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Fijar, cambiar o borrar un parámetro queda en access_log (quién, cuándo, antes y después).
ALTER TABLE access_log
    MODIFY action ENUM('view_exact','export_full','set_level','grant_role','revoke_role','login_failed',
                       'invite_created','invite_revoked','member_joined','member_removed','member_left',
                       'set_reference','photo_review','set_parameter') NOT NULL;
