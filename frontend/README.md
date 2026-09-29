Frontend PWA de BioDex: JS sin framework ni build (módulos ES nativos), servido tal cual por nginx.

- `index.html` · `manifest.webmanifest` · `sw.js` (un fichero nuevo va a `SHELL_ASSETS` y sube `VERSION`;
  lo comprueba `backend/bin/check-sw.php`).
- `css/biodex.css` una sola hoja · `vendor/fonts/` Zilla Slab + Public Sans (OFL) · `img/` iconos
  (los PNG salen de `backend/bin/make-icons.php`).
- `js/app.js` arranque y rutas · `js/views/*` una vista por sección · todo HTML dinámico con `html```.
