# Semgrep Baseline Triage

Scan: Semgrep 1.185.0, rulesets `p/php`, `p/owasp-top-ten`, `p/xss` + custom [semgrep-lumeegy.yml](semgrep-lumeegy.yml) (12 rules). 41 rules, 89 files, **201 raw results**, 1 parse error. Raw output: [evidence/semgrep/semgrep-before.txt](evidence/semgrep/semgrep-before.txt) (also `.sarif`, `.json`).

Verdicts: **TP** = true positive (becomes a finding) · **FP** = false positive (reason given) · **REV** = parse gap, reviewed manually.

## Summary

| Rule (group) | Results | Verdict | Reason |
|---|---|---|---|
| `echoed-request` (registry) | 114 | 85 FP, 29 FP | 85 lines already wrap output in `h()` / `json_encode` / `(int)` / `number_format` / `urlencode`, which the registry rule doesn't recognise. The other 29 print integers (`$o['id']`, `$productId` cast with `(int)`), the `SITE_URL` constant, or internal helpers that escape inside (`product_price()`, `money()`, `action_icon()`). |
| `lumeegy-xss-request-echoed` | 12 | FP | Same lines: integer IDs, `count()`, values already passed through `h()`. `admin/email-diagnostic.php:163` prints `$testResult['msg']`, which is built only from fixed strings in the code. |
| `tainted-sql-string` / `tainted-callable` / `lumeegy-sqli-request-to-query` | 37 | FP | Every case builds only the *structure* of the SQL from safe parts: `?` placeholder lists from `array_fill`, `ORDER BY` from a whitelist (`admin/orders.php:290-293`), `ASC/DESC` from a ternary, `LIMIT`/`OFFSET` from integers, `WHERE` fragments that are fixed strings with `?`. Values always go through `execute($params)`. |
| `lumeegy-sql-built-by-concatenation` | 8 | FP (1 note) | Placeholder lists and column names from the code's own arrays (`duplicate_product`). `config/database.php:29` concatenates `date('P')` (server-generated). Note: `admin/abandoned.php:46` uses `db()->quote()` instead of a placeholder. It's safe, but inconsistent with the project rule; we can change it to `?`. |
| `lumeegy-open-redirect` | 6 | FP | Every `header('Location: ' . SITE_URL . …)` starts with the site's own URL; the request parts are `(int)` IDs or `urlencode()`d. `checkout.php:251` redirects to the Paymob iframe URL the server built. |
| `lumeegy-post-handler-without-csrf` | 3 | FP | `api/paymob_callback.php` is a server-to-server webhook verified by HMAC; `api/pv.php` and `api/track.php` are anonymous analytics beacons that change no user data. These are documented exceptions. |
| `tainted-filename` | 2 | FP | `thumb.php` resolves `src` with `realpath()`, requires it under `assets/`, and requires an image extension (`thumb.php:27-39`). Path traversal is blocked. Confirm with a manual test (UPL / INP cases). |
| `lumeegy-exception-message-to-user` | 14 | **TP ×6 (Low)**, rest FP | Shown to the user in production: `admin/pages.php:35`, `admin/settings.php:96`, `admin/shipping.php:28`, `admin/themes.php:19` (via `die()`), `admin/themes.php:74`, `admin/variants.php:102`. Raw PDO messages reach the admin screen (table/column names). Admin-only, so Low. FP: `api/admin-products.php` maps errors to fixed text; `api/section-preview.php` escapes the message in an admin-only preview; `config/database.php:32` shows detail only when `ENV=development`. |
| `lumeegy-direct-order-status-update` | 5 | **TP ×1 (Low/Medium)**, 1 TP (Info), 3 FP | **TP:** `admin/order-new.php:86` sets the status with a bare `UPDATE` after `create_order()`. The allowed statuses don't include cancelled/refunded, so stock isn't corrupted today, but it bypasses `set_order_status()` and its audit trail. **Info:** `checkout.php:260` (pending → pending_payment, no stock effect). FP: the 3 hits in `includes/functions.php` are inside `set_order_status()` / `cancel_order()` themselves. |
| Parse error | 1 | REV | `includes/media-video.php:52` uses a PHP 8 `match` expression Semgrep can't parse. Covered by the manual upload review. |

## True positives carried into the Vulnerability Assessment Report

| ID | Finding | Location | Severity (provisional) | Planned fix |
|---|---|---|---|---|
| SAST-01 | Raw database exception text shown to admins | `admin/pages.php:35`, `admin/settings.php:96`, `admin/shipping.php:28`, `admin/themes.php:19,74`, `admin/variants.php:102` | Low | Log the detail, show a generic message with a reference ID (Part 1 control C3) |
| SAST-02 | Order status changed outside `set_order_status()` | `admin/order-new.php:86`, `checkout.php:260` | Low | Route both through `set_order_status()` |
| SAST-03 | `db()->quote()` used instead of a placeholder | `admin/abandoned.php:46` | Info | Use `?` with `execute()` |

## Configuration findings (not from Semgrep, found during setup)

| ID | Finding | Where | Severity (provisional) |
|---|---|---|---|
| CFG-01 | `Server` and `X-Powered-By` headers reveal exact Apache/PHP versions (`expose_php = On`) | Local Apache/PHP config | Low |
| CFG-02 | `session.use_strict_mode = 0`: uninitialised session IDs are accepted (fixation aid) | Local `php.ini`; can also be set in code | Medium |
| CFG-03 | HSTS header commented out | `.htaccess:52` | Low (Medium on production HTTPS) |
| CFG-04 | CSP allows `'unsafe-inline'` scripts | `.htaccess:54` | Medium |
| CFG-05 | Deprecated `X-XSS-Protection` header still sent | `.htaccess:48` | Info |

CVSS 3.1 scores are assigned in the report once each finding is confirmed by a dynamic or manual test.
