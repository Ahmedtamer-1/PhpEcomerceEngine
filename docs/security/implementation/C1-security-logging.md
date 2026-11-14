# Part 1 – Security Implementation

One section per control. Evidence (screenshots and command output) is in `docs/security/evidence/`.

Local test site: `http://localhost:8000` (PHP built-in server, `ENV=development`), database `lumeegy_test`, seed `database/seed-security-test.sql`.
Note: `php -S` ignores `.htaccess`, so header and deny-rule checks (H1) need Apache; see the H1 section.

---

## C1 – Security Logging and Monitoring

**Threat:** T11 (attacks leave no trace); supports T01, T08, T19, T20. Findings: GAP-01.

**Design**
- A new table `security_events` (separate from the admin *Activity Log*, which records what admins changed) holds one row per security-relevant event: type, severity (`info|warning|critical`), user, identifier tried, IP, user agent, path, details, time.
- One function, `security_log($type, $severity, $context)`, writes it. It never throws (errors go to `error_log()`), cuts every value to a fixed length, strips control characters (no log forging), logs the **path only** (query strings can carry reset or CSRF tokens) and drops detail keys that look like secrets (`pass|token|csrf|secret|session|cookie|auth`). Passwords, session IDs and CSRF tokens are never passed in.
- CSRF rejections are logged inside `csrf_verify()` itself, so storefront forms, APIs and the admin guard (`admin_enforce_csrf()`) are all covered by one line.
- The table is created on first use (`security_log_ensure_table()`) and is also in `database/schema.sql`; the seed truncates it. Events older than a year are pruned occasionally.
- Admin page **Security Events** (`admin/security.php`): last-24-hour counters (failed logins, lockouts, CSRF rejections, access denied), filters (type, severity, IP, date range) checked against whitelists / strict formats, 50 rows per page, newest first, everything escaped with `h()`.

**Events logged**

| Event | Severity | Where |
|---|---|---|
| `login_success` | info | `account.php:59`, `admin/login.php:36` |
| `login_failed` | warning | `account.php:65`, `admin/login.php:43` |
| `login_locked` | critical | `includes/functions.php:346` (inside `login_failed()`, once when the limit is reached; used by `account.php` and `admin/login.php`) |
| `csrf_rejected` | warning | `includes/functions.php:178` (inside `csrf_verify()`) |
| `access_denied` | warning | `admin/includes/auth.php:12`, `admin/login.php:31`, `api/admin-products.php:14`, `api/media.php:12`, `api/sections.php:11`, `api/section-preview.php:30` |
| `password_reset_requested` / `_completed` | info | `includes/password-reset.php:45`, `:97` |
| `upload_rejected` | warning | `includes/functions.php:1471`, `:1502`, `:1506` (inside `upload_check_image_path()` / `upload_check_image()`, the shared H3 check every upload handler calls) |
| `role_changed` | critical | `admin/users.php:77` |
| `logout` | info | `includes/functions.php:531` (`logout_user()`) |

**Files and functions**
- `includes/security-log.php` (new): `security_log_ensure_table()`, `security_log_clean()`, `security_log()`.
- `includes/functions.php:9` requires it; `csrf_verify()` (:171) and `logout_user()` (:528) log.
- `admin/security.php` (new), sidebar link in `admin/includes/header.php` (after Activity Log).
- `database/schema.sql` (`security_events`), `database/seed-security-test.sql` (truncate).

**How to verify** (automated run: `evidence/C1-verification.txt`)
1. 5 wrong passwords for one account, then a 6th attempt → 5 `login_failed` rows and one `login_locked` row.
2. POST to an admin page without the CSRF token → HTTP 403 and a `csrf_rejected` row.
3. Sign in as customer B, open `/admin/orders.php` → `access_denied` row.
4. Search the table for the wrong passwords, the seed passwords and 32+ character hex strings → 0 rows.
5. Filters and pagination work; `?ip=<script>alert(1)</script>` is shown as text and no script runs.

**Evidence**
- `screenshots/before-C1-no-security-log.png` (before: Activity Log shows only admin logins, none of the failed attempts)
- `screenshots/C1-security-events.png`, `screenshots/C1-filter-xss.png`
- `C1-verification.txt` (command output for the five checks)

**Result (measured):** all five checks passed; see the "Done when" list in `part1-controls-spec.md`.
