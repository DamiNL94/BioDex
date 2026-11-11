# Mapeo Darwin Core

Exportación: `backend/Lib/export.php` (datos y seguridad), `Lib/dwc.php` (términos, URIs, meta.xml, eml.xml) y
`Lib/xlsx.php` (hojas). Rutas: `docs/api.md` › Exportación. Pruebas: `docker compose exec php php bin/test-f10a.php` y,
desde F11h, `bin/test-f11h.php` (núcleo Event, coherencia de meta.xml y ninguna coordenada exacta sensible al publicar).
Ficheros de texto: UTF-8 sin BOM, tabuladores, `\n`, sin comillas; tabuladores y saltos dentro de un campo → un espacio.

**Desde F11h el archivo tiene NÚCLEO EVENT** (estrella de DwC-A): `event.txt` es el núcleo y `occurrence.txt` y
`measurementorfact.txt` son extensiones enlazadas por `eventID`. Toda ocurrencia cuelga de un evento: la de una visita,
de su visita; una marca suelta (oportunista), de un evento propio `biodex:evt:<occurrenceID>`.

## Modos (la seguridad de localizaciones)
- **Completa (privada)**, owner y custodio: coordenadas exactas (`applyVisibility()` con su rol) y una fila
  `export_full` en `access_log` por descarga. Marca de agua en el nombre (`_completa_`), el título del EML y «Léeme».
- **Para publicar**, cualquier miembro: `applyPublicVisibility()`, generalizada según `species_sensitivity.level`
  **sea quien sea quien exporta**. Nivel 2: centro de la celda de 0,1°; nivel 3: de 0,01°; nivel 1: sin coordenadas
  (`shared/sensitivity.json`). En las filas generalizadas tampoco salen `occurrenceRemarks` ni `georeferenceRemarks`
  (un texto libre puede deshacer la generalización), y lo dice `informationWithheld`. El registro original no cambia.
- **F11h, eventos y sitios**: la posición de una visita y de su sitio sale con el nivel MÁS restrictivo de las especies
  del sitio (objetivo y registradas en sus visitas; `Lib/visits.php`). Generalizada: centro de la celda, sin
  `footprintWKT` (ni polígono ni traza de transecto), sin `eventRemarks` (condiciones) y sin el nombre del sitio. Y una
  marca de una visita sale con el más restrictivo entre su especie y su visita: una marca exacta de una especie común no
  delata el sitio de una sensible contada en la misma visita (la misma regla vale en `/api/sync/pull`).

## event.txt (núcleo Event)
| Término | Visita | Sitio (evento padre) | Marca oportunista |
|---|---|---|---|
| `eventID` (`<id>`) | `visitID` | `biodex:site:<siteID>` | `biodex:evt:<occurrenceID>` |
| `parentEventID` | `biodex:site:<siteID>` (si tiene sitio) | — | — |
| `samplingProtocol` | censo completo de especies objetivo · conteo en parcela · transecto con banda de N m · búsqueda por tiempo | «sitio de muestreo permanente (tipo)» | «observación oportunista» |
| `sampleSizeValue` · `sampleSizeUnit` | superficie del sitio en `square metre` (transecto: longitud × banda); búsqueda por tiempo: minutos, `minute` | superficie | — |
| `samplingEffort` | «N minutos; M observadores: …» | — | — |
| `eventDate` | intervalo ISO `inicio/fin` (UTC) | de la primera a la última visita exportada | la de la marca |
| `eventRemarks` | condiciones (retenidas si se generaliza) | «Sitio «nombre»» (retenido si se generaliza) | — |
| `locationID` | `biodex:site:<siteID>` | el mismo | — |
| `decimalLatitude` · `decimalLongitude` · `coordinateUncertaintyInMeters` | centro del sitio y su radio o extensión (o la posición al empezar, sin sitio) | ídem | las de la ocurrencia |
| `footprintWKT` · `footprintSRS` | geometría del sitio (POLYGON o LINESTRING) y `EPSG:4326`; vacías si se generaliza o es un círculo | ídem | — |
| `dataGeneralizations` · `informationWithheld` | de `shared/sensitivity.json` si se generaliza | ídem | los de la ocurrencia |
| `countryCode`, `datasetName`, `dcterms:license`, `rightsHolder`, `language`, `modified` | como en occurrence.txt | | |

## occurrence.txt (extensión Occurrence desde F11h; `http://rs.tdwg.org/dwc/terms/`; `dcterms:` los cuatro marcados)
La primera columna es `eventID` (el `coreid`); detrás, los términos de siempre en el mismo orden. Las ausencias de una
visita (especies objetivo sin contar al cerrarla) llevan `occurrenceStatus = absent` e `individualCount = 0`.
| Término | Origen |
|---|---|
| `eventID` (`coreid`) | visita de la marca, o `biodex:evt:<occurrenceID>` si es oportunista |
| `occurrenceID` | `occurrences.occurrenceID` (UUID v4 del cliente) |
| `basisOfRecord` | `HumanObservation` |
| `occurrenceStatus` | `present` · `absent` |
| `eventDate` | UTC con zona, `2026-06-15T08:00:00Z` |
| `recordedBy` · `recordedByID` | nombre del autor · `https://orcid.org/<ORCID>` si lo tiene |
| `individualCount` | columna; **ausencias: 0** |
| `sex` · `lifeStage` | vocabularios de GBIF tal cual |
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

