# Convenciones del proyecto

Reglas que sigue todo el código de BioDex. El detalle de cada módulo está en el resto de `docs/`.

## Stack
- **Frontend** (`frontend/`): JavaScript sin framework ni paso de build, módulos ES nativos. Leaflet y
  markercluster vendorizados, IndexedDB sin librerías y un Service Worker propio (`sw.js`).
- **Backend** (`backend/`): PHP 8.3 sin framework + PDO. `Api/` tiene un fichero por recurso y `Lib/` las
  funciones de apoyo. Bajo `/api` solo se sirve JSON.
- **Mapas**: WMTS del IGN (IGN Base, PNOA, MTN) a través de un proxy nginx en `/tiles/` (mismo origen), con
  descarga por zonas para usarlos sin conexión.
- **Especies**: todas las de EIDOS (sin filtro provincial) y las que crean los usuarios
  (`species.source = 'user'`, taxonID `biodex:<uuid>`).
- **EOO/AOO**: se calculan en el cliente para la vista previa y en el servidor para el valor definitivo, con el
  mismo código y los mismos casos de prueba (`shared/geo-metrics.js`, `shared/metrics-cases.json`).
- **Base de datos**: MySQL 8, con migraciones SQL numeradas en `backend/migrations/`.
- **Docker**: nginx + php-fpm + MySQL + Adminer + Mailpit (`docker-compose.yml`).
- **Datos compartidos** entre cliente y servidor (criterios UICN, sensibilidad, grupos) en `shared/*.json`.

## Código
- Interfaz y comentarios en castellano; identificadores (variables, funciones) en inglés.
- Los campos de una observación usan los nombres exactos de Darwin Core (`occurrenceID`, `eventDate`,
  `decimalLatitude`…).
- `occurrenceID` es un UUID v4 generado en el cliente. `/api/sync` es idempotente y funciona en dos sentidos:
  *push* de lo propio y *pull* de lo del equipo con un cursor (`server_seq`). Ver `docs/sync.md`.
- Todo HTML dinámico del frontend pasa por la plantilla `html``…``` de `util.js`, que escapa cada valor.
- Sin dependencias nuevas sin justificarlo en el commit.
- Commits pequeños, con el mensaje en castellano y el prefijo de la fase del plan (`F2: login y registro`).

## Permisos
- Catálogos compartidos con cuatro roles en `catalog_members`: `owner`, `custodian`, `editor` y `viewer`.
  Cada endpoint comprueba el rol (`Lib/authz.php`). Una marca solo la edita su autor.
- Borrados lógicos (`deleted_at`): lo borrado se sincroniza como borrado, no desaparece.

## Seguridad de las coordenadas (no negociable)
- La coordenada exacta nunca se modifica y solo sale del servidor hacia el owner, los custodios o el autor de la
  marca.
- Al resto se le envía generalizada **en el servidor**, según el nivel de `species_sensitivity`
  (reglas en `shared/sensitivity.json`, detalle en `docs/sync.md`).
- Los EOO/AOO definitivos se calculan en el servidor.
- Quedan registrados en `access_log`: vistas de coordenadas exactas, exportaciones completas, cambios de nivel de
  sensibilidad y cambios de rol.

## Servicios externos
- EIDOS solo se llama desde el backend (`https://iepnb.gob.es/api/especie/…`), con caché en la base de datos.
  `EIDOS_BASE_URL` permite apuntar a otro servidor (p. ej. un doble para las pruebas).
- Las fotos de respaldo (iNaturalist, GBIF, Wikimedia Commons) respetan 1 petición/s por fuente y solo aceptan
  las licencias de `docs/fotos-respaldo.md`.

## Reutilizado de NaturDex
La sesión larga (`Lib/session.php`, `Api/auth.php`, `js/auth.js`), la base de la API (`Lib/api.php`,
`Config/`), el estilo, el arranque sin conexión y el mapeo Darwin Core parten de mi proyecto anterior NaturDex,
copiados y adaptados (no se importan).
