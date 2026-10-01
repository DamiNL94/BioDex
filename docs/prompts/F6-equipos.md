# F6 · Equipos: invitaciones, roles y confianza

Requiere **F5b hecha** (el móvil ya sincroniza). Lee `CLAUDE.md`, `docs/api.md`, `docs/sync.md`, `docs/db.md`
y las firmas de `backend/Lib/{authz,sensitivity,audit,mail,sync}.php` y de `frontend/js/**/*.js`.
No leas NaturDex. Mapa (F7) fuera: la capa «Equipo» del mapa llegará allí.

## Principios
- **Confianza explícita:** nadie ve coordenadas exactas ajenas sin que el owner lo haya decidido para esa persona.
- **Un cambio de confianza se aplica también a lo que ya está en los móviles** (ver 3).
- Todo cambio de miembros, roles o niveles queda en `access_log`.

## 1. Migración `008_teams.sql`
- `catalog_invites`: `code` → **`code_hash`** (SHA-256 del código; el código en claro solo se muestra una vez),
  `max_uses` (1 para invitación por email; hasta 20 para enlace), `uses`, `revoked_at`.
- `catalog_members.visibility_epoch INT NOT NULL DEFAULT 1`.
- `access_log.action` añade `invite_created`, `invite_revoked`, `member_joined`, `member_removed`, `member_left`.
- `users.orcid` validado (formato `0000-0000-0000-000X` y dígito de control ISO 7064 11,2).

## 2. Endpoints (`Api/catalogs.php` o `Api/teams.php`, como encaje mejor)
| Método · ruta | Permiso | Qué hace |
|---|---|---|
| `GET /catalogs/{id}/members` | view | Miembros: nombre, email (solo al owner), ORCID, rol, email verificado, fecha de alta |
| `POST /catalogs/{id}/invites` | manage_members | `{email?, role: editor\|viewer, maxUses?, days? (1–30, def. 7)}` → código de 128 bits en base32, **devuelto una sola vez**. Con email: envía el enlace por correo (Mailpit en local) |
| `GET /catalogs/{id}/invites` | manage_members | Pendientes (sin el código), con usos y caducidad |
| `DELETE /catalogs/{id}/invites/{inviteId}` | manage_members | Revoca |
| `POST /invites/accept` `{code}` | sesión | Une al catálogo con el rol de la invitación. Invitación con email: solo si coincide con el de la cuenta. Caducada, revocada o agotada → 410. Ya miembro → 200 sin cambios. **Máx. 10 intentos fallidos por usuario y hora** (429) |
| `PUT /catalogs/{id}/members/{userId}` `{role}` | manage_members | `editor` ↔ `viewer` ↔ `custodian`. **Custodio solo con email verificado** (422 si no). No se puede cambiar el propio rol ni tocar al owner |
| `DELETE /catalogs/{id}/members/{userId}` | manage_members | Expulsa (el móvil del expulsado borra el catálogo en su siguiente sync, ya hecho en F5) |
| `POST /catalogs/{id}/leave` | view | Salir. El owner no puede salir sin transferir antes |
| `POST /catalogs/{id}/transfer` `{userId}` | owner | Transfiere la propiedad a un custodio; el owner anterior pasa a custodio. Pide contraseña de nuevo |
| `GET /catalogs/{id}/audit?limit=50` | owner | Registro de accesos y cambios del catálogo, legible |
| `PATCH /auth/me` `{name?, orcid?}` | sesión | Perfil |

## 3. Revocar confianza también en los móviles
Si un miembro **pierde acceso a exactas** (custodio → editor/lector) o el owner **sube el nivel** de una
especie, su móvil puede tener guardadas coordenadas exactas que ya no le corresponden.
- Cualquiera de esos cambios incrementa `visibility_epoch` del miembro afectado (subida de nivel: de todos
  los miembros sin acceso a exactas).
