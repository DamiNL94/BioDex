# Esquema de base de datos

MySQL 8.4, InnoDB, `utf8mb4_0900_ai_ci` (comparaciones insensibles a acentos y mayúsculas). Migraciones en
`backend/migrations/NNN_*.sql`, aplicadas con `docker compose exec php php bin/migrate.php` (registro en
`schema_migrations`; idempotente). UUID = `CHAR(36)`; `taxonID` = `VARCHAR(64)`. Marcas de tiempo con `NOW()` en SQL.

## Usuarios y sesión
| Tabla | Propósito | Campos clave |
|---|---|---|
| `users` | Cuentas | `email` único, `password_hash` (argon2id), `email_verified_at` (NULL = sin verificar: no podrá ser custodio), `orcid` (`CHECK` de formato; el dígito de control ISO 7064 11,2 lo comprueba PHP, `orcidNormalize()`) |
| `user_auth_tokens` | "Seguir conectado" (selector/validador, 90 días deslizantes) | `selector` único en claro, `validator_hash`/`previous_hash` SHA-256, `rotated_at` (gracia 60 s), `expires_at` |
| `email_verifications` | Enlaces de verificación (48 h, un uso) | `token_hash` SHA-256, `user_id`, `used_at` |
| `login_attempts` | Intentos fallidos para el límite de 5 / 15 min | `email`, `ip`, `attempted_at` |

## Especies
| Tabla | Propósito | Campos clave |
|---|---|---|
| `species` | Caché de EIDOS + especies de usuario | PK `taxonID` (entero EIDOS como texto o `biodex:<uuid>`), `source` `eidos`/`user`, términos DwC (`scientificName`, `taxonRank`, `kingdom`…`family`, `vernacularName`), `canonical_name` (sin autor, índice), `synonyms`, `iucn_assessments`, `legal_status` (`{general, andalucia}`), `images` (JSON), `iucn_primary_code/scope/year`, `detail_fetched_at` (ficha completa, caduca a 30 días), `legal_fetched_at` (solo estado legal, filtro del buscador), `kingdom_id`…`family_id` (ids de EIDOS, para el árbol), `classification_source` eidos/gbif/user, `taxonRemarks`, `created_by` |

| `species_photos` | Foto de respaldo de iNaturalist (F11b, migración 012; `docs/fotos-respaldo.md`). Una fila por especie, global; solo especies de EIDOS sin foto de la Fototeca | PK `taxonID` → `species` (cascada), `source` `inaturalist` (sitio para `commons`/`gbif`), `photo_id` (id de la foto en iNat), `photo_ext` jpg·jpeg·png, `inat_taxon_id`, `matched_name` (nombre en iNat), `match_type` exact·synonym, `attribution` (tal cual de iNat), `license` cc0·cc-by·cc-by-nc, `status` pending·ok·review·rejected·none (solo `ok` se muestra), `checked_at`, `reviewed_by` → `users` (SET NULL), `reviewed_at` |

Mapeo columna ← vista de EIDOS: `docs/eidos.md`. `order` es palabra reservada: siempre entre comillas invertidas.

## Catálogos
| Tabla | Propósito | Campos clave |
|---|---|---|
| `catalogs` | Catálogo compartido | `id` UUID, `owner_id`, `content_seq` (sube con cada cambio de especies, niveles o datos: el móvil vuelve a bajar el paquete offline), `deleted_at` (borrado lógico) |
| `catalog_members` | Rol de cada usuario | PK (`catalog_id`, `user_id`), `role` owner/custodian/editor/viewer, `granted_by`, `visibility_epoch` (F6: sube cuando cambia lo que ve de las marcas ajenas; `docs/sync.md`) |
| `catalog_invites` | Invitaciones por código (F6) | PK `id`, `code_hash` único (SHA-256 del código; el código en claro solo sale al crearla), `email` (NULL = enlace), `role` editor/viewer, `max_uses` (1 por email; ≤ 20 enlace) y `uses`, `expires_at`, `revoked_at`, `created_by`. Quién entró por cada una: `access_log` (`member_joined`) |
| `invite_attempts` | Códigos inexistentes (F6) | `user_id`, `attempted_at`: 10 por usuario y hora → 429 |
| `catalog_species` | Especies del catálogo | PK (`catalog_id`, `taxonID`), `added_by`, `deleted_at` |
| `species_sensitivity` | Nivel 1–4 por especie y catálogo | PK (`catalog_id`, `taxonID`) → `catalog_species`; `level` efectivo, `suggested_level` (desde EIDOS), `reason`, `set_by` (NULL = sugerencia sin confirmar) |

Permisos por rol: `backend/Lib/authz.php` (`CATALOG_PERMISSIONS`). Niveles y generalización: `shared/sensitivity.json`
y `backend/Lib/sensitivity.php`.

