# F9 · Checklist (EOO y AOO)

Automático (todo en verde al cerrar F9):
- `node shared/geo-metrics.test.js` y `docker compose exec php php bin/test-f9.php` pasan **los mismos 14 casos** de
  `shared/metrics-cases.json` (±0,01 km²): cuadrado de 10 km → EOO 100; punto interior; 3 colineales y 2 puntos → sin
  EOO; 3 puntos en una celda de 2 km → AOO 4 km², 1 celda, EOO 1,22 igualada a 4; los mismos con 1 km → 3 celdas;
  punto justo en la esquina de celda; rejilla desplazada (rango 4–8); ausencias, imprecisos y sin coordenadas
  excluidos (con 2 y 1 km); registro fuera del huso 30 con aviso; **Sierra Nevada, 15 puntos** con 2 km (EOO 31,83 <
  AOO 56 → 56) y 1 km (EOO 31,83, AOO 15), y su proyección contra PROJ (±1 cm). JS: 17 comprobaciones.
- `test-f9.php`: 50 (los casos en PHP y el endpoint): owner con casco y celdas en EPSG:25830, guardado en
  `species_metrics` con celda y `source_seq`, `view_exact` en `access_log`; segunda petición cacheada; **editor en
  nivel 2**: mismas cifras, `generalized` con celdas de 0,1° y **sin vértices del casco** (ni una coordenada de las
  marcas en la respuesta); lector; ámbito «me» del editor (sus 4, geometría completa); nivel 3 (0,01°), nivel 1 (solo
  cifras; la owner sí ve la geometría), nivel 4 (el editor la ve); **recálculo tras una marca nueva**; **ausencia
  excluida**; impreciso excluido con aviso; celda de 1 km en otra fila; 403 no miembro, 400 `cell`/`scope`, 404, 405.
- Siguen en verde el motor UICN (74), `test-f8b` (57), `test-f8a` (68), `test-f7` (35), `test-f6` (89), `test-f5` (58),
  `test-f4` (31), `utm.test.mjs` y `check-sw.php` (`VERSION v9`, 66 ficheros).

**Referencia independiente**: en esta máquina no hay Python (solo el alias de la Microsoft Store), así que no se ha
podido pasar `backend/bin/metrics-reference.py` (pyproj + shapely). La referencia del caso Sierra Nevada sale de PROJ
(`cs2cs EPSG:4258 EPSG:25830`, imagen `osgeo/proj`) y de un casco por **envoltura de Jarvis**, un algoritmo distinto
del de `geo-metrics.js`: EOO 31,8294 km², 11 vértices, 14 celdas de 2 km y 15 de 1 km. Coincide en JS y en PHP.

Recorrido en Edge headless por CDP con los perfiles de F8b: Ana (propietaria, escritorio 1280 px) y Bruno (editor,
móvil 390 px), tema claro. Por API, catálogo «Recorrido F9» con la *Artemisia granatensis* (nivel 2) y **12 marcas**
en Sierra Nevada (8 de Ana, 4 de Bruno). Esperado (motor en Node): equipo, 2 km → EOO 48 km² igualada, AOO 48 km²
(12 celdas), rango 44–48; con 1 localidad y b(iii) → `CR B1ab(iii)`. 29 comprobaciones, consola sin errores de JS.
Capturas en `docs/design/f9-*.png`.

