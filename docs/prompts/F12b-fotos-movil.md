# F12b · Fotos: cámara, cola sin conexión, galerías y portada (móvil y escritorio)

> Nota (F11b): donde este prompt dice «hueco Sin foto», ahora va la foto de iNaturalist aceptada o la silueta del grupo (`silhouette`); la portada y EIDOS siguen delante.

Requiere F12a. Lee `CLAUDE.md`, `docs/sync.md` (y su sección de F12a), `docs/api.md` › Fotos, las firmas de
`frontend/js/{db,offline,marks,sync}.js`, `frontend/js/views/{mark-sheet,species-detail,settings}.js`,
`frontend/js/components/species-card.js` y `docker/nginx/security-headers.conf`. No toques `backend/` salvo un fallo
de F12a (commit aparte).

## 1. Captura y reducción en el dispositivo
- Dos botones, ≥ 48 px: **«Hacer foto»** (`<input type="file" accept="image/*" capture="environment">`, abre la
  cámara trasera) y **«Elegir de la galería»** (`multiple`, sin `capture`). En escritorio, además, arrastrar y soltar
  en la ficha. Sin `getUserMedia`: el input funciona en Android, iOS y escritorio y no pide permisos extra.
- Antes de guardar: `createImageBitmap()` (aplica la orientación) → `canvas` → JPEG, lado mayor ≤ 2048 px y
  calidad 0,82, y miniatura de 400 px. Pasar por canvas **descarta el EXIF y la posición GPS** ya en el móvil
  (el servidor lo vuelve a hacer). Si el navegador no puede decodificar el fichero (HEIC en un escritorio, por
  ejemplo): «No se puede leer esta imagen; prueba a exportarla como JPEG». `created` = `lastModified` del fichero
  o la hora actual si es de la cámara.
- `navigator.storage.persist()` la primera vez que se guarda una foto; si el almacenamiento libre estimado
  (`storage.estimate()`) baja de 100 MB, aviso antes de guardar.

## 2. Sin conexión (`db.js` versión 5, `media.js` nuevo)
- Almacén `media` (índices `catalogId`, `taxonID`, `occurrenceID`): metadatos + `thumbBlob` y, mientras no esté
  subida, `fullBlob`. Cola: entrada `mediaUpload` (fichero + metadatos) y `media` / `mediaDelete` para editar el pie,
  el tipo o borrar, una por foto como las marcas.
- Orden del motor (`docs/sync.md` › Orden en el móvil): especies → push (marcas, notas, evaluaciones, `media`) →
  **subidas de fotos de una en una**, solo si la marca ya tiene `serverSeq` → pull. Una subida que falla por red se
  reintenta con el mismo `mediaID` (el servidor es idempotente); `rejected` se queda en Ajustes › Sincronización
  con su motivo, como las marcas. Tras `accepted`, se borra el `fullBlob` local y se queda la miniatura.
- Las fotos ajenas: el pull trae metadatos; «Descargar para usar sin conexión» guarda las **miniaturas** en
  `biodex-img-<catalogId>` (`offline.js` › `cacheImages` acepta también `/api/media/.../thumb`) y las grandes se
  piden con red al abrirlas. La portada se guarda siempre, también en grande.
- Época distinta o expulsión: fuera también las fotos ajenas y su caché; las propias sin subir no se tocan nunca.
- Cerrar sesión: el diálogo cuenta también «n fotos sin subir». Respaldo `version: 4`: metadatos de todas y, en
  base64, **solo las que no se han subido** (son las únicas que se pueden perder); importa también 1–3.
- CSP: `img-src 'self' data: blob:` (las fotos locales se pintan con `URL.createObjectURL`; revocar al desmontar).

## 3. Interfaz
- **Hoja de marca**: bloque «Fotos» con los dos botones y miniaturas (hasta 6); cada una con pie opcional y tipo
  `Individuo` por defecto. Guardar la marca sin fotos sigue siendo un toque.
- **Ficha de especie** (catálogo activo): galería en este orden: portada, fotos de EIDOS («Fototeca MITECO · vía
  EIDOS»), fotos del equipo agrupadas por tipo (Individuo · Rasgo diagnóstico · Hábitat · Rastro · Se confunde
  con · Otra). Cada una: autor, fecha, licencia y pie; la de una marca enlaza a la marca. «Añadir foto» abre los
  dos botones + tipo + pie. Visor a pantalla completa con deslizar y zoom con dos dedos.
- **Portada**: si la especie no tiene foto (ni portada ni EIDOS), la tarjeta muestra un hueco «Sin foto» y, a quien
  puede (`set_cover`), «Añadir foto de portada». En el visor, «Usar como portada» / «Quitar portada». Si la especie es
  de nivel 1–2: confirmación «Esta foto la verá todo el catálogo, también editores y lectores».
- Crédito siempre visible en la tarjeta y la ficha: «Foto: <autor> · <licencia>».
- **Ajustes**: «Licencia de mis fotos» (CC BY 4.0 por defecto, CC0, CC BY-NC 4.0, Todos los derechos reservados) con
  una línea de qué significa cada una; espacio que ocupan las fotos en el dispositivo.
- Borrar las propias; el owner ve «Retirar del catálogo» en las ajenas (confirmación).
- Sin red y sin portada/EIDOS: la tarjeta pinta el hueco, nunca una imagen rota.

## 4. Reglas
Ficheros nuevos a `SHELL_ASSETS`; `VERSION`, `APP_VERSION` `1.2.0 · F12`; `check-sw.php` en verde. Todo HTML con
`html```. Textos alternativos: nombre científico + tipo + pie. Revisa con la skill `design:accessibility-review`
la hoja de marca y el visor.

## 5. Comprobación
Recorrido CDP (capturas en `docs/design/f12-*.png`) con dos perfiles (editor y owner):
1. Modo avión: marca con 2 fotos (una con GPS en el EXIF) y foto de rasgo de la especie → reconectar → `accepted`;
   en el disco del servidor, sin EXIF.
2. Corte de red a mitad de una subida → se reintenta sola y no hay duplicado en `media`.
3. Especie de usuario sin foto: el owner sube una y la pone de portada → la tarjeta del editor la muestra tras
   sincronizar; en otro catálogo, no.
4. Especie de nivel 2: el editor no ve la foto de campo del owner (ni en IndexedDB); sí la portada tras confirmar.
5. Cerrar sesión con una foto sin subir → el diálogo la cuenta; respaldo v4 → importar → se sube.
6. Exportación «para publicar» sin fotos de nivel 1–2.
Resultados en `docs/prompts/F12-checklist.md`. Actualiza `docs/demo.md` con un paso de fotos (opcional en el guion)
y `docs/limitaciones.md`. Commits `F12b:`. Resume en 8 líneas.