## Observaciones
| Tabla | Propósito | Campos clave |
|---|---|---|
| `occurrences` | Marcas de campo | PK `occurrenceID` (UUID v4 del cliente), `catalog_id`, `user_id` (autor), `taxonID`; DwC exactos: `eventDate` (UTC), `decimalLatitude`/`decimalLongitude` DECIMAL(10,7) **exactas, nunca se modifican**, `coordinateUncertaintyInMeters`, `occurrenceStatus` present/absent (F8a), `individualCount`, `lifeStage` y `sex` (vocabularios cerrados de GBIF, F8a), `habitat`, `georeferenceRemarks`, `georeferenceProtocol` («GPS del dispositivo» · «Punto marcado en el mapa»), `occurrenceRemarks`, `basisOfRecord`, `geodeticDatum`; `mature_count`, `client_updated_at` DATETIME(3) UTC (última edición en el dispositivo: decide los conflictos), `device_id`, `deleted_at`, `server_seq` único. `iucn_assessment` se quitó en 009 (la evaluación es de la especie, F8b) |
| `species_notes` | Notas de cuaderno por especie y catálogo (F8a) | PK `noteID` (UUID v4 del cliente), `catalog_id`, `taxonID`, `user_id` (autor: solo él edita), `text` (≤ 4000), `client_updated_at` DATETIME(3), `device_id`, `deleted_at`, `server_seq` único (del mismo `sync_seq`) |
| `species_assessments` | Evaluaciones UICN de una especie en un catálogo (F8b, `docs/iucn.md`) | PK `assessmentID` (UUID v4 del cliente), `catalog_id`, `taxonID`, `user_id` (autor: solo él edita), `inputs` y `result` JSON (≤ 32 KB cada uno; el servidor no recalcula), `category` CR·EN·VU·NT·LC·DD (tras el ajuste), `regional_adjustment` down·up, `justification` (≤ 4000, obligatoria con ajuste), `status` draft·final; referencia del owner: `is_reference`, `reference_set_by`, `reference_set_at` y `reference_key` generada y **única** (una por especie y catálogo); `client_updated_at`, `device_id`, `deleted_at`, `server_seq` único |
| `sync_seq` | Contador global de sincronización (una fila) | `value`; cada insert/update de `occurrences`, `species_notes` y `species_assessments` toma el siguiente |
| `species_metrics` | EOO/AOO definitivos calculados en el servidor (F9, migración 011) | `catalog_id`, `taxonID`, `scope` me/team, `user_id` (NULL en team), `eoo_km2`, `eoo_adjusted` (EOO < AOO → igualada), `aoo_km2`, `aoo_cells`, `aoo_min_km2`/`aoo_max_km2` (rejilla desplazada), `cell_size_km` (2 o 1, siempre el usado), `n_records`, `n_excluded`, `warnings` JSON, `source_seq` (máximo `server_seq` de las marcas de la especie al calcular: si hay uno mayor, se recalcula), `computed_at`; único por (catálogo, taxón, scope, usuario vía `owner_key`, celda). Sin geometría: el casco y las celdas se sacan al responder, según la confianza |

Siguiente `server_seq` (`syncNextSeq()` en `Lib/sync.php`), dentro de la misma transacción que la escritura
(protocolo en `docs/sync.md`):

```sql
UPDATE sync_seq SET value = LAST_INSERT_ID(value + 1) WHERE id = 1;
SELECT LAST_INSERT_ID();
```

Las coordenadas que salen del servidor pasan SIEMPRE por `applyVisibility()` (autor, owner y custodian ven la exacta;
el resto, generalizada según `species_sensitivity.level`).

## Auditoría
| Tabla | Propósito | Campos clave |
|---|---|---|
| `access_log` | Rastro de accesos sensibles | `action` view_exact/export_full/set_level/grant_role/revoke_role/login_failed (F6) invite_created/invite_revoked/member_joined/member_removed/member_left y (F8b) set_reference, `user_id`, `catalog_id`, `target` (`user:<id> editor → custodian`, `invite:<id> …`, F10a `zip completo: n registros, m especies`), `ip`, `created_at`; las FK pasan a NULL al borrar, el rastro se conserva. (F11b) photo_review. `GET /catalogs/{id}/audit` lo devuelve legible |

## Borrados
- Lógicos (`deleted_at`) en `catalogs`, `catalog_species`, `occurrences`, `species_notes` y `species_assessments`.
- `occurrences`, `species_notes` y `species_assessments` → `catalogs`/`users`/`species` con `ON DELETE RESTRICT`: una
  marca, una nota o una evaluación nunca desaparece en cascada.
- `species_photos` se borra en cascada con su especie.
- Tokens, verificaciones, miembros, invitaciones, intentos de invitación y métricas se borran en cascada con su usuario o catálogo.
- Salir o ser expulsado borra la fila de `catalog_members` (no hay rol «ex-miembro»); sus marcas se quedan.
