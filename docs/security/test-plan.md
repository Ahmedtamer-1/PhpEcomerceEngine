# Security Test Plan (Parts 2 + 3)

Runs once, after Part 1 is merged. Target: **`http://localhost:8090` only**. Budget: **about 3 hours** in one sitting.

- **Part 2 (Security Testing):** 16 test cases, 2 for each of the 8 categories in the brief.
- **Part 3 (Security Tools):** Burp and sqlmap for the manual tests, then one Semgrep rerun and one ZAP rescan to compare against the baseline.

The brief sets no minimum number of tests. Two per category is enough when each test has a **positive control**: a step showing the feature works for the right user, so a "blocked" result means the control blocked it, not that the page was broken.

## Deliverables of this phase

| File | Content |
|---|---|
| `test-cases.xlsx` (built from `test-cases.md`) | 16 rows, the columns below |
| `evidence/burp/`, `evidence/screenshots/` | One image per test, `<ID>.png` (`<ID>-b.png` for a second one) |
| `evidence/sqlmap/SQLI-02.txt` | sqlmap output |
| `evidence/semgrep/semgrep-after.{txt,sarif}` | Semgrep rerun |
| `evidence/zap/zap-after.html` + `zap-after-alerts.png` | ZAP rescan |
| `tools.md` | One table: tool, version, used for, tests covered, evidence |

**Columns:** ID · WSTG ref · Category · Target · Role · Tool · Steps · Payload · Expected · Actual · Pass/Fail · Evidence.

## Before starting (10 min)

1. `git pull` on `main` (Part 1 merged), reload `database/schema.sql` + `database/seed-security-test.sql`, then run the smoke test in [local-setup.md](local-setup.md).
2. Test data for RBAC-02. The seeded orders aren't awaiting payment, and `instapay-upload.php` sends away **every** order that isn't, so without this step the test passes even with no ownership check. This is a test-data change only:
   ```sql
   UPDATE orders SET payment_method='instapay', status='pending_payment' WHERE id IN (1,2);
   ```
3. Open Burp (proxy on 8082) and **two browser profiles**: one for customer A, one for admin (or customer B).

## The 16 tests

Run them **in this order**: read-only tests first, data-changing tests last, so the seed only needs reloading once.

| # | ID | WSTG | Target | Role | Tool | Steps → Expected (Pass when) |
|---|---|---|---|---|---|---|
| 1 | **SQLI-01** | INPV-05 | `shop.php?q=`, `?category=`, `?sort=`; `product.php?slug=` | guest | Burp Repeater | Send `'`, `' OR '1'='1`, `1 AND SLEEP(5)`, `name DESC;--` → no SQL error, no extra products, response time unchanged (< 5 s), `sort` falls back to default |
| 2 | **SQLI-02** | INPV-05 | `shop.php?q=x`, `product.php?slug=x` | guest | sqlmap | `sqlmap -u "http://localhost:8090/shop.php?q=x" --batch --level 2 --risk 1` (same for `slug`) → "all tested parameters do not appear to be injectable". **Not on the login form**: sqlmap's many attempts would trigger the lockout, and the CSRF token blocks it anyway. |
| 3 | **XSS-01** | INPV-01 | `shop.php?q=`; `track-order.php?order_number=&email=`; `account.php?error=` | guest | Burp Repeater + browser | `"><script>alert(1)</script>` → response contains `&lt;script&gt;`, no alert in the browser |
| 4 | **SESS-01** | SESS-02 | `lumeegy_session`, `adn_remember` | customer A | DevTools → Application → Cookies | **Control:** note the session ID before login. Log in → ID **changed**, `HttpOnly` ✓, `SameSite=Lax` ✓ (`Secure` is only set on HTTPS: state this) |
| 5 | **SESS-02** | SESS-03 | session cookie | guest → A | Burp Repeater | Send `Cookie: lumeegy_session=attacker123` → server replies with a **new** `Set-Cookie` (H2 strict mode). Then log out, replay an old `account.php?action=dashboard` request with the old cookie → login page, not the dashboard |
| 6 | **RBAC-01** | ATHZ-02 | `/admin/orders.php`, `admin/api/search.php?q=a`, `api/admin-products.php` | guest, then customer A | Browser + Repeater | Take one admin request from Burp history, replay it with **no cookie** and with **A's cookie** → redirect to login / 403 and no data; `access_denied` row in Security Events (C1). **Control:** the same request with the admin cookie → 200 |
| 7 | **RBAC-02** | ATHZ-04 (IDOR) | `instapay-upload.php?order_id=`; `track-order.php` | customer A | Browser / Repeater | **Control:** `order_id=1` (A's own) → payment page loads. `order_id=2` (B's) → redirected, B's order number and total not shown. `track-order.php` with B's order number + A's email → "not found" |
| 8 | **INP-01** | BUSL-01 | `api/cart.php` POST `action=add` / `update` | customer A | Burp Repeater | `quantity=-5`, `0`, `999999`, `quantity[]=1`, `product_id=abc` → clamped to ≥ 1 and never above stock, or a clean JSON error; never a PHP error |
| 9 | **INP-02** | BUSL-01 | `api/cart.php` + `checkout.php` | customer A | Burp Repeater | Add the **inactive** product's ID and a variant of another product → rejected. At checkout, change `shipping_zone` to `999` and add a `price=1` field → rejected or ignored; order total = DB prices + real zone cost |
| 10 | **CSRF-01** | SESS-05 | admin settings POST; `api/cart.php` | admin; customer A | Burp Repeater | Remove the token, then send a wrong one → **403** + `csrf_rejected` in Security Events. **Control:** the original request with its token → 200 |
| 11 | **CSRF-02** | SESS-05 | `api/cart.php` `action=add` | customer A | Hand-written page `evidence/burp/csrf-poc.html`, served with `php -S localhost:9000` | While A is logged in, open the page → it auto-POSTs to `:8090/api/cart.php` with no token → "Invalid request" (403), A's cart unchanged. (`localhost:9000` and `:8090` count as the **same site**, so SameSite doesn't block this; only the token does. This makes the test meaningful.) |
| 12 | **XSS-02** | INPV-02 | contact form → `admin/messages.php` | guest → admin | Browser | Submit name/message `<img src=x onerror=alert(1)>`. Open `admin/messages.php` as admin → shown as text, no alert |
| 13 | **AUTH-02** | ATHN-07 | `account.php?action=register` | guest | Browser + Burp Repeater | `password123`, `Short1`, a password containing the email → rejected (C2). Same POST sent in Repeater, skipping the browser checks → still rejected. **Control:** a strong password → account created |
| 14 | **AUTH-01** | ATHN-03 | `account.php` login POST | guest | Burp Intruder (Sniper on `password`, 6 payloads) | Same session and token for all 6 (the token is per session, so Intruder can reuse it) → the 6th shows the lockout message **with the remaining minutes** (C4), `login_locked` in Security Events (C1). Admin clicks **Unlock** → the correct password works |
| 15 | **UPL-01** | BUSL-09 | admin Media page → `api/media.php` (`media_upload()`) | admin | Burp Proxy intercept | Upload `shell.php` (`<?php echo 1; ?>`), then the same file renamed `shell.php.jpg` with `Content-Type: image/jpeg` → both rejected + `upload_rejected` logged (H3) |
| 16 | **UPL-02** | BUSL-09 | `assets/` | admin → guest | Browser + curl | **Control:** a real JPG uploads → stored with a random name and the `.jpg` extension. Then `curl -i http://localhost:8090/assets/<any>.php` → 403/404, never PHP output (`assets/.htaccess`) |

