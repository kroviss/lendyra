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
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force        # optional starter data; creates admin@example.com/password ONLY on an empty database — change it immediately
touch storage/app/installed.lock   # marks the app as installed
```

## Cron

Penalties accrue once a day via the scheduler. Add ONE system cron entry:

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

## Email (password reset)

"Forgot password" emails use Laravel's mail system. Set the `MAIL_*`
variables in `.env` (SMTP host, port, username, password, from address).
With the default `MAIL_MAILER=log`, reset links are written to
`storage/logs/laravel.log` instead of being sent — fine for testing,
not for production.

## Branch scoping (optional)

Set `LMS_BRANCH_SCOPING=true` to restrict loan officers and cashiers to
records of their own branch. Admins and managers always see everything.
Users without a branch are not restricted.

## Translation

Copy `lang/en.json` to `lang/<locale>.json`, translate the values, and set
`APP_LOCALE=<locale>` in `.env`.

## Upgrading

1. Back up your database and `.env`.
2. Replace application files (keep `.env`, `storage/`, `public/uploads` if any).
3. Run `php artisan migrate --force && php artisan optimize:clear`.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Redirected to `/install` after installing | `storage/app/installed.lock` missing — create it |
| 500 after moving servers | `php artisan optimize:clear`; check `storage/` permissions |
| Styles missing | Web root must be `public/`; check `public/build/` was uploaded |
| "Connection failed" in installer | Verify the DB user can connect from the web host (not just localhost) |
