# F5b · Modo sin conexión en el dispositivo

Requiere F5a hecha. Lee `CLAUDE.md`, `docs/sync.md`, `docs/api.md` (solo sync y catálogos) y las firmas
exportadas de `frontend/js/**/*.js`. No leas NaturDex. Mapa (F7) y ficha de marca completa (F8) quedan fuera.

## 1. Base de datos local (`js/db.js`)
IndexedDB **sin librerías** (envoltorio propio con promesas, ≤ 150 líneas). Una base **por usuario**:
`biodex-<userId>` (dos cuentas en el mismo dispositivo no se mezclan). Almacenes:
- `catalogs` (id → datos, rol, `contentSeq`, `downloadedAt`)
- `species` (taxonID → ficha resumida del paquete offline) y `catalogSpecies` ([catalogId, taxonID] → nivel)
- `occurrences` (occurrenceID → marca DwC + `status`: `pending` · `synced` · `rejected`, `reason`,
  `authorId`, `generalized`, `serverSeq`, `clientUpdatedAt`, `deletedAt`)
- `outbox` (autoincremental: `{kind: 'species' | 'occurrence' | 'delete', ref, attempts, lastError}`)
- `meta` (cursor de pull por catálogo, `deviceId`, última sincronización)
Sustituye la copia de `localStorage` de F3b (resuelve los `TODO F5` y borra las claves `biodex.copy:*`).

## 2. «Llevar al campo»
- En cada catálogo, botón **«Descargar para usar sin conexión»** (`GET /catalogs/{id}/offline` + pull
  completo + imágenes en Cache Storage `biodex-img-<catalogId>`: miniatura y primera foto de cada especie).
  Muestra el tamaño estimado antes y el progreso durante. Al terminar: «Disponible sin conexión · 30/09 18:40».
- El catálogo activo se descarga solo al activarlo si hay red.
- Pide `navigator.storage.persist()` la primera vez y muestra en Ajustes el espacio usado (`storage.estimate()`).
- Sin red, **todas** las vistas (Catálogos, Especies, ficha) leen de IndexedDB. Nada de errores técnicos.

## 3. Marca mínima (para poder probar; F8 la completa)
En la ficha de especie y en cada fila de Especies: **«Marcar aquí»** → `getCurrentPosition`
(`enableHighAccuracy`, 30 s) → guarda en IndexedDB `{occurrenceID: crypto.randomUUID(), eventDate: ahora
con zona, decimalLatitude, decimalLongitude, coordinateUncertaintyInMeters: accuracy redondeado,
basisOfRecord: 'HumanObservation'}` con `status: pending` y entrada en `outbox`. Nota opcional.
- Sin GPS o permiso denegado: aviso de guarda claro; nunca una marca sin coordenadas.
- Bajo la ficha: «Mis marcas» con fecha, precisión (±m) y estado (pendiente · sincronizada · rechazada), y
  borrar las propias (borrado lógico que también se sincroniza).
- Las marcas ajenas generalizadas se ven con el aviso «Ubicación generalizada (~10 km)».

## 4. Motor de sincronización (`js/sync.js`)
- Se dispara al arrancar, al pasar a `online` (estado de F3b), cada 5 min con red y con **«Sincronizar
  ahora»** en Ajustes. Nunca dos a la vez (candado).
- Orden: primero especies creadas sin red, luego push de marcas en lotes de 200, luego pull hasta
  `hasMore: false`. Si `contentSeq` cambió, vuelve a descargar el paquete del catálogo.
- Resultados: `accepted`/`unchanged` → `synced`; `stale` → sustituye por la versión del servidor;
  `rejected` → queda visible en Ajustes › Sincronización con el motivo (reintentar o descartar a mano).
- Especie `409` → reasigna el `taxonID` en sus marcas locales y en la cola antes de enviarlas.
- Red caída a mitad → reintento con espera creciente (30 s, 1, 2, 5 min). **401 → la cola se conserva**
  y aparece «Inicia sesión para sincronizar (n pendientes)».
- Pull 403 `not_member` → borra ese catálogo del dispositivo; las marcas propias no sincronizadas se
  ofrecen para exportar antes (ver 5).
- Cabecera: junto al indicador de conexión, **«n pendientes»** cuando la cola no está vacía.

## 5. Cerrar sesión y respaldo
- Con marcas pendientes, cerrar sesión se **bloquea** con un diálogo: «Tienes n marcas sin sincronizar»
  → «Sincronizar ahora» · «Exportar respaldo» · «Cancelar». Nunca se borran sin que el usuario lo decida.
- «Exportar respaldo» descarga un JSON con las marcas pendientes (DwC) y su catálogo; «Importar respaldo»
  en Ajustes las devuelve a la cola (idempotente por `occurrenceID`).
- Sin pendientes: cerrar sesión borra la base `biodex-<userId>` y las cachés de imágenes (dispositivos compartidos).

## 6. Reglas
- Cada fichero JS nuevo a `SHELL_ASSETS`, subir `VERSION` y `APP_VERSION` (`0.5.0 · F5`); `check-sw.php` en verde.
- Todo HTML dinámico con `html```. Sin dependencias nuevas.

## 7. Comprobación (Edge headless por CDP; capturas en `docs/design/`)
Usa `Emulation.setGeolocationOverride` (un punto en Sierra Nevada) y `Network.emulateNetworkConditions`.
1. Con red: activar un catálogo con *Artemisia granatensis* (nivel 2) → descarga con progreso y fecha.
2. Sin red: recargar → Catálogos, Especies y fichas con imagen desde el dispositivo; banda de aviso.
3. Sin red: 3 marcas → «3 pendientes»; recargar → siguen ahí.
4. Volver la red → en ≤ 30 s «0 pendientes» y 3 filas en MySQL (compruébalo con `test-f5.php` o una consulta).
5. Segundo perfil como editor del mismo catálogo: ve las 3 marcas **generalizadas** con el aviso.
6. Cerrar sesión con 1 pendiente → diálogo; exportar respaldo; importar en limpio → vuelve a la cola.
7. Expulsar al editor (SQL) → su siguiente sync borra el catálogo de su dispositivo.
8. **A mano (Damián):** app instalada en Edge, `docker compose stop nginx`, marcar, `start` → se sincroniza sola.
Anota el resultado de cada punto en `docs/prompts/F5-checklist.md`. Commits `F5b:`. Resume en 8 líneas.
