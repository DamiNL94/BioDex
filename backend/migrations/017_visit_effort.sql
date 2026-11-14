-- Modo búsqueda y esfuerzo de muestreo de las visitas (F11j; docs/esfuerzo.md, docs/sync.md).
--   visits.effort_protocol   cómo se BUSCÓ: free (libre) · timed (búsqueda con tiempo fijo, Goff et al. 1982) · transect
--                            (longitud recorrida). Es independiente de `protocol`, que dice cómo se CUENTA.
--   visits.effort_target     objetivo: minutos de búsqueda activa (timed) o metros recorridos (transect).
--   visits.blind             1 = modo búsqueda de principio a fin (las marcas anteriores estuvieron ocultas). Una vez 0,
--                            no vuelve a 1 (Lib/visits.php). Solo con un protocolo que no sea «libre».
--   visits.visit_kind        exploratoria o repetición de un sitio, según el diseño de muestreo de la especie.
--   Lo demás lo CALCULA el servidor del recorrido que llega al cerrar la visita (Lib/track.php): tiempo activo (sin las
--   pausas), distancia, tiempo vigilado por el GPS, huecos, objetivo alcanzado, recorrido completo y celdas de 2 km
--   cubiertas (JSON: ["i,j", …] en UTM 30N, como la AOO).
--   visit_tracks             el recorrido: puntos [lat, lon, t, precisión, tÚltimo] y pausas [[t0, t1], …] en JSON. En
--                            tabla aparte para que el pull de visitas no lo arrastre; no sale por el pull, solo en la
--                            exportación (footprintWKT), y nunca si la visita se generaliza.
-- Las visitas anteriores quedan con protocolo «libre» y blind = 0: no cuentan como ocasión de Chao2.

ALTER TABLE visits
    ADD COLUMN effort_protocol ENUM('free','timed','transect') NOT NULL DEFAULT 'free' AFTER protocol,
    ADD COLUMN effort_target   INT UNSIGNED NULL AFTER effort_protocol,
    ADD COLUMN blind           TINYINT(1) NOT NULL DEFAULT 0 AFTER effort_target,
    ADD COLUMN visit_kind      ENUM('exploratory','repeat') NULL AFTER blind,
    ADD COLUMN active_seconds  INT UNSIGNED NULL AFTER effort_minutes,
    ADD COLUMN distance_m      DECIMAL(10,1) NULL AFTER active_seconds,
    ADD COLUMN tracked_seconds INT UNSIGNED NULL AFTER distance_m,
    ADD COLUMN track_gaps      SMALLINT UNSIGNED NULL AFTER tracked_seconds,
    ADD COLUMN target_met      TINYINT(1) NOT NULL DEFAULT 0 AFTER track_gaps,
    ADD COLUMN track_complete  TINYINT(1) NOT NULL DEFAULT 0 AFTER target_met,
    ADD COLUMN covered_cells   MEDIUMTEXT NULL AFTER track_complete;

-- Protocolo de búsqueda y objetivo por defecto del sitio (los de su última visita; los propone la siguiente).
ALTER TABLE sites
    ADD COLUMN effort_protocol ENUM('free','timed','transect') NULL AFTER protocol,
    ADD COLUMN effort_target   INT UNSIGNED NULL AFTER effort_protocol;

CREATE TABLE IF NOT EXISTS visit_tracks (
    visitID    CHAR(36) NOT NULL,
    points     MEDIUMTEXT NOT NULL,             -- JSON [[lat, lon, t, precisión, tÚltimo], …] (≤ 5000 puntos)
    pauses     TEXT NULL,                       -- JSON [[t0, t1], …] en segundos Unix
    n_points   INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (visitID),
    CONSTRAINT fk_vtrack_visit FOREIGN KEY (visitID) REFERENCES visits (visitID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
