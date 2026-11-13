# Security Tools Used (Part 3)

The brief requires at least one tool with screenshots and reports. This project used four, across the three testing layers (SAST → DAST → manual), all against a local copy only (`http://localhost:8090`).

| Tool | Version | Layer | Used for | Tests it covered | Evidence |
|---|---|---|---|---|---|
| **Semgrep** | 1.185 | SAST (static) | Scan PHP source for SQL built from strings, missing output escaping, missing CSRF, admin pages without `auth.php`, unsafe uploads. Custom ruleset `semgrep-lumeegy.yml` (12 rules) + `p/php`, `p/owasp-top-ten`, `p/xss`. Before Part 1 and after. | Found SAST-01/02/03 (all fixed in Part 1); supports SQLI-*, XSS-*, CSRF-*, UPL-* | `evidence/semgrep/semgrep-before.*`, `semgrep-after.*`; [semgrep-triage.md](semgrep-triage.md) |
| **OWASP ZAP** | 2.17 | DAST (dynamic) | Authenticated crawl + active scan of the running site; passive header/cookie checks. Baseline report. | Headers (ZAP-01..06), CSP, cookie flags; broad coverage of all pages | `evidence/zap/zap-before.html`, `zap-before-summary.html`, `zap-before-alerts.png` |
| **Burp Suite** | Community 2026.10.1 | Manual | Intruder (brute force), Proxy intercept (upload tampering), Repeater (request replay). | AUTH-01 (lockout), UPL-01 (upload bypass); Repeater used across SQLI/XSS/RBAC/INP/CSRF | `evidence/burp/AUTH-01*.png`, `UPL-01*.png`, `csrf-poc.html` |
| **sqlmap** | 1.10.11 | Manual (SQLi) | Automated SQL-injection probing of two parameters. | SQLI-02 | `evidence/sqlmap/SQLI-02.txt` |
| curl | 8.14 | Manual (scripting) | Scripted the repeatable request tests exactly as a Repeater user would send them. | Most of the 16 cases | `evidence/tests/*.txt` |

## Method

Three layers, each cross-checking the others:

1. **SAST — Semgrep + manual code review.** Reads the source without running it. Run before Part 1 (baseline) and after (to show the fixes). 201 raw results triaged to 9 true positives at baseline; all static true positives closed after Part 1.
2. **DAST — OWASP ZAP.** Authenticated active scan of the running site (guest / customer / admin contexts, Protected Mode, logout and callback URLs excluded). The baseline active scan was stopped early for time; passive checks covered every crawled page. Result: 0 High, 7 Medium, 6 Low, 16 Info — all triaged (fixed / false positive / accepted) in [findings.md](findings.md).
3. **Manual — Burp + sqlmap + curl.** The attacks automation can't judge: IDOR, RBAC bypass, lockout, CSRF, upload tricks, business-logic tampering. These are the 16 cases in [test-cases.md](test-cases.md).

Severity for confirmed findings is scored with **CVSS 3.1** and mapped to **OWASP WSTG** in the report.

## Notes on the two automated scans

- **Both ZAP runs were time-boxed** (the baseline active scan was stopped at 23%). To cover what the scanner didn't reach, **SQL injection and XSS were verified manually** (SQLI-01/02, XSS-01/02) with Burp, sqlmap and curl.
- **Burp Community limits** we worked around: Intruder is rate-limited (fine — we only needed enough attempts to trigger the lockout); there is no active scanner (that role is ZAP's); the CSRF PoC generator is Pro-only (the attacker page was hand-written, `evidence/burp/csrf-poc.html`).
