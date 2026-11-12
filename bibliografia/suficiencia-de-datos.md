# Suficiencia de datos para la evaluación UICN automática: bibliografía y reglas

Base bibliográfica para la fase «suficiencia de datos»: cuántos datos, de qué tipo y con qué método hacen falta para que
lo que BioDex calcula desde las marcas (maduros, AOO, EOO, tendencias) tenga fundamento. Recopilada el 2026-11-12.

**Cómo leerlo.** Cada apartado tiene: qué dice la bibliografía (con la cita) → qué hace hoy BioDex (1.8.0) → regla
propuesta. Las reglas son **propuestas de diseño**, no normas de la UICN: donde la bibliografía no da un número, se dice.
**Verificación**: los DOI se comprobaron contra Crossref uno a uno; los documentos de la UICN, del MITECO y de la BLM,
en su página o PDF oficial. Las citas marcadas con † se tomaron de un trabajo que las cita, sin leer el original.

---

## 1. Calidad mínima del dato por criterio (la regla que más falta)

**Bibliografía.** Las Directrices (IUCN SPC 2024, §3.1, tabla 3.1) ordenan la calidad: observado > estimado =
proyectado > inferido > sospechado, y fijan un **mínimo por parámetro**. Si el dato es de calidad inferior, el criterio
**no se cumple aunque se supere el umbral numérico**:

| Criterio | Parámetro | Calidad mínima |
|---|---|---|
| A | Reducción de la población | sospechada |
| B | AOO y EOO | estimada |
| B1b, B2b | Disminución continua (EOO, AOO, hábitat, localidades, subpoblaciones, maduros) | inferida |
| C, D | Nº de individuos maduros | estimado |
| C1 | Disminución continua estimada | estimada |
| C2 | Disminución continua de maduros | inferida |
| C2a(i) | Tamaño de la mayor subpoblación | estimado |
| E | Probabilidad de extinción | estimada |

**BioDex hoy.** Cada dato lleva `quality`, pero `shared/iucn-engine.js` no la usa para decidir.

**Regla propuesta.** El motor compara la calidad de cada entrada con la tabla 3.1. Si no llega: «cumpliría EN por el
umbral, pero el dato es *inferido* y B pide *estimado*» y el criterio queda «datos insuficientes». Esta regla es la que
convierte la idea de «mínimos por tipo de dato» en algo que la propia UICN exige.

## 2. Incertidumbre, intervalos y actitud ante el riesgo

**Bibliografía.** Akçakaya et al. (2000) formalizan la clasificación con datos inciertos (números difusos, rango
plausible de categorías). Las Directrices (§3.2) piden mejor estimación + rango plausible, documentar el método, y
señalan que el rango puede fijarse con un intervalo de confianza o credibilidad **del 90 %**; para listas globales
recomiendan una tolerancia al riesgo única y **algo precautoria (0,40–0,49)**. Mace et al. (2008) y Collen et al. (2016)
explican la lógica de los umbrales; Keith et al. (2004) muestran que los protocolos aciertan más cuando se incorpora la
incertidumbre de los parámetros.

**BioDex hoy.** Evalúa con mejor valor y extremos (bien), pero los intervalos son del **95 %** y la actitud es
**neutra** (sin opción de cambiarla).

**Regla propuesta.** (a) Intervalos del 90 % para el rango plausible, como en las Directrices (se puede seguir mostrando
el 95 % en los gráficos). (b) Tolerancia al riesgo configurable por catálogo, por defecto la neutra actual, con la opción
0,40–0,49 documentada. Revisar ambas con quien evalúe para la Lista Roja.

## 3. Individuos maduros (C y D)

**Bibliografía.**
- Directrices §4.3: maduros = capaces de reproducirse; en clonales, el ramet; usar la estimación baja con proporción de
  sexos sesgada o poblaciones fluctuantes; el cálculo densidad × superficie × proporción ocupada **puede sobreestimar
  mucho**, y la proporción debe salir del conocimiento del taxón, no de un valor por defecto.
- Iriondo (coord., 2011), manual del AFA: el resultado ideal es el **censo directo**, contando ejemplares **hasta al
  menos 2.500** (estudio básico) o **5.000** (detallado); por encima, estimar con la densidad de parcelas o transectos,
  por biotopo si la población es heterogénea. Recomienda marcar los ejemplares contados para no contar dos veces.
