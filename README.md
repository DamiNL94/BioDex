# BioDex

PWA de campo para biólogos: catálogos de especies (EIDOS), observaciones offline geolocalizadas, evaluación UICN y exportación Darwin Core.

## Requisitos
- Docker y Docker Compose v2

## Arrancar
```bash
cp .env.example .env   # y ajusta DB_PASS
docker compose up -d
```

- App: http://localhost:8090
- Comprobación: http://localhost:8090/api/health → `{"ok":true,"db":true}`
- Adminer: http://localhost:8081 (servidor `mysql`, usuario/contraseña de `.env`)
