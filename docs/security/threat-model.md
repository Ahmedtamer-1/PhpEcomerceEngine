# Threat Model (STRIDE)

Scope: the LUMEEGY storefront and admin panel as run locally for this assessment. The model decides which features are tested first and which tests matter most. Each threat links to the Part 2 test category that checks it and the control that mitigates it.

## 1. System overview

| Component | Entry points | Data / assets |
|---|---|---|
| Storefront pages (root `*.php`) | `shop.php`, `product.php`, `cart.php`, `checkout.php`, `track-order.php`, `contact.php` | Products, cart, orders, customer name/phone/address |
| Customer account | `account.php` (login, register, forgot/reset, change password), `google-callback.php` | Email, password hash, session, remember-me token |
| Payments | `checkout.php` → Paymob iframe, `api/paymob_callback.php` (webhook, HMAC), `instapay-upload.php` (InstaPay instructions; receipts are sent by WhatsApp, no upload), COD | Order payment status |
| Storefront JSON API (`api/`) | `cart.php`, `checkout-lead.php`, `contact.php`, `newsletter.php`, `search.php`, `track.php`, `pv.php`, `media.php`, `sections.php` | Cart, leads, analytics events |
| Admin panel (`admin/`) | `login.php` + every page behind `admin/includes/auth.php`; `admin/api/*`, `api/admin-products.php` | All orders, all customers, products, stock, settings (payment keys, email sender), user roles |
| Uploads | Admin only: product/variant/media/settings images and video (`media_upload()`, `admin/variants.php`, `admin/settings.php`, `includes/media-video.php`) | Files under `assets/` |
| Third parties | Google OAuth, Paymob, Meta Pixel/CAPI, Resend/SMTP | API keys in `.env` and Settings |

## 2. Trust boundaries

1. **Internet → storefront**: anonymous visitors, all input untrusted.
2. **Customer → own data**: a logged-in customer must see only their own orders and account.
3. **Customer → admin**: the role check in `admin/includes/auth.php` is the main boundary.
4. **Paymob → webhook**: callbacks are trusted only after HMAC verification.
5. **Upload → web root**: uploaded files must never execute (`assets/.htaccess`).
6. **App → database**: all queries go through prepared statements in `db()`.

## 3. Actors

| Actor | Goal |
|---|---|
| Anonymous attacker | Steal data (SQLi), hijack sessions (XSS), take over accounts (brute force), get free orders (tampering) |
| Malicious customer | Read other customers' orders (IDOR), become admin, change prices |
| Malicious or compromised admin | Upload a web shell through the media or product image uploaders |
| Cross-site attacker | Make a logged-in admin or customer submit an action (CSRF) |
| Forged payment callback | Mark an unpaid order as paid |

## 4. STRIDE threats

Risk = likelihood × impact (H/M/L), used to prioritise testing. CVSS is used later for confirmed findings only.

