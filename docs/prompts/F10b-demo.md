# F10b · Datos de demostración, pulido y guion del congreso

Requiere F10a hecha. Lee `CLAUDE.md`, `README.md` y las listas `docs/prompts/F*-checklist.md` (solo los puntos
pendientes). No añadas funcionalidades nuevas: esta fase es para **que la demo no falle**.

## 1. Datos de demostración (`backend/bin/seed-demo.php`)
- Idempotente y **solo si `APP_ENV=dev`**. Crea 3 cuentas verificadas (owner, custodio, editor) con contraseñas
  que imprime por consola, y el catálogo **«DEMO — Flora amenazada de Sierra Nevada (datos ficticios)»** con 8–10
  especies reales de EIDOS (entre ellas *Artemisia granatensis* y *Atropa baetica*) y sus niveles sugeridos.
- ~60 marcas **ficticias** repartidas entre los tres usuarios y varias fechas, en la zona de Sierra Nevada, con
  presencias, 5 ausencias, individuos y maduros, 2 marcadas en el mapa, notas de especie y una evaluación de
  referencia. `datasetName` y el EML dicen «Datos ficticios para demostración: no publicar».
- `bin/reset-demo.php` borra solo lo creado por el seed.

## 2. Repaso de lo pendiente
Recorre las listas de comprobación de F3 a F10a y resuelve o documenta cada punto abierto. Lista final de
limitaciones conocidas en `docs/limitaciones.md` (licencia de las fotos de la Fototeca, EOO/AOO solo con los
registros del catálogo, evaluación orientativa, servidor local sin publicar, prueba en móvil real pendiente…).

## 3. Pulido mínimo
- Revisión de accesibilidad de las pantallas del recorrido (contraste, foco, objetivos táctiles).
- Mensajes vacíos y de error revisados: ninguno técnico.
- `README.md`: instalación desde cero en 10 pasos, cuentas de demo y cómo probar sin red.

## 4. Guion de la demo (`docs/demo.md`)
Recorrido de **8 minutos** con tiempos, qué se dice y qué se enseña, en dos pantallas (escritorio y app instalada
con tamaño de móvil):
1. (1 min) El problema: trabajo de campo sin cobertura, datos dispersos, especies sensibles.
2. (1,5 min) Escritorio: buscar en EIDOS con filtros, construir el catálogo, niveles de sensibilidad.
3. (2 min) «Llevar al campo»: descargar catálogo y zona del mapa → `docker compose stop nginx` → marcar
   (presencia, maduros, ausencia) con el GPS simulado.
4. (1 min) Volver: arrancar nginx → se sincroniza sola; el editor ve la especie de nivel 2 generalizada.
5. (1,5 min) EOO/AOO en el mapa y asistente UICN con el caso `EN B1ab(iii)+2ab(iii)`.
6. (1 min) Descargar datos: ZIP con Darwin Core y la hoja de Excel abierta.
Incluye un **plan B** para cada paso (capturas en `docs/design/` y un vídeo de respaldo grabado con la
grabadora de pantalla de Windows) y una **lista previa al congreso**: Docker arrancado, seed cargado, zona
descargada, caché de teselas llena, geolocalización simulada preparada (DevTools › Sensors), portátil sin
actualizaciones pendientes.

## Criterio de hecho
`seed-demo.php` y `reset-demo.php` funcionan dos veces seguidas; todos los `test-f*.php` y `check-sw.php` en verde;
el recorrido del guion completo por CDP con capturas; `APP_VERSION` `1.0.0 · demo`. Commits `F10b:`. Resume en 8 líneas.
