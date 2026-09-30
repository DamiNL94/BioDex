# F5 · Checklist

Automático antes de esto (todo en verde al cerrar F5b): `docker compose exec php php bin/test-f5.php`
(servidor, F5a) y `check-sw.php` (`VERSION v4`, 39 ficheros en `SHELL_ASSETS`).

Recorrido F5b en Edge headless por CDP (8 comprobaciones, 8 OK). Dos perfiles: A (propietaria, escritorio
1280 px) y B (editor, móvil 390 px en oscuro). Posición con `Emulation.setGeolocationOverride` en Sierra
Nevada (37,0931, −3,3953, ±12 m); red cortada con `Network.emulateNetworkConditions`. Usuarios de prueba
`f5b-*@biodex.test` creados por API; B entra como editor con un `INSERT` en `catalog_members`.
Consola sin excepciones de JS; solo avisos de recursos provocados a propósito (peticiones sin red, 401 de
`/auth/me` antes de entrar, 403 del expulsado, 409 de la especie repetida, favicon sin red).
Capturas en `docs/design/f5-*.png`.

| # | Punto | Resultado | Captura |
|---|---|---|---|
| 1 | Con red: activar un catálogo con *Artemisia granatensis* (nivel 2) → descarga con progreso y fecha | OK. Al activarlo se descarga solo: barra de progreso («Guardando imágenes n de m») y al terminar «Disponible sin conexión · 30/09 04:16» y «Sin conexión ✓» en la fila. 3 fichas en IndexedDB, 3 fotos en `biodex-img-<id>`, nivel 2 sugerido | `f5-1a-descarga-progreso.png`, `f5-1b-disponible.png` |
| 2 | Sin red: recargar → Catálogos, Especies y fichas con imagen desde el dispositivo; banda de aviso | OK. Banda «Sin conexión: trabajas en local.»; nota «Datos del dispositivo · descargados el …»; la foto de la ficha carga (800 px) desde Cache Storage vía SW; árbol y resumen calculados en local; sin errores técnicos | `f5-2a-catalogos-sin-red.png`, `f5-2b-especies-sin-red.png`, `f5-2c-ficha-sin-red.png` |
| 3 | Sin red: 3 marcas → «3 pendientes»; recargar → siguen | OK. «Marcar aquí» da «Posición 37.09310, −3.39530 · ±12 m» y solo entonces deja guardar. Cabecera «3 PENDIENTES»; tras recargar, igual y 3 marcas en IndexedDB | `f5-3a-marcar-aqui.png`, `f5-3b-tres-pendientes.png` |
| 4 | Volver la red → en ≤ 30 s «0 pendientes» y 3 filas en MySQL | OK. El evento `online` → `/health` → sincroniza: la cola se vacía en 0,2 s (el indicador desaparece al llegar a 0) y hay 3 filas en `occurrences` | `f5-4-sincronizadas.png` |
| 5 | Segundo perfil (editor): ve las 3 marcas generalizadas con el aviso | OK. «Del equipo» con 3 × «Ubicación generalizada (~10 km)»; el servidor le envía 37.05000, −3.35000 (centro de la celda de 0,1°) y ±7122 m | `f5-5-editor-generalizadas.png` |
| 6 | Cerrar sesión con 1 pendiente → diálogo; exportar respaldo; importar en limpio → vuelve a la cola | OK. «Tienes 1 marca sin sincronizar» con Sincronizar ahora (desactivado sin red) · Exportar respaldo · Cancelar. Exportar descarga `biodex-respaldo-….json` (1 marca DwC + catálogo) y solo entonces aparece «Cerrar sesión y borrar del dispositivo». Tras cerrar: ninguna base `biodex-*` ni caché de imágenes. Entrar de nuevo e importar → «1 marca vuelve a la cola.» → se sincroniza (4 filas en MySQL) | `f5-6a-cerrar-sesion-pendientes.png`, `f5-6b-respaldo-exportado.png`, `f5-6c-importado.png` |
| 7 | Expulsar al editor (SQL) → su siguiente sync borra el catálogo de su dispositivo | OK. `DELETE FROM catalog_members …` y «Sincronizar ahora» en B: pull 403 `not_member` → catálogos 1 → 0, marcas 3 → 0, catálogo activo a null, caché `biodex-img-<id>` borrada | `f5-7-expulsado.png` |
| + | Especie creada sin red que ya existe (409) | OK. Sin red, «Crear especie» *Quercus ilex* L. → `biodex:<uuid>` en el dispositivo, se marca; al volver la red `POST /species` da 409 con el taxonID de EIDOS (7483): la marca y el alta en el catálogo pasan a ese id y el local desaparece | `f5-8a-especie-sin-red.png` |
| 8 | **A mano (Damián):** app instalada en Edge, `docker compose stop nginx`, marcar, `start` → se sincroniza sola | Pendiente | — |

## Pendiente de mirar a mano
- [x] Punto 8 (app instalada, nginx parado). **F10b:** comprobado por CDP (paso 3–4 de `docs/demo.md`). Con nginx caído el estado es «Servidor no disponible» (no
      «Sin conexión»): la vuelta la detecta `/health` cada 30 s y el reintento del motor (30 s, 1, 2, 5 min),
      así que puede tardar hasta ~30 s en sincronizar tras `docker compose start nginx`.
- [ ] Móvil real: permiso de ubicación denegado → aviso de guarda y ninguna marca; GPS sin señal (30 s).
- [ ] `navigator.storage.persist()`: en headless lo deniega el navegador («Temporal» en Ajustes); con la app
      instalada Edge/Chrome suelen concederlo.
- [ ] Rechazadas: no se provocó ninguna en el recorrido (el servidor las cubre en `test-f5.php`); revisar en
      Ajustes › Sincronización que «Reintentar» y «Descartar» se ven bien con una real.

> **F10b (2026-09-30):** los puntos que siguen abiertos están recogidos en `docs/limitaciones.md`.
