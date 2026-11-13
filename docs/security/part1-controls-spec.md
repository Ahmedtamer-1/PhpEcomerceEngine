# Part 1 – Additional Security Controls: Implementation Spec

The brief asks for **at least four additional security controls**. This spec defines the four we build (C1–C4), the hardening that goes with them (H1–H3), and the static-analysis fixes (S1–S3) found in [semgrep-triage.md](semgrep-triage.md).

Read first: [CLAUDE.md](../../CLAUDE.md) (code rules and git rules), [threat-model.md](threat-model.md), [semgrep-triage.md](semgrep-triage.md), [local-setup.md](local-setup.md).

## Ground rules

- Plain PHP 8.1, procedural functions, no framework, **no new dependencies**, no Composer.
- SQL only through `db()` with prepared statements; escape HTML output with `h()`.
- Storefront POSTs check `csrf_verify($_POST['csrf'] ?? '')`; admin pages start with `require_once __DIR__ . '/includes/auth.php'` (admin role + CSRF).
- New tables are created on first use, like `remember_tokens` (`CREATE TABLE IF NOT EXISTS` inside an `*_ensure_table()` function), **and** added to [database/schema.sql](../../database/schema.sql).
- Orders and stock only through `create_order()`, `set_order_status()`, `cancel_order()`, `delete_order()`.
- Every changed `.php` file must pass `php -l`.
- Git: branch `<type>/<kebab-topic>` from `main`, Conventional Commits (subject ≤72 chars), **one commit per file** (or up to 4 files for one identical change), stage by path, **no AI attribution** anywhere, merge commits only, never delete branches.
- Test only on the local copy ([local-setup.md](local-setup.md)).

## Step 0 – "Before" evidence (do this first, before changing any code)

On a clean `main` with the seed loaded, take these screenshots into `docs/security/evidence/screenshots/`. They are the "before" half of the before/after proof in the report.

| File | What to show |
|---|---|
| `before-C2-weak-password.png` | Registering with the password `password1` succeeds (only 8 characters are required today) |
| `before-C4-lockout-message.png` | After 6 wrong logins: the fixed "Please wait 15 minutes" message, and the 6th attempt with the right password is refused |
| `before-C3-raw-error.png` | An admin screen showing raw database error text, e.g. add a shipping zone with a name that already exists (`admin/shipping.php`) |
| `before-C1-no-security-log.png` | The admin sidebar and Activity Log after those failed logins: no record of them anywhere |
| `before-H1-headers.png` | `curl -I http://localhost:8090/` showing `X-Powered-By` and `X-XSS-Protection`, and no HSTS |

Commit them on the first branch (`feat/security-event-log`) as `docs(security): add before screenshots for part 1`.

## Branches

| Branch | Contains |
|---|---|
| `feat/security-event-log` | C1 |
| `feat/password-policy` | C2 |
| `feat/secure-error-handling` | C3 + S1 |
| `feat/account-lockout` | C4 (depends on C1: merge C1 first, then branch from the updated `main`) |
| `chore/security-hardening` | H1–H3 + S2 + S3 |

---

## C1 – Security Logging and Monitoring

**Threat:** T11 (attacks leave no trace), supports T01, T08, T19, T20.

**Build**
1. `includes/security-log.php` (required from `includes/functions.php`):
   - `security_log_ensure_table()` creates `security_events`:
     `id BIGINT PK`, `event_type VARCHAR(40)`, `severity VARCHAR(10)` (`info|warning|critical`), `user_id INT NULL`, `identifier VARCHAR(190) NULL` (e.g. the email tried), `ip_address VARCHAR(45)`, `user_agent VARCHAR(255) NULL`, `path VARCHAR(255)`, `details TEXT NULL`, `created_at DATETIME`; indexes on `(created_at)`, `(event_type, created_at)`, `(ip_address, created_at)`.
   - `security_log(string $type, string $severity = 'info', array $context = []): void` – never throws (catch and `error_log()`), truncates long values, never stores passwords or tokens.
2. Log these events (type → where):

| Event type | Severity | Where |
|---|---|---|
| `login_success` | info | `account.php` and `admin/login.php` after `login_succeeded()` |
| `login_failed` | warning | both login handlers, next to `login_failed()` |
| `login_locked` | critical | both login handlers when `login_throttled()` is true |
| `csrf_rejected` | warning | `admin_enforce_csrf()` before the 403, and storefront handlers when `csrf_verify()` fails |
| `access_denied` | warning | `admin/includes/auth.php` and `api/admin-products.php` when a logged-in non-admin hits admin |
| `password_reset_requested` / `password_reset_completed` | info | `includes/password-reset.php` |
| `upload_rejected` | warning | every upload handler when the MIME/size/extension check fails |
| `role_changed` | critical | `admin/users.php` when a role changes |
| `logout` | info | `logout_user()` |

