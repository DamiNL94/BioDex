-- F2a · Usuarios, sesión persistente ("seguir conectado"), verificación de email y límite de login.
-- Marcas de tiempo siempre calculadas en SQL (NOW()), nunca en PHP.

CREATE TABLE IF NOT EXISTS users (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email             VARCHAR(254) NOT NULL,
    email_verified_at DATETIME NULL,
    password_hash     VARCHAR(255) NOT NULL,
    name              VARCHAR(120) NOT NULL,
    orcid             CHAR(19) NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Patrón selector/validador (Lib/session.php): selector en claro, solo el hash del validador.
CREATE TABLE IF NOT EXISTS user_auth_tokens (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    selector       CHAR(18) NOT NULL,
    validator_hash CHAR(64) NOT NULL,
    previous_hash  CHAR(64) NULL,
    rotated_at     DATETIME NULL,
    last_used_at   DATETIME NULL,
    expires_at     DATETIME NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tokens_selector (selector),
    KEY ix_tokens_user (user_id),
    KEY ix_tokens_expires (expires_at),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Enlace de verificación: solo el hash SHA-256 del token que viaja en el correo.
CREATE TABLE IF NOT EXISTS email_verifications (
    token_hash CHAR(64) NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (token_hash),
    KEY ix_verif_user (user_id),
    CONSTRAINT fk_verif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Intentos FALLIDOS de login (5 por email o por IP en 15 min → 429). Sin FK: el email puede no existir.
CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email        VARCHAR(254) NOT NULL,
    ip           VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_attempts_email (email, attempted_at),
    KEY ix_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
