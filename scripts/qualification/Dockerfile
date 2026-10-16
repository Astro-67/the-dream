FROM php:8.3-fpm-bookworm@sha256:f768bd9142444f6126a690697b028c23f30878c3b628a7c355dfce75e29cc045

RUN docker-php-ext-install -j2 pdo_mysql
ARG HOST_UID
ARG HOST_GID
RUN groupmod -o -g "$HOST_GID" www-data && usermod -o -u "$HOST_UID" -g "$HOST_GID" www-data
COPY php.ini /usr/local/etc/php/conf.d/qualification.ini
COPY fpm.conf /usr/local/etc/php-fpm.d/zz-qualification.conf
WORKDIR /app
