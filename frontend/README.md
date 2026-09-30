Frontend PWA de BioDex: JS sin framework ni build (módulos ES nativos), servido tal cual por nginx.

- `index.html` · `manifest.webmanifest` · `sw.js` (un fichero nuevo va a `SHELL_ASSETS` y sube `VERSION`;
  lo comprueba `backend/bin/check-sw.php`).
- `css/biodex.css` una sola hoja · `vendor/fonts/` Zilla Slab + Public Sans (OFL) · `img/` iconos
  (los PNG salen de `backend/bin/make-icons.php`).
- `js/app.js` arranque y rutas · `js/views/*` una vista por sección · todo HTML dinámico con `html```.
- Sin conexión (F5): `js/db.js` IndexedDB por usuario (`biodex-<userId>`) · `js/offline.js` descarga de
  catálogos y lecturas locales · `js/marks.js` marcas, cola y respaldo · `js/sync.js` motor de
  sincronización · `js/components/marks.js` «Marcar aquí» y «Mis marcas». Protocolo: `docs/sync.md`.
- Equipos (F6): `js/views/catalog-team.js` Equipo (pestaña del catálogo), Niveles y Registro · `js/views/join.js`
  unirse con invitación (`#/unirse/<código>` y «Tengo un código»).
- Marcas completas (F8a): `js/views/mark-sheet.js` hoja de marca (GPS o punto del mapa, presente/ausente,
  individuos, maduros, estado vital, sexo, hábitat; también editar) · `js/components/marks.js` resumen y «Mis
  marcas» · `js/components/species-notes.js` notas de la especie.
