# CLAUDE.md

Guidance for Claude Code (and humans) working in this repo.

## What this is

LUMEEGY: a plain PHP 8.1+ / MySQL storefront for Egypt, with no framework and no Composer. It's hosted on Hostinger (Apache + `.htaccess`), and the repo root is the web root.

- Payments: Paymob (card), InstaPay (manual transfer), cash on delivery.
- Google sign-in, Meta Pixel + Conversions API, email via bundled PHPMailer or Resend.

## Layout

| Path | What lives there |
|---|---|
| `*.php` (root) | Storefront pages; each file is a public URL (`/shop.php`). Don't move them. |
| `admin/` | Admin panel; every page starts with `require 'includes/auth.php'` (admin-only + CSRF) |
| `admin/api/`, `api/` | JSON endpoints. Admin endpoints check `role === 'admin'` + `admin_enforce_csrf()` |
| `includes/` | Shared code: `functions.php` (core), `mailer.php`, `paymob.php`, `analytics.php`, `render-sections.php`, `password-reset.php`, email templates |
| `config/` | `config.php` reads `.env` into constants; `database.php` gives `db()` (PDO singleton) |
| `assets/` | Storefront CSS/JS and uploaded images. Scripts can't execute here (`assets/.htaccess`) |
| `database/` | Hand-run SQL migrations (there is no full schema file yet) |
| `docs/local/` | Local-only notes and plans, git-ignored. Never commit them |

`includes/`, `config/`, `database/` are blocked from the web in `.htaccess`.

## Running locally

- Copy `.env.example` to `.env` and fill in `DB_*`. Set `ENV=development` to see errors.
- Serve the repo root with Apache (needs `mod_rewrite`) or `php -S localhost:8000`. Note that `.htaccess` rules don't apply under `php -S`.
- Some tables and columns are created on first use (`remember_tokens`, `password_resets`, analytics columns), so the DB user needs `CREATE`/`ALTER`.
- No test suite. Minimum check before committing: `php -l` on every changed `.php` file.

## Code rules

- **SQL**: always prepared statements via `db()`. Never interpolate request data. A dynamic `ORDER BY` must come from a whitelist.
- **Output**: escape with `h()` in HTML (`htmlspecialchars` in email templates).
- **Forms**: storefront POSTs check `csrf_verify($_POST['csrf'])`; admin pages get CSRF from `admin/includes/auth.php`.
- **Redirects**: `redirect()` with a site path; user-supplied targets go through `safe_redirect_path()`.
- **Orders/stock**: use `create_order()`, `set_order_status()`, `cancel_order()`, `delete_order()`. They lock the order row and keep variant stock and the `products.stock` roll-up consistent. Never `UPDATE orders SET status` or touch stock directly.
- **Settings**: read with `setting('key')`. Paymob credentials go through `paymob_setting()` (Settings first, then `.env`).
- **InstaPay**: no hardcoded fallback. It's only offered when `instapay_configured()`.
- Match the surrounding style: procedural functions, short "why" comments, no new dependencies.

## Git workflow

- Branch `<type>/<kebab-topic>` from `main`. Conventional Commits, subject ≤72 chars.
- One commit per file, or up to 4 files when it's one identical change. Stage by path, never `git add .`.
- **No AI attribution** anywhere: no `Co-Authored-By`, no "Generated with".
- Merge commits only; branches are never deleted.
- `C:\Users\DELL` is itself a git repo, so always run git from inside this folder (or use `git -C <path>`).

## Deploy notes

- Production `.env` must set `SITE_URL` (otherwise the Host header is used, with only basic checks).
- Before relying on InstaPay or password reset, fill in Settings → Payment (InstaPay) and the email sender settings.
- Paymob webhook: `api/paymob_callback.php` (the old root `/paymob_callback.php` is rewritten to it).
