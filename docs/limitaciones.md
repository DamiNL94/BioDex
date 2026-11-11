# Limitaciones conocidas (1.8.0)

Puesta al día con la versión 1.8.0 (2026-11-11). Recoge lo que BioDex **no hace**, lo que hace de forma
**orientativa** y lo que **no se ha podido probar** en esta máquina.

## Alcance del prototipo
- **Despliegue solo de prueba.** El desarrollo corre en Docker en local (`localhost:8090`); además hay un despliegue
  de prueba en Railway (`docs/railway.md`) con HTTPS, pero con el dominio de Railway, sin copias de seguridad de MySQL
  y con los datos de la demo cargados (las contraseñas de las cuentas demo están en el README).
- **Sin correo real.** En local, Mailpit; en Railway no hay SMTP, así que el registro no verifica por correo: las cuentas
  sin verificar entran igual y se unen a un equipo con enlace o QR. `Lib/mail.php` no hace AUTH ni STARTTLS: con un
  SMTP real no enviaría.
- **Los datos de la demo son ficticios.** Especies reales de EIDOS, marcas inventadas. `datasetName` sale con el nombre
  del catálogo («… (datos ficticios)») y el EML con «Datos ficticios para demostración: no publicar».

## Datos y licencias
- **Fotos de la Fototeca del MITECO**: EIDOS no trae autor ni licencia. Se citan «Fototeca MITECO · vía EIDOS»,
  pero las condiciones de reutilización están **sin resolver** (`docs/eidos.md`). No publicar capturas con fotos
  fuera de la demo sin aclararlo.
- **Fotos de respaldo de iNaturalist, GBIF y Wikimedia Commons** (`docs/fotos-respaldo.md`): son
  **ilustrativas**, no evidencia (no entran en ninguna exportación). Las tres fuentes cubren poco algunas especies
  (endemismos, invertebrados, hongos): muchas se quedan con la silueta del grupo. Solo se aceptan coincidencias exactas
  o sinónimos revisados a mano, así que puede faltar foto aunque alguna fuente tenga una. En iNat es la «por defecto»
  del taxón; en GBIF, la primera observación con foto que cumple las reglas; en Commons, la imagen (P18) de Wikidata.
- **Las copias de GBIF y Commons van en MySQL**: si un autor retira su foto de Commons o GBIF, la nuestra sigue
  hasta que se pasa el script con `--recheck-ok-days` (por defecto no se recomprueban; `docs/fotos-respaldo.md`).
  Con Wikidata en retraso de réplica (`maxlag`) o GBIF lento, esas especies se quedan pendientes hasta otra pasada.
- **EIDOS**: todas sus especies, sin filtro provincial; la caché caduca a 30 días. Si EIDOS no responde, solo se puede
  buscar en lo ya guardado.
- **Exportación**: el ZIP no ha pasado el validador de GBIF (sin red a GBIF desde aquí) ni se ha abierto el XLSX en
  Excel o LibreOffice (no hay ofimática en esta máquina). `datasetID` y el correo de contacto del EML quedan para
  cuando se publique en el IPT de GBIF.ES.
- **Plinian Core validado solo contra el XSD** (3.2.2.7 vendorizado, con el `include` que le falta para compilar:
  `backend/Lib/plinian/README.md`). No hay un validador público equivalente al de GBIF, y los ejemplos del repositorio de
  TDWG son de PliC 3.0/3.1. Categoría y criterios van juntos en `ThreatCategory` (el esquema no tiene elemento de
  criterios); el ámbito es el nombre del catálogo (los catálogos no tienen área geográfica) y la celda siempre de 2 km.

## UICN, EOO y AOO
- **Evaluación orientativa.** El asistente aplica los umbrales de la UICN 3.1 a los datos que se le dan; no sustituye
  una evaluación de la Lista Roja ni la revisión de un evaluador. La redacción de los pasos está pendiente de revisar
  con alguien que evalúe para la Lista Roja.
- **EOO y AOO solo con los registros del catálogo.** Son las marcas de ese equipo, no la distribución de la especie.
  Las localidades no se calculan: las introduce quien evalúa.
- **Sin red, cifras provisionales**: el móvil solo cuenta las marcas exactas que tiene (las ajenas generalizadas no
  cuentan); el rótulo lo dice, falta validarlo con usuarios.
- **Parámetros de especie** (`docs/parametros.md`): F11g los usa para B, C y D y F11h para A y C1 (E queda fuera).
  La separación por defecto (1 km) solo se da para grupos sésiles o poco móviles; es una convención de trabajo, no un
  valor publicado para cada especie. Se editan solo con red, como los hechos y las localidades.
- **Cuantificadores**: las subpoblaciones por enlace simple dependen por completo de la distancia de separación;
  la densidad × superficie supone parcelas representativas (sin diseño de muestreo ni detectabilidad); el reparto de
  la estimación por subpoblación es por celdas de AOO, no por hábitat. La fragmentación y las localidades son
  propuestas: las decide quien evalúa. El orden de las preguntas del ajuste regional está escrito a partir de las
  Directrices v4.0 sin cotejarlo con su figura 2; y la versión 16 de las Guidelines solo se ha visto en una copia de
  Artsdatabanken. Con datos inciertos la propuesta es la del mejor valor (actitud ante el riesgo neutra), sin opción
  de cambiarla.
