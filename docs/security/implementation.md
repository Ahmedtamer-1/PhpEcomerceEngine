# Part 1 – Security Implementation (index)

Four additional security controls (C1–C4), three hardening changes (H1–H3) and three static-analysis fixes (S1–S3). Each has its own notes file with Threat → Design → Files and functions (with line numbers, checked against merged `main`) → How to verify → Evidence.

| ID | Control | Notes | Branch |
|---|---|---|---|
| C1 | Security logging and monitoring | [C1-security-logging.md](implementation/C1-security-logging.md) | `feat/security-event-log` |
| C2 | Password policy enforcement | [C2-password-policy.md](implementation/C2-password-policy.md) | `feat/password-policy` |
| C3 + S1 | Secure error handling (+ raw exception text removed) | [C3-secure-error-handling.md](implementation/C3-secure-error-handling.md) | `feat/secure-error-handling` |
| C4 | Login rate limiting / account lockout | [C4-account-lockout.md](implementation/C4-account-lockout.md) | `feat/account-lockout` (needs C1) |
| H1, H2, H3, S2, S3 | Headers, sessions, uploads, order status, SQL placeholder | [H-hardening.md](implementation/H-hardening.md) | `chore/security-hardening` (needs C1: uploads log `upload_rejected`) |

Merge order: **C1 first**, then C4 and the hardening branch (both contain C1's commits until C1 is merged). C2 and C3 are independent of the others.

## Test environment used for the evidence

- Apache 2.4 (XAMPP build, PHP 8.2) at `http://localhost:8090` with `AllowOverride All`, `mod_rewrite`, `mod_headers`; a second TLS listener at `https://localhost:8443` (self-signed) only for the HSTS check. MySQL 8.0, database `lumeegy_test`, `database/schema.sql` + `database/seed-security-test.sql`, `ENV=development`, `SITE_URL=http://localhost:8090`.
- C1 and C2 were first run on `php -S localhost:8000`; the results do not depend on `.htaccess`, so they hold on Apache. Everything that needs `.htaccess` (C3 error pages, H1) was run on Apache only.
- Screenshots were taken with Microsoft Edge driven by `playwright-core`; command output is saved as text next to the screenshots in `docs/security/evidence/`.
- The test accounts and the keys are fake and local-only (see the seed file). Nothing was run against the live site.

## Per-branch checks

- `php -l`: passed on every changed PHP file.
- Smoke test (home, shop, product, cart, account, COD checkout, admin sees order, cancel returns stock) on each branch: `evidence/smoke-<branch>.txt`, all passed.
- Semgrep (same rule set as `local-setup.md` §7), per branch, compared with the baseline for the changed files: no new true positives. New results that are false positives are listed in the notes (`C4`: 2, `H-hardening`: 2). The raw results of the baseline run are unchanged in `evidence/semgrep/`; a final "after" run belongs to the report, once all branches are merged.

## New findings (not fixed, to confirm first)

| # | File:line | Severity | Finding |
|---|---|---|---|
| N1 | `api/admin-products.php:349`, `:425` | Low | The JSON error returns `$e->getMessage()` (database error text) to the admin browser. The Semgrep triage called this file "fixed text", which is only true for lines 170 and 273. Same fix as S1. |
| N2 | `api/section-preview.php:90` | Info | Shows the (escaped) exception message in an admin-only preview. |
| N3 | `includes/auth.php` | Info | A second copy of the admin guard whose `require_once '../../includes/functions.php'` points outside the repository; nothing includes it. Probably a leftover; remove or fix. |
| N4 | `includes/functions.php` `login_succeeded()` | Info | A successful login from one address clears the email's account-wide throttle counter for all addresses. The tight email + address counter is not affected. |
| N5 | `account.php` registration and reset | Info | bcrypt only uses the first 72 bytes of a password, so the 128-character maximum allows characters that add no strength. Pre-hashing or a 72-byte cap would fix it. |
| N6 | `account.php` (registration error) | Info | After a rejected registration the form comes back empty (name and email are not kept), which makes a stricter password policy more annoying than it needs to be. |
| N7 | `includes/media-video.php` `media_store_file()` | Low | **Fixed 2026-11-14.** The admin Media page uploads in chunks; a file that is neither an image nor a video (e.g. PHP renamed `shell.php.jpg`) was refused ("Unsupported file type") but **not logged**, because the refusal came before the shared check that writes `upload_rejected`. Found while building the demo runner; now logged with reason "type not allowed" (line 166). Verified: the shell via the Media page path → refused + 1 `upload_rejected`; a real JPG through the same path still uploads. |

## Not done / limits

- C4's optional "someone tried to sign in" email: not built (email sending is not configured in the test setup, so it could not be tested).
- CSP `'unsafe-inline'`, no SRI for the Google Fonts stylesheet, no COEP: accepted residual risks (see H1).
- `ServerTokens Prod` (hides the Apache version in the `Server:` header) is an Apache setting, not part of `.htaccess`; it is set in the local test configuration and must be set on the host.
- The H3 size limit is 8 MB per image (`UPLOAD_MAX_IMAGE_BYTES`), a little above the 5 MB example in the brief, so phone photos still upload.