3. Admin page `admin/security.php` (+ sidebar link next to Activity Log in `admin/includes/header.php`, `$adminPage = 'security'`):
   - Filters: event type, severity, IP, date range (GET params, whitelisted).
   - Counters for the last 24 h: failed logins, lockouts, CSRF rejections, access denied.
   - Paginated table (50 per page), newest first, all output through `h()`.
   - Match the look of `admin/activity.php`.

**Done when**
- [ ] 5 wrong passwords produce 5 `login_failed` rows and then a `login_locked` row, visible on the Security Events page.
- [ ] Removing the CSRF token from an admin POST gives 403 and a `csrf_rejected` row.
- [ ] Customer A opening `/admin/orders.php` gives an `access_denied` row.
- [ ] No row contains a password, a session ID or a CSRF token.
- [ ] Filters and pagination work; injecting `<script>` into a filter value shows it as text.

## C2 – Password Policy Enforcement

**Threat:** T02 (weak passwords).

**Build**
1. `password_policy_errors(string $password, string $email = '', string $name = ''): array` in `includes/functions.php`, returning a list of human-readable problems (empty = OK):
   - At least **10** characters (and at most 128).
   - At least one lowercase, one uppercase, one digit; **or** 16+ characters (passphrase).
   - Not in a common-password list: `includes/common-passwords.txt` (one per line, lowercase, about 1,000 entries; compare lowercase).
   - Must not contain the email's local part or the first name (case-insensitive, when 3+ characters).
2. Use it in **every** place a password is set: registration (`account.php`, `action=register`), password reset (`account.php`, `action=reset` → `password_reset_complete()`), and any admin user-creation form. Replace the existing `strlen($pass) < 8` checks.
3. Update the HTML `minlength` to 10 and add a short hint under the password fields listing the rules. Optional: a small strength meter in `assets/js/main.js` (client side is a convenience only; the server check is the control).
4. Google sign-in users keep their random password (no change).

**Done when**
- [ ] `password`, `Password1`, `aaaaaaaaaa`, the email itself, and `Lumeegy2026` (if listed) are rejected with clear messages.
- [ ] `Correct-Horse-7-Battery` and a 16+ character passphrase are accepted.
- [ ] Bypassing the browser (`minlength` removed, or a raw POST in Burp) still gets rejected.

## C3 – Secure Error Handling

**Threat:** T13 (error pages leak SQL, paths, stack traces).

**Build**
1. `includes/error-handler.php`, loaded first from `config/config.php` (before anything can fail):
   - `set_exception_handler()` and `register_shutdown_function()` (for fatal errors): generate a short reference ID (`bin2hex(random_bytes(4))`), write the full detail with that ID via `error_log()`, then respond with **500** and:
     - HTML request → a generic branded page (`500.php` content) showing only "Something went wrong" and the reference ID.
     - JSON request (path under `/api/` or `/admin/api/`, or `Accept: application/json`) → `{"success":false,"message":"Something went wrong","ref":"…"}`.
   - In `ENV=development` also show the detail on screen (current behaviour for developers).
   - `set_error_handler()` turns warnings into logged entries without output in production.
2. Custom `404.php` and `403.php` pages in the site style; wire them with `ErrorDocument 404 /404.php` and `ErrorDocument 403 /403.php` in `.htaccess`.
3. **S1 – fix the six places that show raw exception text to admins** (Semgrep `lumeegy-exception-message-to-user`): `admin/pages.php:35`, `admin/settings.php:96`, `admin/shipping.php:28`, `admin/themes.php:19` and `:74`, `admin/variants.php:102`. Log `$e->getMessage()` with a reference ID and show a generic message with that ID.
4. `config/database.php`: keep the production message generic (already is); log the PDO error with a reference ID.

**Done when**
- [ ] Forcing a DB error (wrong `DB_PASS` in `.env`) in production shows the generic page with a reference ID; the log line with that ID has the real error.
- [ ] An API endpoint throwing returns the JSON form, status 500.
- [ ] `/does-not-exist.php` shows the custom 404; `/includes/` shows the custom 403.
- [ ] Rerunning Semgrep shows 0 `lumeegy-exception-message-to-user` results (except the dev-only branch in `config/database.php`).

## C4 – Login Rate Limiting / Account Lockout

**Threat:** T01 (brute force), T18 (lockout abuse). Rate limiting already exists (`login_throttle_keys()` in `includes/functions.php`: 5 per email+IP, 20 per IP, 50 per email, 15-minute window). This control makes it a visible, logged, manageable lockout.

