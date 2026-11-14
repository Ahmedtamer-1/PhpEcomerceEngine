# Part 5 – Live Demo Script

About 8 minutes of live security tests against the **local test copy only** (`http://localhost:8090`, seeded database `lumeegy_test`). It shows each Part 1 control blocking a real attack, then shows the tool results. Every step repeats a test that already passed in Part 2 (`test-cases.md`). If a step fails live, show its fallback evidence and move on.

**Roles.** *Driver* runs the browsers, Burp and the terminal. *Narrator* says what the audience should look at and which control blocked the attack. Swap halfway if you like.

**Two browsers, so the sessions don't mix:**

- **Browser A: Burp's built-in Chromium** (Burp → Proxy → *Open browser*). Used as the guest and customer, and every request goes through Burp. Intercept **off**.
- **Browser B: Edge InPrivate (not proxied).** Logged in as the admin, so the audience can watch the Security Events page fill up.

Test accounts and passwords are at the top of `database/seed-security-test.sql`: admin `admin@lumeegy.test`, customer A `customer.a@lumeegy.test`, customer B `customer.b@lumeegy.test`.

## Automated run (optional)

[`demo-runner.mjs`](demo-runner.mjs) plays all 8 steps in two visible Edge windows: left is the guest/customer, right is the admin. Before each step it shows an orange **Continue ▶** button in both windows and waits until you click it (or press Enter in the terminal), so you decide when the explanation is done. Every switch between the two windows inside a step (for example, attack → admin Security Events) also waits for a click, so nothing changes screen until you click: 18 clicks for the whole demo. Each page shows a banner (step, test ID, control) and a green or red result box. The runner resets the database at the start and the end, and refuses any site other than `localhost:8090`. It uses Edge's DevTools protocol, so there is nothing to install.

```powershell
node docs/security/demo-runner.mjs --speed 0.7
```

Options: `--speed` scales the holds inside each step (1 = default, 0.7 = a little faster, 2 = twice as slow; typing speed does not change). `--burp` routes the left window through Burp (`127.0.0.1:8082`), so every request appears live in Burp's HTTP history. `--step 3` starts at a step (no reset). `--keep` leaves the windows open. `--auto` runs without pauses (rehearsal). `--shots <folder>` saves a screenshot of both windows after each step.

The runner drives the browser, not Burp's own screens. Burp Community cannot be scripted, so if the graders should see Intruder itself, run step 3 by hand as written below and the rest with the runner (`--step 4`). In the automated version, step 3 types the six wrong passwords into the login form, and step 5 sends the token-less request from the page, then opens the attacker page `csrf-poc.html` on `localhost:9000`.

## Pre-demo checklist (15 min before)

| # | Check | How | Expected |
|---|---|---|---|
| 1 | Services running | PowerShell: `Get-Service Apache2.4, MySQL80` | Both `Running`; `http://localhost:8090/` loads |
| 2 | Reset the database | `"C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -u lumeegy_test -p lumeegy_test -e "source database/seed-security-test.sql"` (from the repo root) | No errors |
| 3 | Test data for step 4 (IDOR) | Same client: `UPDATE orders SET payment_method='instapay', status='pending_payment' WHERE id IN (1,2);` | `instapay-upload.php` only opens orders awaiting payment; without this, step 4 would pass even with no ownership check |
| 4 | Security events empty | `SELECT COUNT(*) FROM security_events;` | `0` |
| 5 | Burp ready | Proxy listener `127.0.0.1:8082`, Intercept off, HTTP history cleared; Browser A opened from Burp | Requests show in HTTP history |
| 6 | Browser A tabs | `shop.php`, `contact.php`, `account.php` (signed out) | Guest |
| 7 | Browser B tabs (admin signed in) | `admin/security.php`, `admin/messages.php`, `admin/users.php?id=3`, `admin/media.php`, `admin/shipping.php` | All load |
| 8 | Upload test file | Create `shell.php.jpg` on the Desktop containing `<?php echo 1; ?>` | File exists |
| 9 | Terminal for step 7 | PowerShell at the repo root | Ready |
| 10 | Fallback ready | File Explorer on `docs/security/evidence/`; the report PDF open | Open |

## The demo (≈ 8 min)

