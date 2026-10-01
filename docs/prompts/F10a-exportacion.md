# F10a · Exportación: Darwin Core Archive y hojas legibles

Requiere F9 hecha. Lee `CLAUDE.md`, `docs/sync.md` (términos de marca), `docs/db.md`, `docs/iucn.md`,
`shared/sensitivity.json` y las firmas de `backend/Lib/{sensitivity,authz,audit,metrics,species}.php`.
De NaturDex, solo `Docs/DARWIN-CORE.md` §5 («Sensibilidad») y §7 («Exportar») y `Backend/Lib/dwc.php` como referencia.

## Principio
Dos productos en una descarga, pensados para dos lectores:
1. **Un Darwin Core Archive válido** para GBIF u otras bases (máquinas).
2. **Hojas limpias en castellano** para el investigador (personas): se abre con doble clic en Excel/LibreOffice.

La exportación se genera **en el servidor** (tiene las exactas y aplica la seguridad). Necesita conexión;
sin ella, el botón explica que existe el respaldo JSON de F5b.

## 1. Dos modos (los de la seguridad de localizaciones)
| Modo | Quién | Coordenadas | Registro |
|---|---|---|---|
| **Completa (privada)** | owner, custodian | exactas | `export_full` en `access_log` |
| **Para publicar** | cualquier miembro | generalizadas según el nivel de cada especie (nivel 1: sin coordenadas) | — |
En «para publicar», cada fila generalizada lleva `dataGeneralizations`, `informationWithheld` y un
`coordinateUncertaintyInMeters` acorde (como hace `applyVisibility`). **El registro original nunca se altera.**
La marca de agua del modo va en el nombre del fichero, en el EML y en la hoja «Léeme».

## 2. Opciones de la exportación
Catálogo; rango de fechas; especies (todas o selección); observadores; incluir ausencias (sí por defecto);
licencia (**CC BY 4.0** por defecto, CC0 o CC BY-NC 4.0: las tres que acepta GBIF); título y descripción del
conjunto (precargados con el nombre y la descripción del catálogo).

## 3. Darwin Core Archive (`dwca/` dentro del ZIP)
- `occurrence.txt` (núcleo Occurrence), **UTF-8, separado por tabuladores**, sin tabuladores ni saltos de línea
  dentro de los campos (se sustituyen por espacio). Términos, en este orden:
  `occurrenceID, basisOfRecord, occurrenceStatus, eventDate, recordedBy, recordedByID, individualCount, sex,
  lifeStage, occurrenceRemarks, habitat, decimalLatitude, decimalLongitude, geodeticDatum,
  coordinateUncertaintyInMeters, georeferenceProtocol, georeferenceRemarks, dataGeneralizations,
  informationWithheld, countryCode, scientificName, scientificNameAuthorship, taxonID, nameAccordingTo,
  kingdom, phylum, class, order, family, genus, specificEpithet, infraspecificEpithet, taxonRank,
  vernacularName, taxonRemarks, datasetName, license, rightsHolder, language, modified`.
  - `taxonID` = `EIDOS:<idtaxon>` con `nameAccordingTo` «EIDOS (IEPNB, MITECO)»; especies propias `biodex:<uuid>`
    con `taxonRemarks` «Taxón no presente en EIDOS».
  - `eventDate` ISO 8601 con zona; `geodeticDatum` `EPSG:4326`; `countryCode` `ES`; `language` `es`.
  - Ausencias: `occurrenceStatus = absent`, `individualCount = 0`.
- `measurementorfact.txt` (extensión MeasurementOrFact, enlazada por `occurrenceID`): «número de individuos
  maduros» (`mature_count`) cuando exista. Nada más: la evaluación UICN no es de una observación.
- `meta.xml` con los `rowType` y URIs oficiales de TDWG/GBIF de cada término (valídalo contra el esquema de texto de DwC).
- `eml.xml` (GBIF Metadata Profile): título, resumen, creadores (miembros con marcas, con ORCID si lo tienen),
  contacto (owner), licencia, cobertura geográfica (bbox; en «para publicar», la de las coordenadas
  generalizadas), cobertura temporal, cobertura taxonómica (familias y especies), métodos («Observación de campo
  con GPS del dispositivo o punto marcado en el mapa; app BioDex vX»), y la nota de generalización si aplica.

## 4. Hojas legibles (`hojas/` dentro del ZIP)
**Un XLSX** `BioDex_<catálogo>_<fecha>.xlsx`, generado con un escritor propio mínimo (ZIP + XML de OOXML; la
extensión `zip` ya está instalada; sin dependencias nuevas), con estas pestañas:
1. **Léeme**: qué es cada pestaña, modo (completa/para publicar), fecha, filtros usados, licencia, cómo citar.
2. **Observaciones**: una fila por marca, **cabeceras en castellano** y debajo, en gris, el término DwC; fechas
   como fecha de Excel; coordenadas con 6 decimales y también **UTM 30N (X, Y)**; filas congeladas y autofiltro.
3. **Especies**: nombre científico, común, grupo, clasificación, UICN oficial de cada ámbito (EIDOS), estado
   legal, nivel de sensibilidad, nº de marcas y ausencias.
4. **Evaluaciones UICN**: por especie, la evaluación de referencia y las del equipo (autor, fecha, categoría,
   cadena de criterios, entradas, justificación, ajuste regional), rotuladas «Evaluación BioDex».
5. **Distribución**: EOO, AOO, celdas, tamaño de celda, registros usados y excluidos, fecha de cálculo, ámbito.
6. **Notas**: notas de cuaderno por especie, con autor y fecha.
Además, **los mismos datos en CSV** (UTF-8 con BOM y separador `;`, que es lo que espera Excel en español).

## 5. ZIP final
`BioDex_<catálogo>_<completa|publicar>_<AAAA-MM-DD>.zip` con `dwca/`, `hojas/` y un `LEEME.txt` breve. En la app:
botón «Descargar datos» en el catálogo y en **Ajustes › Descargas** (lo pidió el enunciado original), con las opciones
del punto 2 y un resumen antes de descargar (nº de observaciones, especies, ausencias, cuántas se generalizan).

## 6. Comprobación
- `backend/bin/test-f10a.php`:
  - «Completa» por editor → 403; por owner → 200 y fila `export_full`.
  - «Para publicar» de una especie de nivel 2: coordenadas en el centro de la celda de 0,1°, con
    `dataGeneralizations`; nivel 1 sin coordenadas y con `informationWithheld`; nivel 4 exactas.
  - `occurrence.txt`: una fila por marca no borrada, `occurrenceID` únicos, sin tabuladores internos, cabecera
    igual a `meta.xml`; `meta.xml` y `eml.xml` bien formados; el XLSX abre (compruébalo con Python `openpyxl` si
    está disponible, o descomprimiendo y validando el XML).
- **Validador de GBIF (a mano, Damián):** subir el `dwca/` comprimido de la exportación «para publicar» de un
  catálogo de prueba a https://www.gbif.org/tools/data-validator y anotar el resultado en `docs/prompts/F10-checklist.md`.
- `docs/dwc.md`: el mapeo término a término (≤ 80 líneas). `VERSION`, `APP_VERSION` `1.0.0-rc · F10a`.
Commits `F10a:`. Resume en 8 líneas.
