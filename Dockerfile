ARG PHP_VERSION=8.5
ARG FRANKENPHP_VERSION=1.12
ARG DEBIAN_VERSION=trixie

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-${DEBIAN_VERSION} AS app

LABEL org.opencontainers.image.source=https://github.com/cvvfcm/benevolio
LABEL org.opencontainers.image.licenses=AGPL-3.0-or-later
LABEL org.opencontainers.image.authors="Yohan Giarelli <yohan@giarel.li>"
LABEL org.opencontainers.image.description="An application for managing volunteer work and donations for non-profit organizations."

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

ARG EXTERNAL_USER_ID

# qpdf is not optional: ReceiptGenerator stamps the rendered overlay onto the official
# CERFA with `qpdf --overlay`, which Gotenberg cannot do — its merge route concatenates
# pages rather than superimposing them.
RUN set -eux; \
    apt-get update; \
    apt-get install --no-install-recommends -y qpdf; \
    rm -rf /var/lib/apt/lists/*; \
    sync

RUN set -eux; \
    install-php-extensions \
          @composer \
          apcu \
          intl \
          mbstring \
          opcache \
          pcntl \
          pdo_pgsql \
          zip \
      ; \
    sync


COPY --chown=www-data:www-data .infra/docker/php/Caddyfile /etc/caddy/Caddyfile
COPY --chown=www-data:www-data .infra/docker/php/docker-entrypoint /usr/local/bin/docker-entrypoint

RUN chmod a+x /usr/local/bin/docker-entrypoint

RUN set -eux; \
    sed -i -r s/"(www-data:x:)([[:digit:]]+):([[:digit:]]+):"/\\1${EXTERNAL_USER_ID}:${EXTERNAL_USER_ID}:/g /etc/passwd; \
    sed -i -r s/"(www-data:x:)([[:digit:]]+):"/\\1${EXTERNAL_USER_ID}:/g /etc/group; \
    mkdir -p /var/run/php /app/var /var/www /data /config; \
    chown -R www-data:www-data /usr/local/etc/php /var/run/php /var/www /app /app/var /data /config

USER www-data

ENV APP_ENV=prod
ENV APP_DEBUG=false

WORKDIR /app

COPY --chown=www-data:www-data composer.json composer.lock symfony.lock ./
RUN set -eux; \
    composer install --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress; \
    composer clear-cache; \
    mkdir -p var assets/vendor


COPY --chown=www-data:www-data . ./


RUN set -eux; \
    mkdir -p var/cache var/log; \
    composer install --prefer-dist --no-dev --no-progress; \
    composer dump-autoload --optimize --no-dev --classmap-authoritative; \
    php bin/console importmap:install; \
    php bin/console asset-map:compile; \
    php bin/console cache:clear; \
    php bin/console cache:warmup -eprod; \
    chmod +x bin/console; \
    sync


EXPOSE 80
EXPOSE 443
EXPOSE 443/udp

HEALTHCHECK --start-period=60s CMD curl -f http://localhost:2019/metrics || exit 1

ENTRYPOINT ["docker-entrypoint"]

CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]