- **Sin red, la evaluación es provisional**: el dispositivo solo cuenta las marcas exactas que tiene; a un editor de
  una especie sensible le salen menos subpoblaciones y maduros que al servidor (lo rotula).
- **Comparación con GBIF**: busca en el recuadro de las marcas del catálogo, no en un polígono del área de estudio, y
  hasta 3000 registros; los registros de GBIF no se depuran (duplicados, cultivados, antiguos). Sin DOI (una búsqueda
  no lo tiene).
- **Impactos fuera del DwC-A** (`docs/dwc.md`): solo van en la hoja «Impactos».
- **Series temporales**: el modelo supone una tasa constante (log-lineal) en toda la serie y que los sitios
  representan a la población (no corrige la detectabilidad ni el diseño de muestreo); un cambio de tendencia dentro de
  la ventana no se detecta (hay que mirar el gráfico). Con menos de 3 años con datos, «no evaluable». A3 y A4 proyectan
  la MISMA tasa; no hay escenarios. E queda fuera de alcance (haría falta un análisis de viabilidad). Los casos con ruido
  se contrastaron con statsmodels 0.15 (Python en un contenedor, `bin/series-reference.py`).
- **DwC-A con núcleo Event sin pasar por el validador de GBIF** (se pasa a mano): `meta.xml` valida contra el XSD de DwC y
  las pruebas comprueban que cada fichero coincide con él. Las medidas van en la extensión eMoF de OBIS (la que admite
  `occurrenceID` con núcleo Event).
- **Visitas en el campo, sin probar en un teléfono**: la traza de un transecto se graba solo con la página visible
  (el navegador para el GPS en segundo plano) y con precisión ≤ 50 m; con la pantalla apagada se pierden tramos.
- **Referencia independiente sin pasar.** No hay Python en el anfitrión: `backend/bin/metrics-reference.py` (pyproj +
  shapely) está escrito pero no ejecutado; los casos se contrastaron con PROJ. (Las series de F11h sí se generaron con
  Python, en el contenedor `python:3.12-slim`; el mismo camino serviría para este script.)
- Una evaluación de referencia que se edita después sigue siendo la referencia: la ficha lo avisa y la owner la confirma
  («Confirmar referencia»); hasta entonces cuenta como referencia igual.

## Pruebas pendientes en dispositivos reales
Todo lo de abajo se ha probado en Edge headless por CDP (emulación de móvil, red cortada, GPS simulado y nginx
parado de verdad), no en un teléfono:
- Instalar en la pantalla de inicio de un móvil, modo avión y abrir desde el icono (F3b a–e con el wifi real).
- GPS real: permiso denegado, sin señal (30 s), precisión, «Seguir» caminando y batería.
- Mantener pulsado en el mapa en Android y en iOS (Safari puede necesitar un temporizador propio).
- «Atrás» del sistema en Android; copiar el enlace de invitación (`navigator.clipboard`).
- Con guantes y al sol: tamaño de «−»/«+» y del conmutador Presente/Ausente.
- Descargas largas de zonas con el móvil bloqueado (el navegador puede congelar la pestaña; se reanuda).
- Actualizar de v7 a v8 con datos (IndexedDB 3 → 4) y evaluar sin red de verdad.
- Lector de pantalla (NVDA, TalkBack).
- Conteo en el campo: toques seguidos con prisa, «Deshacer» y cifra escrita; el QR de invitación leído con la
  cámara de otro móvil (comprobado solo que el SVG generado se decodifica).
- F11f en el campo: la letra de 17 px y los objetivos de 48/56 px con guantes y al sol, el buscador con el teclado
  de Android (abreviaturas) y «cerca» con un GPS real. Lo automático (Playwright en Chromium, 390×844 y 1280×800, con
  la API simulada) cubre marcar contando desde el buscador, el estado de las pestañas, «atrás», un parámetro visto sin
  red y la auditoría de objetivos táctiles.
- `navigator.storage.persist()`: en headless lo deniega el navegador; instalada, Edge y Chrome suelen concederlo.

## Comportamientos conocidos
- Con nginx parado la vuelta se detecta por `/health` cada 30 s: la sincronización tarda hasta ~30 s (21–29 s medidos).
- La estimación de MB de una zona usa medias provinciales: en ciudad se queda corta (Alhambra: 1,2 MB estimados, 1,6 reales).
- La lista de miembros no se refresca sola: al cambiar de pestaña se recarga.
- «Cerca» en la pestaña Especies solo aparece si el permiso de ubicación ya estaba concedido (no se pide al abrir la
  pestaña); en la hoja de marcar llega con el GPS de la propia hoja.
- En la sección de inicio el «atrás» del sistema pide una segunda pulsación para salir; en las demás vuelve primero a
  la de inicio. Quien esperase volver a la pestaña anterior (no a la de inicio) lo notará.
- Notas y marcas editadas a la vez en dos dispositivos: gana la última escritura.
- `view_exact` se registra una vez al día por usuario, catálogo y objeto (pull, EOO/AOO o subpoblaciones de una
  especie), con el mayor número de registros exactos ajenos recibidos ese día; no guarda la hora de cada vista.
- Rendimiento con cientos de marcas por especie (cálculo en el servidor y provisional en un móvil modesto) sin medir.
- Rechazadas en la sincronización: cubiertas por `test-f5.php`, pero no se ha visto una real en Ajustes.
