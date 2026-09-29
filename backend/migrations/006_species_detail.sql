-- F2b · Especies: ids estables de la clasificación (para el árbol del catálogo), año de la UICN principal,
-- taxonRemarks (especies de usuario) y frescura del estado legal por separado de la ficha completa
-- (el filtro `legal` del buscador lo trae sin pedir la ficha entera).
-- Un solo ALTER: MySQL lo aplica entero o no lo aplica.

ALTER TABLE species
    ADD COLUMN kingdom_id        VARCHAR(32) NULL AFTER family,   -- id de EIDOS (kingdomid...) o NULL
    ADD COLUMN phylum_id         VARCHAR(32) NULL AFTER kingdom_id,
    ADD COLUMN class_id          VARCHAR(32) NULL AFTER phylum_id,
    ADD COLUMN order_id          VARCHAR(32) NULL AFTER class_id,
    ADD COLUMN family_id         VARCHAR(32) NULL AFTER order_id,
    ADD COLUMN classification_source ENUM('eidos','gbif','user') NULL AFTER family_id,
    ADD COLUMN iucn_primary_year SMALLINT NULL AFTER iucn_primary_scope,
    ADD COLUMN taxonRemarks      VARCHAR(255) NULL AFTER description,
    ADD COLUMN legal_fetched_at  DATETIME NULL AFTER detail_fetched_at,
    ADD KEY ix_species_iucn (iucn_primary_code);
