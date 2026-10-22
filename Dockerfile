# PHP 8.3.36 is pinned: do not regress PHP security fixes by changing tags.
FROM php:8.3.36-fpm-alpine@sha256:69f823ed1766f7b39249d6b9cbcac17f64fa356f3e4393541bab4a9594008fba AS pcre-builder
ARG PHP_BUILD_JOBS=2
COPY security/pcre/ /patches/
RUN sh /patches/rebuild.sh

FROM php:8.3.36-fpm-alpine@sha256:69f823ed1766f7b39249d6b9cbcac17f64fa356f3e4393541bab4a9594008fba AS patched-runtime
COPY --from=pcre-builder /out/php /usr/local/bin/php
COPY --from=pcre-builder /out/php-fpm /usr/local/sbin/php-fpm
# Retain the exact patched compilation unit + provenance, not an unpatched
# tarball alone, as audit evidence. PCRE_VERSION intentionally stays 10.42.
COPY --from=pcre-builder /out/evidence/ /usr/local/share/epay-pcre-fix/
COPY security/pcre/php-8.3.36-pcre2-large-stack.patch /usr/local/share/epay-pcre-fix/

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

FROM patched-runtime AS application
WORKDIR /var/www/html
COPY --chown=82:82 . /var/www/html/
