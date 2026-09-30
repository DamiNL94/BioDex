# F6 · Checklist

Automático (todo en verde al cerrar F6): `docker compose exec php php bin/test-f6.php` (84 comprobaciones:
invitaciones, límites, roles, época de visibilidad, salir, transferir, expulsar, registro y ORCID),
`test-f5.php`, `test-f2b.php`, `test-f4.php` y `check-sw.php` (`VERSION v5`, 41 ficheros en `SHELL_ASSETS`).
`test-f2a.php` no llega a empezar por datos que dejó el recorrido de F5b (ver «Pendiente»), no por F6.

Recorrido en Edge headless por CDP con dos perfiles: A (propietaria, escritorio 1280 px, claro) y B (móvil
390 px, oscuro). Posición con `Emulation.setGeolocationOverride`; red cortada con
`Network.emulateNetworkConditions`. A se registra por API y crea el catálogo con el lince (nivel 2 sugerido);
**B entra solo por la interfaz** (enlace del correo → «Crear cuenta» → unirse). Consola sin excepciones de JS.
Capturas en `docs/design/f6-*.png`. Usuarios `f6b-*@biodex.test` borrados al terminar.

| # | Punto | Resultado | Captura |
|---|---|---|---|
| 1 | A invita a B por email → correo en Mailpit → B abre el enlace sin sesión → entra → acepta | OK. Equipo › Invitar «Por email»: «Invitación enviada a …» y el enlace con Copiar y «Guárdalo ahora; no se volverá a mostrar». El correo llega a Mailpit con `…/#/unirse/<26 caracteres>`. B abre el enlace sin sesión: acceso con «Te han invitado a un catálogo…»; crea la cuenta y aparece el diálogo con catálogo, propietaria y rol (EDITOR) antes de aceptar. Al unirse, el catálogo queda activo y se descarga solo | `f6-1a-invitar-email.png`, `f6-1b-correo-mailpit.png`, `f6-1c-enlace-sin-sesion.png`, `f6-1d-unirse.png`, `f6-1e-unido.png` |
| 2 | B marca sin red una especie de nivel 2 → sincroniza → A la ve exacta | OK. Sin red, «Marcar aquí» en el lince (±9 m) → «1 pendiente»; al volver la red la cola se vacía. A, tras «Sincronizar ahora», ve en «Marcas del equipo» a Bruno Editor, fecha, ±9 m, EXACTA y 37.47123, −3.81235 | `f6-2a-marca-sin-red.png`, `f6-2b-propietaria-ve-exacta.png` |
| 3 | A hace custodio a B → B ve exactas las marcas de A | OK. B verifica su email (enlace de Mailpit; sin eso la opción «Custodio» sale desactivada y el servidor da 422). A marca el lince; B, aún editor, la tiene generalizada (37.25, −3.55). Al conceder custodio, diálogo «Verá las coordenadas exactas de todas las especies, también las amenazadas». B sincroniza: época nueva en `meta`, la marca de A **exacta en IndexedDB** (37.2894321, −3.5512345) y en la ficha; su catálogo activo pasa a custodio | `f6-3a-conceder-custodio.png`, `f6-3b-custodio-ve-exactas.png` |
| 4 | A quita custodio a B → en IndexedDB de B ya no queda ninguna coordenada exacta ajena | OK. Diálogo «Sus dispositivos borrarán las coordenadas exactas en la próxima sincronización». Tras sincronizar, **leyendo `biodex-<idB>.occurrences`**: 0 marcas ajenas exactas; la de A pasa a 37.25, −3.55, ±7115 m, `generalized: true` (su `server_seq` no cambió: sin la época se habría quedado exacta). La propia de B sigue exacta. Ficha: «Ubicación generalizada (~10 km)» | `f6-4a-quitar-custodio.png`, `f6-4b-editor-generalizadas.png` |
| 5 | A expulsa a B → el catálogo desaparece del dispositivo de B | OK. Diálogo propio de expulsión. B sincroniza: pull 403 `not_member` → fuera de IndexedDB (catálogo y marcas), su caché `biodex-img-<id>` y el catálogo activo | `f6-5a-expulsar.png`, `f6-5b-expulsado.png` |
| + | Niveles (owner): subir el lince de 2 a 1 | OK. Aviso «Los dispositivos del equipo sin confianza borrarán las coordenadas exactas de esta especie»; la tabla muestra sugerido, actual, motivo y autor del cambio. B (editor) sincroniza y la marca de A queda sin coordenadas | `f6-6a-niveles-subir.png`, `f6-6b-niveles.png` |
| + | Registro (owner) | OK. Fecha · persona · acción (versalitas) · detalle legible: invitación, alta, rol concedido, vistas exactas, rol retirado, nivel, expulsión | `f6-7-registro.png` |
| + | Ajustes › Perfil: ORCID | OK. `0000-0002-1825-0098` → aviso en el cliente sin llamar al servidor; `0000-0002-1825-0097` → «Perfil guardado» | `f6-8-perfil-orcid.png` |
| + | Equipo sin red | OK. «Datos de las 12:31 · Necesita conexión para cambiar el equipo», «Salir del catálogo» desactivado; ORCID de B enlazado a orcid.org | `f6-9-equipo-sin-red.png` |
| + | «Tengo un código» | OK. B (expulsado) pega un código de enlace en minúsculas y con espacios → vista previa como LECTOR → vuelve a entrar. Código inexistente → «Ese código no existe…» | `f6-10-tengo-un-codigo.png` |

## Decisiones
- La época sube también al **ganar** custodio y al **bajar** un nivel: si no, quien gana acceso seguiría con las
  generalizadas ya descargadas (el punto 3 lo necesita). Editor ↔ lector no la toca.
- Aceptar: «ya miembro» (200) va antes que el email (403), y este antes que caducada/revocada/agotada (410):
  así otra cuenta que prueba un código por email ya usado recibe 403, como pide la prueba.
- Solo cuentan para el límite de 10/hora los códigos que **no existen** (adivinar); también cuentan en la vista
  previa, que si no sería un oráculo.
- La contraseña errónea al transferir da 403 (un 401 haría que la app pidiese entrar de nuevo) y suma al límite
  del login.
- Niveles y Registro se abren a pantalla completa (sus tablas no caben en el panel lateral de 380 px); Equipo es
  una pestaña del detalle del catálogo.
- Invitar pide email o enlace; custodio no se invita: se concede a un miembro con el email verificado.

## Pendiente de mirar a mano
- [ ] `test-f2a.php` borra todos los usuarios `@biodex.test` y choca con el catálogo «Sierra Nevada F5b …» de
      `f5b-a-munh4bi7@biodex.test` que dejó el recorrido de F5b (FK `fk_catalogs_owner`). Borrar esos datos de
      prueba (o hacer que su limpieza borre antes los catálogos de esos usuarios) y volver a lanzarlo.
- [ ] Correo real (no Mailpit): `Lib/mail.php` no hace AUTH ni STARTTLS; el enlace usa `APP_URL`.
- [ ] Copiar el enlace en un móvil real (`navigator.clipboard` exige HTTPS o localhost; si falla, se selecciona
      el texto y el botón dice «Selecciónalo y cópialo»).
- [ ] La lista de miembros no se refresca sola: al cambiar algo en otro dispositivo, cambiar de pestaña la recarga.