- Elzinga, Salzer y Willoughby (1998, cap. 7): no hay un mínimo fijo de parcelas; hacer un **muestreo piloto
  secuencial** (media y desviación acumuladas frente a n) y parar cuando se estabilizan; empezar con **n ≈ 8–10** si los
  valores son parecidos y **n ≥ 15** si varían mucho; corrección de Kupper y Hafner para muestras pequeñas; con
  muchos ceros el diseño es malo.
- Bootstrap: el intervalo por **percentiles se queda corto (cobertura baja) con muestras pequeñas** (Hesterberg 2015);
  DiCiccio y Efron (1996) revisan alternativas (BCa, bootstrap-t).
- Detectabilidad: incluso en plantas la detección es imperfecta (Chen et al. 2013; Kéry y Schmidt 2008); los modelos
  N-mixture (Royle 2004) estiman abundancia desde recuentos repetidos.

**BioDex hoy.** Mínimo observado deduplicado por celda de 50 m y día (bien). Densidad por bootstrap percentil con
cualquier nº de parcelas ≥ 2.

**Reglas propuestas.**
1. El **mínimo** cuenta como *observado* solo si la visita se marca como **censo completo** de la subpoblación
   (casilla nueva en la visita). Si no, es una cota inferior sin calidad suficiente para C/D (tabla 3.1 pide
   *estimado*), salvo que ya supere el umbral (entonces basta para decir «no cumple»).
2. **Estimado por parcelas** solo con **≥ 10 parcelas** en poblaciones homogéneas o **≥ 15** si el coeficiente de
   variación de la densidad es alto (Elzinga et al.); medidor «7/10 parcelas». Mostrar la curva de media acumulada.
3. Con n < 15, **BCa o intervalo t sobre log(densidad)** en lugar del percentil (Hesterberg; DiCiccio y Efron). Añadir
   casos de cobertura simulada a `iucn-cases.json`.
4. Mantener el aviso del §4.3.1 cuando se usa `occupied_habitat_km2`.

## 4. AOO y EOO (B)

**Bibliografía.**
- La AOO por recuento de celdas **refleja sobre todo el esfuerzo**: correlación r = 0,98 con el nº de registros en 227
  helechos (Marsh et al. 2023); hacen falta más de ~500 registros únicos a 2 × 2 km para superar el umbral de VU.
  Directrices §3.1: los sitios inferidos solo pueden fijar el **límite superior** de AOO/EOO.
- El downscaling de ocupación (Kunin 1998; Marsh et al. 2019) es más exacto que el recuento bruto salvo con cobertura
  muy alta, aunque con poca cobertura sigue infraestimando.
- EOO por polígono convexo: sesgado según tamaño de muestra, forma del hábitat y reparto del esfuerzo; los α-hull
  reducen parte del sesgo (Burgman y Fox 2003). El EOO por polígono es bastante robusto salvo en las especies con menos
  registros, y el α-hull es el más estable al quitar registros (Marsh et al. 2023). Unos **20 puntos** dan un EOO
  parecido al de muestras mucho mayores (Syfert et al. 2014†). Herramientas de referencia: GeoCAT (Bachman et al.
  2011) y ConR (Dauby et al. 2017).
- Completitud del muestreo: curvas de acumulación y extrapolación (Colwell y Coddington 1994; Gotelli y Colwell
  2001), rarefacción por cobertura (Chao y Jost 2012), celdas bien muestreadas (KnowBR, Lobo et al. 2018; Hortal et
  al. 2007; Meyer et al. 2015).
- Ausencias: con detección imperfecta, **al menos 3 visitas por sitio** si la probabilidad de detección es > 0,5, más
  si es menor (MacKenzie y Royle 2005; MacKenzie et al. 2002; Guillera-Arroita et al. 2010). El AFA registra las
  cuadrículas vacías y la superficie prospectada (Iriondo 2011).
- Distancia de separación de 1 km entre poblaciones de plantas: la usa el AFA (Iriondo 2011); subpoblaciones y
  localidades desde datos de herbario en Rivers et al. (2010).

**BioDex hoy.** EOO/AOO desde las marcas del catálogo, siempre «mínimo» implícito; sin medida de completitud.

