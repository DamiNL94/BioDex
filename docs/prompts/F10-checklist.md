# F10 · Checklist (exportación)

## F10a · Automático (todo en verde al cerrar F10a)
- `docker compose exec php php bin/test-f10a.php`: 79 comprobaciones. Permisos («completa» por editor → 403, por
  owner y custodio → 200 con una fila `export_full` cada una; el resumen no registra), «para publicar» (nivel 2 al
  centro de la celda de 0,1° con `dataGeneralizations`, nivel 1 sin coordenadas con `informationWithheld`, nivel 4
  exacta, también cuando exporta el owner; notas libres retenidas en las generalizadas), `occurrence.txt` (una fila
  por marca no borrada, `occurrenceID` únicos, sin tabuladores internos, cabecera = meta.xml), meta.xml válido contra
  `tdwg_dwc_text.xsd`, eml.xml bien formado (bbox generalizado, ORCID, contacto), XLSX (sin Python en el contenedor:
  cada parte XML bien formada, pestañas, filas congeladas y autofiltro), CSV con BOM y `;`, filtros y el original intacto.
- Siguen en verde `test-f9`, `test-f8b`, `test-f5` y `check-sw.php` (`VERSION v10`, 67 ficheros).

## F10a · Recorrido en el navegador
Cuenta y catálogo de prueba sembrados y borrados al terminar (`f10a-*@biodex.test`): 8 marcas de 3 especies (niveles 2,
1 y 4), una ausencia, una nota y una evaluación de referencia.
- Catálogo › «Descargar datos»: resumen «8 observaciones (7 presencias · 1 ausencia) · 3 especies · 2 observadores ·
  6 se generalizan (1 sin coordenadas)». Completa + solo la Artemisia: 5 observaciones y el aviso de exactas; descarga
  `BioDex_Recorrido_F10a_Sierra_Nevada_completa_2026-09-30.zip`.
- Ajustes › Descargas: lista de catálogos con su botón (una sola petición a `/api/catalogs`). Sin conexión, el panel y
  la sección remiten al respaldo JSON. Móvil 375 px sin desbordamiento horizontal.

## Validador de GBIF (a mano, Damián)
Exportar un catálogo de prueba en modo **«Para publicar»**, comprimir el **contenido** de `dwca/` (meta.xml en la raíz
del ZIP) y subirlo a https://www.gbif.org/tools/data-validator.

| Fecha | Catálogo · nº de registros | Resultado | Avisos o errores | Acción |
|---|---|---|---|---|
| | | | | |

## Pendiente
- [ ] Resultado del validador de GBIF (arriba).
- [ ] Abrir el XLSX en Excel y en LibreOffice (en esta máquina no hay Python/openpyxl ni ofimática para comprobarlo).
- [ ] `datasetID` registrado en GBIF.ES y correo de contacto en el EML al publicar en el IPT.

> **F10b (2026-09-30):** los puntos que siguen abiertos están recogidos en `docs/limitaciones.md`.

## F10b · Demo (2026-09-30)
Automático, todo en verde: `test-f10b.php` (20: `reset-demo` y `seed-demo` dos veces seguidas cada uno, nada con
`APP_ENV=prod`, contenido del seed, el editor recibe generalizada la Artemisia ajena, exportación con el aviso de datos
ficticios), `test-f2a` … `test-f10a`, `check-sw.php` (SW v11), motor UICN (74), `geo-metrics` (17) y `utm`.

Recorrido del guion (`docs/demo.md`) en Edge headless por CDP, dos perfiles (owner 1280 px, editora 390 px móvil):
62 comprobaciones OK, sin excepciones de JS. nginx parado de verdad (`docker compose stop nginx`): la app abre con
«Servidor no disponible», zona de 28 teselas visible, 2 marcas pendientes (presencia 6/4 maduros y ausencia) con el
GPS simulado; al arrancar nginx se sincronizan **solas en 21–29 s**. EOO 62,46 km² y AOO 12 km² en el mapa y en el
asistente → EN B1ab(iii)+2ab(iii). ZIP «para publicar» con `dwca/`, `hojas/` y `LEEME.txt`. Capturas `docs/design/f10b-*.png`.

Accesibilidad (auditoría automática en cada pantalla del recorrido: objetivos ≥ 44 px en móvil, contraste ≥ 4,5:1,
foco visible con el teclado, erratas de espacio antes de puntuación). Corregido: tabla de niveles, «Niveles» y
«Registro», acciones de marcas y notas y selección del constructor a 36/32 px → 44 px; título de la tarjeta de
especie (única forma de abrir la ficha) a 21 px → 44 px; «no se calcula : depende» y «de su especie ; en ellas».
Mensajes: el 500 ya no enseña la excepción en dev (va aparte en `detail`) y los de respaldo no dicen «(HTTP nnn)».

## F10c · Plinian Core (2026-10-01)
Automático, todo en verde: `test-f10c.php` (47): `formats` inválido o vacío → 400; en los dos modos el XML valida contra
el XSD vendorizado (`plinianValidate` y `schemaValidate`), un TaxonRecord por especie, categorías oficiales de EIDOS y
evaluación BioDex en entradas separadas (la BioDex con «NO oficial», evaluadora, fecha y localidades), EOO/AOO =
`computeMetrics` de las presencias exportadas, filtros de especies y fechas, DwC-A citado solo si va; «para publicar»
sin ninguna coordenada exacta ni de celda de las especies sensibles, con la nota de información retenida y la
justificación retenida; «completa» con `export_full`; regresión (sin `formats` = `[dwc, sheets]`, «solo DwC» = mismo
`dwca/`); la DEMO exporta PliC válido. Siguen en verde `test-f10a` (sin tocarlo), `test-f10b` (tras `reset-demo` +
`seed-demo`, que ahora exporta PliC al terminar) y `check-sw.php` (SW v12, 67 ficheros).
Regresión a mano: ZIP de la DEMO en los dos modos con el código de F10a y con el de F10c sin `formats`: 46 de 48
ficheros idénticos salvo marcas de tiempo; los 2 restantes son el `.xlsx` binario (fechas del ZIP interno), cuyas partes
XML son idénticas.
Navegador: «Formatos» con DwC-A y hojas marcados y Plinian Core no; sin ninguno, «Elige al menos un formato» y botón
desactivado; solo Plinian Core envía `formats: ["plinian"]`. Sin captura (ventana oculta); sin desbordamiento.
