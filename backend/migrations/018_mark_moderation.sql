-- Moderación de marcas por el owner del catálogo (Catálogo › Marcas; Api/marks.php, docs/api.md).
--   occurrences.deleted_by   quién la borró cuando NO fue su autor (el owner). Con valor, el autor ya no puede
--                            deshacer el borrado con una edición más reciente: el push la rechaza
--                            (reason «deleted_by_owner»). NULL = la borró su autor, o no está borrada.
--   access_log 'delete_marks' una fila por cada borrado en lote: nº de marcas, autores y motivo.

ALTER TABLE occurrences
    ADD COLUMN deleted_by INT UNSIGNED NULL AFTER deleted_at,
    ADD CONSTRAINT fk_occ_deleted_by FOREIGN KEY (deleted_by) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE access_log
    MODIFY action ENUM('view_exact','export_full','set_level','grant_role','revoke_role','login_failed',
                       'invite_created','invite_revoked','member_joined','member_removed','member_left',
                       'set_reference','photo_review','set_parameter','set_locations','delete_marks') NOT NULL;