**Reglas propuestas.**
1. AOO desde las marcas = **observada como mínimo**. Pasa a **estimada** solo con un **indicador de completitud**:
   curva de acumulación de celdas de 2 km frente a visitas (con ausencias) cuya pendiente final sea casi nula, o una
   cobertura de muestreo alta (Chao y Jost). El umbral exacto no está en la bibliografía para AOO: fijarlo con
   simulación y documentarlo.
2. **Ausencia confirmada** en una celda = ≥ 3 visitas sin detección (o un modelo de ocupación); una sola visita
   negativa es «no detectada».
3. EOO **estimado** con ≥ 20 presencias en celdas distintas; por debajo, «mínimo». Mostrar el α-hull como contraste
   (Burgman y Fox) y aplicar EOO ≥ AOO (Directrices §4.9).
4. Opcional: AOO por downscaling (Marsh et al. 2019) como límite superior.

## 5. Series temporales (A y C1)

**Bibliografía.**
- Directrices §4.5.1: con dos puntos, cálculo directo; con varios, **regresión** comprobando el ajuste; modelo
  exponencial si la tasa proporcional es constante; si no se puede elegir modelo, dar el rango.
- Las series cortas y ruidosas clasifican mal: con ventanas de 10 años y el umbral del 30 %, ~40 % de falsas alarmas y
  ~60 % de declives no detectados en simulación (Connors et al. 2014); véanse también Porszt et al. (2012) y
  d'Eon-Eggertson et al. (2015). Recomiendan no usar la tendencia sola.
- Tiempo mínimo para detectar una tendencia (potencia 0,8, α = 0,05) en 822 series de vertebrados: **15,9 años de
  media**; el 72 % necesitó ≥ 10 años; depende sobre todo de la fuerza de la tendencia, el CV y la autocorrelación
  (White 2019). Análisis de potencia: Gerrodette (1987).
- Para estimar la magnitud con ±0,1 de error hacen falta ~9 de 10 años; los declives cortos se pierden más que las
  subidas (Wauchope et al. 2019).
- Modelo: TRIM / rtrim (Bogaart, van der Loo y Pannekoek); sobredispersión: Ver Hoef y Boveng (2007); diseño de
  seguimiento: Yoccoz et al. (2001), Pollock et al. (2002).

**BioDex hoy.** Poisson log-lineal con efecto sitio (como TRIM), cuasi-Poisson con φ ≥ 1, `MIN_YEARS = 3`.

**Reglas propuestas.**
1. Mantener 3 años para **calcular**, pero calificar el resultado: **potencia** para detectar la reducción del umbral
   con el CV observado (Gerrodette; White). Con potencia < 0,8, la ausencia de declive no se puede afirmar («no se
   detecta», no «no hay»).
2. Mínimo de **grados de libertad** para estimar φ; si no se llega, φ prudente por defecto.
3. Mostrar la **fracción de la ventana observada** (ya existe) y bajar la calidad a *proyectada* según esa fracción.
4. No dejar que A decida sola si la serie es corta: aviso cuando la categoría la determina solo A (Connors et al.).

## 6. Parámetros que siguen siendo manuales

Duración de generación (Directrices §4.4: tabla de vida, mortalidad adulta o edad de primera reproducción + z ×
periodo reproductor; en plantas con banco de semillas, juvenil + vida media de la semilla; en clonales, media
ponderada), distancia de dispersión, definición de maduro y localidades (§4.11). Ninguno sale de las marcas.

## 7. Estándares de datos

Para exportar esfuerzo y ausencias: Darwin Core (Wieczorek et al. 2012) y la **Humboldt Extension**, ratificada por TDWG
el 2024-02-28, basada en Guralnick, Walls y Jetz (2018).

---

## Qué se ha implementado (BioDex 1.9.0, fase F11i)

