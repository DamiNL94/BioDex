# API

Solo JSON bajo `/api`. Errores: `{"error": "mensaje"}` con el código HTTP correspondiente.
Sesión por cookie (`biodex_session` + `biodex_remember`, `HttpOnly`, `SameSite=Lax`).

## Salud
- `GET /api/health` → `{ok, db}`

## Autenticación (`Api/auth.php`)
| Método y ruta | Cuerpo | Respuesta |
|---|---|---|
| `POST /api/auth/register` | `{email, password (≥ 10), name?}` | 201 `{user, verificationSent}` · 400 · 409 email ya registrado. Deja la sesión abierta y envía el correo de verificación |
| `POST /api/auth/login` | `{email, password}` | 200 `{user}` · 401 · 429 tras 5 fallos por email o IP en 15 min (`Retry-After`) |
| `POST /api/auth/logout` | — | 200 `{ok}`. Cierra solo este dispositivo |
| `GET /api/auth/me` | — | 200 `{user}` · 401 |
| `GET /api/auth/verify?token=` | — | 200 `{ok, user}` · 400 enlace no válido, usado o caducado (48 h) |

`user` = `{id, email, name, orcid, emailVerified}`.