| Step | Time | Test ID | Control shown |
|---|---|---|---|
| 0 | 0:30 | – | Intro: local copy only, seeded data, what we'll attack |
| 1 | 0:45 | SQLI-01 | Prepared statements, `sort` whitelist |
| 2 | 1:00 | XSS-02 | `h()` output escaping |
| 3 | 1:45 | AUTH-01 | C4 lockout + C1 security log + admin unlock |
| 4 | 0:45 | RBAC-01 / RBAC-02 | `admin/includes/auth.php` role check, `can_view_order()` |
| 5 | 1:00 | CSRF-01 / CSRF-02 | `csrf_verify()`, `admin_enforce_csrf()` + C1 |
| 6 | 0:45 | UPL-01 | H3 `upload_check_image()` (finfo) + `assets/.htaccess` + C1 |
| 7 | 0:45 | C3 | Secure error handling: generic page + reference ID |
| 8 | 0:45 | Part 3 | ZAP baseline and Semgrep before → after |

### 1. SQL injection in the shop search (SQLI-01)

- **Action (Browser A):** open `http://localhost:8090/shop.php?q=ring` (control), then `http://localhost:8090/shop.php?q=ring' OR '1'='1' -- -`. Optional: `shop.php?sort=name DESC;--`.
- **Audience sees:** the control shows **2 products**. The payload shows **0 products**, no SQL error, and no delay. The bad `sort` value falls back to the default order.
- **Why it's blocked:** the search value is bound as a parameter (prepared statement), so it is never read as SQL. `sort` only accepts values from a whitelist.
- **Fallback:** `evidence/tests/SQLI-01.txt`, `evidence/sqlmap/SQLI-02.txt` ("all tested parameters do not appear to be injectable").

### 2. Stored XSS via the contact form, shown to the admin (XSS-02)

- **Action (Browser A, guest):** on `contact.php` enter `<img src=x onerror=alert(1)>` as the **name** and the **message**, plus any email, then *Send*.
- **Action (Browser B, admin):** reload `admin/messages.php`.
- **Audience sees:** the message shows the payload as **plain text**, and no alert pops up. (Optional: DevTools shows `&lt;img src=x onerror…` in the HTML.)
- **Why it's blocked:** the payload is stored as it was typed, but every output goes through `h()` (`htmlspecialchars`), so the browser never runs it.
- **Fallback:** `evidence/tests/XSS-02.txt` (raw `<img onerror` on the admin page = 0, escaped = 2).

### 3. Brute force with Burp Intruder → lockout → Security Events → Unlock (AUTH-01)

- **Action (Browser A):** on `account.php`, sign in as `customer.b@lumeegy.test` with a wrong password once (this request is our template).
- **Action (Burp):** HTTP history → that `POST /account.php` → *Send to Intruder* → Sniper, payload position on `password`, payloads `wrong1` … `wrong6` → *Start attack*. The CSRF token stays valid because it is per session.
- **Audience sees:** response times grow from the 3rd failure on (progressive delay of 0.5 s per failure). Once 5 failures are reached, responses come back fast and contain **"Too many attempts. Try again in N minutes."** (N = minutes left in the 15-minute window).
- **Action (Browser B):** reload `admin/security.php`: counters show *Failed logins 5* and *Lockouts 1*, with a **`login_locked` (critical)** row for customer B. Open `admin/users.php?id=3` → *Sign-in lock* box → **Unlock account** → "Sign-in lock cleared." Customer B can now sign in with the correct password.
- **Why it's blocked:** C4 lockout (`login_failed()`, `login_lockout_message()`), C1 `security_log()`, admin `login_unlock()`.
- **Fallback:** `evidence/burp/AUTH-01.png` (Intruder), `AUTH-01-b.png` (lockout message), `AUTH-01-c.png` (Security Events), `AUTH-01-d.png` (Unlock button), `AUTH-01-e.png` (unlocked).

### 4. Customer opens /admin and another customer's order (RBAC-01 / RBAC-02)

- **Action (Browser A):** sign in as **customer A**. Open `http://localhost:8090/admin/orders.php`, then `http://localhost:8090/api/admin-products.php?action=list`.
- **Audience sees:** `/admin/orders.php` redirects to the admin login page; the API answers **403** `{"success":false,"message":"Unauthorized"}`.
- **Action:** open `instapay-upload.php?order_id=1` (A's own order, **control**: it loads and shows `LME-AAA111…`), then `instapay-upload.php?order_id=2` (B's order).
- **Audience sees:** order 2 redirects to `track-order.php`, and **none of B's order data** is shown. In Browser B, `admin/security.php` now lists `access_denied` rows.
- **Why it's blocked:** `admin/includes/auth.php` and the API role checks (`role === 'admin'`), `can_view_order()` for ownership, C1 logging.
- **Fallback:** `evidence/tests/RBAC-01.txt`, `evidence/tests/RBAC-02.txt`.