| Regla | Dónde | Estado |
|---|---|---|
| 1. Tabla 3.1 en el motor: un dato de calidad inferior a la mínima no cumple el criterio; «Calidad insuficiente» → puede dar DD | `shared/iucn-engine.js`, `shared/iucn-criteria.json › dataQuality`, `Lib/iucn.php` | Hecho |
| 2a. Intervalos del 90 % para el rango plausible (densidad BCa, tasa y reducción de la serie) | `iucn-quantifiers.js`, `iucn-series.js` y ports | Hecho |
| 2b. Tolerancia al riesgo configurable | — | Pendiente (decisión de diseño) |
| 3.1 Censo completo declarado → maduros observados | hecho `census_complete` | Hecho |
| 3.2 ≥ 10 parcelas (≥ 15 si CV > 1) para maduros estimados | `matureEstimate` | Hecho |
| 3.3 BCa en lugar de percentiles | `bootstrapMean` | Hecho |
| 3.4 Aviso de densidad × superficie (§4.3.1) | requisito «Superficie de hábitat» | Hecho |
| 4.1 AOO estimada solo con completitud (Chao2 ≥ 90 % y ≥ 5 días) | `samplingCompleteness`, `rangeQuality` | Hecho |
| 4.2 Ausencia confirmada con ≥ 3 días | `samplingCompleteness` (informa) | Hecho |
| 4.3 EOO estimado con ≥ 20 celdas; α-hull como contraste | `rangeQuality` | Hecho (α-hull pendiente) |
| 4.4 AOO por downscaling | — | Pendiente |
| 5.1 Potencia y reducción mínima detectable | `analyzeSeries` (`power`) | Hecho |
| 5.2 Grados de libertad para φ | `analyzeSeries` (`df`), `seriesQuality` | Hecho (decide la calidad; no se fija un φ por defecto) |
| 5.3 Fracción observada de la ventana | ya existía; la reducción es *proyectada* si no cubre la ventana | Hecho |
| 5.4 Aviso si A decide con poca potencia | nota del grupo «Serie temporal» | Hecho |
| Barras de requisitos mínimos (rojo → verde) | `species-iucn.js`, `biodex.css` | Hecho |

Pruebas: `shared/sufficiency-cases.json` (mismos resultados en JS y PHP: `node shared/iucn-engine.test.js`,
`php backend/bin/test-sufficiency.php`, este también en CI).

## Referencias

**UICN y metodología española**
- IUCN (2012). *IUCN Red List Categories and Criteria: Version 3.1*. 2.ª ed. Gland y Cambridge: IUCN.
- IUCN (2012). *Guidelines for Application of IUCN Red List Criteria at Regional and National Levels: Version 4.0*. Gland y Cambridge: IUCN.
- IUCN Standards and Petitions Committee (2024). *Guidelines for Using the IUCN Red List Categories and Criteria. Version 16* (marzo de 2024). Leída en la copia de Artsdatabanken.
- Iriondo, J. M. (coord.) (2011). *Atlas y Libro Rojo de la Flora Vascular Amenazada de España. Manual de metodología del trabajo corológico y demográfico*. Madrid: MARM y SEBiCoP. 70 pp.
- Elzinga, C. L., Salzer, D. W. y Willoughby, J. W. (1998). *Measuring and Monitoring Plant Populations*. BLM Technical Reference 1730-1. Denver: Bureau of Land Management.

**Riesgo de extinción e incertidumbre**
- Akçakaya, H. R., Ferson, S., Burgman, M. A., Keith, D. A., Mace, G. M. y Todd, C. R. (2000). Making consistent IUCN classifications under uncertainty. *Conservation Biology* 14: 1001–1013. https://doi.org/10.1046/j.1523-1739.2000.99125.x
- Collen, B. et al. (2016). Clarifying misconceptions of extinction risk assessment with the IUCN Red List. *Biology Letters* 12: 20150843. https://doi.org/10.1098/rsbl.2015.0843
- Keith, D. A. et al. (2004). Protocols for listing threatened species can forecast extinction. *Ecology Letters* 7: 1101–1108. https://doi.org/10.1111/j.1461-0248.2004.00663.x
- Mace, G. M. et al. (2008). Quantification of extinction risk: IUCN's system for classifying threatened species. *Conservation Biology* 22: 1424–1442. https://doi.org/10.1111/j.1523-1739.2008.01044.x

