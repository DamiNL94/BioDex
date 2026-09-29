# Esquema de base de datos

MySQL 8.4, InnoDB, `utf8mb4_0900_ai_ci` (comparaciones insensibles a acentos y mayúsculas). Migraciones en
`backend/migrations/NNN_*.sql`, aplicadas con `docker compose exec php php bin/migrate.php` (registro en
`schema_migrations`; idempotente). UUID = `CHAR(36)`; `taxonID` = `VARCHAR(64)`. Marcas de tiempo con `NOW()` en SQL.

## Usuarios y sesión
| Tabla | Propósito | Campos clave |
|---|---|---|
| `users` | Cuentas | `email` único, `password_hash` (argon2id), `email_verified_at` (NULL = sin verificar: no podrá ser custodio), `orcid` |
| `user_auth_tokens` | "Seguir conectado" (selector/validador, 90 días deslizantes) | `selector` único en claro, `validator_hash`/`previous_hash` SHA-256, `rotated_at` (gracia 60 s), `expires_at` |
| `email_verifications` | Enlaces de verificación (48 h, un uso) | `token_hash` SHA-256, `user_id`, `used_at` |
| `login_attempts` | Intentos fallidos para el límite de 5 / 15 min | `email`, `ip`, `attempted_at` |

## Especies
| Tabla | Propósito | Campos clave |
|---|---|---|
| `species` | Caché de EIDOS + especies de usuario | PK `taxonID` (entero EIDOS como texto o `biodex:<uuid>`), `source` `eidos`/`user`, términos DwC (`scientificName`, `taxonRank`, `kingdom`…`family`, `vernacularName`), `canonical_name` (sin autor, índice), `synonyms`, `iucn_assessments`, `legal_status` (`{general, andalucia}`), `images` (JSON), `iucn_primary_code/scope/year`, `detail_fetched_at` (ficha completa, caduca a 30 días), `legal_fetched_at` (solo estado legal, filtro del buscador), `kingdom_id`…`family_id` (ids de EIDOS, para el árbol), `classification_source` eidos/gbif/user, `taxonRemarks`, `created_by` |

Mapeo columna ← vista de EIDOS: `docs/eidos.md`. `order` es palabra reservada: siempre entre comillas invertidas.

## Catálogos
| Tabla | Propósito | Campos clave |
|---|---|---|
| `catalogs` | Catálogo compartido | `id` UUID, `owner_id`, `deleted_at` (borrado lógico) |
| `catalog_members` | Rol de cada usuario | PK (`catalog_id`, `user_id`), `role` owner/custodian/editor/viewer, `granted_by` |
| `catalog_invites` | Invitaciones por código | PK `code`, `email` (NULL = enlace abierto), `role` editor/viewer, `expires_at`, `used_at`/`used_by` |
| `catalog_species` | Especies del catálogo | PK (`catalog_id`, `taxonID`), `added_by`, `deleted_at` |
| `species_sensitivity` | Nivel 1–4 por especie y catálogo | PK (`catalog_id`, `taxonID`) → `catalog_species`; `level` efectivo, `suggested_level` (desde EIDOS), `reason`, `set_by` (NULL = sugerencia sin confirmar) |

Permisos por rol: `backend/Lib/authz.php` (`CATALOG_PERMISSIONS`). Niveles y generalización: `shared/sensitivity.json`
y `backend/Lib/sensitivity.php`.

## Observaciones
| Tabla | Propósito | Campos clave |
|---|---|---|
| `occurrences` | Marcas de campo | PK `occurrenceID` (UUID v4 del cliente), `catalog_id`, `user_id` (autor), `taxonID`; DwC exactos: `eventDate` (UTC), `decimalLatitude`/`decimalLongitude` DECIMAL(10,7) **exactas, nunca se modifican**, `coordinateUncertaintyInMeters`, `individualCount`, `lifeStage`, `occurrenceRemarks`, `basisOfRecord`, `geodeticDatum`; `mature_count`, `iucn_assessment` JSON, `deleted_at`, `server_seq` único |
| `sync_seq` | Contador global de sincronización (una fila) | `value`; cada insert/update de `occurrences` toma el siguiente |
| `species_metrics` | EOO/AOO calculados en el servidor | `catalog_id`, `taxonID`, `scope` me/team, `user_id` (NULL en team), `eoo_km2`, `aoo_km2`, `aoo_cells`, `cell_size_km`, `n_records`; único por (catálogo, taxón, scope, usuario) vía `owner_key` |

Siguiente `server_seq` (F5), dentro de la misma transacción que la escritura:

```sql
UPDATE sync_seq SET value = LAST_INSERT_ID(value + 1) WHERE id = 1;
SELECT LAST_INSERT_ID();
```

Las coordenadas que salen del servidor pasan SIEMPRE por `applyVisibility()` (autor, owner y custodian ven la exacta;
el resto, generalizada según `species_sensitivity.level`).

## Auditoría
| Tabla | Propósito | Campos clave |
|---|---|---|
| `access_log` | Rastro de accesos sensibles | `action` view_exact/export_full/set_level/grant_role/revoke_role/login_failed, `user_id`, `catalog_id`, `target`, `ip`, `created_at`; las FK pasan a NULL al borrar, el rastro se conserva |

## Borrados
- Lógicos (`deleted_at`) en `catalogs`, `catalog_species` y `occurrences`.
- `occurrences` → `catalogs`/`users`/`species` con `ON DELETE RESTRICT`: una marca nunca desaparece en cascada.
- Tokens, verificaciones, miembros, invitaciones y métricas se borran en cascada con su usuario o catálogo.