**After #16:** reload the seed (it removes the test accounts, uploads, carts, messages and lockouts).

## Part 3 runs (after the 16 tests)

| Step | Command / action | Time | Output |
|---|---|---|---|
| Semgrep | Same command as the baseline ([local-setup.md](local-setup.md) §7), output `semgrep-after.*` | 3 min | Before → after table per rule at the end of `semgrep-triage.md`. Expected: the SAST-01..03 results are gone |
| ZAP | Same context and exclusions as the baseline; Spider logged in as admin → Active Scan. **Stop at 25% or 15 min** | 20 min | `zap-after.html` + Alerts screenshot. Before → after counts by risk in `findings.md`. Expected: ZAP-02, ZAP-04 and the header alerts gone; CSP `unsafe-inline` remains (accepted) |
| tools.md | One table | 10 min | Semgrep 1.185, ZAP 2.17, Burp Community, sqlmap 1.10: what each covered, which test IDs |

**As run:** the baseline ZAP active scan was stopped early for time, and the ZAP rescan was **skipped** (no `zap-after.html`). SQLi and XSS are covered by SQLI-01/02 and XSS-01/02 instead; the header fixes are shown with `curl` (`evidence/H1-headers-after.txt`). Both points are stated in `tools.md` and the report.

## Rules while testing

- **A test fails?** Mark it Fail, write down file:line and a provisional severity, and keep going. Fix it afterwards on its own branch, then retest the same ID and fill in a "Retest" note. Never fix it silently.
- **Evidence = request + response visible** in one screenshot (Burp), or the DevTools panel / admin page that shows the result. Name it after the test ID before moving on.
- Every Pass names the control that caused it (C1–C4, H1–H3, or an existing one: `h()`, prepared statements, `csrf_verify()`, `auth.php`, `can_view_order()`). The report needs this link.

## Done when

- [ ] 16 rows filled in: Actual, Pass/Fail and Evidence for each; the evidence files exist under those names
- [ ] Each of the 8 brief categories has 2 rows; every Pass names its control
- [ ] Every Fail has a finding with file:line and a retest result
- [ ] `semgrep-after` and `zap-after` exist; before → after numbers written down
- [ ] `tools.md` written; seed reloaded; nothing was sent to the live site
