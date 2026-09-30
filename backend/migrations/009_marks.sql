-- F8a · Marcas completas (términos DwC nuevos y vocabularios cerrados) y notas de cuaderno por especie.

-- lifeStage pasa a vocabulario controlado. Un valor libre de antes no se pierde: va a occurrenceRemarks.
UPDATE occurrences
   SET occurrenceRemarks = CONCAT_WS('\n', occurrenceRemarks, CONCAT('Estado vital: ', lifeStage)),
       lifeStage = NULL
 WHERE lifeStage IS NOT NULL
   AND lifeStage NOT IN ('adult','juvenile','subadult','larva','egg','seedling','flowering','fruiting','vegetative','undetermined');

-- Las ausencias en un punto prospectado también son dato: occurrenceStatus = 'absent'.
-- georeferenceProtocol: GPS del dispositivo o punto marcado a mano en el mapa (con precisión estimada).
ALTER TABLE occurrences
    ADD COLUMN occurrenceStatus     ENUM('present','absent') NOT NULL DEFAULT 'present' AFTER coordinateUncertaintyInMeters,
    ADD COLUMN sex                  ENUM('male','female','hermaphrodite','undetermined') NULL AFTER lifeStage,
    ADD COLUMN habitat              VARCHAR(500) NULL AFTER sex,
    ADD COLUMN georeferenceRemarks  VARCHAR(255) NULL AFTER habitat,
    ADD COLUMN georeferenceProtocol ENUM('GPS del dispositivo','Punto marcado en el mapa') NULL AFTER georeferenceRemarks,
    MODIFY lifeStage ENUM('adult','juvenile','subadult','larva','egg','seedling','flowering','fruiting','vegetative','undetermined') NULL;

UPDATE occurrences SET georeferenceProtocol = 'GPS del dispositivo' WHERE georeferenceProtocol IS NULL;

-- La evaluación UICN es de la especie en el catálogo, no de una marca (F8b: species_assessments).
-- Nunca se rellenó desde la app; se quita para que nadie la vuelva a usar.
ALTER TABLE occurrences DROP COLUMN iucn_assessment;

-- Notas de cuaderno por especie y catálogo: fechadas, con autor, sin coordenadas. Se sincronizan como
-- las marcas (UUID del cliente, client_updated_at, server_seq del mismo contador, tombstones).
CREATE TABLE IF NOT EXISTS species_notes (
    noteID            CHAR(36) NOT NULL,          -- UUID v4 generado en el cliente
    catalog_id        CHAR(36) NOT NULL,
    taxonID           VARCHAR(64) NOT NULL,
    user_id           INT UNSIGNED NOT NULL,      -- autor: solo él la edita o la borra
    text              TEXT NOT NULL,              -- ≤ 4000 caracteres (lo comprueba Lib/sync.php)
    client_updated_at DATETIME(3) NOT NULL,
    device_id         CHAR(36) NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at        DATETIME NULL,
    server_seq        BIGINT UNSIGNED NOT NULL,   -- de sync_seq, compartido con occurrences
    PRIMARY KEY (noteID),
    UNIQUE KEY uq_notes_seq (server_seq),
    KEY ix_notes_catalog_seq (catalog_id, server_seq),
    KEY ix_notes_catalog_taxon (catalog_id, taxonID),
    KEY ix_notes_user (user_id),
    CONSTRAINT fk_notes_catalog FOREIGN KEY (catalog_id) REFERENCES catalogs (id)     ON DELETE RESTRICT,
    CONSTRAINT fk_notes_user    FOREIGN KEY (user_id)    REFERENCES users (id)        ON DELETE RESTRICT,
    CONSTRAINT fk_notes_species FOREIGN KEY (taxonID)    REFERENCES species (taxonID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
