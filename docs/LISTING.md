# CodeCanyon Listing Materials

> Internal — excluded from the distributable zip. Paste-ready material for the
> Envato submission form.

## Item title (max ~100 chars)

**Lendyra — Loan Management System for Micro-lenders & MFIs (Laravel, Self-hosted)**

Alternatives:
- Lendyra — Microfinance & Loan Management System with Double-Entry Ledger (PHP/Laravel)
- Lendyra — Lending Management: Schedules, Penalties, PAR Reports (Self-hosted PHP)

## Category / attributes

- Category: PHP Scripts → Project Management Tools (or PHP Scripts → Miscellaneous — check where ViserBank/competitors sit: usually *PHP Scripts → BackOffice*)
- High resolution: Yes · Compatible browsers: all · Software version: PHP 8.3+, MySQL 8/MariaDB 10.6
- Framework: Laravel 13, Livewire 3, Tailwind CSS 4

## Tags (max 15)

loan management, microfinance, lending, mfi, loan, installment, amortization,
emi, penalty, ledger, accounting, borrower, laravel, livewire, self hosted

## Price

- Regular: **$69** (competitors: ULM $170 with 3.06/5 rating, ViserBank $99; we undercut with better math)
- Extended: **$299**

## Short description (the one-liner under the title)

Self-hosted loan management for micro-lenders, MFIs, pawnshops and credit
cooperatives — correct interest math (flat / declining / annuity / balloon),
double-entry ledger, PAR 30/60/90, penalties & waivers, any-date payoff
quotes, SMS reminders. EN/FR/ES/PT included.

## Item description (HTML — paste into Envato editor)

```html
<h2>Loan management software that gets the math right</h2>

<p><strong>Lendyra</strong> is a self-hosted lending back office for
micro-lenders, MFIs, pawnshops and credit cooperatives. Most loan scripts
demo well and then miscalculate interest in month three — Lendyra was built
engine-first: schedules, penalties and payoff quotes come from a
deterministic calculation engine covered by <strong>146 automated tests and
2,100+ assertions</strong>, including golden rows verified by hand.</p>

<p>&#128279; <strong>Live demo:</strong> https://demo.lendyra.dev —
login <code>admin@example.com</code> / <code>password</code> (resets nightly)</p>

<h3>Interest engine</h3>
<ul>
<li>Four methods: <strong>flat</strong>, <strong>declining balance</strong> (equal principal), <strong>annuity</strong> (equal installments), <strong>interest-only + balloon</strong></li>
<li>Equal-period or <strong>actual/365 · actual/360</strong> day-count accrual</li>
<li>Weekly, biweekly and monthly frequencies; month-end due dates that never drift</li>
<li>Live schedule preview while creating a loan</li>
<li>All money stored as <strong>integer minor units</strong> — no floating point, any currency, any decimal places</li>
</ul>

<h3>Payments &amp; collections</h3>
<ul>
<li>Configurable allocation <strong>waterfall</strong> (penalty → interest → principal) per product</li>
<li>Partial payments, overpayment credit tracking, printable receipts</li>
<li>Full <strong>payment reversal</strong> with mirrored ledger entries</li>
<li><strong>Any-date payoff quotes</strong> — prorated or full-period, future interest always waived</li>
<li>Daily <strong>penalty accrual</strong> with grace days, base choice and cap — idempotent, never double-charges; waivers with audit trail</li>
<li><strong>SMS reminders</strong> (upcoming + overdue) with a generic HTTP driver for any local gateway</li>
</ul>

<h3>Accounting &amp; reporting</h3>
<ul>
<li>True <strong>double-entry ledger</strong>: disbursements, fees, payments, reversals, write-offs</li>
<li><strong>Trial balance</strong> per currency</li>
<li><strong>PAR 30/60/90</strong> — the industry-standard portfolio-at-risk report</li>
<li>Collections route sheet (today / week / month / overdue) with CSV export</li>
<li>Printable loan statements and payment receipts</li>
</ul>

<h3>Control &amp; security</h3>
<ul>
<li><strong>Maker-checker</strong> approvals: officers originate, managers approve &amp; disburse; reject-with-reason and resubmission</li>
<li>Five roles (admin / manager / loan officer / cashier / accountant), enforced server-side on every action</li>
<li>Optional <strong>branch scoping</strong> — staff see only their branch</li>
<li>Borrower &amp; collateral photos stored privately and streamed only to authorized staff</li>
<li>Login throttling, session hardening, audit line on every loan</li>
</ul>

<h3>Easy to install &amp; own</h3>
<ul>
<li><strong>3-step web installer</strong>: requirements → database → admin. No SSH needed.</li>
<li>Runs on ordinary <strong>shared hosting</strong>: PHP 8.3+, MySQL/MariaDB, one cron line. No Node, Redis or queue workers.</li>
<li>Unencrypted source code (Laravel 13 + Livewire 3 + Tailwind), documented schema, runnable test suite</li>
<li><strong>English, French, Spanish and Portuguese</strong> included; single JSON file per language</li>
<li>Version shown in-app, CHANGELOG and upgrade guide included</li>
</ul>

<h3>Requirements</h3>
<p>PHP 8.3+ (pdo_mysql, mbstring, openssl, ctype, curl, fileinfo, dom, xml,
tokenizer) · MySQL 8 / MariaDB 10.6+ · domain root or subdomain</p>

<h3>What's NOT included (roadmap)</h3>
<p>Borrower self-service portal, savings module and online payment gateways
are not in v1 — they are prioritized on the roadmap based on buyer demand.
Lendyra v1 focuses on being the lending back office that calculates
correctly.</p>

<h3>Support</h3>
<p>6 months of support and updates included. Questions before buying:
support@lendyra.dev</p>
```

## Screenshot shot list (capture at 1440×900, light theme)

1. Dashboard — KPI cards + 6-month collections chart
2. Loan create form with live schedule preview (annuity example)
3. Loan detail — installment schedule with overdue rows + stats cards
4. Record payment modal with context box (next due / arrears / credit)
5. Payoff modal with live quote breakdown
6. PAR portfolio report
7. Collections route sheet with totals
8. Trial balance (balanced badge)
9. Borrower profile with loans + photo
10. Products form (interest method / penalty / limits & fees)
11. Web installer step 2
12. SMS logs page

## Preview image guidelines

- Thumbnail 80×80: "L" mark on indigo (#4f46e5)
- Inline preview 590×300: dashboard screenshot + headline "Loan management
  that gets the math right" + "$69 · self-hosted · EN FR ES PT"

## Reviewer notes (Envato "message to reviewer")

- Demo: https://demo.lendyra.dev (admin@example.com / password, demo mode on,
  nightly reset)
- Install docs: docs/INSTALL.md in the zip; 3-step web installer at /install
- Test suite: `php artisan test` (146 tests; needs a MySQL test DB, see
  phpunit.xml)
- All code is our own work; no encrypted/obfuscated files; tablewire package
  in packages/ is our own library bundled with the item.