### 5. Forged request without a CSRF token (CSRF-01 / CSRF-02)

- **Action (Browser A, customer A):** add any product to the cart, so Burp captures `POST /api/cart.php`. HTTP history → *Send to Repeater*.
- **Action (Repeater):** delete the `csrf=` parameter → *Send*. Then set it to `csrf=deadbeef` → *Send*. Then restore the original value → *Send* (**control**).
- **Audience sees:** without a token or with a wrong one: **400** `{"success":false,"message":"Invalid CSRF token"}`. With the real token: **200** `{"success":true,…}`. In Browser B, `admin/security.php` shows new **`csrf_rejected`** rows.
- **Optional (cross-origin):** run `php -S localhost:9000 -t docs/security/evidence/burp` and open `http://localhost:9000/csrf-poc.html` in Browser A. The page auto-submits a forged "add to cart" with A's cookie but no token; the shop refuses it and A's cart is unchanged.
- **Why it's blocked:** the token lives in the session and in the page, not in a cookie, so another site cannot send it. `csrf_verify()` rejects the request, and C1 logs it.
- **Fallback:** `evidence/tests/CSRF-01.txt`, `evidence/tests/CSRF-02.txt`, `evidence/burp/csrf-poc.html`.

### 6. Upload a PHP shell disguised as an image (UPL-01)

- **Action (Browser B, admin):** `admin/media.php` → upload `shell.php.jpg` from the Desktop.
- **Audience sees:** the upload is refused with an error (the API answer is "No files uploaded or invalid file types"). `admin/security.php` shows **`upload_rejected`**. Then open `http://localhost:8090/assets/x.php` → **403**.
- **Why it's blocked:** H3 `upload_check_image()` reads the real type of the bytes with `finfo` (here PHP, not JPEG) and ignores the file name and Content-Type. Even a stored script could not run, because `assets/.htaccess` denies `.php` under `assets/`.
- **Fallback:** `evidence/burp/UPL-01-intercept.png`, `UPL-01.png`, `UPL-01-shelljpg.png`, `evidence/tests/UPL-01-02-curl.txt`.

### 7. Trigger an error: generic page, details only in the log (C3)

- **Action (Browser A):** open `http://localhost:8090/does-not-exist.php`, then `http://localhost:8090/includes/`.
- **Audience sees:** branded **404** and **403** pages, with no Apache or PHP version banner.
- **Action (Browser B, admin):** `admin/shipping.php` → add a zone named **`Cairo`** (it already exists).
- **Audience sees:** "The zone could not be added (is the name already used?). **Reference: &lt;8 hex characters&gt;.**" No SQL is shown on the page.
- **Action (terminal):** `Select-String "ref <reference>" C:\Apache24\logs\lumeegy-error.log`, using the reference shown on the page. The full `PDOException: SQLSTATE[23000] … Duplicate entry 'Cairo'` is **only in the server log**.
- **Why it's blocked:** C3 `lume_error_message()` logs the detail with a reference ID and shows a generic message; `ErrorDocument` serves the 403/404 pages.
- **Fallback:** `evidence/screenshots/before-C3-raw-error.png` (before), `C3-admin-error-with-ref.png` (after), `C3-custom-404.png`, `C3-custom-403.png`, `C3-generic-500.png`; `evidence/C3-verification-s1.txt`.

### 8. Tool results: ZAP and Semgrep (Part 3)

- **Show:** `evidence/zap/zap-before-summary.html`: the ZAP baseline found **0 High · 7 Medium · 6 Low · 16 Informational** alert types, each with our verdict. Then `semgrep-triage.md`, "Before → after": raw results **201 → 199**, and all three static true positives (**SAST-01/02/03**) closed.
- **Say honestly:** the ZAP active scan stopped at 23% for time, so SQLi and XSS were tested manually (steps 1–2). No ZAP rescan was run after the fixes; the header fixes are proven with `curl` (`evidence/H1-headers-after.txt`).
- **Close:** 16 / 16 Part 2 tests pass (`test-cases.xlsx`); 15 findings, 11 fully fixed, 0 High remaining.
- **Fallback:** `evidence/zap/zap-before-alerts.png`, `evidence/semgrep/semgrep-after.txt`.

## After the demo

Re-run the seed (checklist step 2). It removes the test message, cart lines, lockouts and security events.

## If something goes wrong

