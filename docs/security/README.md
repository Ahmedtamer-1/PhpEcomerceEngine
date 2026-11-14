# LUMEEGY – Security Submission

Course: Security in Software Engineering. Supervisors: Dr. Manar Hafaz, Eng. Nada Amr Shaheen.

All testing was performed **only on a local copy** of the site (Apache on localhost, seeded test database, test accounts). The live site was never scanned.

## Deliverables

| # | Deliverable | File |
|---|---|---|
| 1 | Updated website source code | repository `main` branch |
| 2 | Security implementation documentation | [implementation.md](implementation.md) |
| 3 | Security test cases and results | [test-cases.xlsx](test-cases.xlsx) |
| 4 | Vulnerability Assessment Report | [Vulnerability-Assessment-Report.pdf](Vulnerability-Assessment-Report.pdf) |
| 5 | Screenshots and security tool reports | [evidence/](evidence/) |
| 6 | Short presentation and live demonstration | Slides built from a slides prompt (presented on the day) + [demo-script.md](demo-script.md) (live demo, ~8 min, with rehearsal log) |

## Supporting material

| File | Purpose |
|---|---|
| [local-setup.md](local-setup.md) | How to run the local test copy (Apache, MySQL, seed, tools, ZAP settings) |
| [threat-model.md](threat-model.md) | STRIDE threat model: decides what is tested first |
| [test-plan.md](test-plan.md) | Parts 2+3: the 16 test cases (2 per category, each with a positive control), run order, and the Semgrep/ZAP "after" runs |
| [test-cases.md](test-cases.md) | Parts 2+3 **results**: the 16 executed tests with payload, expected/actual, pass/fail and evidence (16/16 pass). Source for `test-cases.xlsx` |
| [tools.md](tools.md) | Part 3: each tool (Semgrep, ZAP, Burp, sqlmap), version, what it tested, and its evidence |
| [part1-controls-spec.md](part1-controls-spec.md) | What Part 1 builds (controls C1–C4, hardening H1–H3, fixes S1–S3) and when each is done |
| [semgrep-lumeegy.yml](semgrep-lumeegy.yml) | Custom static-analysis rules for this codebase |
| [semgrep-triage.md](semgrep-triage.md) | Baseline Semgrep results, each marked true or false positive |
| [findings.md](findings.md) | All baseline findings in one list (Semgrep, ZAP, setup, manual code review GAP-01..03), each with severity and the control that fixes it. Further code-review notes N1–N6 are in [implementation.md](implementation.md) |
| [demo-script.md](demo-script.md) | Part 5: the rehearsable live-demo script, pre-demo checklist and rehearsal log |

## Evidence layout

| Folder | Contents |
|---|---|
| `evidence/zap/` | **`zap-before-summary.html` (start here: clean summary with our verdict per alert)**, `zap-before.html` (raw ZAP report), alert screenshot, `ajax-spider-before.csv` (the browser's own background requests to Bing/Azure were removed; they were not part of the test) |
| `evidence/burp/` | Repeater / Intruder / Proxy screenshots, named by test ID (`CSRF-02.png`), `csrf-poc.html` |
| `evidence/semgrep/` | `semgrep-before.txt` / `.sarif`, `semgrep-after.txt` / `.sarif` |
| `evidence/screenshots/` | Browser and admin-panel screenshots, named by test ID |
| `evidence/sqlmap/` | sqlmap output (SQLI-02) |
| `evidence/tests/` | Scripted request/response output per test ID (curl) |
| `evidence/*.txt` | Part 1 verification and smoke-test output per control |

No ZAP rescan was run after the fixes, so there is no `zap-after.html`. Part 3 is covered by the ZAP baseline, Burp, sqlmap and the Semgrep after-run; the header fixes are proven with direct `curl` output (`evidence/H1-headers-after.txt`).

## Methodology in one line

SAST (Semgrep + manual code review) → DAST (OWASP ZAP authenticated active scan) → manual testing (Burp Suite Community), following the OWASP WSTG checklist, with severity scored by CVSS 3.1 and a full rerun after fixes.
