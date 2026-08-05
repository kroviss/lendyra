<?php

declare(strict_types=1);

namespace LoanEngine\Strategy;

use LoanEngine\Installment;
use LoanEngine\LoanTerms;
use LoanEngine\Money;
use LoanEngine\PeriodInterest;

/**
 * Annuity (equal installments). Payment = P·r / (1 − (1+r)^−n).
 * Interest per period is computed on the outstanding balance and
 * rounded; the FINAL installment absorbs all rounding drift so the
 * principal parts always sum exactly and the balance closes at zero.
 */
final class Annuity implements ScheduleStrategy
{
    public function generate(LoanTerms $terms, array $dueDates): array
    {
        $rate = $terms->periodicRate();
        $n = $terms->termCount;

        // Zero-rate: the annuity formula degenerates to P/n, but rounding
        // that half-up can over-amortize tiny principals (P = 1.00 over
        // 66 periods → 66 × 0.02 > 1.00) and break the schedule sum
        // invariant. Split the principal instead: near-equal integer
        // parts that sum EXACTLY, with the last installment absorbing
        // the remainder — the same last-row-absorbs convention the
        // rate > 0 path uses for rounding drift.
        $equalParts = $rate == 0.0 ? $terms->principal->split($n) : null;

        $payment = $rate == 0.0
            ? null
            : $terms->principal->multiply($rate / (1 - (1 + $rate) ** -$n));

        $installments = [];
        $balance = $terms->principal;
        $previousDate = $terms->disbursedAt;

        foreach ($dueDates as $index => $dueDate) {
            $interest = PeriodInterest::on($balance, $terms, $previousDate, $dueDate);
            $isLast = $index === $n - 1;

            // Last installment: pay off whatever balance remains, exactly.
            $principal = match (true) {
                $isLast => $balance,
                $equalParts !== null => $equalParts[$index],
                default => $payment->sub($interest),
            };

            // Guard against a payment smaller than the accrued interest
            // (possible with extreme rates + terms): never amortize negatively.
            if ($principal->isNegative()) {
                $principal = Money::zero($balance->currency, $balance->scale);
            }

            // Never amortize MORE than the outstanding balance either: with a
            // tiny principal or a long term, rounding the level payment up can
            // push a non-last row's principal past the remaining balance,
            // driving it negative before the last row and tripping the
            // schedule invariant. Clamp to the balance; any leftover rows then
            // carry zero (the loan simply amortizes out early).
            if (! $isLast && $principal->minor > $balance->minor) {
                $principal = $balance;
            }

            $closing = $balance->sub($principal);

            $installments[] = new Installment(
                number: $index + 1,
                dueDate: $dueDate,
                openingBalance: $balance,
                principal: $principal,
                interest: $interest,
                closingBalance: $closing,
            );

            $balance = $closing;
            $previousDate = $dueDate;
        }

        return $installments;
    }
}
