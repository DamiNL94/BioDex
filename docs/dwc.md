# Mapeo Darwin Core

Exportación (F10a): `backend/Lib/export.php` (datos y seguridad), `Lib/dwc.php` (términos, URIs, meta.xml, eml.xml) y
`Lib/xlsx.php` (hojas). Rutas: `docs/api.md` › Exportación. Pruebas: `docker compose exec php php bin/test-f10a.php`.
`occurrence.txt`: UTF-8 sin BOM, tabuladores, `\n`, sin comillas; tabuladores y saltos dentro de un campo → un espacio.

## Modos (la seguridad de localizaciones)
- **Completa (privada)**, owner y custodio: coordenadas exactas (`applyVisibility()` con su rol) y una fila
  `export_full` en `access_log` por descarga. Marca de agua en el nombre (`_completa_`), el título del EML y «Léeme».
- **Para publicar**, cualquier miembro: `applyPublicVisibility()`, generalizada según `species_sensitivity.level`
  **sea quien sea quien exporta**. Nivel 2: centro de la celda de 0,1°; nivel 3: de 0,01°; nivel 1: sin coordenadas
  (`shared/sensitivity.json`). En las filas generalizadas tampoco salen `occurrenceRemarks` ni `georeferenceRemarks`
  (un texto libre puede deshacer la generalización), y lo dice `informationWithheld`. El registro original no cambia.

## occurrence.txt (núcleo Occurrence, `http://rs.tdwg.org/dwc/terms/`; `dcterms:` los cuatro marcados)
| Término | Origen |
|---|---|
| `occurrenceID` | `occurrences.occurrenceID` (UUID v4 del cliente); `<id>` de meta.xml |
| `basisOfRecord` | `HumanObservation` |
| `occurrenceStatus` | `present` · `absent` |
| `eventDate` | UTC con zona, `2026-06-15T08:00:00Z` |
| `recordedBy` · `recordedByID` | nombre del autor · `https://orcid.org/<ORCID>` si lo tiene |
| `individualCount` | columna; **ausencias: 0** |
| `sex` · `lifeStage` | vocabularios de GBIF tal cual (F8a) |
| `occurrenceRemarks` · `habitat` | columnas (remarks retenido si la fila se generaliza) |
| `decimalLatitude` · `decimalLongitude` | exactas o generalizadas según el modo; nivel 1 en «publicar»: vacías |
| `geodeticDatum` | `EPSG:4326` (vacío si no hay coordenadas) |
| `coordinateUncertaintyInMeters` | la del GPS o máx(original, semidiagonal de la celda) si se generaliza |
| `georeferenceProtocol` · `georeferenceRemarks` | «GPS del dispositivo» · «Punto marcado en el mapa»; remarks retenido si se generaliza |
| `dataGeneralizations` · `informationWithheld` | de `shared/sensitivity.json` + la nota de notas retenidas |
| `countryCode` | `ES` |
| `scientificName` · `scientificNameAuthorship` | `species` (con autor · autor) |
| `taxonID` | `EIDOS:<idtaxon>`; especies propias `biodex:<uuid>` |
| `nameAccordingTo` | `EIDOS (IEPNB, MITECO)` (vacío en las propias) |
| `kingdom` … `genus`, `specificEpithet`, `infraspecificEpithet` | `species` (clasificación de EIDOS, GBIF o del usuario) |
| `taxonRank` | en minúsculas y en inglés (`Species` → `species`) |
| `vernacularName` | `species.vernacularName` |
| `taxonRemarks` | propias: «Taxón no presente en EIDOS» (+ su nota) |
| `datasetName` | título elegido (por defecto, nombre del catálogo) |
| `dcterms:license` | URI de CC BY 4.0 (defecto), CC0 1.0 o CC BY-NC 4.0 |
| `dcterms:rightsHolder` | titular del catálogo (owner) |
| `dcterms:language` | `es` |
| `dcterms:modified` | `client_updated_at` (última edición en el dispositivo), UTC |

## measurementorfact.txt (extensión MeasurementOrFact)
Solo `mature_count` de las presencias: `coreid` (= `occurrenceID`), `measurementID` (`<occurrenceID>:mature_count`),
`measurementType` «número de individuos maduros», `measurementValue`, `measurementUnit` «individuos»,
`measurementDeterminedBy` (autor), `measurementDeterminedDate` (= `eventDate`), `measurementMethod` «Conteo en campo
(BioDex)». La evaluación UICN no va: es de la especie, no de una observación (va en la hoja «Evaluaciones UICN»).
Sin maduros, no hay fichero ni `<extension>` en meta.xml.

## meta.xml y eml.xml
- meta.xml: `rowType` Occurrence y MeasurementOrFact, `fieldsTerminatedBy="\t"`, `fieldsEnclosedBy=""`,
  `ignoreHeaderLines="1"`; los `<field>` salen de la misma lista que la cabecera (no pueden discrepar). Validado contra
  `tdwg_dwc_text.xsd` en la prueba.
- eml.xml (EML 2.2.0, perfil GBIF 1.3): título, resumen (descripción + recuentos), `creator` = autores de las marcas
  exportadas con ORCID, `contact` y `metadataProvider` = owner, sin correos (privacidad: se añaden al publicar en el
  IPT), licencia, cobertura geográfica (bbox de las coordenadas **publicadas**), temporal y taxonómica (familias y
  taxones), métodos con la versión (`VERSION`) y la nota de generalización o la marca «no publicar».

## Hojas (`hojas/`)
`BioDex_<catálogo>_<fecha>.xlsx` (Léeme, Observaciones, Especies, Evaluaciones UICN, Distribución, Notas) y los
mismos datos en `hojas/csv/` como CSV estándar para R, Python o QGIS (RFC 4180: UTF-8 sin BOM, `,`, punto decimal, fechas `AAAA-MM-DD hh:mm` en hora peninsular; hasta 1.5.1 eran `;` y coma decimal para Excel en español, y LibreOffice o Google Sheets los partían mal). Observaciones:
cabecera en castellano con el término DwC debajo en gris, coordenadas con 6 decimales y UTM 30N (EPSG:25830), filas
congeladas y autofiltro. Distribución: EOO/AOO definitivos del servidor (equipo, celda de 2 km), solo cifras.

## Pendiente para publicar en GBIF
Pasar el `dwca/` de una exportación «para publicar» por el validador de GBIF (a mano),
un `datasetID` registrado (GBIF.ES) y `scientificNameID` (no lo da EIDOS).
