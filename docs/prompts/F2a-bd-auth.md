# F2a · Base de datos, sesión y librerías de permisos

Lee `CLAUDE.md`, `docs/eidos.md` (solo la sección «Mapeo EIDOS → tabla species») y `shared/sensitivity.json`.
De NaturDex lee SOLO `../NaturDex/Backend/Lib/session.php` y `../NaturDex/Backend/Api/auth.php`.
No toques `frontend/` ni `backend/Lib/eidos.php`. Sin endpoints de catálogos ni especies (eso es F2b).

## 1. Migraciones
- `backend/migrations/NNN_nombre.sql` + `backend/bin/migrate.php` (aplica en orden, registra en
  `schema_migrations`, idempotente: un segundo pase no hace nada).
- MySQL 8.4, `utf8mb4` con colación `utf8mb4_0900_ai_ci` (insensible a acentos: la caché de especies
  la necesita porque `ilike` de EIDOS no ignora acentos). InnoDB, FKs con `ON DELETE` explícito.
- UUID como `CHAR(36)`; `taxonID` `VARCHAR(64)` (entero de EIDOS como texto o `biodex:<uuid>`).
- Tablas (campos mínimos; añade índices para las búsquedas previstas):
  - `users` (id, email UNIQUE, email_verified_at, password_hash, name, orcid NULL, created_at)
  - `user_auth_tokens` (como NaturDex) · `login_attempts` (email, ip, attempted_at)
  - `species` — columnas del mapeo de `docs/eidos.md`, más: `canonical_name` (índice), `synonyms` JSON,
    `iucn_assessments` JSON (todas las vigentes: código, ámbito, año, criterios), `iucn_primary_code`,
    `iucn_primary_scope`, `legal_status` JSON, `images` JSON, `detail_fetched_at`, `created_by` NULL.
    `source` ENUM('eidos','user').
  - `catalogs` (id UUID, owner_id, name, description, created_at, updated_at, deleted_at)
  - `catalog_members` (catalog_id, user_id, role ENUM('owner','custodian','editor','viewer'), granted_by, joined_at; PK compuesta)
  - `catalog_invites` (code, catalog_id, email NULL, role ENUM('editor','viewer'), expires_at, used_at, created_by)
  - `catalog_species` (catalog_id, taxonID, added_by, added_at, deleted_at; PK compuesta)
  - `species_sensitivity` (catalog_id, taxonID, level TINYINT 1–4, suggested_level, reason, set_by, set_at)
  - `occurrences` — occurrenceID UUID PK; catalog_id, user_id, taxonID; términos DwC con nombre exacto
    (`eventDate`, `decimalLatitude` DECIMAL(10,7), `decimalLongitude` DECIMAL(10,7),
    `coordinateUncertaintyInMeters`, `individualCount`, `lifeStage`, `occurrenceRemarks`, `basisOfRecord`
    default 'HumanObservation', `geodeticDatum` default 'EPSG:4326'); `mature_count`, `iucn_assessment` JSON,
    `created_at`, `updated_at`, `deleted_at`, `server_seq` BIGINT UNIQUE.
  - `sync_seq` (una fila, contador) — cada insert/update de `occurrences` toma `server_seq` de aquí (lo usará F5).
  - `species_metrics` (catalog_id, taxonID, scope ENUM('me','team'), user_id NULL, eoo_km2, aoo_km2, aoo_cells, cell_size_km, n_records, computed_at)
  - `access_log` (id, user_id, catalog_id NULL, action ENUM('view_exact','export_full','set_level','grant_role','revoke_role','login_failed'), target, created_at)

## 2. Sesión (portada de NaturDex)
- `Lib/session.php`: mismo patrón selector/validador; cookie `biodex_remember`, `HttpOnly`, `SameSite=Lax`,
  `Secure` si HTTPS; caducidad deslizante **90 días**. Marcas de tiempo en SQL, como en NaturDex.
