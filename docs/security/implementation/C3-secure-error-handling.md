## C3 – Secure Error Handling (with S1)

**Threat:** T13 (error pages leak SQL, paths, stack traces). Findings: SAST-01, ZAP error-page header gap (also H1).

**Design**
- `includes/error-handler.php` is the first file `config/config.php` loads, so anything that fails later (reading `.env`, the database connection, any page) is covered.
- Every failure gets a short reference ID (`bin2hex(random_bytes(4))`). The full detail (exception class, message, file:line, trace) goes to `error_log()` as `[ref <id>] ...`. The visitor sees only "Something went wrong" and the ID. A JSON request (`/api/` path, `Accept: application/json` or `X-Requested-With`) gets `{"success":false,"message":"Something went wrong","ref":"…"}` with status 500.
- `set_exception_handler()` covers uncaught exceptions, `register_shutdown_function()` covers fatal errors (undefined function, parse errors), `set_error_handler()` logs warnings and notices with their location and prints nothing in production. `config/config.php` no longer sets `error_reporting(0)` (that hid warnings from the handler); production now uses `E_ALL & ~E_DEPRECATED` with `display_errors=0`.
- With `ENV=development` the detail is also shown on screen, for developers.
- The error page is standalone HTML with inline CSS: it still works when the database is what failed. `404.php`, `403.php`, `500.php` use the same template and are wired with `ErrorDocument` in `.htaccess` (Apache), so blocked folders (`/includes/`, `/config/…`) and unknown URLs show the branded page without a server banner.
- **S1:** the six places that put `$e->getMessage()` on an admin screen now call `lume_error_message($e, 'what failed')`, which logs the detail with a fresh reference ID and returns "what failed. Reference: <id>." (`die()` in `themes.php:19` too).
- `config/database.php`: a connection failure now calls `lume_fail()` (generic page + ID in production, detail in dev) instead of `die()` with the PDO text.

**Files and functions**
- `includes/error-handler.php` (new): `lume_error_page()` :37, `lume_fail()` :61, `lume_error_message()` :88, exception handler :95, error handler :100, shutdown handler :107.
- `config/config.php:11` (loaded first), `:115` production error reporting; `config/database.php:33`.
- `404.php`, `403.php`, `500.php` (new); `.htaccess:39-41` (`ErrorDocument`).
- S1: `admin/pages.php:35`, `admin/settings.php:96`, `admin/shipping.php:28`, `admin/themes.php:19` and `:74`, `admin/variants.php:102`.

**How to verify** (`evidence/C3-verification-*.txt`)
1. `ENV=production` and a wrong `DB_PASS` → HTTP 500 generic page with a reference ID; the server log line with that ID holds the real PDO error.
2. `/api/cart.php` in the same state → status 500, JSON `{"success":false,"message":"Something went wrong","ref":"…"}`.
3. A throwing script, an undefined-variable warning and a fatal error (temporary test files, removed afterwards) → exception and fatal give the generic page, the warning is only logged and the page continues; none of the responses contains the secret text, a path or a stack trace.
4. `ENV=development` → the detail is shown on screen.
5. Apache: `/does-not-exist.php` → custom 404, `/includes/` and `/config/config.php` → custom 403, no `Apache/`/`PHP/` banner in the body.
6. Admin: add a shipping zone named like an existing one → message with a reference ID, no SQL text; the log has the PDO error for that ID.
7. Semgrep rerun: the six S1 findings and the `config/database.php:32` finding are gone.

**Evidence**
- `screenshots/before-C3-raw-error.png` (before: raw SQLSTATE text on the admin screen)
- `screenshots/C3-generic-500.png`, `screenshots/C3-admin-error-with-ref.png`, `screenshots/C3-custom-404.png`, `screenshots/C3-custom-403.png`
- `C3-verification-db.txt`, `C3-verification-throw.txt`, `C3-verification-dev.txt`, `C3-verification-s1.txt`, `C3-verification-errpages.txt`

**Result (measured):** all seven checks passed. Semgrep: `lumeegy-exception-message-to-user` went from 14 results to 7; the 6 true positives and the dev-only `database.php` result are gone.

### New findings (not fixed, to confirm first)
| # | File:line | Severity | Finding |
|---|---|---|---|
| N1 | `api/admin-products.php:349`, `:425` | Low | The JSON error returns `'Database error: ' . $e->getMessage()` / `'DB error: ' . $e->getMessage()` to the admin browser. `semgrep-triage.md` marks this file as "maps errors to fixed text", which is true for lines 170 and 273 but not for these two. Same fix as S1 (`lume_error_message()`). |
| N2 | `api/section-preview.php:90` | Info | Shows the exception message (escaped) in an admin-only preview. Left as is; same fix possible. |
