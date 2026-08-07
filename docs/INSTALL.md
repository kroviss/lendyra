# Installation Guide

## A. Web installer (recommended)

1. Create a MySQL database and a user with full privileges on it.
2. Upload the application files to your server.
3. Point your web root (document root) at the `public/` directory.
   - Apache: the included `.htaccess` works out of the box.
   - Nginx: standard Laravel config (`try_files $uri $uri/ /index.php?$query_string;`).
4. Make `storage/` and `bootstrap/cache/` writable by the web server.
5. Open `https://your-domain/install` and follow the wizard:
   - **Step 1** checks PHP version, extensions and writable directories.
   - **Step 2** asks for database credentials, tests the connection and runs migrations. An optional table prefix lets you share a database with other apps.
   - **Step 3** creates your admin account and finishes.
6. Log in at `/login`.

## B. Manual install (CLI)

```bash
cp .env.example .env
# edit .env: APP_URL, DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD, optional DB_PREFIX
# (.env.example already ships APP_ENV=production and APP_DEBUG=false — keep
#  them that way on a live server; set SESSION_SECURE_COOKIE=true when
#  serving over HTTPS)
php artisan key:generate
php artisan migrate --force
php artisan storage:link           # makes uploaded photos publicly visible (public/storage → storage/app/public)
php artisan db:seed --force        # optional starter data; creates admin@example.com/password ONLY on an empty database — change it immediately
touch storage/app/installed.lock   # marks the app as installed
```

If `php artisan storage:link` fails because `symlink()` is disabled on your
host, create the link from your hosting control panel (a symlink named
`public/storage` pointing at `storage/app/public`). Everything else works
without it — only uploaded photos need the link to display.

## Fixed `public_html` document root (cPanel / shared hosting)

If you cannot point the document root at `public/` (common on cPanel),
use either of these:

**Option 1 (preferred):** upload the app OUTSIDE `public_html`
(e.g. `~/lendyra`), then replace `public_html` with a symlink:
`ln -s ~/lendyra/public ~/public_html` (or set the domain's document root
to `~/lendyra/public` in cPanel → Domains).

**Option 2:** upload the app to a private folder (e.g. `~/lendyra`), move
the CONTENTS of `~/lendyra/public/` into `public_html/`, then edit
`public_html/index.php` and change the three `__DIR__.'/../` paths so they
point at the app folder:

```php
if (file_exists($maintenance = __DIR__.'/../lendyra/storage/framework/maintenance.php')) {
require __DIR__.'/../lendyra/vendor/autoload.php';
$app = require_once __DIR__.'/../lendyra/bootstrap/app.php';
```

(Adjust `/../lendyra` to wherever you placed the app relative to
`public_html`.) The `storage:link` symlink must then point from
`public_html/storage` to `~/lendyra/storage/app/public`.

Either way the app must answer at the **domain root or a subdomain**
(`https://loans.example.com`). Serving it from a URL subdirectory
(`https://example.com/lendyra/`) is not supported — the installer writes
`APP_URL` without a path.

## Timezone

The installer asks for your timezone and writes it to `APP_TIMEZONE` in
`.env` (default `UTC`). Due dates, "overdue" day boundaries, penalty
accrual (00:30) and SMS reminders (09:00) all run in **this app timezone**,
not the server's. If you skipped it or need to change it later, edit
`APP_TIMEZONE` in `.env` — use a PHP timezone identifier such as
`Africa/Nairobi` or `Asia/Manila`.

## Cron

The scheduler drives daily penalty accrual, SMS payment reminders and (only
if demo mode is enabled) the nightly demo reset. Add ONE system cron entry:

```
* * * * * php /path/to/app/artisan schedule:run >> /dev/null 2>&1
```

You can also run it manually or backfill a date:

```bash
php artisan loans:accrue-penalties
php artisan loans:accrue-penalties --date=2026-07-01
```

Accrual is idempotent — running it repeatedly for the same date never
double-charges.

## SMS payment reminders

The scheduler runs `loans:send-reminders` daily at 09:00 in the app
timezone (`APP_TIMEZONE`, see the Timezone section above). It
sends two kinds of messages, deduplicated through the `sms_logs` table so a
borrower is never texted twice for the same installment on the same basis:

- **Upcoming** — installment due in exactly N days (N below, default 3)
- **Overdue** — unpaid installment past its due date, one notice per week

Configure in `.env`:

```
LMS_SMS_DRIVER=log        # log | http
LMS_SMS_HTTP_URL=         # required for the http driver
LMS_SMS_HTTP_TOKEN=       # optional bearer token
LMS_SMS_REMINDER_DAYS=3   # days before the due date for the "upcoming" reminder
```

