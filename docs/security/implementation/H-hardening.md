## H1–H3 – Hardening, and S2 / S3 static-analysis fixes

Tested on Apache 2.4 (XAMPP build) at `http://localhost:8090`, because `php -S` ignores `.htaccess`.

---

### H1 – Secure HTTP headers

**Threat:** T16 (weak or missing headers). Findings: ZAP-02, ZAP-03, ZAP-04, CFG-01, CFG-03, CFG-05.

**Design**
- Every `Header set` in `.htaccess` is now `Header always set`, so error responses (403/404) carry the security headers too (before, only 2xx responses did).
- Removed the deprecated `X-XSS-Protection`. Added `frame-ancestors 'self'` to the CSP (it has no fallback to `default-src`).
- HSTS is sent **only over HTTPS**: `Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" "expr=%{HTTPS} == 'on'"`.
- `Header always unset X-Powered-By` (and the non-`always` form) hides the PHP version. The `Server:` banner is an Apache setting (`ServerTokens Prod`), a hosting task, not in `.htaccess`.
- Chart.js on admin pages: now `chart.umd.js` (same 4.4.1 build, not the CDN-generated `.min`) with `integrity="sha256-…"` and `crossorigin="anonymous"`. The hash is the one jsDelivr publishes for that file.
- Not changed (residual risk, as agreed): CSP `'unsafe-inline'` and `https:` image sources; no SRI for the Google Fonts stylesheet (it differs per browser); no COEP (it would break the Paymob iframe and YouTube embeds).

**Files:** `.htaccess:44-60`, `admin/includes/header.php:30`.

**How to verify** (`evidence/H1-headers-after.txt`, `evidence/H1-chart-sri.txt`)
1. `curl -I` on `/`, on a 404 and on `/includes/` (403): all carry the security headers; none has `X-XSS-Protection` or `X-Powered-By`.
2. HSTS: present on `https://localhost:8443/` (local HTTPS test listener), absent on `http://localhost:8090/` (0 matching headers).
3. Admin dashboard loads Chart.js with the SRI attribute: `window.Chart` is a function, the chart canvas is present, no integrity error in the console.

**Evidence:** `screenshots/before-H1-headers.png` (before: `X-Powered-By`, `X-XSS-Protection`, no headers on the 404), `screenshots/H1-headers-after.png`, `screenshots/H1-admin-chart-loads.png`.

---

### H2 – Session hardening

**Threat:** T19 (session fixation). Finding: CFG-02 (`session.use_strict_mode = 0`).

**Design:** `lume_session_start()` sets `session.use_strict_mode=1` and `session.use_only_cookies=1` before `session_start()`. Sessions started from `/admin/` (the admin login) get `SameSite=Strict`; the storefront stays `Lax`, because `Strict` would drop a customer's session when they return from Google sign-in or the Paymob payment page.

**Files:** `includes/functions.php:21-22` (ini settings), `:37` (SameSite by area).

**How to verify** (`evidence/H2-session-before.txt`, `H2-session-after.txt`)
1. Send `Cookie: lumeegy_session=<made-up id>`: before, no `Set-Cookie` (the server kept the attacker's ID); after, a new ID is issued.
2. Admin login response: `Set-Cookie … SameSite=Strict`; storefront session cookie: `SameSite=Lax`.
3. A session ID passed in the URL is ignored.

---

### H3 – Secure uploads

**Threat:** T20 (malicious upload). All uploads are admin-only; customers have no upload form.

**Design:** one shared check, `upload_check_image_path()` / `upload_check_image()`, used by every image handler. It enforces: size limit (8 MB per image, `UPLOAD_MAX_IMAGE_BYTES`), real type from the file's bytes (`finfo`) against a whitelist, file name rules (no `/`, `\`, `..`, NUL; no script extensions such as `.php`, `.phtml`, `.phar` anywhere in the name, so `shell.php.jpg` fails), no `<?php` or `<script` inside raster images, `getimagesize()` must succeed, and SVG only if it parses without a DOCTYPE/entities and has no script elements, event attributes or external links. The stored name is `prefix_<32 hex>.<ext>` and the extension comes from the detected type, never from the client name. Each rejection is logged as `upload_rejected` (C1) with the reason and the client's file name. (`assets/.htaccess` still blocks script execution and sends a no-script CSP for SVG, as a second layer.)

Used by: `media_upload()` (library and product images, `api/media.php`, `api/admin-products.php`), `admin/variants.php`, `admin/settings.php` (logo, favicon, OG image), `includes/media-video.php` (chunked image uploads and video posters). Videos keep their own type whitelist and the 1 GB chunked limit. `admin/export.php` accepts a CSV that is only parsed, never stored, so it is out of scope.

**Files:** `includes/functions.php:1291` (constant), `:1294` `upload_random_name()`, `:1305` `upload_svg_is_safe()`, `:1336` `upload_check_image_path()`, `:1370` `upload_check_image()`, `:1384` `media_upload()`; `admin/variants.php:74`, `admin/settings.php:72`, `includes/media-video.php:146` and `:189`.

**How to verify** (`evidence/H3-upload-before.txt`, `H3-upload-after.txt`; real multipart uploads through an admin session)

| Upload | Before | After |
|---|---|---|
| valid PNG, valid JPEG, clean SVG, valid PNG with a normal name (chunked) | accepted | accepted |
| `shell.php` with PHP code | rejected (type) | rejected, logged |
| `shell.php.jpg` with PHP code | rejected (type) | rejected, logged |
| real JPEG with PHP appended (`innocent.jpg`) | **accepted** | rejected, logged |
| valid PNG of 9 MB | **accepted** | rejected, logged |
| `../../evil.png` as the chunked `filename` field | **accepted** (name cleaned) | rejected, logged |
| SVG with `<script>` and `onload` | **accepted** | rejected, logged |
| variants page: `shell.php`, PHP-in-JPEG | silently ignored | "Image not saved: …", logged |

After the run: no stored file has a script extension or PHP code, and every stored name is random hex. A multipart client or PHP strips directory parts from the `name` of a normal upload, so the `../` case can only arrive through the chunked `filename` field, which is what the test uses.

---

### S2 – Order status through `set_order_status()`

`admin/order-new.php:86` and `checkout.php:260` used a bare `UPDATE orders SET status`. They now call `set_order_status()` (row lock, stock rules, `updated_at`). Verified (`evidence/smoke-hardening.txt`): a manual order created with status `paid` ends as `paid` and takes 1 from the product's stock; an InstaPay checkout (InstaPay temporarily configured in the test DB) ends as `pending_payment`.

### S3 – Placeholder instead of `db()->quote()`

`admin/abandoned.php:46` builds the status filter with a `?` placeholder. All five valid filters and an injection attempt (`show=x' OR '1'='1`) return HTTP 200 (the attempt falls back to `open`).

---

### Smoke test (`evidence/smoke-hardening.txt`)

Home, shop, product, cart and account load (200). Add to cart, COD checkout creates order `pending` and takes 1 from stock (10 → 9). Admin sees the order; setting it to Cancelled returns the stock (9 → 10). The S2 and S3 checks above pass.

### Semgrep rerun

For the files changed on this branch: the S2 results (`lumeegy-direct-order-status-update` now only in `includes/functions.php`, i.e. inside `set_order_status()` / `cancel_order()` themselves) and the S3 results (`abandoned.php`) are gone. Two new `lumeegy-upload-without-mime-check` results appear in `admin/variants.php` and `admin/settings.php`: they are false positives of that pattern rule (it looks for `finfo` in the same file); the MIME check now lives in the shared `upload_check_image()` they call.
