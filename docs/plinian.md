# Mapeo Plinian Core

Exportación (F10c): fichas de **especie** del catálogo exportado en Plinian Core 3.2.2.7 (TDWG), en `pliniancore/` del ZIP.
Sin observaciones individuales: esas van en el Darwin Core Archive (`docs/dwc.md`). Esquema vendorizado y sus cambios:
`backend/Lib/plinian/README.md`. Cada XML se valida contra él antes de entregar el ZIP; si no valida, la exportación falla.

## Documento
- **Un solo XML** `pliniancore/especies.xml`: la raíz `Dataset` admite `Metadata` + `TaxonRecord` 1..n, así que van todas
  las especies de la exportación (las del catálogo, con el filtro de especies del diálogo) en un documento.
- Sin espacio de nombres propio (el XSD de PliC no tiene `targetNamespace`); prefijos `dwc:`, `tcs:`, `eol:`, `dc:`, `dcterms:`.
- **Nunca** lleva coordenadas, celdas ni cascos, en ningún modo: solo cifras agregadas.

## Metadata (una vez por documento)
| BioDex | PliC | Notas |
|---|---|---|
| id de la exportación (`urn:uuid:…`, el `packageId` del EML) | `Metadata/Dataset_ID`, `dataset/alternateIdentifier` | |
| fecha de generación | `Metadata/dateStamp` · `dataset/pubDate` | AAAA-MM-DD |
| título del diálogo + «— fichas de especie» | `dataset/title` | «(exportación completa, privada: no publicar)» en modo completo |
| titular del catálogo (owner) | `dataset/creator` · `metadataProvider` · `contact` | `individualName/surName` + ORCID en `onlineUrl` (el perfil EML de PliC no tiene `userId`) |
| evaluadores y observadores | `dataset/associatedParty` (`role`: «Evaluador/a», «Observador/a») | ORCID en `onlineUrl` |
| descripción del diálogo + recuentos | `dataset/abstract/para` | |
| versión de BioDex, modo, referencia a `dwca/` | `dataset/additionalInfo/para` | si no se exporta DwC: «registros no incluidos en esta descarga» |
| licencia (CC BY 4.0 / CC0 / CC BY-NC 4.0) | `dataset/intellectualRights/para` | nombre + URI |
| periodo de las observaciones exportadas | `coverage/temporalCoverage/rangeOfDates` | |
| familias y taxones | `coverage/taxonomicCoverage` | sin `geographicCoverage`: su bbox es obligatoria y no hace falta dar coordenadas |
| método (asistente UICN, EOO/AOO) | `dataset/methods/methodStep/description/para` | |