- `Api/auth.php`: `POST /api/auth/register`, `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`.
  Rutas por subruta (el router pasa `/api/auth/...`; parsea el resto del path), no `?action=`.
- Contraseña: `password_hash` (bcrypt/argon2id), mínimo 10 caracteres.
- Límite: 5 intentos fallidos por email o IP en 15 min → 429. Registrar `login_failed` en `access_log`.
- Verificación de email: al registrarse se envía un enlace (`GET /api/auth/verify?token=`). Añade
  **Mailpit** a `docker-compose.yml` (SMTP 1025, web en :8025) y un `Lib/mail.php` mínimo sin dependencias.
  Una cuenta sin verificar puede entrar, pero no podrá ser custodio (lo comprobará F6).

## 3. Librerías de permisos y sensibilidad (sin endpoints todavía)
- `Lib/authz.php`: `catalogRole(PDO, catalogId, userId): ?string` y
  `requireCatalogRole(PDO, catalogId, userId, array $roles): string` (403 si no). Matriz en un array constante:
  | Acción | owner | custodian | editor | viewer |
  |---|---|---|---|---|
  | ver catálogo y especies | ✓ | ✓ | ✓ | ✓ |
  | añadir especie | ✓ | ✓ | ✓ | – |
  | quitar especie, editar catálogo, niveles, miembros | ✓ | – | – | – |
  | marcar | ✓ | ✓ | ✓ | – |
  | ver coordenadas exactas ajenas, exportación completa | ✓ | ✓ | – | – |
- `Lib/sensitivity.php` (lee `shared/sensitivity.json`; añade `./shared:/var/www/shared:ro` a los volúmenes de `php`):
  - `suggestSensitivityLevel(array $iucnAssessments, array $legalStatus): array` → `[level, reason]`.
    Usa el código UICN, nunca el literal; gana el nivel más restrictivo.
  - `canSeeExact(string $role, bool $isAuthor): bool`
  - `generalizePoint(float $lat, float $lon, int $level, ?int $uncertaintyM): array` → ajuste a rejilla
    (floor + centro de celda), `coordinateUncertaintyInMeters` = máx(original, semidiagonal de la celda),
    `dataGeneralizations`/`informationWithheld` según el JSON; nivel 1 → sin coordenadas.
  - `applyVisibility(array $occ, int $level, string $role, int $viewerId): array` — la única función
    que decide qué coordenadas salen del servidor.
- `Lib/audit.php`: `logAccess(PDO, userId, action, catalogId, target)`.

## 4. Pruebas
`backend/bin/test-f2a.php` (PHP puro, sin frameworks; imprime OK/FAIL y sale con código ≠ 0 si falla):
- migrate dos veces → sin cambios.
- register → login → me → logout → me 401; 6.º login fallido → 429; cookie rota el validador.
- `suggestSensitivityLevel`: *Artemisia granatensis* (CR) → 2; *Atropa baetica* (EN) → 3; *Parnassius
  apollo* (LC, pero revisa su legal) → el que toque; especie sin datos → 4. Usa las muestras de `docs/eidos-samples/`.
- `generalizePoint(37.0921, -3.3947, 2, 5)` → centro de la celda de 0,1° (37.05, -3.35), incertidumbre ≈ 7,1 km (semidiagonal de la celda a 37° N);
  nivel 3 → 0,01°; nivel 1 → sin coordenadas; misma entrada → misma salida.
- `applyVisibility`: autor ve exacto; editor ajeno ve generalizado; custodio ve exacto; nivel 4 → exacto para todos.

## Documentación
Rellena `docs/db.md`: una tabla por tabla con propósito y campos clave (≤ 80 líneas). Nada de volcar el SQL.

## Criterio de hecho
- `docker compose exec php php bin/migrate.php` y `php bin/test-f2a.php` en verde.
- Mailpit recibe el correo de verificación en http://localhost:8025.
- Commits pequeños con prefijo `F2a:`. Resume en 8 líneas al terminar.
