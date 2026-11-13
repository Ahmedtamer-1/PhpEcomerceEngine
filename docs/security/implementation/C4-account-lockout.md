## C4 – Login Rate Limiting / Account Lockout

**Threat:** T01 (brute force), T18 (lockout abuse). Finding: GAP-03 (fixed "15 minutes" message, lockouts not logged, admins cannot unlock).

**Design**
- The existing throttle stays: 5 attempts per email + address, 20 per address, 50 per email, in a 15-minute window (`login_throttle_keys()`). Because the tightest key includes the address, an attacker cannot lock the real owner out from another address (T18).
- `login_lockout_remaining($email)` returns the seconds until the lock lifts: a key is locked when it holds `max` attempts in the window and opens again as the oldest ones age out. `login_lockout_message()` turns it into "Too many attempts. Try again in N minutes." on both login pages.
- Progressive delay: from the 3rd failure for the same email + address, `login_failed()` sleeps 0.5 s × (failures − 2), capped at 3 s, before answering.
- `login_locked` (critical) is logged **once**, in `login_failed()`, when a key reaches its limit, not on every blocked request, so the log is not flooded. The text says which limit was hit. `login_failed` is logged first, so the order in the log is: failed ×5, then locked.
- Admin unlock: a **Sign-in Lock** card on the user page (`admin/users.php?id=…`) with an **Unlock account** button (POST; CSRF comes from the admin guard). `login_unlock()` deletes the account counter and the email+address counters for every address seen in the last day, writes `unlock_account` to the Activity Log and `account_unlocked` to Security Events. The address-wide counter is not cleared (it protects against password spraying).
- The answer for a wrong password is still the same "Invalid email or password." for known and unknown emails (no enumeration).
- Not done (optional in the spec): the "someone tried to sign in" email to the owner. Email sending is not configured in the test setup, so it could not be tested.

**Files and functions**
- `includes/functions.php`: `login_attempt_count()` :284, `login_lockout_remaining()` :296, `login_lockout_message()` :313, `login_failed()` :328 (delay and one-time lock log), `login_unlock()` :348.
- `account.php:51`, `admin/login.php:23` (message), and the order of `security_log('login_failed')` before `login_failed()` in both.
- `admin/users.php:50` (unlock handler), `:171` (button).

**How to verify** (`evidence/C4-verification.txt`, run on Apache at `:8090`)
1. 5 wrong passwords from one address: response times 1103, 229, 752, 1362, 1802 ms (attempt 1 includes a cold start); the 6th attempt with the **right** password is refused with "Too many attempts. Try again in 15 minutes."
2. The same account from another address (`127.0.0.1` instead of `::1`) with the right password → HTTP 302 (signed in), and a `login_success` row.
3. Admin opens the user page, presses Unlock → "Sign-in lock cleared."; the owner can sign in from the original address right away. Security Events has `account_unlocked`, Activity Log has `unlock_account` (5 records cleared).
4. Known email + wrong password and unknown email + wrong password give identical text.
5. Security Events: 5 × `login_failed`, then 1 × `login_locked` (details: "limit of 5 reached for this email from this address"), no further rows for blocked requests.

**Evidence**
- `screenshots/before-C4-lockout-message.png` (before: fixed "Please wait 15 minutes")
- `screenshots/C4-lockout-message.png`, `screenshots/C4-unlock-button.png`, `screenshots/C4-unlocked.png`
- `C4-verification.txt`

**Result (measured):** all five checks passed. A response-time column in Burp Intruder would show the same growth as the table in check 1.

**Note (existing behaviour, not changed):** a successful login clears the email's account-wide counter for every address, so one valid login from address B resets the counter that guards against many-address guessing. The tight email + address key is unaffected.
