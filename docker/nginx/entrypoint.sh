#!/bin/sh
# Activa el servidor HTTPS solo si hay certificados: sin ellos nginx no arrancaría, y el HTTP de
# siempre (8090) tiene que funcionar para quien no use mkcert.
if [ -f /etc/nginx/certs/biodex.pem ] && [ -f /etc/nginx/certs/biodex-key.pem ]; then
    cp /etc/nginx/biodex/https.conf /etc/nginx/conf.d/https.conf
    echo "BioDex: HTTPS activo en el puerto 443 (8443 en el anfitrión)"
else
    rm -f /etc/nginx/conf.d/https.conf
fi
exec /docker-entrypoint.sh "$@"
