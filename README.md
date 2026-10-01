# BioDex

PWA de campo para biólogos: catálogos de especies (EIDOS), observaciones offline geolocalizadas, evaluación UICN y exportación Darwin Core.

Prototipo (1.0.0 · demo): servidor local, sin publicar. Lo que aún no hace o no se ha probado:
[`docs/limitaciones.md`](docs/limitaciones.md). Guion de la demostración: [`docs/demo.md`](docs/demo.md).

## Instalación desde cero (10 pasos)
Necesitas Docker Desktop (Compose v2), Git, Edge o Chrome y conexión a internet (EIDOS y las teselas del IGN).

1. `git clone <repositorio> BioDex` y `cd BioDex`.
2. `cp .env.example .env` y cambia `DB_PASS`. Deja `APP_ENV=dev` para poder cargar la demo.
3. `docker compose up -d` (la primera vez construye la imagen de PHP: unos minutos).
4. `docker compose exec php php bin/migrate.php` → crea las tablas (un segundo pase dice «Nada que aplicar»).
5. Abre http://localhost:8090/api/health → `{"ok":true,"db":true}`.
6. `docker compose exec php php bin/seed-demo.php` → datos de demostración (trae 10 fichas de EIDOS la primera vez).
   Después, `docker compose exec php php bin/fetch-species-photos.php` → fotos de iNaturalist, GBIF o Wikimedia
   Commons para las especies sin foto en EIDOS (1 petición/s por fuente; `docs/fotos-respaldo.md`).
7. Abre http://localhost:8090 y entra con una de las cuentas de abajo.
8. Instala la app: icono «Instalar» de la barra de direcciones de Edge o Chrome (`localhost` cuenta como contexto seguro).
9. En la app instalada: Catálogo › «Llevar al campo» y Mapa › Zonas › «Descargar esta zona».
10. Comprueba que todo está en verde: `docker compose exec php php bin/check-sw.php` y `bin/test-f10b.php` (lista completa abajo).

Otras direcciones: Adminer http://localhost:8081 (servidor `mysql`, usuario y contraseña de `.env`) · correo de
desarrollo (Mailpit) http://localhost:8025.

## Cuentas de demostración
`bin/seed-demo.php` (solo con `APP_ENV=dev`; idempotente) crea tres cuentas verificadas y el catálogo
**«DEMO — Flora amenazada de Sierra Nevada (datos ficticios)»**: 10 especies reales de EIDOS con su nivel de
sensibilidad, 60 marcas **inventadas** de los tres (5 ausencias, 2 marcadas en el mapa), notas y la evaluación UICN
de referencia de *Artemisia granatensis* (EN B1ab(iii)+2ab(iii)).

| Rol | Email | Contraseña | Nombre |
|---|---|---|---|
| owner | `owner@biodex.demo` | `SierraNevada-owner` | Lucía Ortega |
| custodio | `custodio@biodex.demo` | `SierraNevada-custodio` | Carlos Molina |
| editor | `editor@biodex.demo` | `SierraNevada-editor` | Elena Ruiz |

`bin/reset-demo.php` borra solo lo de la demo (el catálogo, lo que esas cuentas hayan creado al ensayar y las
cuentas); después, `seed-demo.php` lo deja como nuevo. Las exportaciones del catálogo dicen «Datos ficticios para
demostración: no publicar».

## Probar sin red
- **Servidor caído** (lo que pasa en el campo): con el catálogo y una zona descargados, `docker compose stop nginx`.
  La app sigue abriendo (cabecera «Servidor no disponible»), se puede marcar y las marcas quedan «pendientes».
  `docker compose start nginx` → se sincronizan solas en ≤ 30 s, sin tocar nada.
- **Sin conexión**: DevTools › Network › *Offline* (o el wifi apagado) → «Sin conexión».
- **GPS simulado**: DevTools › ⋮ › More tools › *Sensors* › *Location* › posición propia, p. ej. 37.0806, −3.3996
  (Borreguiles, Sierra Nevada). Sin GPS, «Marcar aquí» no deja guardar.

## Comprobaciones
```bash
docker compose exec php php bin/check-sw.php    # SHELL_ASSETS de frontend/sw.js al día
docker compose exec php php bin/test-f2a.php    # autenticación
docker compose exec php php bin/test-f2b.php    # especies y catálogos
docker compose exec php php bin/test-f4.php     # buscador y constructor
docker compose exec php php bin/test-f5.php     # sincronización
docker compose exec php php bin/test-f6.php     # equipos y roles
docker compose exec php php bin/test-f7.php     # mapa y generalización
docker compose exec php php bin/test-f8a.php    # marcas y notas
docker compose exec php php bin/test-f8b.php    # evaluaciones UICN
docker compose exec php php bin/test-f9.php     # EOO y AOO
docker compose exec php php bin/test-f10a.php   # exportación
docker compose exec php php bin/test-f10b.php   # datos de demostración (reinicia la demo y la deja cargada)
docker compose exec php php bin/test-f10c.php   # Plinian Core
docker compose exec php php bin/test-f11b.php   # fotos de respaldo (iNaturalist) y siluetas
docker compose exec php php bin/test-f11d.php   # fotos de respaldo de GBIF y Commons, copias locales y cascada
node shared/iucn-engine.test.js && node shared/geo-metrics.test.js && node frontend/tests/utm.test.mjs
```
Los iconos de la PWA salen de `backend/bin/make-icons.php` (necesita ext-gd, que el contenedor no trae):
`php -d extension=gd backend/bin/make-icons.php` en el anfitrión.

## Probar en el móvil
Lo más cómodo: el despliegue de prueba en Railway (HTTPS real, sin certificados locales) → `docs/railway.md`.

El GPS y el Service Worker solo funcionan en contexto seguro (HTTPS o `localhost`). Dos formas:

### A. HTTPS local con mkcert (puerto 8443)
1. Instala [mkcert](https://github.com/FiloSottile/mkcert) y su CA local: `mkcert -install`.
2. Genera el certificado para `localhost` y la IP del PC en la red local (p. ej. `192.168.1.20`):
   ```bash
   mkcert -cert-file docker/nginx/certs/biodex.pem -key-file docker/nginx/certs/biodex-key.pem localhost 127.0.0.1 192.168.1.20
   ```
   `docker/nginx/certs/` está en `.gitignore`.
3. `docker compose up -d --force-recreate nginx`. Con los dos ficheros presentes, `docker/nginx/entrypoint.sh`
   activa `https.conf`; sin ellos nginx arranca solo en HTTP (8090).
4. En el móvil instala la CA de mkcert (`mkcert -CAROOT` → `rootCA.pem`; Android: Ajustes › Seguridad ›
   Cifrado y credenciales › Instalar certificado › Certificado de CA) y abre `https://192.168.1.20:8443`.
   Si el firewall de Windows pregunta, permite el puerto 8443 en redes privadas.

### B. Sin certificados: depuración USB
1. En el móvil activa las opciones de desarrollador y la depuración USB; conéctalo al PC.
2. En Chrome del PC abre `chrome://inspect` › *Port forwarding* › `8090` → `localhost:8090` y marca
   *Enable port forwarding*.
3. En el Chrome del móvil abre `http://localhost:8090`: para el navegador es `localhost`, así que cuenta
   como contexto seguro.
