# BioDex — contexto para Claude Code

PWA de campo para biólogos: catálogos de especies (EIDOS) → observaciones offline geolocalizadas → evaluación UICN → exportación Darwin Core. Prototipo: provincia de Granada.

## Stack
- Front: JS sin framework ni build (como NaturDex), Leaflet vendorizado, IndexedDB, `sw.js` propio. Carpeta `frontend/`.
- Back: PHP 8.3 puro + PDO, estructura `Api/` (un fichero por recurso) + `Lib/` (helpers). Carpeta `backend/`. Solo JSON bajo `/api`.
- Mapas: WMTS del IGN (IGN Base, PNOA, MTN) vía proxy nginx en `/tiles/` (mismo origen); descarga por zona para uso offline.
- Especies: todas las de EIDOS (sin filtro provincial) + especies creadas por el usuario (`species.source='user'`, taxonID `biodex:<uuid>`).
- EOO/AOO: se calculan en el cliente (proj4js + Turf, EPSG:25830); reglas en `shared/iucn-criteria.json` → criteria.B.metrics.
- BD: MySQL 8. Migraciones SQL numeradas en `backend/migrations/`.
- Docker: nginx + php-fpm + mysql + adminer (`docker-compose.yml`).
- Datos compartidos (UICN, mapeo DwC) en `shared/*.json`.

## Comandos
- `docker compose up -d` → app en http://localhost:8080, Adminer en :8081

## Convenciones
- Idioma: UI y comentarios en español; código (variables, funciones) en inglés.
- Campos de observación con nombres Darwin Core exactos (`occurrenceID`, `eventDate`, `decimalLatitude`...).
- `occurrenceID` = UUID v4 generado en el cliente; `/api/sync` es idempotente y va en dos sentidos (push de lo propio, pull de lo del equipo por cursor `server_seq`).
- Catálogos compartidos: roles `owner`/`custodian`/`editor`/`viewer` en `catalog_members`; cada endpoint comprueba el rol. Una marca solo la edita su autor. Borrados lógicos (`deleted_at`).
- SEGURIDAD (no negociable): la coordenada exacta nunca se modifica y solo sale del servidor hacia owner/custodian o hacia su autor. Al resto se le envía generalizada EN EL SERVIDOR según `species_sensitivity.level` (reglas en `shared/sensitivity.json`). EOO/AOO definitivos se calculan en el servidor. Registrar en `access_log` vistas exactas, exportaciones completas, cambios de nivel y de rol.
- EIDOS solo se llama desde el backend (`https://iepnb.gob.es/api/especie/...`), con caché en `species_cache`.
- Sin dependencias nuevas sin justificarlo en el commit.
- Commits pequeños, mensaje en español, prefijo de fase: `F2: login y registro`.

## Documentación (leer SOLO la del módulo que se toca)
- `docs/api.md` endpoints · `docs/db.md` esquema · `docs/dwc.md` mapeo Darwin Core · `docs/iucn.md` criterios
- `docs/prompts/Fx-*.md` tarea de cada fase

## Reutilizar de NaturDex (`../NaturDex`, solo lectura; copiar y adaptar, nunca importar)
- Sesión larga: `Backend/Lib/session.php`, `Backend/Api/auth.php`, `Frontend/Js/auth.js`
- Base API: `Backend/Lib/api.php`, `Backend/Config/db.php`, `Backend/Config/env.php`
- Estilo: `Frontend/Css/styles.css` · Offline: `Frontend/sw.js`, `Frontend/Js/offline.js` · Mapa: `Frontend/Js/map.js`
- Darwin Core: `Backend/Lib/dwc.php`, `Backend/Lib/occurrences.php`, `Docs/DARWIN-CORE.md`
- Abrir SOLO el fichero que indique el prompt de la fase; el CLAUDE.md de NaturDex no se lee.

## No tocar / no leer
`node_modules/`, `vendor/`, `mysql-data/`, `frontend/public/tiles/`, `*.pdf`
