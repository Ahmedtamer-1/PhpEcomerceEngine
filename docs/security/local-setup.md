# Local Test Environment

How to run LUMEEGY locally for the security assessment. **All testing happens on this local copy.** Never scan or attack the live site.

## 1. Prerequisites

| Tool | Version used | Notes |
|---|---|---|
| Apache 2.4 + PHP 8.1+ | Apache 2.4.63, PHP 8.3 | `mod_rewrite` and `mod_headers` on; PHP `pdo_mysql`, `fileinfo`, `curl`, `mbstring`, `openssl`, `gd` on |
| MySQL 8 (or MariaDB 10.4+) | MySQL 8.0.42 | |
| Semgrep | 1.185 | `pip install semgrep`; on Windows set `PYTHONUTF8=1` |
| OWASP ZAP | 2.17 | Install the **Active scanner rules** add-ons from the Marketplace |
| Burp Suite Community | latest | |
| sqlmap (optional) | 1.10 | `pip install sqlmap` |

Ports used: Apache (other projects) 8080 · **test site 8090** · **ZAP proxy 8084** · **Burp proxy 8082**.

`php -S` works for quick checks, but it ignores `.htaccess`, so security headers and deny rules are missing. Use Apache for any security test.

## 2. Apache

In `httpd.conf` (back it up first):

1. Uncomment `LoadModule headers_module modules/mod_headers.so` (and `mod_rewrite` if needed).
2. Add a site on its own port, pointing at your clone of the repo:

```apache
Listen 8090
<VirtualHost *:8090>
    ServerName localhost
    DocumentRoot "C:/path/to/PhpEcomerceEngine"
    <Directory "C:/path/to/PhpEcomerceEngine">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require local
    </Directory>
    ErrorLog "logs/lumeegy-error.log"
    CustomLog "logs/lumeegy-access.log" common
</VirtualHost>
```

3. In `php.ini`, make sure `extension=fileinfo` is not commented out.
4. Check the config with `httpd -t`, then restart Apache.

## 3. Database

Create a database and a dedicated user (pick your own local password):

```sql
CREATE DATABASE IF NOT EXISTS lumeegy_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'lumeegy_test'@'localhost' IDENTIFIED BY 'choose-a-local-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP ON lumeegy_test.* TO 'lumeegy_test'@'localhost';
FLUSH PRIVILEGES;
```

The site creates some tables and columns on first use, so the user needs `CREATE` and `ALTER`. `DROP` is needed because the seed uses `TRUNCATE`.

**Forgot the MySQL root password?** Put the SQL above in a file with one statement per line and no comments, stop the MySQL service, start it once with `mysqld --defaults-file="<path>\my.ini" --init-file="<path>/init.sql" --console`, wait for `ready for connections`, stop it with Ctrl+C, then start the service again. Root's password is not changed.

Load the schema and the fake test data (PowerShell, from the repo root):

```powershell
$mysql = "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe"
& $mysql -u lumeegy_test -p --default-character-set=utf8mb4 lumeegy_test -e "source database/schema.sql"
& $mysql -u lumeegy_test -p --default-character-set=utf8mb4 lumeegy_test -e "source database/seed-security-test.sql"
```

Re-running the seed resets users, products, orders and logs to the known state. Do it before each test run and before the demo.

## 4. `.env`

Copy `.env.example` to `.env` and set:

```ini
ENV=production          # use development only while debugging
SITE_URL=http://localhost:8090
DB_NAME=lumeegy_test
DB_USER=lumeegy_test
DB_PASS=choose-a-local-password
```

Leave the Paymob, Google, Meta and email keys empty: the local copy then cannot charge cards or send email. Use **Cash on delivery** when testing checkout.

## 5. Test accounts

Listed at the top of [database/seed-security-test.sql](../../database/seed-security-test.sql): one admin, customer A and customer B, each with one order (used for IDOR tests). These are fake local-only credentials.

## 6. Smoke test

Open `http://localhost:8090/`, the shop, a product, the cart and `account.php`, then log in as admin at `/admin/login.php` and open a few admin pages. With `ENV=development` any SQL or PHP error is shown on the page; with `production` check `logs/lumeegy-error.log`.

## 7. Static analysis

```bash
PYTHONUTF8=1 semgrep scan --metrics=off --config p/php --config p/owasp-top-ten --config p/xss --config docs/security/semgrep-lumeegy.yml --exclude includes/vendor --exclude assets --exclude docs --exclude database --text-output=docs/security/evidence/semgrep/semgrep-after.txt --sarif-output=docs/security/evidence/semgrep/semgrep-after.sarif --json-output=docs/security/evidence/semgrep/semgrep-after.json
```

## 8. ZAP scan settings

- **Protected Mode** on; context `Default Context` includes `http://localhost:8090.*` only.
- Exclude from Context, Spider and Scanner: logout (`.*action=logout.*`), `.*action=delete.*`, `.*/admin/users\.php.*`, `.*/api/paymob_callback\.php.*`, `.*/api/(track|pv)\.php.*`, `.*/google-callback\.php.*`.
- HTTP Sessions → add `lumeegy_session` and `adn_remember` as session tokens; log in as admin at `/admin/login.php` and set that session active before spidering and scanning.
- Admin forms get their CSRF token from a script at runtime, so the scanner's admin POSTs are rejected with 403. That's expected; admin form inputs are tested manually in Burp.
