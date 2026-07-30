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

        foreach ($dues as $due) {
            $principalOutstanding = $principalOutstanding->add($due->principalDue);
            $penalty = $penalty->add($due->penaltyDue);

            if ($due->dueDate <= $asOf) {
                $pastDueInterest = $pastDueInterest->add($due->interestDue);
            } elseif ($current === null) {
                $current = $due;
            }
        }

        $accrued = $current === null || $principalOutstanding->minor <= 0
            ? $zero
            : self::accruedInterest($terms, $schedule, $current, $principalOutstanding, $asOf, $mode);

        return new PayoffQuote($principalOutstanding, $pastDueInterest, $accrued, $penalty);
    }

    private static function accruedInterest(
        LoanTerms $terms,
        Schedule $schedule,
        InstallmentDue $current,
        Money $principalOutstanding,
        DateTimeImmutable $asOf,
        EarlyPayoffInterestMode $mode,
    ): Money {
        if ($mode === EarlyPayoffInterestMode::FullPeriod) {
            return $current->interestDue;
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

            return $principalOutstanding->multiply($terms->periodicRate() * min(1, $elapsed / $periodDays));
        }

        return $principalOutstanding->multiply($terms->dailyRate() * $elapsed);
    }

    private static function days(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $a = $from->setTime(0, 0);
        $b = $to->setTime(0, 0);

        return $b <= $a ? 0 : (int) $a->diff($b)->format('%a');
    }
}
