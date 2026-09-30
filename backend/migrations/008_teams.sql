-- F6 · Equipos: invitaciones con código cifrado y usos, época de visibilidad por miembro, nuevas acciones
-- de auditoría, ORCID validado y límite de intentos al aceptar invitaciones.

-- Invitaciones: el código solo se guarda como SHA-256 (se muestra una vez al crearlo). Un enlace admite
-- varios usos (max_uses ≤ 20); una invitación por email, uno. used_at/used_by dejan de tener sentido con
-- varios usos: quién entró por cada invitación queda en access_log (member_joined).
ALTER TABLE catalog_invites
    DROP FOREIGN KEY fk_invites_used_by,
    DROP PRIMARY KEY,
    ADD COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST,
    ADD COLUMN code_hash CHAR(64) NULL AFTER id,
    ADD COLUMN max_uses TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER role,
    ADD COLUMN uses TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER max_uses,
    ADD COLUMN revoked_at DATETIME NULL AFTER expires_at;

UPDATE catalog_invites SET code_hash = SHA2(code, 256);

ALTER TABLE catalog_invites
    MODIFY code_hash CHAR(64) NOT NULL,
    ADD UNIQUE KEY uq_invites_code_hash (code_hash),
    DROP COLUMN code,
    DROP COLUMN used_at,
    DROP COLUMN used_by,
    ADD CONSTRAINT ck_invites_uses CHECK (max_uses BETWEEN 1 AND 20 AND uses <= max_uses);

-- Sube cada vez que el miembro pierde o gana acceso a coordenadas exactas (custodio ↔ otro rol) o que
-- cambia el nivel de una especie y él no es de confianza. El pull la devuelve: un móvil con otra borra
-- las marcas ajenas de ese catálogo y las vuelve a bajar (docs/sync.md).
ALTER TABLE catalog_members
    ADD COLUMN visibility_epoch INT UNSIGNED NOT NULL DEFAULT 1 AFTER granted_by;

ALTER TABLE access_log
    MODIFY action ENUM('view_exact','export_full','set_level','grant_role','revoke_role','login_failed',
                       'invite_created','invite_revoked','member_joined','member_removed','member_left') NOT NULL;

-- ORCID: formato 0000-0000-0000-000X aquí; el dígito de control (ISO 7064 11,2) lo comprueba PHP.
UPDATE users SET orcid = NULL WHERE orcid IS NOT NULL AND NOT REGEXP_LIKE(orcid, '^[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{3}[0-9X]$', 'c');
ALTER TABLE users
    ADD CONSTRAINT ck_users_orcid CHECK (orcid IS NULL OR REGEXP_LIKE(orcid, '^[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{3}[0-9X]$', 'c'));

-- Códigos de invitación que no existen: 10 por usuario y hora → 429 (adivinar códigos no sale a cuenta).
CREATE TABLE IF NOT EXISTS invite_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_invite_attempts_user (user_id, attempted_at),
    CONSTRAINT fk_invite_attempts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
