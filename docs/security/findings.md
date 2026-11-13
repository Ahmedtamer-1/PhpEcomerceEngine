# Findings Register (baseline, before fixes)

One list of every finding from the baseline: static analysis (Semgrep + code review), dynamic scanning (OWASP ZAP), and setup checks. Each one is marked **TP** (real finding) or **FP** (false positive, with the reason). Severity is provisional until each finding is confirmed by a Part 2 test and scored with CVSS 3.1 in the report.

Sources:
- Semgrep: [semgrep-triage.md](semgrep-triage.md), raw output in `evidence/semgrep/`.
- ZAP 2.17: [evidence/zap/zap-before.html](evidence/zap/zap-before.html) (site `http://localhost:8090` only), screenshot `evidence/zap/zap-before-alerts.png`. Spider + AJAX Spider + Active Scan logged in as admin, Protected Mode. **The active scan was stopped at 23% (47,820 requests) for time**; the passive checks covered every crawled page. The attack categories the scan didn't reach (SQLi, XSS) are tested manually in Part 2 (Burp + sqlmap).

ZAP baseline totals (localhost only): **0 High · 7 Medium · 6 Low · 16 Informational** alert types.

## Real findings (TP)

| ID | Finding | Source | Severity (prov.) | Fixed by |
|---|---|---|---|---|
| CFG-04 / ZAP-01 | CSP allows `'unsafe-inline'` scripts and styles, has wildcard sources and no `form-action`-style fallback for some directives | ZAP (CSP ×4 alerts), `.htaccess:54` | Medium | Residual risk (needs nonces; out of scope) |
| ZAP-02 | Some responses have no CSP / security headers at all: Apache error pages (403/404) and some non-HTML responses, because `.htaccess` uses `Header set` instead of `Header always set` | ZAP "CSP Header Not Set", "Permissions Policy Header Not Set" | Medium | H1 (use `Header always set`) |
| ZAP-03 | Admin pages load Chart.js from jsDelivr without Subresource Integrity (`admin/includes/header.php:30`) | ZAP "Sub Resource Integrity Attribute Missing", "Cross-Domain JavaScript Source File Inclusion" | Medium | H1 (add `integrity="sha384-…" crossorigin="anonymous"`) |
| CFG-01 / ZAP-04 | `X-Powered-By: PHP/8.3.21` and `Server: Apache/2.4.63 (Win64) PHP/8.3.21` reveal exact versions | ZAP (2 alerts), `curl -I` | Low | H1 (`Header always unset X-Powered-By`; `ServerTokens Prod` in Apache, documented as a hosting setting) |
| CFG-03 | No HSTS header | `.htaccess:52` (commented out) | Low (Medium on HTTPS production) | H1 |
| CFG-05 | Deprecated `X-XSS-Protection` header still sent | `.htaccess:48` | Info | H1 |
| ZAP-05 | Email address and order number travel in the URL query string (`track-order.php?email=…&order_number=…`), so they end up in browser history, server logs and Referer headers | ZAP "Sensitive Information in URL" | Low | Recommendation: switch the track-order form to POST (optional, not in Part 1 scope) |
| ZAP-06 | Missing `Cross-Origin-Embedder-Policy` header | ZAP | Low | Accepted: COEP would break the Paymob iframe and YouTube embeds; documented as an accepted risk |
| CFG-02 | `session.use_strict_mode = 0`: the server accepts session IDs it never issued (helps session fixation) | `php.ini` | Medium | H2 (`ini_set` in `lume_session_start()`) |
| SAST-01 | Raw database exception text shown on 6 admin screens | Semgrep, `admin/pages.php:35` and 5 more | Low | C3 / S1 |
| SAST-02 | Order status changed without `set_order_status()` | Semgrep, `admin/order-new.php:86`, `checkout.php:260` | Low | S2 |
| SAST-03 | `db()->quote()` used instead of a placeholder | Semgrep, `admin/abandoned.php:46` | Info | S3 |
| GAP-01 | No security event logging (attacks leave no trace) | Code review | Medium | C1 |
| GAP-02 | Weak password policy (8 characters, no other rules) | Code review, `account.php:81,131` | Medium | C2 |
| GAP-03 | Lockout message is fixed ("15 minutes"), lockouts aren't logged, admins can't unlock | Code review | Low | C4 |

## False positives (FP)

| Alert / result | Count | Reason |
|---|---|---|
| ZAP "Absence of Anti-CSRF Tokens" | 3 alert types | The flagged forms are admin forms, whose token is added at runtime by `admin/includes/csrf-script.php` from `<meta name="csrf-token">`, so ZAP's HTML parser can't see it. Proof that the protection works: every scanner POST to an admin form without the token was rejected with **403** (1,131 times in the access log). |
| ZAP "Timestamp Disclosure – Unix" | 5 | The numbers are cache-busting asset versions (`?v=1791899996`), not server times. |
| ZAP "User Controllable HTML Element Attribute (Potential XSS)" | 83 | Informational: request values echoed into attributes. The values are escaped with `h()`; confirmed manually in Part 2 (XSS-01). |
| ZAP "Information Disclosure – Suspicious Comments" | 97 | Words like "TODO"/"admin" in HTML and JS comments; no secrets found when reviewed. |
| Semgrep results (192) | 192 | See [semgrep-triage.md](semgrep-triage.md): output already escaped with `h()`, numeric IDs, SQL whose structure uses placeholders and whitelists. |

## Not reached by automation (covered in Part 2)

SQL injection, reflected and stored XSS, IDOR, CSRF from another origin, session fixation, upload bypass, brute force. Each has its own test case in Part 2.
