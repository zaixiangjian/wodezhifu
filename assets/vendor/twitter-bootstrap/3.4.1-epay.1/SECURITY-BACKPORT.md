# Bootstrap 3.4.1-epay.1 (local security backport, not an upstream release)

Base: https://raw.githubusercontent.com/twbs/bootstrap/v3.4.1/dist/js/bootstrap.js

Bootstrap 3 has no upstream patched release for GHSA-vxmc-5x29-h64v / CVE-2024-6485 or GHSA-q58r-hwc8-rm9j / CVE-2025-1647. Do not replace this file with upstream 3.4.1 or request this local version from a public CDN. Consumers deliberately load this same-origin directory. CSS/fonts remain upstream 3.4.1; no Bootstrap 5 migration is made. Component VERSION remains its original upstream base 3.4.1.

Reviewed distribution patches:
- https://sources.debian.org/data/main/t/twitter-bootstrap3/3.4.1%2Bdfsg-7/debian/patches/0003-CVE-2024-6485.patch
- https://sources.debian.org/data/main/t/twitter-bootstrap3/3.4.1%2Bdfsg-7/debian/patches/CVE-2025-1647.patch

Changes:
1. Button setState sanitizes data-derived state strings before the html sink, following Debian. String conversion additionally accepts numeric data values without a TypeError. HTML supplied via data-loading-text/data-reset-text is now literal text. Existing applications requiring formatted state labels must not bypass this fix; explicitly review a safe separate rendering contract. The application's searched source has no loading/reset callers or data-loading-text use.
2. Tooltip/popover sanitizer uses DOMParser first; its legacy fallback requires a real DOMImplementation, otherwise throws instead of passing unsafe HTML through. Implements the Debian patch with ES5 var declarations and a body alias, avoiding its let/const syntax change. Existing whitelist/sanitizeFn options retain upstream behavior; applications explicitly setting sanitize:false or supplying unsafe custom sanitizeFn remain responsible for those choices.

Readable source is the deployed artifact to keep the review diff exact; no generated minified twin is shipped. Tests are kept outside the public webroot in the frontend evidence directory. They compare original vulnerable library bytes and this artifact in headless Chromium (including clobbered document.implementation), plus ordinary button/reset, modal, collapse, dropdown, tab, popover and the application's table/date/layer/tree plugins. This is library/UI fixture acceptance, not payment/provider or whole-application acceptance.
