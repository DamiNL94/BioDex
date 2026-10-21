# Limitaciones conocidas (1.5.1)

Lista abierta al terminar F10b (2026-09-30) y puesta al día con 1.5.1 (2026-10-21). Recoge lo que BioDex **no hace**,
lo que hace de forma **orientativa** y lo que **no se ha podido probar** en esta máquina. Cada punto abierto de las
listas de F3 a F10a está aquí o marcado como resuelto en su lista.

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
- **Fotos de respaldo de iNaturalist, GBIF y Wikimedia Commons** (F11b, F11d, `docs/fotos-respaldo.md`): son
  **ilustrativas**, no evidencia (no entran en ninguna exportación). Las tres fuentes cubren poco algunas especies
  (endemismos, invertebrados, hongos): muchas se quedan con la silueta del grupo. Solo se aceptan coincidencias exactas
  o sinónimos revisados a mano, así que puede faltar foto aunque alguna fuente tenga una. En iNat es la «por defecto»
  del taxón; en GBIF, la primera observación con foto que cumple las reglas; en Commons, la imagen (P18) de Wikidata.
- **Las copias de GBIF y Commons van en MySQL** (F11d): si un autor retira su foto de Commons o GBIF, la nuestra sigue
  hasta la siguiente comprobación. Hoy el script no vuelve a mirar las `ok`: para comprobar una, se borra su fila de
  `species_photos` y se pasa el script. Con Wikidata en retraso de réplica (`maxlag`) o GBIF lento, esas especies se
  quedan pendientes hasta otra pasada.
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
- **Referencia independiente sin pasar.** No hay Python aquí: `backend/bin/metrics-reference.py` (pyproj + shapely)
  está escrito pero no ejecutado; los casos se contrastaron con PROJ.
- Una evaluación de referencia que se edita después sigue siendo la referencia, sin aviso.

## Pruebas pendientes en dispositivos reales
Todo lo de abajo se ha probado en Edge headless por CDP (emulación de móvil, red cortada, GPS simulado y nginx
parado de verdad), no en un teléfono:
- Instalar en la pantalla de inicio de un móvil, modo avión y abrir desde el icono (F3 · 3, F3b a–e con el wifi real).
- GPS real: permiso denegado, sin señal (30 s), precisión, «Seguir» caminando y batería.
- Mantener pulsado en el mapa en Android y en iOS (Safari puede necesitar un temporizador propio).
- «Atrás» del sistema en Android; copiar el enlace de invitación (`navigator.clipboard`).
- Con guantes y al sol: tamaño de «−»/«+» y del conmutador Presente/Ausente.
- Descargas largas de zonas con el móvil bloqueado (el navegador puede congelar la pestaña; se reanuda).
- Actualizar de v7 a v8 con datos (IndexedDB 3 → 4) y evaluar sin red de verdad.
- Lector de pantalla (NVDA, TalkBack).
- Conteo (F11e) en el campo: toques seguidos con prisa, «Deshacer» y cifra escrita; el QR de invitación leído con la
  cámara de otro móvil (comprobado solo que el SVG generado se decodifica).
- `navigator.storage.persist()`: en headless lo deniega el navegador; instalada, Edge y Chrome suelen concederlo.

## Comportamientos conocidos
- Con nginx parado la vuelta se detecta por `/health` cada 30 s: la sincronización tarda hasta ~30 s (21–29 s medidos).
- La estimación de MB de una zona usa medias provinciales: en ciudad se queda corta (Alhambra: 1,2 MB estimados, 1,6 reales).
- La lista de miembros no se refresca sola: al cambiar de pestaña se recarga.
- Notas y marcas editadas a la vez en dos dispositivos: gana la última escritura.
- `view_exact` se registra en cada petición de la owner o del custodio que recibe exactas ajenas (ficha, mapa, cada
  sincronización): el registro crece deprisa. Pendiente decidir si una fila por día y especie.
- Rendimiento con cientos de marcas por especie (cálculo en el servidor y provisional en un móvil modesto) sin medir.
- Rechazadas en la sincronización: cubiertas por `test-f5.php`, pero no se ha visto una real en Ajustes.
