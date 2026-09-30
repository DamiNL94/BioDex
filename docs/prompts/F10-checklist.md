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
