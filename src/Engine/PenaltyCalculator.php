<?php

declare(strict_types=1);

namespace LoanEngine;

use DateTimeImmutable;

/**
 * Deterministic penalty math: penalty as-of a date is always computed
 * from scratch (never incremented), so re-running any day is
 * idempotent — the application stores the result, not a delta.
 */
final class PenaltyCalculator
{
    /** Days that actually accrue penalty: days overdue minus grace, never negative. */
    public static function chargeableDays(DateTimeImmutable $dueDate, DateTimeImmutable $asOf, int $graceDays = 0): int
    {
        $due = $dueDate->setTime(0, 0);
        $at = $asOf->setTime(0, 0);

        if ($at <= $due) {
            return 0;
        }

        return max(0, (int) $due->diff($at)->format('%a') - $graceDays);
    }

    public static function amount(Money $base, PenaltyConfig $config, int $days): Money
    {
        if ($days <= 0 || $base->minor <= 0) {
            return Money::zero($base->currency, $base->scale);
        }

        $penalty = $base->multiply($config->dailyRatePercent / 100 * $days);

        if ($config->capPercentOfBase !== null) {
            $cap = $base->multiply($config->capPercentOfBase / 100);
            if ($penalty->minor > $cap->minor) {
                return $cap;
            }
        }

        return $penalty;
    }

    /** Total penalty an overdue installment has accrued as of $asOf. */
    public static function forInstallment(InstallmentDue $due, PenaltyConfig $config, DateTimeImmutable $asOf): Money
    {
        $base = match ($config->base) {
            PenaltyBase::OverduePrincipal => $due->principalDue,
            PenaltyBase::OverdueInstallment => $due->principalDue->add($due->interestDue),
        };

        return self::amount($base, $config, self::chargeableDays($due->dueDate, $asOf, $config->graceDays));
    }
}
