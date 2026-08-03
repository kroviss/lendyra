# Changelog

All notable changes to Lendyra are documented here.

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
