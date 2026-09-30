# F4 · Checklist

Automático antes de esto (todo en verde al cerrar F4):
`docker compose exec php php bin/test-f2a.php`, `test-f2b.php`, `test-f4.php` y `check-sw.php`.

Recorrido en Edge headless por CDP (29 comprobaciones, 29 OK, consola sin errores; los 401/409/422
provocados a propósito no cuentan). Capturas en `docs/design/f4-*.png` (escritorio en claro, móvil en oscuro).

| # | Punto | Resultado | Captura |
|---|---|---|---|
| 1 | Buscar «aguila» con filtro Aves → *Aquila adalberti* con autor en redonda | OK. `<em>` solo en «Aquila adalberti»; «Brehm, CL, 1861» en redonda (`font-style: normal`). Foto con pie FOTOTECA MITECO · VÍA EIDOS, UICN de todos los ámbitos con año (EN · PENÍNSULA 2021 primero), ficha «AVES» quitable | `f4-1-buscador-aguila-aves.png` |
| 2 | `category=CR` + `legal=rd139` → resultados coherentes | OK. «lince» → solo tarjetas con UICN principal CR (*Lynx pardinus*). Con «aquila», *A. adalberti* no sale: es EN (comprobado en `test-f4.php`) | `f4-2-filtros-cr-rd139.png` |
| 3 | Constructor: 3 especies + guardar → en Mis catálogos con 3 especies | OK. Borrador en `localStorage` con 3; un solo `POST /api/catalogs` con `taxonIDs`; diálogo «¿Marcar como activo?»; la lista muestra «3 especies · Activo» y el borrador se borra | `f4-3a-constructor.png`, `f4-3b-guardado-activo.png` |
| 4 | Crear *Inventadus falsus* → clasificación con fuente; repetir → 409 con enlace | OK. Desde «Sin resultados» con el nombre precargado; «Fuente: ninguna» (ni EIDOS ni GBIF conocen el género) y aviso de guarda. Repetir → «Ya existe: *Inventadus falsus*» y «Abrir su ficha» abre la existente | `f4-4a-sin-resultados.png`, `f4-4b-especie-creada.png`, `f4-4c-ya-existe.png` |
| 5 | Ficha de *Artemisia granatensis*: UICN de todos los ámbitos, legal, nivel 2 sugerido; bajar a 4 sin motivo → error visible | OK. Tabla UICN (España CR 2010 principal, Mundial EN 2011), legal `CEEA · EN PELIGRO DE EXTINCIÓN`, `DIR. HÁBITATS · ANEXO II - PRIORITARIA`, `ANDALUCÍA · EN PELIGRO DE EXTINCIÓN`; «Nivel 2 · Alta · se mostrará a ~10 km…». Nivel 4 sin motivo → 422 y error en el formulario; con motivo se guarda y muestra autor, fecha y motivo | `f4-5a-ficha-artemisia.png`, `f4-5b-nivel-sin-motivo.png` |
| 6 | Árbol del catálogo y filtro por familia | OK. Reino › filo › clase › orden › familia con recuentos; «Asteraceae» → pestaña Especies con la ficha «FAMILIA ASTERACEAE ×» y 1 de 3 especies | `f4-6a-arbol.png`, `f4-6b-especies-familia.png` |
| 7 | Sin conexión: buscador deshabilitado con el aviso | OK. Campo y «Filtros» deshabilitados; banda «El buscador necesita conexión. Prepara tus catálogos antes de salir al campo» | `f4-7-sin-conexion.png` |
| + | Salir del constructor con cambios | OK. «atrás» abre el diálogo propio «¿Salir sin guardar?» con el foco dentro; «Seguir editando» lo mantiene; tras recargar, el borrador se recupera; «Descartar» lo cierra y lo borra | `f4-8-salir-sin-guardar.png` |
| + | Móvil (390 px) | OK. Constructor en pestañas «Buscar · Seleccionadas (n)», filtros en hoja desplegable, ficha a pantalla completa | `f4-movil-*.png` |

## Pendiente de mirar a mano
- [ ] Lector de pantalla (NVDA o TalkBack): recuento en `aria-live`, nombres de los botones «Añadir …» y
      «Quitar …», diálogos anunciados como diálogo.
- [ ] Móvil real: el «atrás» del sistema cierra en orden hoja de filtros → ficha → constructor (y este pregunta
      si hay borrador).

## Decisiones y notas
- Filtros: grupo, origen y ámbito son de un solo valor (EIDOS los filtra con `eq.`); marco legal y categoría
  admiten varios (la API ya los recibe con comas). Buscar exige texto (≥ 2 letras): sin texto la API solo
  miraría la caché local, que no es «el buscador de EIDOS».
- La tarjeta necesita foto y descripción ya en la búsqueda: `eidosSearch()` hace dos peticiones más por lotes
  (`v_imagenes` y `v_atributos_pliniancore` con `in.(…)`, ~0,2 s cada una).
- Bug encontrado en el recorrido y corregido: cerrar un panel con cambios sin guardar reapilaba su nivel
  dentro del bucle de desapilado de `BioDexNav` y colgaba la página. Ahora `isDirty()` no tiene efectos y
  reapilar + preguntar van en una microtarea (`components/overlay.js`).

> **F10b (2026-09-30):** los puntos que siguen abiertos están recogidos en `docs/limitaciones.md`.
