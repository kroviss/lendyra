# Changelog

All notable changes to Lendyra are documented here.

## 1.0.5 — 2026-08-07

### Fixed
- **Disbursing a loan can no longer silently reprice its first period.**
  Activation re-anchors the schedule on the day the money actually moves, but a
  first due date planned weeks earlier was carried over unchecked. Under the
  equal-periods basis period 1 charges exactly one periodic rate however short
  it really is, so a loan prepared for a month-long first period and disbursed
  three days before that date still billed a full month of interest. Activation
  now refuses a planned first due date that falls on or before the disbursement,
  or that would leave period 1 shorter than half a period, and says what to
  change. Daily bases (actual/365, actual/360) price short stubs correctly and
  are unaffected. The loan form warns about the same thing at origination
  without blocking it — a deliberate calendar anchor is still a valid choice.
- **A currency dropped by its last product no longer renders 100× too small.**
  The currency→scale map was built only from `loan_products`, so re-pointing a
  product at another currency left the loans already booked in the old one
  falling back to two decimals: a ¥1,200,000 portfolio read as "12,000.00" on
  the dashboard, portfolio, collections, payments and trial balance. Loans
  snapshot their own currency and scale, and are now the fallback.

### Added
- **The payment waterfall is configurable from the product form.** Allocation
  order (penalty → interest → principal, plus two alternatives) and allocation
  mode (oldest installment first / component across the whole loan) were already
  honoured by the engine but reachable only by editing the database. The payment
  modal now names the product's actual waterfall instead of assuming the default.
- **Minimum and maximum term** per product, enforced on every loan originated
  against it — the columns had existed since 1.0 but nothing ever read them,
  unlike the principal limits.

### Security
- Deleting a borrower now deletes their ID photo from the private disk instead
  of leaving the image behind, and collateral uploads are written only after
  every permission check has passed (a rejected edit used to strand files).
- `.env.example` ships `LOG_LEVEL=error` rather than `debug`, so a production
  install does not write debug-level records to disk by default.

### Documentation
- `LMS_PAYMENT_BACKDATE_DAYS` is documented in `.env.example` and
  `docs/INSTALL.md`; it existed in 1.0.4 but appeared only in this changelog.
- The branch-scoping section now describes the fail-closed behaviour introduced
  in 1.0.4 — it still claimed branchless accounts were unrestricted.

## 1.0.4 — 2026-08-05

### Security
- **Branch scoping now fails closed.** A branch-scoped account (loan officer,
  cashier, accountant) left with no branch assigned previously saw *every*
  branch's data — the scope had nothing to bind to. Such accounts now match no
  branch until one is assigned, and the user form requires a branch for scoped
  roles whenever branch scoping is enabled.
- Cashiers can no longer erase accrued penalties by backdating a payment onto an
  overdue due date: without the write-off privilege, payment dates are capped to
  a recent window (`LMS_PAYMENT_BACKDATE_DAYS`, default 7). Managers may still
  backdate to disbursement.
- Loan officers can no longer destroy collateral photos or write down a
  collateral's value through the edit path — those changes now require a
  manager, matching the delete/release gate.
- Anti-framing headers (`X-Frame-Options`, CSP `frame-ancestors`) are sent on
  every response so money actions cannot be clickjacked, and a root `.htaccess` /
  `web.config` shields `.env`, source and storage for buyers who point the
  document root at the project root by mistake.
- The dashboard's chart currency is now branch-scoped like every other figure.

### Fixed
- **Penalty and early-payoff terms are snapshotted onto each loan at
  origination** (like the interest rate already was). Re-pricing a product no
  longer retroactively rewrites the penalty history of every live loan on the
  next nightly accrual.
- **Annuity schedules for very small principals (and long terms on 0-decimal
  currencies) no longer fail to generate.** Rounding the level payment up could
  amortize a row past the remaining balance and trip the schedule invariant,
  blanking the preview and blocking activation; a non-last row's principal is
  now clamped to the outstanding balance.
- Money inputs now read dot-grouped thousands (`1.234`, `2.500`) as whole
  amounts in the Spanish/Portuguese UI instead of tiny fractions — the mirror of
  the decimal-comma fix.
- The loan form's schedule preview refreshes immediately when the amount changes
  (the money field now honours `wire:model.live`).
- French/Spanish/Portuguese now translate framework messages too: field
  validation errors, the paginator, auth and password-reset lines ship in all
  four languages, and error labels read "borrower"/"annual rate" instead of
  "borrower id"/"annual_rate".
