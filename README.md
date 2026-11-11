# BioDex

PWA de campo para biólogos de la conservación: catálogos de especies creados desde EIDOS (MITECO), marcas
geolocalizadas que funcionan sin conexión, evaluación orientativa según los criterios de la UICN y exportación en
Darwin Core y Plinian Core para volcar los datos en bases internacionales como GBIF.

**Versión 1.8.0** · prototipo. Hay un despliegue de prueba en Railway (`docs/railway.md`); el desarrollo se hace en
local con Docker. Lo que aún no hace o no se ha probado: [`docs/limitaciones.md`](docs/limitaciones.md). Guion de la
demostración: [`docs/demo.md`](docs/demo.md). Convenciones de código, permisos y seguridad de las coordenadas:
[`docs/convenciones.md`](docs/convenciones.md).

## Qué hace
- **Dos modos según la pantalla.** *Estudio* (≥ 900 px): crear catálogos, especies y equipos, revisar y exportar
  datos. *Campo* (< 900 px): mapa a pantalla completa, lista de especies y «Listo para el campo» para llevarse el
  catálogo, las fichas y las teselas del mapa antes de salir.
- **Pensada para usarla de pie** (`docs/interfaz.md`). En campo, botones de 48–56 px y letra de 17 px, un
  botón flotante «Marcar», un buscador que encuentra la especie por nombre común, científico o abreviatura (`pinnev`)
  con las recientes y las de alrededor primero, pestañas que vuelven como se dejaron y un «atrás» que no saca de la app.
- **Página de cada especie** con Resumen, Registros (minimapa), **Parámetros** (generación, distancias, individuo
  maduro…: `docs/parametros.md`) y Evaluación UICN.
- **Marcar es contar.** En una marca de presencia se cuentan individuos por categoría con un toque (fauna: macho,
  hembra, juvenil, larva…; flora: plántula, vegetativa, en flor, en fruto…). Al guardar sale una ocurrencia Darwin Core
  por categoría en el mismo punto, con los individuos maduros calculados. Las **ausencias** también se registran.
- **Sin conexión de verdad.** IndexedDB y Service Worker propios: con el servidor caído o sin cobertura se sigue
  marcando, y las marcas pendientes se sincronizan solas al volver la conexión.
- **Coordenadas sensibles protegidas.** Cada especie tiene un nivel de sensibilidad; según el rol, el servidor
  entrega las marcas de otros ya generalizadas y la coordenada exacta nunca sale hacia quien no debe.
- **Equipos con roles** (owner, custodio, editor) e invitaciones por enlace o **código QR** para unirse en el campo.
- **UICN.** EOO y AOO (celdas de 2 km por defecto, 1 km opcional) calculados en UTM 30N y los
  cuantificadores de B, C y D a partir de las marcas: individuos maduros (observados y estimados con parcelas, con su
  intervalo), subpoblaciones, fragmentación grave y localidades definidas por amenaza. La pestaña Evaluación UICN
  propone la categoría con su rango plausible y su cadena de criterios, dice qué falta para cada criterio y guarda
  instantáneas comparables; se puede contrastar con los registros de GBIF. El resultado es orientativo (`docs/iucn.md`).
- **Visitas y series temporales**: «Empezar visita» en el mapa (sitio de muestreo permanente, protocolo,
  especies objetivo, esfuerzo), una barra fija mientras dura, y al cerrarla las especies objetivo sin contar quedan
  como **ausentes**; todo sin red. Con visitas repetidas a los mismos sitios, la ficha de cada especie dibuja la serie
  por sitio y total, y la evaluación calcula la **tasa anual** y la **reducción** (criterios A y C1) con un modelo
  log-lineal de Poisson con efecto sitio. Sitios desde casa en Catálogo › Sitios.
- **Impactos en campo**: pisoteo, residuos, ganado, recolección, obras, incendio, invasoras… con un toque,
  sin red, mapeados al esquema de amenazas de la UICN.
- **Exportación** desde el servidor, completa (solo owner y custodio, queda registrada) o «para publicar»
  (generalizada): Darwin Core Archive con núcleo Event (visitas y sus ocurrencias), fichas en Plinian Core 3.2.2.7 validadas contra su XSD, libro XLSX y CSV estándar
  (RFC 4180) para R, Python o QGIS.
- **Fotos de las especies**: Fototeca del MITECO vía EIDOS y, si no hay, iNaturalist, GBIF o Wikimedia Commons con
  su autor y licencia; si ninguna sirve, una silueta propia del grupo.