- `log` (default) writes each message to `storage/logs/laravel.log` — safe
  for testing, nothing is actually sent. Note the log then contains borrower
  phone numbers; switch to the `http` driver (or protect the log directory)
  before production use.
- `http` POSTs JSON `{"to": "<phone>", "message": "<text>"}` to
  `LMS_SMS_HTTP_URL` (10-second timeout). If `LMS_SMS_HTTP_TOKEN` is set it
  is sent as an `Authorization: Bearer` header. Any 2xx response counts as
  sent; anything else (or a connection error) is recorded as failed. This
  generic contract adapts to almost any local SMS gateway or aggregator —
  point the URL at your gateway or at a small relay script that reformats
  the payload.

Every attempt (sent or failed) is visible in the app under **SMS Logs**
(`/sms-logs`, admin and manager roles). You can also run or backfill
manually: `php artisan loans:send-reminders [--date=YYYY-MM-DD]`.

## Demo mode

`LMS_DEMO_MODE=true` blocks destructive account changes (user, profile,
product and branch edits) and schedules a nightly `demo:reset` at 03:00
that wipes all business data and reseeds the demo dataset. It exists for
running a public demo — never enable it in production.

## Email (password reset)

"Forgot password" emails use Laravel's mail system. Set the `MAIL_*`
variables in `.env` (SMTP host, port, username, password, from address).

While the mailer is still the default `MAIL_MAILER=log`, the
"Forgot your password?" link is hidden and no reset tokens are minted —
a reset link in a log file is a plaintext account-takeover credential for
anyone who can read it. Configure real mail to enable self-service resets;
until then an admin can change passwords from the Users page.

## Running behind a reverse proxy / load balancer

By default the app trusts no proxies: `X-Forwarded-*` headers from clients
are ignored, so nobody can spoof their IP address to dodge the login rate
limiter. If you run behind nginx, a load balancer, or a CDN, set
`TRUSTED_PROXIES` in `.env` to a comma-separated list of your proxy IPs
(for example `TRUSTED_PROXIES=127.0.0.1` for a local nginx, or
`TRUSTED_PROXIES=*` if the app is only reachable through the proxy).

## Branch scoping (optional)

Set `LMS_BRANCH_SCOPING=true` to restrict loan officers, cashiers and
accountants to records of their own branch. Admins and managers always see
everything.

Scoping fails **closed**: a scoped account left without a branch sees
nothing rather than everything, and the user form requires a branch for
those roles while scoping is on. Records that themselves carry no branch
(created before scoping was switched on, or by a branchless admin) stay
visible to everyone. The trial balance is org-wide and is not offered to
scoped accounts at all.

## Payment backdating window

```
LMS_PAYMENT_BACKDATE_DAYS=7
```

Dating a payment on or before an overdue installment's due date erases the
penalty that had accrued on it — a waiver in disguise. Users **without**
the write-off/waiver privilege (cashiers, loan officers) can therefore only
backdate a payment this many days. Admins and managers may still backdate
all the way to the disbursement date.

## Translation

English, French, Spanish and Portuguese ship in the box. `APP_LOCALE` in
`.env` sets the default; each user can also switch languages from the
selector in the top-right corner (stored per session). For any other
language, copy `lang/en.json` to `lang/<locale>.json` and translate the
values — it appears in the selector automatically.

## Backups

Everything that matters lives in two places:

- **The database** — schedule a daily dump, e.g.
  `mysqldump -u USER -p DBNAME | gzip > backup-$(date +%F).sql.gz`
  (or use your hosting panel's backup tool).
- **`storage/app/private/`** — borrower and collateral photos.

Also keep a copy of your `.env` (it holds the APP_KEY; without it,
encrypted values such as remembered sessions are lost). Restoring is:
fresh files → restore `.env` → restore DB → restore photos →
`php artisan optimize:clear`.

## Upgrading

Your installed version is shown at the bottom of the sidebar (e.g. v1.0.2);
changes per release are in `CHANGELOG.md`.

1. Back up your database and `.env`.
2. Replace application files (keep `.env`, `storage/`, `public/uploads` if any).
3. Run `php artisan migrate --force && php artisan optimize:clear`.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Redirected to `/install` after installing | `storage/app/installed.lock` missing — create it |
| 500 after moving servers | `php artisan optimize:clear`; check `storage/` permissions |
| Styles missing | Web root must be `public/`; check `public/build/` was uploaded |
| Uploaded photos don't display | `public/storage` link missing — run `php artisan storage:link` or create the symlink manually (see above) |
| "Connection failed" in installer | Verify the DB user can connect from the web host (not just localhost) |
