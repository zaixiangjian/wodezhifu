FROM php:8.3-fpm-alpine

RUN set -eux; \
    apk add --no-cache \
      freetype-dev libjpeg-turbo-dev libpng-dev libzip-dev oniguruma-dev \
      unzip git ca-certificates curl; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli gd zip mbstring bcmath opcache

# Defense in depth for PHP-FPM; .phar libraries remain usable via PHP includes.
RUN printf '%s\n' \
      'expose_php = Off' \
      'display_errors = Off' \
      'display_startup_errors = Off' \
      'log_errors = On' \
      'cgi.fix_pathinfo = 0' \
      > /usr/local/etc/php/conf.d/zz-epay-security.ini; \
    printf '%s\n' '[www]' 'security.limit_extensions = .php' \
      > /usr/local/etc/php-fpm.d/zz-epay-security.conf

WORKDIR /var/www/html
COPY --chown=82:82 . /var/www/html/
