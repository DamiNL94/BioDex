-- F11b · Fotos de respaldo para las especies de EIDOS sin foto de la Fototeca (docs/fotos-respaldo.md).
-- Una fila por especie, global (la comparten todos los catálogos). Solo especies de EIDOS sin foto de la
-- Fototeca; las de usuario (biodex:) no se buscan y van directas a la silueta de su grupo.
-- La busca bin/fetch-species-photos.php (1 petición/s a iNaturalist); al dar de alta una especie en un
-- catálogo solo se inserta 'pending'. Se expone únicamente con status = 'ok'.

CREATE TABLE IF NOT EXISTS species_photos (
    taxonID       VARCHAR(64) NOT NULL,
    source        ENUM('inaturalist') NOT NULL DEFAULT 'inaturalist',   -- sitio para commons/gbif
    photo_id      BIGINT UNSIGNED NULL,          -- id numérico de la foto en iNat (/img/inat/<id>/…)
    photo_ext     ENUM('jpg','jpeg','png') NULL,
    inat_taxon_id INT UNSIGNED NULL,
    matched_name  VARCHAR(255) NULL,             -- nombre aceptado en iNat
    match_type    ENUM('exact','synonym') NULL,
    attribution   VARCHAR(512) NULL,             -- texto tal cual de iNat: «(c) autor, some rights reserved (CC BY)…»
    license       ENUM('cc0','cc-by','cc-by-nc') NULL,
    -- pending: por buscar · ok: se muestra · review: sinónimo, lo decide owner/custodian ·
    -- rejected: rechazada en la revisión (no se vuelve a proponer) · none: sin foto aceptable
    status        ENUM('pending','ok','review','rejected','none') NOT NULL DEFAULT 'pending',
    checked_at    DATETIME NULL,                 -- última consulta a iNat
    reviewed_by   INT UNSIGNED NULL,
    reviewed_at   DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (taxonID),
    KEY ix_photos_status (status, checked_at),
    CONSTRAINT fk_photos_species  FOREIGN KEY (taxonID)     REFERENCES species (taxonID) ON DELETE CASCADE,
    CONSTRAINT fk_photos_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Aceptar o rechazar una foto propuesta por sinónimo va a access_log (quién y cuándo).
ALTER TABLE access_log
    MODIFY action ENUM('view_exact','export_full','set_level','grant_role','revoke_role','login_failed',
                       'invite_created','invite_revoked','member_joined','member_removed','member_left',
                       'set_reference','photo_review') NOT NULL;