- Engine guard messages ("loan cannot accept payments", "payment already
  reversed", "schedule cannot be regenerated") are translated and name the loan
  status by its label.
- Editing a loan whose product was later deactivated still shows that product
  (marked inactive) instead of a blank select.
- The record-payment modal resets its method as well as amount/date, and
  replacing a borrower photo deletes the old file from the private disk.
- Inline row actions (waive, reverse, collateral, guarantor) disable while
  their request is in flight, so a double-click cannot fire twice.

### Performance
- The portfolio report no longer hydrates every loan in arrears before capping
  to 200; PAR and the detail rows are derived from one grouped query and only
  the worst 200 loans are loaded.
- The nightly penalty accrual pulls each loan's allocation history in a single
  query and skips writing installments whose penalty is unchanged.
- The payments hub no longer 500s on a malformed `from`/`to` date in the URL,
  the collections report has a stable sort tiebreaker (no repeated/dropped rows
  across pages or export chunks), and the borrower profile only loads active
  loans' installments.

## 1.0.3 — 2026-08-04

### Fixed
- Branch-scoped users no longer see out-of-branch loans on a borrower's
  profile page or in the borrower list's loan counts — the profile's
  eager-loaded loans are now scoped like every other loan surface.
- The `/media` collateral photo route no longer fails open when the owning
  loan is soft-deleted; a missing loan is treated as denied for scoped users
  instead of served to anyone.
- **Backdated payments no longer swallow penalty that accrued after the payment
  date.** Penalty is now recomputed from the dated payment history (segment by
  segment on the principal actually outstanding), so a bank transfer that
  arrived on time but was keyed in late settles its installment in full instead
  of being partially diverted to penalty. The same rework fixes the mirror-image
  bug: after a partial payment on an overdue installment, penalty keeps accruing
  on the reduced balance instead of silently freezing.
- Reversing a payment now re-accrues penalties as if the payment had never
  happened, and settle → reverse → re-settle books identical amounts.
- Collateral photo lists are validated server-side against the record's own
  stored photos — forged paths can no longer be injected into the database or
  used to delete other records' files from the private disk.
- The record-payment modal resets its date to today after a successful save, so
  a legitimately backdated payment no longer leaks its date into the next one
  (payoff date/method now reset the same way).
- Money inputs understand decimal commas: typing `1234,56` in the French,
  Spanish or Portuguese UI is parsed as 1 234.56 — previously the comma was
  stripped and the amount inflated 100×. Mixed `1.234,56` / `1,234.56`
  formats are both handled.
- The first due date is bounded to two repayment periods after disbursement
  (enforced on the form and re-checked at activation), preventing mispriced
  stub periods under the equal-periods interest basis.
- Zero-rate loans with very small principals over many terms no longer crash
  at activation; the schedule splits the principal exactly for any amount.
- Demoting or deleting a *deactivated* admin is no longer blocked by the
  last-active-admin guard (the guard still protects the last active admin).
- The collections report CSV export now carries the same PII bar as every
  other export — cashiers can view the page but not bulk-export borrower
  names and phone numbers.
- The loan form's selected-borrower fallback is branch-scoped, closing an
  ID-probing disclosure of out-of-branch borrower names and phones.
- Added the missing `Language` translation for the locale switcher label.
- A pristine install's very first request no longer writes two spurious
  errors to the log while bootstrapping the application key.
- Installation guide: reminder schedule time is documented as app timezone
  (`APP_TIMEZONE`), matching the Timezone section and the scheduler.

## 1.0.2 — 2026-08-03

### Fixed
- **Penalty waivers now stick.** Waived penalties were silently re-billed by the
  next accrual run (nightly cron or any payment). Waivers are now tracked per
  installment (`penalty_waived_minor`) and netted out of every future accrual.
- **Payoff no longer keeps interest prepaid on future periods.** Overpayments
  that landed on future installments' interest (component-across allocation)
  are now netted out of the payoff quote in both prorated and full-period modes.
- Payoff can no longer be backdated before the disbursement date (which waived
  100% of the interest).
- Payoff payments are identified by a dedicated flag instead of the free-text
  reference, so a cashier typing "payoff" can no longer corrupt a reversal.
- Product minimum/maximum principal limits are now enforced at origination —
  and configurable on the product form, along with processing fees and
  currency decimal places (previously database-only).
- The plain select input rendered Eloquent option lists as raw JSON, breaking
  the product dropdown on the loan form and the branch dropdown on the user form.
- Collateral/guarantor modal errors were invisible (rendered behind the
  overlay); modals now show errors inline and fully reset between opens.
- CSV export row cap did not apply (`chunk()` overrode `take()`); exports also
  gained role gates on the guarantor and collateral registers.
- `/media` photo streaming now requires an owning record, applies branch
  scoping, and no longer falls back to the world-readable public disk.
- Password-reset no longer leaks which emails are registered, and reset links
  are not minted while the mailer is still the log driver.

### Added
- Reject-with-reason flow (reason shown to the loan officer for resubmission).
- Payment modal shows next due, arrears and unapplied credit; payoff modal
  disables confirmation when no quote is available.
- Overpayment credit is visible on the loan page.
- Collateral photos can be removed; borrower prefill works past the dropdown cap.
- Collections report CSV export (route sheet), portfolio truncation notice.
- Installer asks for the timezone; version shown in the sidebar footer.
- Page titles on every screen, localized labels for roles/enums/badges.

## 1.0.1 — 2026-07-31

- First packaged release: schedule engine (flat/declining/annuity/balloon),
  double-entry ledger, PAR reports, maker-checker approvals, branch scoping,
  penalties/waivers, payoff quotes, SMS reminders, CSV exports, web installer,
  demo mode.

## 1.0.0 — 2026-07-30

- Internal release (never distributed).
