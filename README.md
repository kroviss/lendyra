# Lendyra


Self-hosted loan management for micro-lenders, MFIs, pawnshops and
credit cooperatives. Built with Laravel 13, Livewire 3 and Tailwind CSS.

## What makes it different

- **Correct interest math** — flat, declining balance, annuity and
  interest-only/balloon methods; equal-periods or actual-365/360 accrual;
  month-end due dates that never drift; every schedule proven by a
  1,600-combination invariant test suite
- **Real payment engine** — configurable allocation waterfall
  (penalty → interest → principal, per product), partial payments,
  overpayment tracking, full audit trail per payment line
- **Idempotent penalties** — daily accrual with grace days, principal or
  installment base, and an optional cap; recomputing never double-charges
- **Any-date payoff quotes** — prorated or full-period interest, future
  interest always waived
- **PAR 30/60/90 reporting** — the industry-standard portfolio risk metric
- **Money is integer minor units everywhere** — no floating point in storage

## Features

Borrowers · Guarantors · Collateral registry (add/release) · Loan products
with per-product engine config · Live schedule preview while creating a
loan · Disbursement workflow · Payment recording with waterfall breakdown ·
Early payoff with live quote · Printable statements · Branch + role support
(admin / manager / loan_officer / cashier / accountant) · Dashboard ·
Portfolio report · Daily penalty cron · Web installer · Translatable —
English, French, Spanish and Portuguese included (`lang/*.json`)

## Requirements

- PHP 8.3+ (pdo_mysql, mbstring, openssl, ctype, curl, fileinfo, dom, xml, tokenizer)
- MySQL 8 / MariaDB 10.6+
- Any hosting that runs Laravel (shared hosting OK) — the app must be served
  from the domain root or a subdomain, not a URL subdirectory

## Installation

1. Upload the files, point your web root at `public/`
2. Visit `https://your-domain/install`
3. Follow the 3-step wizard: requirements → database → admin account
4. Set up the cron (penalties + scheduler):

```
* * * * * php /path/to/app/artisan schedule:run >> /dev/null 2>&1
```

Manual install and full docs: see `docs/INSTALL.md`.

## Tests

The distributed package ships a production (`--no-dev`) vendor directory, so
PHPUnit is not included. To run the suite, install dev dependencies first:

```bash
composer install          # with dev dependencies
./vendor/bin/phpunit
```

Note: `phpunit.xml` sets `DB_CONNECTION=mysql`, so feature tests run against
the MySQL database configured in your `.env`. Each test runs inside a
transaction that is rolled back, but do not point tests at a production
database.

The engine (`src/Engine`) is framework-agnostic and covered by golden-file
tests with hand-computed expected values.

## License

Commercial. One license per installation. See [LICENSE.md](LICENSE.md).
When purchased on CodeCanyon, Envato's standard Regular/Extended licenses
apply.
