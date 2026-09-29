# F3 · Checklist manual

Automático antes de esto: `check-sw.php`, `test-f2a.php` y `test-f2b.php` en verde (ver README).

- [ ] **1. Escritorio.** Crear cuenta → llega el correo a Mailpit (http://localhost:8025) → crear catálogo
      → marcarlo activo (el cartel muestra su nombre) → Especies lista las del catálogo.
- [ ] **2. DevTools › Application.** *Manifest* sin errores ni avisos de iconos; *Service workers*: `sw.js`
      activado y controlando; *Cache storage* `biodex-shell-vN` con todos los `SHELL_ASSETS`. Marcar
      *Offline* (pestaña Network) y recargar → la app abre con la banda «Sin conexión: trabajas en local».
- [ ] **3. Móvil** (opción A o B del README). Instalar en la pantalla de inicio, poner el modo avión y
      abrir desde el icono → entra con el usuario guardado y la banda de aviso.
- [ ] **4. Tema oscuro legible.** Ajustes › Oscuro: el cartel es verde retroiluminado, no blanco invertido;
      texto ≥ 4,5:1 (medidos al hacer F3: gris de metadato 5,05, EN 5,56, VU 8,07, CR 5,45, latón del
      cartel 4,53, bandas de aviso 8,15).
- [ ] **5. Consola sin errores de CSP.** Recorrer las cuatro secciones, entrar y salir: ningún
      «Refused to … because it violates the Content Security Policy». (Los 401 de `/api/auth/me` al
      arrancar sin sesión son esperados.)

## Estado al cerrar F3 (Edge headless por CDP, 2026-09-30)
- 1: hecho (cuenta de prueba, correo en Mailpit, catálogo activo, 7 especies de EIDOS en la lista).
- 2: SW `activated` y controlando, 28 ficheros en caché, `Page.getAppManifest` sin errores; recarga con red
  cortada → entra con la banda de aviso.
- 3: **pendiente de hacer a mano en un móvil real** (instalar y modo avión).
- 4: contrastes medidos arriba; captura en oscuro revisada.
- 5: sin mensajes de CSP en la consola.
