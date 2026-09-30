-- F9 · EOO y AOO calculados en el servidor (el definitivo) con las coordenadas exactas.
-- species_metrics ya existía (004) sin usarse: se guarda por catálogo, especie, ámbito (me/team), usuario (en
-- «me») y TAMAÑO DE CELDA (2 o 1 km: siempre se guarda el usado), con lo necesario para decidir si recalcular.

DELETE FROM species_metrics;

ALTER TABLE species_metrics
    DROP INDEX uq_metrics,
    MODIFY cell_size_km DECIMAL(6,3) NOT NULL DEFAULT 2.000,
    ADD COLUMN n_excluded  INT UNSIGNED NOT NULL DEFAULT 0 AFTER n_records,
    ADD COLUMN aoo_min_km2 DECIMAL(14,3) NULL AFTER aoo_cells,   -- rejilla desplazada medio tamaño (informativo)
    ADD COLUMN aoo_max_km2 DECIMAL(14,3) NULL AFTER aoo_min_km2,
    ADD COLUMN eoo_adjusted TINYINT(1) NOT NULL DEFAULT 0 AFTER eoo_km2,   -- EOO < AOO → EOO = AOO
    ADD COLUMN warnings    JSON NULL AFTER n_excluded,
    -- server_seq máximo de las marcas de la especie en el catálogo al calcular: si hay uno mayor (marca
    -- nueva, editada o borrada), se recalcula al pedirlo.
    ADD COLUMN source_seq  BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER warnings,
    ADD UNIQUE KEY uq_metrics (catalog_id, taxonID, scope, owner_key, cell_size_km);
