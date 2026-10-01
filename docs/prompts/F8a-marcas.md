# F8a · Marcas completas y notas de especie

Requiere F7 hecha. Lee `CLAUDE.md`, `docs/sync.md`, `docs/db.md` y las firmas de `backend/Lib/sync.php`,
`frontend/js/{marks,sync,db}.js` y `frontend/js/views/species-detail.js`. De NaturDex, solo
`Docs/DARWIN-CORE.md` §3 («De dónde sale cada término») como referencia de términos. No toques el mapa salvo
lo indicado en 3.

## 1. Datos (migración `009_marks.sql`)
Nuevas columnas de `occurrences`, con el nombre DwC exacto:
- `occurrenceStatus` ENUM('present','absent') NOT NULL DEFAULT 'present' — **las ausencias en un punto
  prospectado también son dato científico** (p. ej. revisitar una localidad conocida y no encontrarla).
- `sex` (vocabulario GBIF: `male`, `female`, `hermaphrodite`, `undetermined`), `habitat` (texto ≤ 500),
  `georeferenceRemarks` (≤ 255), `georeferenceProtocol` (`GPS del dispositivo` · `Punto marcado en el mapa`).
- `lifeStage` pasa a vocabulario controlado: `adult` · `juvenile` · `subadult` · `larva` · `egg` ·
  `seedling` · `flowering` · `fruiting` · `vegetative` · `undetermined` (etiquetas en castellano en la UI).
Reglas nuevas en la validación del push: `mature_count ≤ individualCount`; `occurrenceStatus = absent` ⇒
`individualCount = 0` o nulo y sin `mature_count`; vocabularios cerrados.
**`occurrences.iucn_assessment` deja de usarse**: la evaluación UICN es de la especie, no de una marca (F8b).

Tabla `species_notes`: `noteID` (UUID del cliente), `catalog_id`, `taxonID`, `user_id`, `text` (≤ 4000),
`client_updated_at`, `deleted_at`, `server_seq`. Notas de cuaderno por especie, fechadas y con autor.

## 2. Sincronización de entidades nuevas
Amplía push y pull a **tipos de entidad** sin romper lo que hay: `occurrences` (como ahora), `notes`
(y en F8b `assessments`). Mismas reglas: UUID del cliente, `clientUpdatedAt`, solo el autor edita,
`server_seq` común en la misma transacción, tombstones. Las notas no llevan coordenadas: no pasan por
`applyVisibility`, pero **solo se envían a miembros con permiso `view`**. Actualiza `docs/sync.md`.

## 3. Interfaz
- **Hoja de marca** (`js/views/mark-sheet.js`), al pulsar «Marcar aquí» en la ficha, la lista o el mapa. Se
  abre ya con la posición GPS y la precisión; todo lo demás es opcional y rápido de rellenar con una mano:
  - Presente / **Ausente** (conmutador grande).
  - Nº de individuos (botones − / + y campo), **«Individuo maduro»** (casilla; si se marca con varios
    individuos, pregunta «¿cuántos maduros?» con el total por defecto) → `lifeStage: adult` y `mature_count`.
  - Estado vital (lista corta según reino: animales y plantas tienen valores distintos), sexo (solo fauna).
  - Hábitat (texto, recuerda el último usado en esa sesión) y observaciones.
  - «Guardar» siempre visible; guardar sin rellenar nada más debe seguir siendo posible (es lo que se hace
    con prisa en el campo).
- **Marcar en un punto del mapa** (individuo visto a distancia, con prismáticos): mantener pulsado en el mapa
  → la misma hoja, con `georeferenceProtocol: Punto marcado en el mapa`, precisión **obligatoria** estimada
  por el usuario (m) y `georeferenceRemarks`. El marcador lo distingue (borde discontinuo).
- **Editar y borrar** las marcas propias desde «Mis marcas» (la edición sube `clientUpdatedAt`).
- **Notas de la especie** en la ficha: lista cronológica con autor y fecha, añadir/editar/borrar las propias,
  sin red también.
- **Resumen de la especie** en la ficha (datos de este catálogo, propios y del equipo): nº de marcas, nº de
  ausencias, **individuos máximos en un mismo día**, suma de maduros registrados, primera y última fecha.
  Con la nota visible: «Suma de registros, puede contar varias veces a los mismos individuos».

## 4. Reglas
Nuevos ficheros a `SHELL_ASSETS`; `VERSION`, `APP_VERSION` `0.8.0 · F8a`; `check-sw.php` en verde. Todo HTML con
`html```. Objetivos táctiles ≥ 48 px en la hoja de marca (se usa con guantes o al sol).

## 5. Comprobación
- `backend/bin/test-f8a.php`: vocabularios, `mature_count > individualCount` → rechazo, ausencia con
  individuos → rechazo, notas push/pull con autoría, idempotencia y tombstones, notas no visibles para un no miembro.
- Recorrido CDP (capturas): marca completa sin red (2 individuos, 1 maduro, hábitat); marca de ausencia; marca
  en punto del mapa con precisión 150 m y borde discontinuo; editar una marca y ver `accepted` al sincronizar;
  nota de especie sin red que aparece en el otro perfil tras sincronizar; resumen de la especie correcto.
Resultados en `docs/prompts/F8-checklist.md`. Commits `F8a:`. Resume en 8 líneas.