## Stack
JavaScript sin framework ni paso de compilación (Leaflet y markercluster vendorizados, IndexedDB sin librerías,
Service Worker propio) · PHP 8.3 sin framework + PDO · MySQL 8.4 · Docker (nginx, php-fpm, MySQL, Adminer, Mailpit) ·
CI en GitHub Actions · despliegue de prueba en Railway (un contenedor nginx + php-fpm).

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
**«DEMO — Flora amenazada de Sierra Nevada (datos ficticios)»**: 12 especies reales de EIDOS (10 con su nivel de
sensibilidad confirmado y 2 para los cuantificadores UICN), 73 marcas **inventadas** de los tres (5 ausencias, 2 marcadas en el mapa), notas y la evaluación UICN
de referencia de *Artemisia granatensis* (EN B1ab(iii)+2ab(iii)). Además, 4 sitios **ficticios** con
visitas anuales de 2014 a 2026 (ninguna en 2019) de *Plantago nivalis*, con un declive de ≈ 58 % en 10 años: la
tarjeta A de su evaluación sale completa (EN A2ab).

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
`docker compose exec php php bin/test-all.php` ejecuta todas las baterías en orden y resume (o solo algunas:
`bin/test-all.php f8a f9`). Por separado:
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
docker compose exec php php bin/test-f11f.php   # parámetros de especie (permisos, access_log y pull)
docker compose exec php php bin/test-f11g.php   # cuantificadores UICN (casos compartidos), impactos, localidades, exportación
docker compose exec php php bin/test-f11h.php   # series (casos compartidos), sitios y visitas, A y C1, DwC con núcleo Event
node shared/iucn-engine.test.js && node shared/geo-metrics.test.js && node frontend/tests/utm.test.mjs
node frontend/tests/tally.test.mjs && node frontend/tests/qr.test.mjs   # conteo rápido y QR de invitación
node frontend/tests/species-search.test.mjs   # buscador de especies
node frontend/tests/site-geom.test.mjs        # geometría de los sitios de muestreo
```
Casos de las series temporales: `backend/bin/series-reference.py --write` los regenera con statsmodels (sin él,
solo los de solución analítica). En Docker: `docker run --rm -v "$PWD:/w" -w /w python:3.12-slim sh -c "pip install
statsmodels==0.15.0 && python -I backend/bin/series-reference.py --write"`. La CI comprueba que no cambian.

Interfaz (Playwright con la API simulada, en móvil 390×844 y escritorio 1280×800; no necesita Docker):
```bash
cd frontend/tests/e2e
```
```bash
npm ci
```
```bash
npx playwright install chromium
```
```bash
npm test
```
Usa `npm test` (no `npx playwright test`): en Windows, si la ruta se escribe con otras mayúsculas («desktop\biodex»),
Playwright carga su librería dos veces y falla con «did not expect test() to be called here»; `run.mjs` lo evita.
Solo un tamaño o una prueba: `npm test -- --project=movil -g "atrás"`.
Marcar contando desde el buscador, volver a una pestaña con su estado, «atrás» que cierra la hoja, editar un
parámetro y verlo tras recargar sin red, y la auditoría de objetivos táctiles (ninguno por debajo de 48 px en campo).
Registrar un impacto sin red, sincronizar y verlo en la evaluación, y los objetivos táctiles de la hoja de impacto.
Una visita completa en el móvil sin red (empezar en un sitio nuevo, contar dos especies, cerrar con una ausente
y sincronizar: sitio y visita llegan antes que sus marcas).
La API la simula `mock-api.mjs` con respuestas de la demo grabadas en `fixtures/` (datos ficticios).

En GitHub, `.github/workflows/ci.yml` comprueba en cada push la sintaxis de PHP, el Service Worker, las pruebas de JS
y las de interfaz.

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

## Cómo se ha hecho
BioDex es un proyecto personal. La idea, los requisitos y las decisiones de diseño son míos y salen de mi
formación en Ciencias Ambientales (UGR) y del ciclo de DAW: qué datos guardar y en qué estándar (Darwin Core y
Plinian Core), las reglas de sensibilidad de las coordenadas, la evaluación UICN, el funcionamiento sin conexión y
la división entre modo estudio (escritorio) y modo campo (móvil).

Lo he desarrollado por fases (el prefijo F2, F5… de los commits) con un asistente de IA, Claude Code, como
*pair programmer*: yo definía cada fase y sus criterios de aceptación, y comprobaba cada entrega con las
pruebas automáticas y probándola en el navegador y en el móvil.
