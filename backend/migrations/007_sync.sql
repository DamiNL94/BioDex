-- F5a · Sincronización: hora de edición en el dispositivo y dispositivo de origen de cada marca, y versión
-- del contenido del catálogo (especies y niveles) para que el móvil sepa cuándo volver a descargarlo.

-- client_updated_at (UTC, con milisegundos) decide los conflictos: gana la edición más reciente.
-- El DEFAULT solo rellena las filas previas; se quita justo después (cada push lo trae siempre).
ALTER TABLE occurrences
    ADD COLUMN client_updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) AFTER iucn_assessment,
    ADD COLUMN device_id         CHAR(36) NULL AFTER client_updated_at;

ALTER TABLE occurrences ALTER COLUMN client_updated_at DROP DEFAULT;

-- Sube al añadir o quitar especies, cambiar niveles o renombrar el catálogo (Api/catalogs.php).
ALTER TABLE catalogs
    ADD COLUMN content_seq BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER description;
