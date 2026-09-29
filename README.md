# BioDex

PWA de campo para biólogos: catálogos de especies (EIDOS), observaciones offline geolocalizadas, evaluación UICN y exportación Darwin Core.

## Requisitos
- Docker y Docker Compose v2

## Arrancar
```bash
cp .env.example .env   # y ajusta DB_PASS
docker compose up -d
```

- App: http://localhost:8090
- Comprobación: http://localhost:8090/api/health → `{"ok":true,"db":true}`
- Adminer: http://localhost:8081 (servidor `mysql`, usuario/contraseña de `.env`)
- Correo de desarrollo (Mailpit): http://localhost:8025

## Comprobaciones
```bash
docker compose exec php php bin/check-sw.php   # SHELL_ASSETS de frontend/sw.js al día
docker compose exec php php bin/test-f2a.php   # autenticación
docker compose exec php php bin/test-f2b.php   # especies y catálogos
```
Los iconos de la PWA salen de `backend/bin/make-icons.php` (necesita ext-gd, que el contenedor no trae):
`php -d extension=gd backend/bin/make-icons.php` en el anfitrión.

## Probar en el móvil
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