## TaxonRecord (uno por especie)
| BioDex | PliC | Notas |
|---|---|---|
| `EIDOS:<idtaxon>` · `biodex:<uuid>` | `BaseElements/TaxonRecordID` | igual que `taxonID` del DwC-A |
| «EIDOS (IEPNB, MITECO)» | `BaseElements/dwc:nameAccordingTo` | solo especies de EIDOS |
| fecha de generación · versión | `RecordMetadata/Version/DateIssued` · `Version/Modifier` («BioDex 1.0.0») | `Language` = `es` |
| quien exporta, fecha y hora | `RecordMetadata/Revision` (`associatedParty` rol «Generó la ficha», `pubDate`, `dcterms:created`) | |
| `scientificName` (con autor) | `TaxonRecordName/ScientificName/tcs:Simple` | atributos obligatorios de TCS: `id` (`n-EIDOS-2337`) y `nomenclaturalCode` (Plantae y Fungi → Botanical, Animalia → Zoological, Bacteria → Bacteriological, resto → Indeterminate) |
| `taxonRank` | `tcs:Rank` (`code` sp · ssp · var · fm) y `Hierarchy/dwc:taxonRank` | |
| `canonical_name` · `scientificNameAuthorship` | `tcs:CanonicalName/tcs:Simple` · `tcs:CanonicalAuthorship/tcs:Simple` | |
| `synonyms` (EIDOS) | `Synonyms/SynonymsAtomized/SynonymName/tcs:Simple` | |
| `vernacularName` | `CommonNames/CommonNamesAtomized/CommonName` + `Language` `es` | |
| reino … género, epítetos | `Hierarchy/dwc:kingdom` … `dwc:genus`, `dwc:specificEpithet`, `dwc:infraspecificEpithet` | `@classification` = origen (EIDOS, GBIF, usuario) |
| **UICN oficial** de EIDOS, un ámbito por entrada | `ThreatStatus/ThreatStatusAtomized`: `ThreatCategory` = «CR B1ac(iii,iv)+2ac(iii,iv)», `Authority` = «EIDOS (IEPNB, MITECO) · evaluación oficial de 2010», `AppliesTo/DistributionUnstructured` = «España» / «Mundial» / «Península» | categoría y criterios en la notación de la UICN, como los da EIDOS |
| **Evaluación BioDex** (la de referencia del catálogo) | otra `ThreatStatusAtomized`: `ThreatCategory` = «EN B1ab(iii)+2ab(iii)», `Authority` = «BioDex · evaluación regional preliminar NO oficial» + una por evaluador/a (con ORCID), `AppliesTo/DistributionUnstructured` = ámbito del catálogo | ver «Decisiones» |
| fecha, estado, ajuste regional, localidades, justificación | `ThreatStatus/AncillaryData` (`dc:title` «Evaluación BioDex — no oficial», `eol:agent role="author"`, `dcterms:created`, `eol:subject` …#ConservationStatus, `dc:description`) | texto: «Evaluación regional preliminar hecha con el asistente UICN de BioDex. No es una categoría oficial.» |
| EOO (km², EOO = AOO si se igualó) | `Territory/TerritoryAtomized/ExtentOfOccurrence/dwc:MeasurementOrFact` | `measurementUnit` km², `measurementMethod` (casco convexo, EPSG:25830), `DeterminedDate`, `Remarks` |
| AOO (km²), rango al mover la rejilla | `…/AreaOfOccupancy/dwc:MeasurementOrFact` (`measurementAccuracy` «8–12 km²») | |
| nº de cuadrículas ocupadas · tamaño de celda | 2.º y 3.º `dwc:MeasurementOrFact` de `AreaOfOccupancy` | celda de 2×2 km |
| periodo de las observaciones de la especie | `HabitatAndDistribution/Distribution/temporalCoverage` | |
| área geográfica del catálogo | `Distribution/DistributionAtomized` (`dwc:country` España + `dwc:locality`) | ver «Decisiones» |
| fuente de la distribución: `dwca/` del ZIP | `Distribution/AncillaryData/dc:source` y `TaxonRecord/References` | |
| «para publicar» y nivel 1–3: información retenida | `TerritoryAtomized/AncillaryData` (`dc:title` «Información retenida») | PliC no tiene `informationWithheld` |

## No mapeado (se queda fuera)
| Dato de BioDex | Motivo |
|---|---|
| Observaciones, coordenadas, celdas, casco | PliC es de especies; van en el DwC-A (y sin geometría) |
| Evaluaciones BioDex que no son la de referencia (borradores, otras del equipo) | no son la posición del catálogo; están en la hoja «Evaluaciones UICN» |
| Entradas completas del asistente (A, C, D, E) | solo resumen en la nota; detalle en la hoja |
| Estado legal (CEEA, LESRPE, Andalucía) | cabe en `Legislation`, pero F10c no lo pide; candidato para después |
| Nivel de sensibilidad del catálogo | dato interno de seguridad, no de la especie |
| Descripción y fotos de EIDOS | licencia sin resolver (`docs/limitaciones.md`) |
| Notas de cuaderno | texto libre: puede decir dónde está la especie |
| `taxonomicGroup`, `establishmentMeans`, `habitatScope` | sin elemento claro en PliC 3.2.2.7 |
| Correos | privacidad (igual que en el EML) |

## Decisiones (confirmadas el 2026-10-01)
- **Categoría y criterios juntos en `ThreatCategory`** («EN B1ab(iii)+2ab(iii)», el código primero): `ThreatStatusAtomized`
  solo tiene `ThreatCategory`, `Authority` y `AppliesTo`; no hay elemento de criterios ni de fecha. La fecha y la nota de
  «no oficial» van en el `AncillaryData` de `ThreatStatus`, y además el `Authority` de la entrada BioDex ya lo dice.
- **Solo la evaluación de referencia del catálogo.** Sin referencia, la especie sale sin entrada BioDex.
- **EOO/AOO con las presencias exportadas** (mismo filtro de fechas, especies y observadores que el DwC-A citado como
  fuente), calculadas en el servidor con las exactas (`computeMetrics`). La hoja «Distribución» sigue con todas las del
  catálogo, así que las cifras pueden no coincidir.
- **Ámbito del catálogo:** `catalogs` no tiene área geográfica. Se escribe «Catálogo BioDex «<nombre>»» con
  `dwc:country` España; no se da por hecho «provincia de Granada».
- **Celda siempre de 2×2 km** (el diálogo no ofrece 1 km).
- **«Para publicar», especies de nivel 1–3:** además de la nota de información retenida, la justificación de la evaluación
  BioDex no sale (texto libre, como las notas de campo en el DwC-A). En «completa» sale entera.
- **Sin `geographicCoverage`** en los metadatos: su bbox es obligatoria en el perfil EML de PliC.
- **Formatos:** sin `formats` en la petición, `["dwc", "sheets"]`, y el ZIP es el de F10a (LEEME.txt y «Léeme» solo
  nombran las carpetas que van). Con PliC, el XML se genera y valida antes que nada: si no valida, no hay ZIP.