- `GET /sync/pull` devuelve `visibilityEpoch`. Si el móvil tiene uno anterior: **borra de IndexedDB todas las
  marcas ajenas de ese catálogo**, pone el cursor a 0 y vuelve a descargar (solo recibe lo que ahora le toca).
  Las propias nunca se tocan.
- Prueba explícita en `test-f6.php` y en el recorrido CDP (punto 5).

## 4. Interfaz
- **Catálogo › Equipo** (`js/views/catalog-team.js`), dentro del detalle del catálogo:
  - Lista de miembros con rol en versalitas; iconos de «email verificado» y ORCID (enlace a orcid.org).
  - Owner: invitar por email o crear enlace (rol, usos, días) → muestra el enlace **una vez** con botón
    copiar y aviso «Guárdalo ahora; no se volverá a mostrar». Invitaciones pendientes y revocar.
  - Owner: cambiar rol. Al conceder **custodio**, diálogo que explique qué implica: «Verá las coordenadas
    exactas de todas las especies, también las amenazadas». Al quitarlo: «Sus dispositivos borrarán las
    coordenadas exactas en la próxima sincronización».
  - Owner: expulsar (diálogo propio) · transferir propiedad. No owner: «Salir del catálogo».
- **Registro** (owner): tabla fecha · persona · acción · detalle, en versalitas los tipos de acción.
- **Niveles de sensibilidad del catálogo** (owner): tabla especie · sugerido · actual · motivo · autor del
  cambio, con edición en línea (misma regla: bajar exige motivo). Aviso al subir un nivel: «Los dispositivos
  del equipo sin confianza borrarán las coordenadas exactas de esta especie».
- **Unirse:** ruta `#/unirse/<código>`. Sin sesión → acceso y luego vuelve a la ruta. Muestra nombre del
  catálogo, owner y rol antes de aceptar. También campo «Tengo un código» en Catálogos.
- **Ajustes › Perfil:** nombre y ORCID (validado en el cliente también).
- **Marcas del equipo** en la ficha de especie: debajo de «Mis marcas», las de otros con autor, fecha,
  precisión y el aviso de generalización cuando aplique.
- Todo sin red: Equipo, Registro y Niveles se muestran desde lo último descargado con «Datos de las HH:MM»;
  las acciones que cambian cosas se desactivan con aviso («Necesita conexión»).

## 5. Comprobación
`backend/bin/test-f6.php` (A owner, B, C):
- Invitación por email a B → B acepta → editor. C intenta ese código → 403 (email no coincide).
- Enlace con `maxUses=1` → C acepta; segundo uso → 410. Código revocado → 410. 11 intentos malos → 429.
- B a custodio sin email verificado → 422; verificado → 200 y `grant_role` en el registro.
- Nivel 2: B (custodio) recibe exactas; A le baja a editor → el siguiente pull devuelve `visibilityEpoch` nuevo
  y solo generalizadas. A sube una especie de nivel 4 a 2 → epoch de los no confiados sube.
- Owner no puede salir; transferir a un custodio con contraseña correcta → funciona.
- ORCID `0000-0002-1825-0097` válido; `0000-0002-1825-0098` inválido.
Recorrido CDP con dos perfiles (capturas en `docs/design/`):
1. A invita a B por email → correo en Mailpit → B abre el enlace sin sesión → entra → acepta.
2. B marca sin red una especie de nivel 2 → sincroniza → A la ve exacta.
3. A hace custodio a B → B ve exactas las marcas de A.
4. A quita custodio a B → tras sincronizar, **en IndexedDB de B ya no queda ninguna coordenada exacta ajena**
   (compruébalo leyendo la base, no solo la pantalla).
5. A expulsa a B → el catálogo desaparece del dispositivo de B.
Anota los resultados en `docs/prompts/F6-checklist.md`; `docs/api.md` y `docs/sync.md` al día.
`check-sw.php` en verde, `VERSION` y `APP_VERSION` (`0.6.0 · F6`). Commits `F6:`. Resume en 8 líneas.
