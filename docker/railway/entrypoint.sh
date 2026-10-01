#!/bin/sh
# Arranque en Railway (F11): migraciones → php-fpm → nginx en $PORT → (opcional) datos de demo.
set -e

PORT="${PORT:-8080}"
sed -i "s/listen 80;/listen ${PORT};/" /etc/nginx/conf.d/default.conf

# MySQL de Railway puede tardar en aceptar conexiones tras un despliegue: hasta ~60 s de reintentos.
i=0
until php /var/www/backend/bin/migrate.php; do
    i=$((i + 1))
    if [ "$i" -ge 30 ]; then echo "BioDex: no hay conexión con MySQL; revisa las variables DB_*" >&2; exit 1; fi
    echo "BioDex: esperando a MySQL ($i/30)..."
    sleep 2
done

php-fpm -D

# Demo (SEED_DEMO=1): seed-demo.php es idempotente y va contra la API, así que espera a nginx.
# Solo ese proceso corre con APP_ENV=dev; el servicio sigue con el APP_ENV de Railway.
if [ "${SEED_DEMO:-0}" = "1" ]; then
    (
        n=0
        until curl -fsS "http://127.0.0.1:${PORT}/api/health" >/dev/null 2>&1 || [ "$n" -ge 30 ]; do n=$((n + 1)); sleep 1; done
        cd /var/www/backend && APP_ENV=dev TEST_BASE_URL="http://127.0.0.1:${PORT}" php bin/seed-demo.php \
            || echo "BioDex: el seed de demo falló (la app sigue funcionando)" >&2
    ) &
fi

echo "BioDex: escuchando en el puerto ${PORT}"
exec nginx -g "daemon off;"
