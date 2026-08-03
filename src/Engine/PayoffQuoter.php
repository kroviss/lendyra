<?php

declare(strict_types=1);

namespace LoanEngine;

use DateTimeImmutable;

/**
 * Full-payoff amount at any date, broken into components:
 *
 *   principal outstanding   — all unpaid principal, past and future
 * + past-due interest       — unpaid interest on installments already due
 * + accrued interest        — current period's interest up to $asOf
 *                             (prorated by days, or full-period per mode)
 * + penalties               — as present in the dues (accrue them first
 *                             with PenaltyCalculator)
 *
 * Interest on future installments beyond the current period is NOT
 * charged — paying off early always saves that interest.
 */
final class PayoffQuoter
{
    /** @param InstallmentDue[] $dues */
    public static function quote(
        LoanTerms $terms,
        Schedule $schedule,
        array $dues,
        DateTimeImmutable $asOf,
        EarlyPayoffInterestMode $mode = EarlyPayoffInterestMode::Prorated,
    ): PayoffQuote {
        usort($dues, fn (InstallmentDue $a, InstallmentDue $b) => $a->number <=> $b->number);

        $zero = Money::zero($terms->principal->currency, $terms->principal->scale);

        $principalOutstanding = $zero;
        $pastDueInterest = $zero;
        $penalty = $zero;
        $current = null;
        $futurePrepaid = 0;

        foreach ($dues as $due) {
            $principalOutstanding = $principalOutstanding->add($due->principalDue);
            $penalty = $penalty->add($due->penaltyDue);

            if ($due->dueDate <= $asOf) {
                $pastDueInterest = $pastDueInterest->add($due->interestDue);
            } else {
                $current ??= $due;

                // Interest already collected on ANY not-yet-due period
                // (overpayment waterfalls prepay interest across the whole
                // loan under component-across mode) must be netted against
                // the accrual — otherwise a payoff double-charges it.
                $scheduled = $schedule->installments[$due->number - 1]->interest;
                $futurePrepaid += max(0, $scheduled->minor - $due->interestDue->minor);
            }
        }

        $accrued = $current === null || $principalOutstanding->minor <= 0
            ? $zero
            : self::accruedInterest($terms, $schedule, $current, $principalOutstanding, $asOf, $mode, $futurePrepaid);

        return new PayoffQuote($principalOutstanding, $pastDueInterest, $accrued, $penalty);
    }

    private static function accruedInterest(
        LoanTerms $terms,
        Schedule $schedule,
        InstallmentDue $current,
        Money $principalOutstanding,
        DateTimeImmutable $asOf,
        EarlyPayoffInterestMode $mode,
        int $futurePrepaid = 0,
    ): Money {
        if ($mode === EarlyPayoffInterestMode::FullPeriod) {
            // interestDue is already net of what was paid on the current
            // period; only prepayments on periods BEYOND it still need
            // netting out.
            $currentScheduled = $schedule->installments[$current->number - 1]->interest;
            $beyondPrepaid = $futurePrepaid - max(0, $currentScheduled->minor - $current->interestDue->minor);

            return Money::minor(
                max(0, $current->interestDue->minor - $beyondPrepaid),
                $current->interestDue->currency,
                $current->interestDue->scale,
            );
        }

        $periodStart = $current->number === 1
            ? $terms->disbursedAt
            : $schedule->installments[$current->number - 2]->dueDate;

        $elapsed = self::days($periodStart, $asOf);

        if ($elapsed <= 0) {
            return Money::zero($principalOutstanding->currency, $principalOutstanding->scale);
        }

        if ($terms->basis === AccrualBasis::EqualPeriods) {
            $periodDays = max(1, self::days($periodStart, $current->dueDate));

            $accrued = $principalOutstanding->multiply($terms->periodicRate() * min(1, $elapsed / $periodDays));
        } else {
            $accrued = $principalOutstanding->multiply($terms->dailyRate() * $elapsed);
        }

        // Interest the borrower already paid on the current period AND on
        // any later period must not be charged a second time: net the
        // whole future prepayment out, floored at zero. (If prepayments
        // exceed the accrual, the excess stays earned — refunding it
        // would require reclassifying income already posted.)
        return Money::minor(
            max(0, $accrued->minor - $futurePrepaid),
            $accrued->currency,
            $accrued->scale,
        );
    }

    private static function days(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $a = $from->setTime(0, 0);
        $b = $to->setTime(0, 0);

        return $b <= $a ? 0 : (int) $a->diff($b)->format('%a');
    }
}