| ID | STRIDE | Threat | Target | Existing mitigation | Risk | Test category | Control |
|---|---|---|---|---|---|---|---|
| T01 | **S**poofing | Brute-force or credential-stuffing a customer/admin login | `account.php`, `admin/login.php` | Rate limit per email + per IP (`login_attempts`) | H | Authentication | C4 Lockout, C1 Logging |
| T02 | Spoofing | Weak passwords guessed | Register / reset / change password | Min 8 characters | H | Authentication | C2 Password policy |
| T03 | Spoofing | Session hijack via stolen cookie or session fixation | Session, remember-me cookie | HttpOnly, SameSite=Lax, Secure on HTTPS, `session_regenerate_id` at login, remember validator stored as SHA-256 | M | Session management | Cookie hardening |
| T04 | Spoofing | Password-reset token guessed, reused or not expiring | `includes/password-reset.php` | Single use, expiry (`PASSWORD_RESET_MINUTES`) | M | Authentication | C1 Logging |
| T05 | Spoofing | Forged Paymob callback marks an order paid | `api/paymob_callback.php` | HMAC check; rejects if the secret is not set | H | Input validation (code review) | — |
| T06 | **T**ampering | SQL injection in search, filters, IDs, login | `shop.php`, `product.php`, `api/search.php`, admin search | Prepared statements; whitelist for ORDER BY | H | SQL Injection | — |
| T07 | Tampering | Price / quantity / variant tampering at checkout | `checkout.php`, `api/cart.php` | Server-side totals (to verify) | H | Input validation | — |
| T08 | Tampering | CSRF: forced order, account change, admin settings or role change | All POST handlers | `csrf_verify()` storefront, `admin_enforce_csrf()` admin, SameSite=Lax | H | CSRF | C1 Logging, admin SameSite=Strict |
| T09 | Tampering | Stored XSS in reviews / names / addresses that runs in the admin panel | Storefront forms → admin order/customer views | `h()` escaping, CSP | H | XSS | Header hardening |
| T10 | Tampering | Web shell uploaded as an image (admin uploaders; reachable by a customer only through admin takeover or CSRF) | Upload handlers | Admin-only, `finfo` MIME check, `assets/.htaccess` blocks scripts | M | File upload | Upload hardening |
| T11 | **R**epudiation | Attacks leave no trace; admin actions can't be attributed | Whole app | Admin Activity Log only; `error_log()` | M | (all, via logging checks) | C1 Logging |
| T12 | **I**nformation disclosure | IDOR: customer reads another customer's order | Order pages, `track-order.php` | Ownership check (to verify) | H | Authorization / RBAC | — |
| T13 | Information disclosure | Error pages reveal SQL, paths, stack traces | Whole app | `display_errors=0` in production | M | Input validation | C3 Error handling |
| T14 | Information disclosure | `.env`, `.git`, `database/`, `includes/` downloadable | Web root | `.htaccess` deny rules | H | (ffuf / ZAP) | — |
| T15 | Information disclosure | User enumeration through login / forgot-password messages | `account.php` | Generic messages (to verify) | L | Authentication | — |
| T16 | Information disclosure | Missing transport security (no HSTS), weak CSP | Headers | CSP, nosniff, X-Frame-Options; HSTS commented out | M | Session management | Header hardening |
| T17 | **D**enial of service | Huge uploads or huge inputs exhaust resources | Uploads, forms | PHP `upload_max_filesize` (to verify) | L | File upload, Input validation | Upload size cap |
| T18 | Denial of service | Lockout abused to lock real users out | Login | Per-email + per-IP limits | L | Authentication | C4 (time-limited lockout, admin unlock) |
| T19 | **E**levation of privilege | Customer reaches admin pages or admin APIs | `admin/*`, `admin/api/*`, `api/admin-products.php` | Role check + CSRF in `auth.php` and the admin API | H | Authorization / RBAC | C1 Logging |
| T20 | Elevation of privilege | Customer promotes self to admin via role-change POST | `admin/users.php` | Admin-only page, role whitelist | H | Authorization / RBAC | C1 Logging |
| T21 | Elevation of privilege | Open redirect after login used for phishing | Login `?redirect=` | `safe_redirect_path()` | L | Input validation | — |

## 5. Test priority

1. **High risk first:** T01, T05–T09, T12, T14, T19, T20 → Authentication, SQLi, Input validation, CSRF, XSS, RBAC.
2. **Then medium:** T03, T04, T10, T11, T13, T16 → Session, Logging, Error handling, Headers.
3. **Low:** T15, T17, T18, T21.

Items marked "to verify" become test cases. If a test fails, the threat becomes a finding in the Vulnerability Assessment Report.

## 6. Out of scope

- Third-party services themselves (Google, Paymob, Meta, Resend): only how our code uses them.
- Hosting infrastructure (Hostinger), network-level DoS, physical and social-engineering attacks.
- The live production site: never tested.
