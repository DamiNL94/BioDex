# F0 · Repo y Docker

Lee solo `CLAUDE.md`. No crees lógica de negocio todavía.

## Tarea
1. `git init` (rama `main`) y `.gitignore` (node_modules, vendor, mysql-data, .env).
2. Estructura:
   ```
   backend/  (public/index.php, Api/, Lib/, Config/, migrations/)
   frontend/ (vacío salvo README de una línea; se crea en F3)
   shared/   (ya existe iucn-criteria.json)
   docker/   (nginx/default.conf, php/Dockerfile)
   docs/     (api.md, db.md, dwc.md, iucn.md: solo título + "pendiente")
   ```
3. `docker-compose.yml` con servicios:
   - `nginx` (puerto 8080) → sirve `frontend/` en `/` y pasa `/api` a php-fpm. Deja comentado un bloque `location /tiles/` (proxy + `proxy_cache` al WMTS del IGN) que se activa en F6.
   - `php` (8.3-fpm, extensiones pdo_mysql, zip, intl). Sin Composer por ahora.
   - `mysql` (8.x, volumen `./mysql-data`, credenciales desde `.env`).
   - `adminer` (puerto 8081).
4. `.env.example` con DB_HOST, DB_NAME=biodex, DB_USER, DB_PASS, APP_ENV=dev.
5. Backend mínimo en PHP puro. Copia y adapta de `../NaturDex/Backend/`: `Config/env.php`, `Config/db.php` y `Lib/api.php` (lee solo esos tres). Router simple en `public/index.php`: `GET /api/health` → `{"ok":true,"db":true|false}` (prueba conexión PDO).
6. `README.md` corto: requisitos y cómo arrancar.

## Criterio de hecho
- `docker compose up -d` levanta los 4 servicios sin errores.
- `curl localhost:8080/api/health` devuelve `{"ok":true,"db":true}`.
- Primer commit: `F0: estructura y entorno Docker`.

No añadas nada fuera de esta lista. Al terminar, resume en 5 líneas qué creaste.