## measurementorfact.txt (extensión Extended MeasurementOrFact desde F11h)
`rowType` `http://rs.iobis.org/obis/terms/ExtendedMeasurementOrFact` (la eMoF que GBIF acepta con núcleo Event): el
`coreid` es el `eventID` y, cuando el dato es de una ocurrencia, `occurrenceID` la enlaza. Dos datos:
- **maduros** de las presencias: `occurrenceID`, `measurementID` (`<occurrenceID>:mature_count`), `measurementType`
  «número de individuos maduros», `measurementValue`, `measurementUnit` «individuos», `measurementDeterminedBy` (autor),
  `measurementDeterminedDate` (= `eventDate`), `measurementMethod` «Conteo en campo (BioDex)»;
- **esfuerzo** de cada visita (solo `eventID`): «esfuerzo de muestreo», minutos, «Duración de la visita, entre inicio y fin».
La evaluación UICN no va: es de la especie, no de una observación (va en la hoja «Evaluaciones UICN»). Sin ningún dato,
no hay fichero ni `<extension>` en meta.xml.

## meta.xml y eml.xml
- meta.xml: `<core rowType="…/Event">` con `event.txt` e `<id index="0"/>`; extensiones Occurrence
  (`occurrence.txt`) y eMoF (`measurementorfact.txt`), cada una con `<coreid index="0"/>` y su `<field index="0">`
  `eventID`. `fieldsTerminatedBy="\t"`, `fieldsEnclosedBy=""`, `ignoreHeaderLines="1"`; los `<field>` salen de la misma
  lista que la cabecera (no pueden discrepar). Validado contra `tdwg_dwc_text.xsd` en la prueba; la prueba de F11h
  comprueba además que cada fichero coincide con su `<field>` y que toda ocurrencia cuelga de un evento que existe.
- eml.xml (EML 2.2.0, perfil GBIF 1.3): título, resumen (descripción + recuentos), `creator` = autores de las marcas
  exportadas con ORCID, `contact` y `metadataProvider` = owner, sin correos (privacidad: se añaden al publicar en el
  IPT), licencia, cobertura geográfica (bbox de las coordenadas **publicadas**), temporal y taxonómica (familias y
  taxones), métodos con la versión (`VERSION`) y la nota de generalización o la marca «no publicar».

## Hojas (`hojas/`)
`BioDex_<catálogo>_<fecha>.xlsx` (Léeme, Observaciones, Especies, Evaluaciones UICN, Distribución, Notas y, desde F11g,
Evaluación por especie, Subpoblaciones e Impactos y, desde F11h, Visitas y Series) y los
mismos datos en `hojas/csv/` como CSV estándar para R, Python o QGIS (RFC 4180: UTF-8 sin BOM, `,`, punto decimal, fechas `AAAA-MM-DD hh:mm` en hora peninsular; hasta 1.5.1 eran `;` y coma decimal para Excel en español, y LibreOffice o Google Sheets los partían mal). Observaciones:
cabecera en castellano con el término DwC debajo en gris, coordenadas con 6 decimales y UTM 30N (EPSG:25830), filas
congeladas y autofiltro. Distribución: EOO/AOO definitivos del servidor (equipo, celda de 2 km), solo cifras.
Evaluación por especie y Subpoblaciones: el cálculo de la pestaña Evaluación UICN con todas las presencias,
solo cifras (sin geometría). Impactos: el sitio sigue la regla de las marcas (generalizado para publicar, sin la
nota en esas filas).

**Ni la evaluación ni los impactos van en el Darwin Core Archive**. La evaluación es de la especie, no de una
observación (decisión de F10a). Los impactos tampoco, por ahora: no hay una extensión DwC estándar para amenazas
observadas en campo (podrían ir como eventos con MeasurementOrFact o en la extensión de distribución con
`threatStatus`, pero ninguna encaja sin forzar el vocabulario); quedan en la hoja «Impactos» hasta decidirlo.

Visitas: una fila por visita de las marcas exportadas (sitio, protocolo, inicio, fin, esfuerzo, observadores,
especies objetivo, marcas y ausencias), con la posición y la geometría de su evento: en la de publicar, si se generaliza,
sin nombre del sitio, sin geometría y sin condiciones. Series: por especie con visitas a sitios, una fila por año
(visitado o no, total observado e índice del modelo) y, en la primera, tasa anual con IC, reducción en la ventana de A y
lo observado (`docs/iucn.md`). Solo cifras.

## Pendiente para publicar en GBIF
Pasar el `dwca/` de una exportación «para publicar» por el validador de GBIF (a mano; con núcleo Event desde F11h),
un `datasetID` registrado (GBIF.ES) y `scientificNameID` (no lo da EIDOS).