**Área de distribución, AOO y EOO**
- Bachman, S., Moat, J., Hill, A. W., de la Torre, J. y Scott, B. (2011). Supporting Red List threat assessments with GeoCAT. *ZooKeys* 150: 117–126. https://doi.org/10.3897/zookeys.150.2109
- Burgman, M. A. y Fox, J. C. (2003). Bias in species range estimates from minimum convex polygons. *Animal Conservation* 6: 19–28. https://doi.org/10.1017/S1367943003003044
- Dauby, G. et al. (2017). ConR: An R package to assist large-scale multispecies preliminary conservation assessments using distribution data. *Ecology and Evolution* 7: 11292–11303. https://doi.org/10.1002/ece3.3704
- Gaston, K. J. y Fuller, R. A. (2009). The sizes of species' geographic ranges. *Journal of Applied Ecology* 46: 1–9. https://doi.org/10.1111/j.1365-2664.2008.01596.x
- Joppa, L. N. et al. (2016). Impact of alternative metrics on estimates of extent of occurrence for extinction risk assessment. *Conservation Biology* 30: 362–370. https://doi.org/10.1111/cobi.12591
- Kunin, W. E. (1998). Extrapolating species abundance across spatial scales. *Science* 281: 1513–1515. https://doi.org/10.1126/science.281.5382.1513
- Marsh, C. J., Gavish, Y., Kunin, W. E. y Brummitt, N. A. (2019). Mind the gap: Can downscaling area of occupancy overcome sampling gaps when assessing IUCN Red List status? *Diversity and Distributions* 25: 1832–1845. https://doi.org/10.1111/ddi.12983
- Marsh, C. J., Syfert, M. M., Aletrari, E., Gavish, Y., Kunin, W. E. y Brummitt, N. (2023). The effect of sampling effort and methodology on range size estimates of poorly-recorded species for IUCN Red List assessments. *Biodiversity and Conservation* 32: 1105–1123. https://doi.org/10.1007/s10531-023-02543-9
- Rivers, M. C., Bachman, S. P., Meagher, T. R., Nic Lughadha, E. y Brummitt, N. A. (2010). Subpopulations, locations and fragmentation: applying IUCN red list criteria to herbarium specimen data. *Biodiversity and Conservation* 19: 2071–2085. https://doi.org/10.1007/s10531-010-9826-9
- Syfert, M. M. et al. (2014). Using species distribution models to inform IUCN Red List assessments. *Biological Conservation* 177: 174–184. https://doi.org/10.1016/j.biocon.2014.06.012

**Completitud del muestreo**
- Chao, A. y Jost, L. (2012). Coverage-based rarefaction and extrapolation. *Ecology* 93: 2533–2547. https://doi.org/10.1890/11-1952.1
- Colwell, R. K. y Coddington, J. A. (1994). Estimating terrestrial biodiversity through extrapolation. *Phil. Trans. R. Soc. B* 345: 101–118. https://doi.org/10.1098/rstb.1994.0091
- Gotelli, N. J. y Colwell, R. K. (2001). Quantifying biodiversity: procedures and pitfalls. *Ecology Letters* 4: 379–391. https://doi.org/10.1046/j.1461-0248.2001.00230.x
- Hortal, J., Lobo, J. M. y Jiménez-Valverde, A. (2007). Limitations of biodiversity databases: case study on seed-plant diversity in Tenerife. *Conservation Biology* 21: 853–863. https://doi.org/10.1111/j.1523-1739.2007.00686.x
- Lobo, J. M. et al. (2018). KnowBR: an application to map the geographical variation of survey effort and identify well-surveyed areas. *Ecological Indicators* 91: 241–248. https://doi.org/10.1016/j.ecolind.2018.03.077
- Meyer, C., Kreft, H., Guralnick, R. y Jetz, W. (2015). Global priorities for an effective information basis of biodiversity distributions. *Nature Communications* 6: 8221. https://doi.org/10.1038/ncomms9221

**Detectabilidad y ocupación**
- Chen, G., Kéry, M., Plattner, M., Ma, K. y Gardner, B. (2013). Imperfect detection is the rule rather than the exception in plant distribution studies. *Journal of Ecology* 101: 183–191. https://doi.org/10.1111/1365-2745.12021
- Guillera-Arroita, G., Ridout, M. S. y Morgan, B. J. T. (2010). Design of occupancy studies with imperfect detection. *Methods in Ecology and Evolution* 1: 131–139. https://doi.org/10.1111/j.2041-210X.2010.00017.x
- Kéry, M. y Royle, J. A. (2016). *Applied Hierarchical Modeling in Ecology, vol. 1*. Academic Press. ISBN 978-0-12-801378-6.
- Kéry, M. y Schmidt, B. R. (2008). Imperfect detection and its consequences for monitoring for conservation. *Community Ecology* 9: 207–216. https://doi.org/10.1556/ComEc.9.2008.2.10
- MacKenzie, D. I. et al. (2002). Estimating site occupancy rates when detection probabilities are less than one. *Ecology* 83: 2248–2255. https://doi.org/10.1890/0012-9658(2002)083[2248:ESORWD]2.0.CO;2
- MacKenzie, D. I. y Royle, J. A. (2005). Designing occupancy studies: general advice and allocating survey effort. *Journal of Applied Ecology* 42: 1105–1114. https://doi.org/10.1111/j.1365-2664.2005.01098.x
- Royle, J. A. (2004). N-mixture models for estimating population size from spatially replicated counts. *Biometrics* 60: 108–115. https://doi.org/10.1111/j.0006-341X.2004.00142.x

