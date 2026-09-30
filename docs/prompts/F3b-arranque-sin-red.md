# F3b · Arreglo: arranque y listas sin servidor

Corrección de F3 antes de seguir con F5. Lee `CLAUDE.md`, `frontend/sw.js`, `frontend/js/app.js`,
`frontend/js/auth.js`, `frontend/js/api.js` y `frontend/js/state.js`. No toques `backend/`.

## Síntomas (reproducidos por Damián con la app instalada en Edge)
1. **Arrancar con nginx parado → ventana en blanco**, sin página de error del navegador.
2. **Parar nginx con la app abierta → las listas no cargan** y la cabecera sigue diciendo «En línea».

## Causa probable (compruébala antes de arreglar)
- **El SW es network-first sin límite de tiempo.** Con el servidor caído (y en el campo con cobertura
  mínima, que es el caso real) la navegación espera a que la red falle; si la conexión se queda colgada
  en vez de rechazarse, la ventana se queda en blanco sin llegar nunca a la caché.
- **El arranque espera a la red:** `bootSession()` hace `GET /auth/me` (corte de 8 s) antes de pintar nada,
  aunque haya usuario guardado.
- **El indicador solo mira `navigator.onLine`**, no si el servidor responde.

Reprodúcelo con Edge headless por CDP: `docker compose stop nginx`, abrir la app, medir el tiempo hasta
el primer pintado y capturar la consola y las peticiones pendientes. Anota la causa real en el commit.

## Arreglo
1. **SW: el armazón pasa a cache-first** (es una app de campo: tiene que abrir al instante sin red).
   - Navegaciones y `SHELL_ASSETS`: responder desde la caché si está; en paralelo, revalidar contra la red
     y actualizar la caché (stale-while-revalidate). Si no está en caché, red con **corte de 3 s**.
   - Las versiones nuevas llegan por `VERSION` (como ahora). Al activarse un SW nuevo, aviso discreto
     «Hay una versión nueva · Recargar» en vez de recargar solo.
   - `/api/` sigue sin pasar por el SW.
2. **Arranque sin esperar a la red:** con `biodex.user` guardado se pinta la app **inmediatamente** y la
   sesión se valida en segundo plano (`/auth/me` con corte de 3 s). Solo un **401** manda a la pantalla de
   acceso (con el aviso de sesión caducada); un fallo de red o un timeout no.
3. **Estado de conexión real** en `state.js`: tres valores `online` · `server-down` · `offline`.
   - `offline`: `navigator.onLine === false`.
   - `server-down`: hay red pero una llamada a la API falla con `status 0` o timeout.
   - Mientras no sea `online`, se comprueba `/api/health` cada 30 s (y al volver el evento `online`).
   - Cabecera: «En línea» · «Servidor no disponible» · «Sin conexión», con la banda de aviso en los dos últimos.
4. **Listas sin servidor:** Catálogos y Especies nunca muestran un error técnico. Hasta que llegue F5
   (IndexedDB), guarda en `localStorage` la **última respuesta buena** de `GET /api/catalogs` y de
   `GET /api/catalogs/{activo}` y píntala con la línea «Datos de las HH:MM · sin conexión con el servidor».
   Sin copia: estado vacío «Sin conexión con el servidor. Tus catálogos se guardarán en el dispositivo en F5».
   (F5 sustituirá esta copia por IndexedDB; déjalo marcado con `TODO F5`.)
5. Sube `VERSION` del SW y `APP_VERSION`.

## Comprobación (añádela a `docs/prompts/F3-checklist.md`)
Con la app instalada en Edge y un catálogo activo:
- a) `docker compose stop nginx` → abrir la app → **pinta en < 1 s**, con el usuario, el catálogo activo,
  sus especies (copia guardada) y la banda «Servidor no disponible».
- b) Con la app abierta, parar nginx → al primer fallo la cabecera cambia a «Servidor no disponible»;
  `docker compose start nginx` → vuelve a «En línea» en ≤ 30 s sin recargar.
- c) Wifi desactivado → «Sin conexión».
- d) Con red y servidor: cambiar un texto de `index.html`, subir `VERSION` → aparece el aviso de versión nueva.
- e) Sesión caducada de verdad (borra la fila de `user_auth_tokens` en Adminer) → pantalla de acceso con el aviso.
- `check-sw.php` en verde. Commit `F3b:`. Resume en 6 líneas indicando la causa real encontrada.
