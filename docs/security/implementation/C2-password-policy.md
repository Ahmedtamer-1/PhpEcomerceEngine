## C2 – Password Policy Enforcement

**Threat:** T02 (weak passwords). Finding: GAP-02 (8 characters, no other rules).

**Design**
- One function, `password_policy_errors($password, $email, $name)`, returns a list of readable problems (empty = OK). Every place that sets a password calls it, so the rules cannot drift apart.
- Rules: 10 to 128 characters; lowercase + uppercase + digit **or** a passphrase of 16+ characters; not one repeated character; not in `includes/common-passwords.txt` (2,659 lowercase entries of 10+ characters, compared in lowercase; `includes/` is blocked from the web by `.htaccess`); must not contain the full email, the email's local part (3+ characters) or the first name (3+ characters).
- The check is on the server. The HTML `minlength="10"` / `maxlength="128"` and the hint text are only a convenience. The raw-POST test below removes the browser from the picture.
- Google sign-in accounts keep their random password. There is no admin user-creation form in this codebase (admins only change roles), so there is nothing else to wire.
- Known limit: bcrypt only uses the first 72 bytes, so characters beyond that do not add strength (recorded under "New findings").

**Files and functions**
- `includes/functions.php:419` section header, `password_policy_errors()` at `:430`.
- `account.php:86` registration (replaces `strlen($pass) < 8`), `account.php:140` password reset (looks up the account's email and first name for the "no personal data" rules).
- `account.php:229-231` (register form) and `:261-263` (reset form): `minlength`, `maxlength`, `autocomplete="new-password"`, hint text.
- `includes/common-passwords.txt` (new).

**How to verify** (automated run, raw HTTP POSTs with no browser validation: `evidence/C2-verification.txt`)
1. Register with `password`, `Password1`, `aaaaaaaaaa`, the email itself, `Lumeegy2026`, a password containing the first name → each is rejected with a clear message.
2. Register with `Correct-Horse-7-Battery` and a 16+ character passphrase → accepted.
3. Reset a password with `password123` and with the email → rejected; `Maple-Stream-Quiet-9!` → accepted.
4. Remove `minlength` in the browser (or send a raw POST) → still rejected.

**Evidence**
- `screenshots/before-C2-weak-password.png` (before: `password1` was accepted)
- `screenshots/C2-weak-rejected.png` (after: same input rejected, `minlength` removed in the browser), `screenshots/C2-register-hint.png`, `screenshots/C2-strong-accepted.png`
- `C2-verification.txt`

**Result (measured):** all rejections and acceptances above happened as listed.
