# Changelog

All notable changes to Lendyra are documented here.

## 1.0.3 — 2026-08-04

### Fixed
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