**Build**
1. `login_lockout_remaining(string $email): int` returns the seconds until the oldest counted attempt leaves the window (0 if not locked). Show "Too many attempts. Try again in N minutes." on both login pages instead of the fixed "15 minutes".
2. Progressive delay: after the 3rd failed attempt for an email+IP pair, `usleep()` 0.5 s × (failures − 2), capped at 3 s, before answering.
3. Log `login_locked` (C1) the first time a key crosses its limit in a window (not on every blocked request).
4. Optional: email the account owner once per lockout ("Someone tried to sign in…"), only if email sending is configured.
5. Admin unlock: on `admin/users.php`, an **Unlock** button per user (POST + CSRF via the admin auth) that calls `clear_login_attempts()` for that email's keys and logs `account_unlocked` (C1).
6. The generic "Invalid email or password." message stays the same for unknown and known emails (no enumeration).

**Done when**
- [ ] 5 wrong passwords from one IP lock that email+IP; the 6th attempt with the **right** password is still refused, with the minutes shown.
- [ ] Another IP (or after the window) can still log in (no permanent lockout = T18 mitigated).
- [ ] Admin Unlock clears it immediately and is logged.
- [ ] Response time grows after the 3rd failure (visible in Burp Intruder's response-time column).

---

## Hardening that goes with the controls

| ID | Change | Where | Done when |
|---|---|---|---|
| **H1** | Headers (findings ZAP-02, ZAP-03, ZAP-04, CFG-01/03/05): change every `Header set` to **`Header always set`** so error pages (403/404) get the headers too; remove deprecated `X-XSS-Protection`; add `frame-ancestors 'self'` to the CSP (it has no fallback to `default-src`); add HSTS **only when HTTPS** (`Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" "expr=%{HTTPS} == 'on'"`); hide versions with `Header always unset X-Powered-By` (and note `ServerTokens Prod` as a hosting setting); add `integrity` + `crossorigin="anonymous"` to the Chart.js `<script>` in `admin/includes/header.php:30` | `.htaccess`, `admin/includes/header.php` | `curl -I` on a page **and** on a 404 shows the security headers, no `X-XSS-Protection`/`X-Powered-By`; HSTS only on HTTPS; the admin dashboard chart still loads |
| **H2** | Sessions: `ini_set('session.use_strict_mode', '1')` and `ini_set('session.use_only_cookies', '1')` before `session_start()`; admin sessions use `SameSite=Strict` (set the cookie params by path or after admin login) | `includes/functions.php` (`lume_session_start()`) | A made-up session ID sent in the cookie is replaced by a new one (fixation test passes) |
| **H3** | Uploads: one shared check used by every handler: max size (e.g. 5 MB images), `finfo` MIME whitelist, extension chosen **from the detected MIME** (never from the client name), random file name (`bin2hex(random_bytes(16))`), reject SVG unless sanitised; log `upload_rejected` (C1) | `includes/functions.php` (`media_upload()`, used by `api/media.php` and `api/admin-products.php`), `admin/variants.php`, `admin/settings.php`, `includes/media-video.php`. All uploads are admin-only; customers have no upload form (InstaPay receipts go by WhatsApp). | `shell.php`, `shell.php.jpg`, PHP-in-JPG, oversized file and `../` name are all rejected and logged |
| **S2** | Route the two direct status updates through `set_order_status()` | `admin/order-new.php:86`, `checkout.php:260` | Semgrep `lumeegy-direct-order-status-update` only flags `includes/functions.php` |
| **S3** | Replace `db()->quote()` with a `?` placeholder | `admin/abandoned.php:46` | Semgrep `lumeegy-sql-built-by-concatenation` no longer lists it |

The CSP `'unsafe-inline'` finding (CFG-04) is **out of scope** for now: removing it needs nonces on every inline script. It is recorded as a residual risk in the report.

---

## Documentation to write as you go

Create `docs/security/implementation.md` with one section per control (C1–C4, H1–H3):

1. **Threat** (threat-model ID)
2. **Design** (what and why, 3–6 lines)
3. **Files and functions** (paths with line numbers)
4. **How to verify** (exact steps, the same as the "Done when" list)
5. **Evidence** (screenshot file names in `docs/security/evidence/screenshots/`, e.g. `C1-security-events.png`)

## Final checks before each PR

- [ ] `php -l` passes on every changed file.
- [ ] Smoke test: home → shop → product → add to cart → checkout (COD) → order appears in admin → change its status → stock is correct.
- [ ] All "Done when" boxes for that control are ticked, with screenshots saved.
- [ ] Semgrep rerun shows no new findings from the changed files.
- [ ] No secrets, `.env`, or `docs/local/` files staged.