**Tendencias y seguimiento**
- Bogaart, P., van der Loo, M. y Pannekoek, J. *rtrim: Trends and Indices for Monitoring Data* (paquete de R, v2.3.1). https://doi.org/10.32614/CRAN.package.rtrim
- Connors, B. M., Cooper, A. B., Peterman, R. M. y Dulvy, N. K. (2014). The false classification of extinction risk in noisy environments. *Proc. R. Soc. B* 281: 20132935. https://doi.org/10.1098/rspb.2013.2935
- d'Eon-Eggertson, F., Dulvy, N. K. y Peterman, R. M. (2015). Reliable identification of declining populations in an uncertain world. *Conservation Letters* 8: 86–96. https://doi.org/10.1111/conl.12123
- Gerrodette, T. (1987). A power analysis for detecting trends. *Ecology* 68: 1364–1372. https://doi.org/10.2307/1939220
- Pollock, K. H. et al. (2002). Large scale wildlife monitoring studies: statistical methods for design and analysis. *Environmetrics* 13: 105–119. https://doi.org/10.1002/env.514
- Porszt, E. J., Peterman, R. M., Dulvy, N. K., Cooper, A. B. y Irvine, J. R. (2012). Reliability of indicators of decline in abundance. *Conservation Biology* 26: 894–904. https://doi.org/10.1111/j.1523-1739.2012.01882.x
- Ver Hoef, J. M. y Boveng, P. L. (2007). Quasi-Poisson vs. negative binomial regression: how should we model overdispersed count data? *Ecology* 88: 2766–2772. https://doi.org/10.1890/07-0043.1
- Wauchope, H. S., Amano, T., Sutherland, W. J. y Johnston, A. (2019). When can we trust population trends? *Methods in Ecology and Evolution* 10: 2067–2078. https://doi.org/10.1111/2041-210X.13302
- White, E. R. (2019). Minimum time required to detect population trends: the need for long-term monitoring programs. *BioScience* 69: 40–46. https://doi.org/10.1093/biosci/biy144
- Yoccoz, N. G., Nichols, J. D. y Boulinier, T. (2001). Monitoring of biological diversity in space and time. *Trends in Ecology & Evolution* 16: 446–453. https://doi.org/10.1016/S0169-5347(01)02205-4

**Estadística (bootstrap)**
- DiCiccio, T. J. y Efron, B. (1996). Bootstrap confidence intervals. *Statistical Science* 11: 189–228. https://doi.org/10.1214/ss/1032280214
- Hesterberg, T. C. (2015). What teachers should know about the bootstrap. *The American Statistician* 69: 371–386. https://doi.org/10.1080/00031305.2015.1089789

**Estándares**
- Guralnick, R., Walls, R. y Jetz, W. (2018). Humboldt Core – toward a standardized capture of biological inventories for biodiversity monitoring, modeling and assessment. *Ecography* 41: 713–725. https://doi.org/10.1111/ecog.02942
- Wieczorek, J. et al. (2012). Darwin Core: an evolving community-developed biodiversity data standard. *PLoS ONE* 7: e29715. https://doi.org/10.1371/journal.pone.0029715

## Pendiente de leer en el original
- Directrices v16 §4.9–4.11 (EOO, AOO, localidades): la copia leída se cortaba antes; la regla EOO ≥ AOO está por confirmar en el texto.
- Syfert et al. (2014): el dato de ~20 puntos se toma de Marsh et al. (2023).
- Umbrales concretos de KnowBR para «celda bien muestreada».
- Tabla 1 de MacKenzie y Royle (2005): nº óptimo de visitas por combinación de ocupación y detección.