| Problem | Fallback |
|---|---|
| Site or DB down | `Get-Service Apache2.4, MySQL80`. Restarting Apache needs an admin shell (`Restart-Service Apache2.4`). Otherwise, present every step from its fallback evidence. |
| Burp proxy not capturing | Check the listener on 8082 and that Browser A was opened from Burp. For step 3, show `burp/AUTH-01*.png`; for step 5, use the browser plus the `csrf-poc.html` variant. |
| Account already locked or data dirty | Re-seed (checklist steps 2–4); takes about 10 seconds. |
| Running out of time | Skip step 8 (it's on the slides) and the optional part of step 5. |

## Rehearsal log

**2026-11-14: scripted rehearsal on `localhost:8090` (curl stands in for the browser and Burp; same requests).** Seed reset and RBAC-02 data set first; re-seeded afterwards (`security_events` back to 0).

| Step | Test | Result | Observed |
|---|---|---|---|
| Pre | Checklist 1–4 | ✅ Pass | Site 200; `security_events` = 0 after the seed |
| 1 | SQLI-01 | ✅ Pass | `q=ring` → 2 products; `q=ring' OR '1'='1' -- -` → 0 products; 0 SQL errors; `SLEEP(5)` payload answered in 0.04 s |
| 2 | XSS-02 | ✅ Pass | Contact form accepted the payload; `admin/messages.php`: raw `<img onerror` = 0, escaped = 2 |
| 3 | AUTH-01 | ✅ Pass | Failures 1–5: 0.19 s, 0.18 s, 0.72 s, 1.20 s, 1.68 s (progressive delay); 6th → "Too many attempts. Try again in 15 minutes." in 0.04 s; 5 `login_failed` + 1 `login_locked`; correct password refused while locked; admin Unlock → 302 `msg=unlocked`; B then signs in (302); `account_unlocked` = 1 |
| 4 | RBAC-01/02 | ✅ Pass | A → `/admin/orders.php` 302 to admin login; A → `api/admin-products.php` 403; own order 1 → 200 with `AAA111`; B's order 2 → 302 to `track-order.php`, `BBB222` not shown; 2 `access_denied` |
| 5 | CSRF-01/02 | ✅ Pass | No token (Origin `localhost:9000`) → 400 "Invalid CSRF token"; wrong token → 400; valid token → 200; admin POST without token → 403; `csrf_rejected` 0 → 3 |
| 6 | UPL-01 | ✅ Pass | `shell.php.jpg` → 400 "No files uploaded or invalid file types"; `upload_rejected` = 1; `/assets/x.php` → 403 |
| 7 | C3 | ✅ Pass | `/does-not-exist.php` 404 and `/includes/` 403, no version banner; duplicate zone → "…Reference: a7bed3ac."; no SQLSTATE on the page; the log line for `ref a7bed3ac` holds the `PDOException: SQLSTATE[23000]` |
| 8 | Tool results | ✅ Pass | `zap-before-summary.html`, `zap-before-alerts.png`, `semgrep-after.txt`, `semgrep-triage.md` all present |

**2026-11-14: automated runner (`node docs/security/demo-runner.mjs --auto`), four runs in visible Edge windows.** Three runs passed 8 / 8. One failed at setup (see the end of this entry). Each run reset the database first and re-seeded it afterwards (`security_events` = 0). Same observations as above: lockout after 5 failures with 5 `login_failed` + 1 `login_locked`; admin Unlock, then B signs in; `access_denied` = 2; token-less cart POST → 400, with the token → 200; `shell.php.jpg` → 400 and 1 `upload_rejected`; duplicate zone → reference ID on screen, `SQLSTATE[23000] … Duplicate entry 'Cairo'` only in the log. The failed run connected to a stale Edge window left by an earlier run, so the admin sign-in form was not found. The runner now opens its own tab and closes Edge cleanly, and a setup failure still triggers the re-seed.

**2026-11-14: runner with the Continue button, `--speed 0.7`.** A test helper clicked Continue about 4 s after each button appeared: 8 buttons, 8 clicks, 8 / 8 steps passed, and every step started only after its click. Step times after the click: about 8 s (1), 13 s (2), 48 s (3), 23 s (4), 17 s (5), 12 s (6), 6 s (7), 2 s (8). With no click the runner waits (checked for 25 s). Database re-seeded afterwards.

**2026-11-14: click-gated version, `--speed 0.7`.** 8 step buttons + 10 in-step window switches = 18 buttons; the helper made 18 clicks, and nothing moved without one. 8 / 8 steps passed; database re-seeded afterwards.

**Still to rehearse by hand (UI only, same requests as above):** the Burp Intruder run and its results table (step 3), the Burp Repeater edits (step 5), the media-page error message (step 6), the optional `csrf-poc.html` run, and timing the full 8 minutes with both presenters. Record the date and result here after each hand rehearsal.
