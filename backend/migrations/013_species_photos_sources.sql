-- F11d · Más fuentes de fotos de respaldo: GBIF y Wikimedia Commons después de iNaturalist (docs/fotos-respaldo.md).
-- Orden: Fototeca (EIDOS) > iNaturalist > GBIF > Wikimedia Commons > silueta del grupo. Una búsqueda en cascada
-- por especie: la primera fuente que da ok o review la cierra.
-- iNat sigue por su proxy (/img/inat/). De GBIF y Commons se guarda en MySQL UNA copia de la miniatura elegida
-- (species_photo_files), servida en /img/respaldo/ desde nuestro origen (backend/public/respaldo.php).
-- inat_taxon_id se queda con su nombre: solo lo usa iNaturalist; GBIF y Commons guardan su taxón en source_ref.

-- source: fuente de la foto propuesta o aceptada (review/ok); NULL mientras no hay foto (pending/none).
ALTER TABLE species_photos
    MODIFY source  ENUM('inaturalist','gbif','commons') NULL DEFAULT NULL,
    MODIFY license ENUM('cc0','pd','cc-by','cc-by-nc','cc-by-sa') NULL,
    ADD COLUMN license_version  VARCHAR(8)   NULL AFTER license,          -- «4.0», «3.0-de» (para el crédito); iNat no la da
    ADD COLUMN source_ref       VARCHAR(64)  NULL AFTER inat_taxon_id,    -- usageKey de GBIF o QID de Wikidata
    ADD COLUMN photo_ref        VARCHAR(255) NULL AFTER source_ref,       -- GBIF: «<gbifID>/<md5>»; Commons: nombre del fichero
    ADD COLUMN photo_page_url   VARCHAR(512) NULL AFTER photo_ref,        -- página de la foto (enlace del crédito)
    -- Fuentes cuya foto se rechazó en la revisión: la cascada se las salta.
    ADD COLUMN rejected_sources SET('inaturalist','gbif','commons') NOT NULL DEFAULT '' AFTER status;

-- Las rechazadas de F11b (siempre de iNat) vuelven a la cola, saltándose iNat.
UPDATE species_photos
   SET status = 'pending', rejected_sources = 'inaturalist', checked_at = NULL
 WHERE status = 'rejected';

-- Las «none» de F11b también: el primer despliegue busca ya en GBIF y Commons, sin esperar a --recheck-none-days.
UPDATE species_photos SET status = 'pending', checked_at = NULL WHERE status = 'none';

-- Sin foto, sin datos de foto (las que vuelven a la cola llevaban los de iNat).
UPDATE species_photos
   SET source = NULL, photo_id = NULL, photo_ext = NULL, inat_taxon_id = NULL, matched_name = NULL,
       match_type = NULL, attribution = NULL, license = NULL
 WHERE status IN ('pending', 'none');

-- «rejected» ya no es un estado: rechazar añade la fuente a rejected_sources y deja la especie pending
-- (o none si ya no queda ninguna fuente).
ALTER TABLE species_photos
    MODIFY status ENUM('pending','ok','review','none') NOT NULL DEFAULT 'pending';

-- Copia local de la miniatura de GBIF o Commons: los bytes tal cual llegaron (sin reescalar ni recomprimir).
-- ~50–100 KB por especie; tope de 1,5 MB al descargar. En MySQL y no en disco: el disco del contenedor de
-- Railway se borra en cada despliegue.
CREATE TABLE IF NOT EXISTS species_photo_files (
    taxonID    VARCHAR(64) NOT NULL,
    mime       ENUM('image/jpeg','image/png','image/webp') NOT NULL,
    bytes      MEDIUMBLOB NOT NULL,
    sha256     CHAR(64) NOT NULL,                -- sus 16 primeros hex van en la URL: foto nueva → URL nueva
    width      SMALLINT UNSIGNED NOT NULL,
    height     SMALLINT UNSIGNED NOT NULL,
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (taxonID),
    CONSTRAINT fk_photo_files_photo FOREIGN KEY (taxonID) REFERENCES species_photos (taxonID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
