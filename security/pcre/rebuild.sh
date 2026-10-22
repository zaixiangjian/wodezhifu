#!/bin/sh
# Rebuild only CLI/FPM from the verified same-version official PHP source.
set -eux
test "$PHP_VERSION" = 8.3.36
test "$(php -r 'echo PHP_VERSION;')" = 8.3.36
printf '%s  %s\n' dd54693aa334c5c4d72e9aee96b082c2473750a18a6c1d105789c93a3b1692fd /usr/src/php.tar.xz | sha256sum -c -
printf '%s  %s\n' 42e2458b6fe09dbe5f309339022e9c56b764973af3460d7892bc74eb2288a370 /patches/php-8.3.36-pcre2-large-stack.patch | sha256sum -c -
apk add --no-cache --virtual .pcre-build $PHPIZE_DEPS argon2-dev coreutils curl-dev gnu-libiconv-dev libsodium-dev libxml2-dev linux-headers oniguruma-dev openssl-dev readline-dev sqlite-dev patch
# Match docker-library/php's iconv/header and hardening build configuration.
rm -f /usr/include/iconv.h
export CFLAGS="$PHP_CFLAGS" CPPFLAGS="$PHP_CPPFLAGS" LDFLAGS="$PHP_LDFLAGS"
export PHP_BUILD_PROVIDER='https://github.com/docker-library/php' PHP_UNAME='Linux - Docker'
options="$(php-config --configure-options)"
docker-php-source extract
cd /usr/src/php
printf '%s  %s\n' 8a50960e5758bede47d11151b7bb9a6213592283a809cd181955bf2454af2621 ext/pcre/pcre2lib/pcre2_jit_compile.c | sha256sum -c -
patch --batch --fuzz=0 -p2 -d ext/pcre/pcre2lib < /patches/php-8.3.36-pcre2-large-stack.patch
mkdir -p /out/evidence
cp ext/pcre/pcre2lib/pcre2_jit_compile.c /out/evidence/
sha256sum ext/pcre/pcre2lib/pcre2_jit_compile.c > /out/evidence/patched-source.sha256
# Options come only from the pinned, trusted official base image, not user input.
eval "./configure $options"
make -j"${PHP_BUILD_JOBS:-2}" sapi/cli/php sapi/fpm/php-fpm
strip --strip-all sapi/cli/php sapi/fpm/php-fpm
cp sapi/cli/php /out/php
cp sapi/fpm/php-fpm /out/php-fpm
/out/php -n -r 'if (PHP_VERSION !== "8.3.36" || !PCRE_JIT_SUPPORT) exit(1); echo PHP_VERSION," ",PCRE_VERSION," JIT=",PCRE_JIT_SUPPORT,"\n";'
sha256sum /out/php /out/php-fpm > /out/evidence/binaries.sha256
