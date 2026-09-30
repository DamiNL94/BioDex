# Plinian Core vendorizado (F10c)

Esquema con el que `Lib/plinian.php` valida (`DOMDocument::schemaValidate`) cada `pliniancore/*.xml` de la exportación.
Todo en local: la validación no sale a la red.

## Versión fijada
- **Plinian Core 3.2.2.7**, carpeta `xsd/abstract models/stable version/` de https://github.com/tdwg/PlinianCore
  (es la única «stable»; `prerelease/` trae la 3.2.2.8.1, que renombra casi todos los elementos a lowerCamelCase y no
  es estable).
- Repositorio en el commit `c771488d33c34eac383573134913b2c6c5fde6bd` (2024-10-07, rama `master`). El XSD principal
  cambió por última vez en `23225868` (2023-09-15). Lista normativa de términos del repo: versión 2023-11-29.
- Descargado el 2026-09-30.

## Ficheros
| Fichero | Origen |
|---|---|
| `PlinianCore_AbstractModel_v3.2.2.7.xsd` | repo PliC, `stable version/` (esquema principal, sin espacio de nombres; raíz `Dataset`) |
| `eml-gbif-profile-PliC.xsd` | repo PliC, `stable version/` (EML-GBIF adaptado a PliC: `dataset`, `associatedParty`, `pubDate`, `temporalCoverage`) |
| `content_0_3.xsd` | repo PliC, `stable version/` (EOL 0.3: `AncillaryData`, `References`) |
| `tdwg_dwc_extensions.xsd`, `tdwg_dwc_simple.xsd`, `tdwg_dwcterms.xsd`, `tdwg_basetypes.xsd`, `tdwg_dw_core.xsd`, `tdwg_dw_element.xsd` | repo PliC, `stable version/` (Darwin Core) |
| `v101.xsd` | https://raw.githubusercontent.com/tdwg/tcs/master/TCS101/v101.xsd (TCS 1.01: `ScientificName`) |
| `SpeciesStatus.xsd` | https://raw.githubusercontent.com/tdwg/gisin/master/xsd/SpeciesStatus.xsd |
| `ABCD_2.06.xsd` | http://rs.tdwg.org/abcd/2.06/ABCD_2.06.xsd |
| `dc.xsd`, `dcterms.xsd`, `dcmitype.xsd` | http://dublincore.org/schemas/xmls/qdc/ |
| `xml.xsd` | http://www.w3.org/2001/xml.xsd |
| `w3c_geo.xsd` | https://raw.githubusercontent.com/tdwg/tapir/master/schema/w3c_geo.xsd |
| `dwc_master_tdwg_basetypes.xsd` | https://raw.githubusercontent.com/tdwg/dwc/master/docs/xml/tdwg_basetypes.xsd (lo incluye `tdwg_dw_core.xsd`; distinto del `tdwg_basetypes.xsd` de PliC) |

## Cambios respecto al original (solo estos)
1. **`schemaLocation` a local.** Todas las URL remotas apuntan al fichero de esta carpeta. `http://www.w3.org/2001/03/xml.xsd`
   y `http://services.eol.org/schema/xml.xsd` (ya no responde) → `xml.xsd` del W3C. Saltos de línea normalizados a LF.
2. **Una línea del esquema principal.** El XSD «stable» tal cual **no compila** (libxml2: `The QName value 'dataset' does
   not resolve to a(n) element declaration`, y lo mismo con `associatedParty`, `pubDate`, `temporalCoverage` y
   `description`): los usa sin prefijo, pero solo importa `eml.xsd` con el espacio `eml://ecoinformatics.org/eml-2.1.1`,
   y esas declaraciones están en `eml-gbif-profile-PliC.xsd`, sin espacio de nombres. Se sustituye
   `<xs:import namespace="eml://…" schemaLocation="eml.xsd"/>` por `<xs:include schemaLocation="eml-gbif-profile-PliC.xsd"/>`
   (comentado en el propio XSD). Es el fichero que el repo prepara para eso (su import de `eml.xsd` está comentado).
   Con ello `eml.xsd` y `eml-gbif-profile.xsd` dejan de usarse y no se vendorizan.

Ningún elemento ni atributo se ha añadido, quitado ni cambiado de tipo. Los ejemplos XML del repo (`Use cases and
examples/`) son de PliC 3.0/3.1 (espacio de nombres de EOL) y no validan contra la 3.2.2.7: no sirven de referencia.
