# LUMEEGY

A fast, framework-free e-commerce storefront for the Egyptian market, built in plain **PHP 8.1+** and **MySQL**, with a full admin panel and security controls verified by testing.

![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql&logoColor=white)
![Apache](https://img.shields.io/badge/Apache-.htaccess-D22128?logo=apache&logoColor=white)
![Dependencies](https://img.shields.io/badge/dependencies-none-success)

## Highlights

- **Storefront**: catalogue with variants, live search, cart, checkout, customer accounts, order tracking, contact form.
- **Payments**: Paymob (card), InstaPay (manual transfer) and cash on delivery.
- **Admin panel**: orders, products and variants, media library (images and video), page builder, themes, shipping zones, users and roles, analytics, activity log and a Security Events dashboard.
- **Integrations**: Google sign-in, Meta Pixel + Conversions API, transactional email (PHPMailer or Resend).
- **No framework, no Composer**: every storefront page is a plain PHP file served directly by Apache.

## Tech stack

| Layer | Technology |
|---|---|
| Language | PHP 8.1+ (procedural) |
| Database | MySQL 8 via PDO (prepared statements only) |
| Web server | Apache 2.4 with `mod_rewrite` and `.htaccess` |
| Front end | Server-rendered HTML, vanilla JavaScript, CSS |
| Hosting | Hostinger (repository root = web root) |

## Project structure

```text
/                 storefront pages (each file is a public URL)
admin/            admin panel and admin JSON APIs
api/              storefront JSON endpoints and payment webhook
includes/         shared code: core functions, mail, payments, security log, error handler
config/           environment loading (.env) and database connection
assets/           CSS, JavaScript and uploaded media (scripts never execute here)
database/         schema, migrations and a local test seed
docs/security/    security assessment: controls, test cases, reports and evidence
```

`includes/`, `config/` and `database/` are blocked from the web by `.htaccess`.

## Getting started

1. Clone the repository and copy `.env.example` to `.env`; fill in the `DB_*` values and `SITE_URL`.
2. Create the database from `database/schema.sql`.
3. Serve the repository root with Apache (`mod_rewrite` enabled). `php -S localhost:8000` works for quick checks, but it ignores `.htaccess`.
4. Set `ENV=development` locally to see errors; keep `ENV=production` everywhere else.
5. Configure payments, InstaPay and the email sender in **Admin → Settings**.

For a local security-test copy with fake data, follow [docs/security/local-setup.md](docs/security/local-setup.md).

## Security

Security is part of the design, not an add-on:

| Area | Control |
|---|---|
| Injection | Every query uses prepared statements; dynamic sorting comes from a whitelist |
| Cross-site scripting | All output is escaped; a Content-Security-Policy is sent on every response |
| CSRF | Per-session tokens on every form and AJAX request |
| Access control | Role checks on every admin page and API; ownership checks on customer orders |
| Authentication | Strong password policy, progressive login delay, account lockout with admin unlock |
| Sessions | Strict mode, HttpOnly cookies, SameSite (Strict in the admin panel), Secure on HTTPS |
| Uploads | File type read from the content, random file names, size limits, no script execution |
| Errors | Generic error pages with a reference ID; details only in the server log |
| Monitoring | Security events (failed logins, lockouts, rejected requests and uploads) on an admin dashboard |
| Transport and headers | HSTS on HTTPS and a full set of security headers on every response, error pages included |

The full assessment is in [docs/security/](docs/security/README.md): implementation notes, a STRIDE threat model, 16 security test cases, tool reports and the Vulnerability Assessment Report.

## Development

- Code rules for contributors are in [CLAUDE.md](CLAUDE.md): prepared statements, escaped output, CSRF on every form, and the order and stock helpers.
- Before committing: `php -l` on every changed PHP file.
- Git: branches `<type>/<topic>` from `main`, Conventional Commits, merge commits only.
- Secrets live in `.env`, which is never committed.
