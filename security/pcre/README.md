# PHP embedded PCRE CVE-2026-103111 candidate

Scope: candidate only; no publication/deployment; no production secrets or exploit inputs.

## Official evidence and patch

Read GHSA-r9hj-j2rw-4q3m and PCRE2 SUPPORT-LIFECYCLE (snapshots adjacent).
10.42 is affected; the 10.42 lifecycle section recommends the 10.49 large-stack
allocation backport. APK PCRE 10.49 does not repair PHP's embedded PCRE.

Canonical patch:
https://github.com/PCRE2Project/pcre2/blob/pcre2-10.49/patches/pcre2-10.49-Fix-large-stack-allocation.patch
Upstream commit: 2b4038298072684b0fae29b15bedfb1a75bda46d.
Full official patch SHA256: 380f853c9c5ff3828dc7fa9b440f388a6bdd3e4c143fedd3960d51b325b8fe26.

`php-8.3.36-pcre2-large-stack.patch` contains only its C compilation-unit hunks.
Only adaptation: unchanged context LOCAL1 -> LOCALS1 in the fourth hunk,
as required by PHP's bundled 10.42 and verified against official release branch
c49acf8b041e5a8f4ad935661f0b04f563a4ab74. No added/removed code semantics
were changed. Testdata hunks are omitted because PHP has no upstream testdata
layout. The first build failed on this context mismatch, preserved in evidence.
Patch SHA256: 42e2458b6fe09dbe5f309339022e9c56b764973af3460d7892bc74eb2288a370.

## Reproduction

From repository root (not html/):

```
docker build --pull=false --target patched-runtime --build-arg PHP_BUILD_JOBS=2 \
  -t epay-pcre-candidate:20261022-ghsa-r9hj .
docker run --rm --network none --read-only --tmpfs /tmp \
  -v "$PWD/security/pcre/regression.php:/regression.php:ro" \
  epay-pcre-candidate:20261022-ghsa-r9hj php /regression.php
docker run --rm --network none epay-pcre-candidate:20261022-ghsa-r9hj php-fpm -tt
```

Official PHP base pinned by manifest digest and 8.3.36 version; tarball,
unpatched compilation unit and patch all fail-closed SHA256 verified.
Reuse exact php-config configure options and official hardening flags;
recompile only CLI and FPM targets. Copy those binaries into the same pinned
clean base, then build existing shared extensions and security INI/FPM config
unchanged. Builder dependencies never enter the final runtime. No downgrade,
no JIT disabling. PCRE_VERSION remains 10.42: the backport is proven by source
hash + build transcript + binary hashes, not a fabricated version string.

The retained original `/usr/src/php.tar.xz` is unpatched input; it is NOT the
patched source evidence. Actual compiled patched `pcre2_jit_compile.c` and
hashes live at `/usr/local/share/epay-pcre-fix/` in the candidate.

## Required packaging / parent integration

Root Dockerfile context is the repository. For the parent's generated context
with application sources under html/, put Dockerfile at context root, copy
`security/pcre/rebuild.sh` and `security/pcre/php-8.3.36-pcre2-large-stack.patch`
under context-root `security/pcre/` (the whole directory is acceptable), preserve
all runtime build stages above `FROM patched-runtime AS application`, and change
only the application COPY to `COPY --chown=82:82 html/ /var/www/html/`.
Do not put build patches only inside html/; COPY security/pcre/ would fail.
Do not change 103Epay.sh in this task. Exclude build evidence/security files
from the serving webroot in the generated deployment context. No existing
epay-php tag may be overwritten by this candidate build.

This is a targeted fix for CVE-2026-103111, not a claim all lifecycle backports
or all PCRE vulnerabilities have been removed. Other recommended backports
remain separately assessable. Normal regex tests are not exploit/ASAN proof.
APK build dependencies resolve from Alpine repositories, so this is a pinned
source/patch workflow, not a promise of bit-for-bit reproducibility across
future compiler/package revisions.