| # | Punto | Resultado | Captura |
|---|---|---|---|
| 1 | Ficha › «Distribución» (owner) | OK. SW `v9` con `geo-metrics.js`. EOO **48 km²** «igualada a la AOO», AOO **48 km²** en 12 celdas de 2 km, rango 44–48, 12 registros, 0 excluidos, «Servidor · Calculado con las coordenadas exactas el …», aviso EOO = AOO y la nota de que no es la distribución completa. 1 km: EOO 31,83, AOO 12 km² y aviso de calibración. «Propias»: 8 registros, 32 km² | `f9-1a-ficha-distribucion.png`, `f9-1b-ficha-1km.png` |
| 2 | Mapa › «EOO / AOO» (owner) | OK. Polígono convexo (1) y 12 celdas de 2 km; nota «EOO 48 km² · AOO 48 km² (12 celdas de 2 km, 12 registros) · Servidor, fecha» y leyenda | `f9-2a-mapa-owner.png` |
| 3 | Editor en nivel 2 | OK. La API le da las mismas cifras, `generalized` con celdas de 0,1°, sin `hull` ni `cells` y sin ninguna coordenada de Ana. En el mapa: solo celdas de 0,1°, ni polígono ni celdas de 2 km; nota «geometría generalizada según el nivel» | `f9-2b-mapa-editor-generalizado.png` |
| 4 | Sin red: provisional rotulado | OK. «Provisional · 4 registros exactos en este dispositivo · el definitivo lo calcula el servidor»: EOO 16, AOO 16 km² (las 8 de Ana llegan generalizadas y no cuentan), con «Último del servidor (fecha): EOO 48 km² · AOO 48 km² · 12 registros». El asistente sin red rellena 16 / 16 con ese origen | `f9-3a-ficha-provisional.png` |
| 5 | Asistente, paso B | OK. «Calcular (celdas de 2 km)» → EOO 48, AOO 48, «Origen: servidor, fecha · celdas de 2 km · 12 registros»; con 1 km, aviso de calibración. Con 1 localidad y b(iii): en vivo `CR · B1ab(iii)` («B2: AOO 48 km² ≥ 10 ✗»); resultado **`CR B1ab(iii)`**. Cambiar la AOO a mano: «AOO cambiada a mano» en el origen | `f9-4a-asistente-paso-B.png`, `f9-4b-asistente-resultado.png` |

Servidor tras el recorrido: `species_metrics` con 3 filas (equipo 2 km: 48/48, 12 celdas; equipo 1 km: 31,829/12;
«me» de Ana 2 km: 32/32), todas con el mismo `source_seq`; 7 `view_exact` «EOO/AOO 9918: 4» de Ana y ninguno de Bruno.

## Decisiones
- **La proyección se inyecta** en `geo-metrics.js` (`project`): `utm.js` vive en `frontend/js/geo/` y la ruta relativa
  desde `/shared/` no sería la misma en el navegador y en Node. nginx sirve también `/shared/geo-metrics.js`.
- Los casos admiten puntos ya proyectados (bordes de celda exactos, sin ruido de proyección) o registros lat/lon.
- **Ámbito «propias» → geometría completa** también para el editor: son sus marcas, y el autor ve siempre las suyas
  exactas (misma regla que `applyVisibility`). Lo recoge `metricsGeometryAccess()`.
- Celdas generalizadas = esquina SO de la celda de `gridDeg`, con el mismo redondeo que `generalizePoint()`.
- `species_metrics` guarda cifras, no geometría; el casco y las celdas se sacan al responder (con las exactas del
  momento) y solo si la confianza lo permite. `computedAt` solo cambia cuando hay marcas nuevas (`source_seq`).
- Sin precisión conocida, un registro cuenta (en BD siempre la hay). Una precisión igual a la celda cuenta; peor, no.
- Aviso «EOO = AOO» con coma decimal y el mismo texto en JS y PHP.

## Encontrado y corregido durante el recorrido
- La capa del mapa reutilizaba la clase `gen-cell` de las marcas generalizadas (F7): ahora `metric-gen-cell`.
- «PROVISIONAL Provisional · …»: la insignia pasa a «Dispositivo».
- El paso B enseñaba la nota interna del JSON («Guardar siempre el tamaño de celda usado»): frase para el usuario.
- Del script, no de la app: comprobar que el paso B no dice «F9» fallaba porque el catálogo se llama «Recorrido F9».

## Pendiente de mirar a mano
- [ ] **(Damián)** Pasar `backend/bin/metrics-reference.py` con Python + pyproj + shapely y comparar con los casos.
- [ ] `view_exact` se registra en cada petición de la owner (ficha, mapa y cada sincronización): 7 filas en el
      recorrido. ¿Registrar solo al recalcular, o una por día y especie?
- [ ] Con cientos de marcas: tiempo del cálculo en el servidor y del provisional en un móvil modesto.
- [ ] En la ficha, las cifras del ámbito «Equipo» para un editor sin red solo cuentan sus marcas: el rótulo lo dice,
      pero conviene validarlo con usuarios.

> **F10b (2026-09-30):** los puntos que siguen abiertos están recogidos en `docs/limitaciones.md`.
