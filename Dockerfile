# ================================================================================================
# Imagen de despliegue para Railway (F11): nginx + php-fpm en UN contenedor, con el código dentro.
# ------------------------------------------------------------------------------------------------
# El desarrollo local sigue en docker-compose.yml (no usa este fichero). Aquí se reutiliza la misma
# configuración de nginx (docker/nginx/*.conf) con tres cambios que hace sed al construir:
#   · fastcgi_pass php:9000      → 127.0.0.1:9000 (php-fpm en el mismo contenedor)
#   · resolver 127.0.0.11 (DNS de Docker) → resolvers públicos (en Railway no existe el de Docker)
#   · listen 80                  → el puerto que da Railway en $PORT (lo pone entrypoint.sh)
# La BD es el servicio MySQL de Railway (variables DB_* en docs/railway.md). El correo no está
# configurado: las cuentas de la demo ya vienen verificadas.
# ================================================================================================
FROM php:8.3-fpm

# pdo_mysql: BD · zip: exportación DwC-A · intl: nombres científicos · nginx: frontend y proxies
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libicu-dev nginx \
    && docker-php-ext-install pdo_mysql zip intl \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default \
    && mkdir -p /var/cache/nginx/tiles /var/cache/nginx/fototeca /var/cache/nginx/inat /etc/nginx/biodex \
    && chown -R www-data:www-data /var/cache/nginx \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log

# Configuración de producción de PHP (sin display_errors) y php-fpm solo en local
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -i 's/^listen = .*/listen = 127.0.0.1:9000/' /usr/local/etc/php-fpm.d/zz-docker.conf

COPY docker/nginx/default.conf           /etc/nginx/conf.d/default.conf
COPY docker/nginx/biodex-app.conf        /etc/nginx/biodex/biodex-app.conf
COPY docker/nginx/security-headers.conf  /etc/nginx/biodex/security-headers.conf
RUN sed -i 's/fastcgi_pass php:9000;/fastcgi_pass 127.0.0.1:9000;/' /etc/nginx/biodex/biodex-app.conf \
    && sed -i 's/resolver 127\.0\.0\.11 /resolver 1.1.1.1 8.8.8.8 /' /etc/nginx/biodex/biodex-app.conf \
    && grep -q "127.0.0.1:9000" /etc/nginx/biodex/biodex-app.conf \
    && ! grep -q "127.0.0.11" /etc/nginx/biodex/biodex-app.conf

# Misma disposición que en docker-compose: las rutas relativas del backend (../../shared, ../../VERSION) siguen valiendo
COPY backend  /var/www/backend
COPY frontend /var/www/frontend
COPY shared   /var/www/shared
COPY VERSION  /var/www/VERSION
COPY docker/railway/entrypoint.sh /usr/local/bin/biodex-entrypoint
# Lectura para www-data aunque el código llegue con permisos 700 (p. ej. `railway up` desde Windows)
RUN chmod -R a+rX /var/www && chmod +x /usr/local/bin/biodex-entrypoint && nginx -t

WORKDIR /var/www/backend
ENV PORT=8080
EXPOSE 8080
CMD ["biodex-entrypoint"]
