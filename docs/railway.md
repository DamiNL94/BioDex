# Despliegue de prueba en Railway (F11)

Para probar BioDex desde el móvil con HTTPS real (GPS y Service Worker), sin mkcert. El desarrollo
local no cambia: sigue en `docker-compose.yml`.

## Qué hay en el repo
- `Dockerfile` (raíz): un solo contenedor con nginx + php-fpm 8.3 y el código dentro. Reutiliza
  `docker/nginx/*.conf`; al construir, sed cambia `fastcgi_pass php:9000` → `127.0.0.1:9000` y el
  `resolver` de Docker por 1.1.1.1/8.8.8.8. El HTTPS lo pone Railway (la cookie de sesión sale `secure`
  porque `session.php` mira `X-Forwarded-Proto`).
- `docker/railway/entrypoint.sh`: escucha en `$PORT`, aplica migraciones (reintenta ~60 s mientras
  MySQL arranca), arranca php-fpm y nginx y, con `SEED_DEMO=1`, lanza `seed-demo.php` en segundo plano
  (idempotente; solo ese proceso corre con `APP_ENV=dev`).
- `railway.json`: builder Dockerfile, healthcheck `/api/health`.
- `.dockerignore`: fuera `.git`, `mysql-data/`, `docs/`, `.env`, PDFs.

## Pasos (una vez)
1. Subir el repo a GitHub (privado): `git remote add origin …` y `git push -u origin main`.
2. Railway → **New Project** → **Deploy from GitHub repo** → BioDex. Región **EU West** (EIDOS, IGN y
   la Fototeca son servicios españoles).
3. En el proyecto: **+ Create** → **Database** → **MySQL**.
4. Servicio BioDex → **Variables** (Raw Editor):
   ```
   DB_HOST=${{MySQL.MYSQLHOST}}
   DB_PORT=${{MySQL.MYSQLPORT}}
   DB_NAME=${{MySQL.MYSQLDATABASE}}
   DB_USER=${{MySQL.MYSQLUSER}}
   DB_PASS=${{MySQL.MYSQLPASSWORD}}
   APP_ENV=prod
   APP_URL=https://${{RAILWAY_PUBLIC_DOMAIN}}
   SEED_DEMO=1
   CONTACT_EMAIL=<tu correo>
   ```
   `CONTACT_EMAIL` (F11d) va en el User-Agent de las peticiones a Wikimedia y GBIF
   («BioDex/1.4.0 (https://…; correo)»): la política de User-Agent de Wikimedia pide un contacto. Sin ella, solo
   la URL. No se enseña en la app.
5. **Settings → Networking → Generate Domain** (puerto 8080). Abrir `https://<dominio>/api/health` →
   `{"ok":true,"db":true}`.
6. En los logs del despliegue tiene que salir el resumen de `seed-demo.php` con las tres cuentas
   (`@biodex.demo`, contraseñas en el README) y, después, la línea `BioDex fotos: …` de
   `fetch-species-photos.php` (F11b/F11d, en segundo plano; con unas 140 especies sin foto, ~1 h: GBIF es lento). En el
   móvil: abrir el dominio → instalar la PWA.

Cada `git push` a `main` vuelve a desplegar. Lo no confirmado en Git no se despliega.

## Limitaciones de este despliegue
- **Sin correo**: no hay SMTP, así que el registro de cuentas nuevas no puede verificarse por email.
  Para probar, usar las cuentas de demo. (`Lib/mail.php` no implementa AUTH/STARTTLS.)
- **Datos de demo públicos**: las contraseñas de la demo están en el repo. Es un entorno de prueba con
  datos ficticios; no meter observaciones reales. Para dejar de sembrar: `SEED_DEMO=0`.
- Las cachés de teselas y fotos de nginx (Fototeca e iNaturalist) viven en el contenedor: se vacían en cada despliegue.
- Fotos de respaldo (F11b, F11d): `fetch-species-photos.php` corre en segundo plano en cada arranque (cascada
  iNaturalist > GBIF > Wikimedia Commons, 1 petición/s por fuente). Las copias de GBIF y Commons van en MySQL, no
  en el disco del contenedor: sobreviven a los despliegues. En local: `docker compose exec php php bin/fetch-species-photos.php`
  (`docs/fotos-respaldo.md`).
- Sin Adminer: para mirar la BD, la pestaña **Data** del servicio MySQL en Railway.
