# F9 · EOO y AOO

Requiere F8b hecha. Lee `CLAUDE.md`, `shared/iucn-criteria.json` (solo `criteria.B.metrics`),
`shared/sensitivity.json`, `docs/iucn.md`, `docs/mapa.md` y las firmas de `frontend/js/geo/utm.js`,
`backend/Lib/{sensitivity,authz}.php` y `frontend/js/views/iucn-wizard.js`. No leas NaturDex.

## Definiciones (las de la UICN, sin inventar)
- **EOO:** área del **polígono convexo mínimo** que contiene todos los registros, en km².
- **AOO:** nº de **celdas de rejilla ocupadas × área de la celda**. Celda por defecto **2 × 2 km (4 km²)**;
  1 km opcional con aviso («los umbrales de B2 están calibrados para 2 km»). Se guarda siempre el tamaño usado.
- Si EOO < AOO → EOO = AOO (con aviso). Con menos de 3 puntos no colineales no hay EOO.
- Cálculo en **ETRS89 / UTM 30N (EPSG:25830)**. Registros con longitud fuera de 6° O–0° (fuera del huso 30):
  se calculan igual pero con aviso de deformación.
- Rejilla anclada al origen UTM (celdas `floor(x / tamaño)`), la misma que la cuadrícula UTM nacional. Además,
  como dato informativo, el mínimo y el máximo de AOO desplazando la rejilla medio tamaño en x, en y y en ambos
  (la UICN advierte de que la posición de la rejilla influye).

## Qué registros cuentan
Solo `occurrenceStatus = present`, no borrados y del catálogo. Por defecto se excluyen los de
`coordinateUncertaintyInMeters` > tamaño de celda (se informa de cuántos). Ámbito `team` (todo el equipo) o `me`
(solo los propios). Número de **localidades** NO se calcula: depende de las amenazas y lo decide el investigador.

## 1. Cálculo en dos sitios, mismo resultado
- `shared/geo-metrics.js` (JS puro): casco convexo (cadena monótona de Andrew), área por la fórmula del
  lazo, celdas de rejilla, desplazamientos. Usa `utm.js`.
- `backend/Lib/metrics.php` + `backend/Lib/utm.php`: el mismo algoritmo portado a PHP.
- `shared/metrics-cases.json`: casos de prueba **compartidos** que ejecutan los dos (Node o página por CDP para
  JS, `backend/bin/test-f9.php` para PHP), con tolerancia de 0,01 km²:
  - 4 puntos en un cuadrado de 10 km de lado → EOO 100 km².
  - 3 puntos colineales → sin EOO. 2 puntos → sin EOO.
  - 3 puntos en la misma celda de 2 km → AOO 4 km², 1 celda. Un punto justo en el borde de celda.
  - Mismo conjunto con celda de 1 km. Caso EOO < AOO → EOO igualada. Ausencias y registros imprecisos excluidos.
  - Un conjunto real de ~15 puntos en Sierra Nevada: si en la máquina hay Python con `pyproj` + `shapely`,
    genera la referencia con ellos (`backend/bin/metrics-reference.py`) y compárala; si no, anótalo.

## 2. Servidor: el cálculo definitivo
- `GET /api/catalogs/{id}/species/{taxonID}/metrics?scope=team|me&cell=2|1` (permiso `view`): calcula con las
  **coordenadas exactas**, guarda en `species_metrics` y devuelve cifras (`eooKm2`, `aooKm2`, `aooCells`,
  `cellSizeKm`, `aooRange`, `nRecords`, `nExcluded`, avisos, `computedAt`).
- **Geometría según confianza** (misma regla que `applyVisibility`): owner, custodio o nivel 4 → polígono de la EOO
  y celdas de la AOO. Resto con nivel 2–3 → **solo las cifras** y, para el mapa, las celdas de su nivel de
  generalización (0,1° o 0,01°) que contienen registros. Nivel 1 → solo cifras. Nunca se devuelve el casco
  convexo a quien no puede ver exactas: sus vértices son registros reales.
- Se recalcula al pedirlo si hay marcas nuevas desde `computed_at` (compara con el `server_seq` máximo).

## 3. Dispositivo: provisional sin red
- Sin red, `geo-metrics.js` calcula con las marcas **exactas** que tenga el dispositivo (las generalizadas
  se excluyen) y lo rotula «Provisional · N registros exactos en este dispositivo · el definitivo lo calcula el
  servidor». El último resultado del servidor se guarda en IndexedDB y se muestra con su fecha.

## 4. Interfaz
- Ficha de especie: bloque **«Distribución»** con EOO y AOO, nº de registros y excluidos, conmutador equipo/propias
  y 2 km/1 km, y el origen del dato (servidor con fecha o provisional).
- Mapa: capa «EOO / AOO» para la especie elegida (polígono y celdas, o celdas generalizadas según confianza),
  con leyenda.
- **Asistente UICN, paso B:** rellena EOO y AOO con su origen y tamaño de celda (editables); si la celda es 1 km,
  aviso. Recuerda que las localidades las introduce el investigador.
- `docs/iucn.md`: sección «EOO y AOO en BioDex» (≤ 30 líneas) con estas reglas y sus limitaciones: el resultado
  refleja los registros del catálogo, no la distribución completa de la especie.

## 5. Comprobación
- Casos compartidos en verde en JS y PHP.
- `test-f9.php`: endpoint con owner (geometría completa), editor en especie de nivel 2 (solo cifras + celdas de
  0,1°, **sin vértices del casco**), recálculo tras una marca nueva, exclusión de ausencias.
- Recorrido CDP: especie con 12 marcas → EOO/AOO en la ficha y en el mapa; sin red, provisional rotulado;
  asistente paso B relleno → con 1 localidad y b(iii) propone la categoría esperada según las cifras.
`VERSION`, `APP_VERSION` `0.9.0 · F9`. Resultados en `docs/prompts/F9-checklist.md`. Commits `F9:`. Resume en 8 líneas.
