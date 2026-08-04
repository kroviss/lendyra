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

    /**
     * Total penalty accrued as of $asOf, as a pure function of the
     * ORIGINAL scheduled base and the dated payment history against it:
     * the integral over time segments of daily rate × base outstanding
     * during that segment, where the base drops at each reduction's
     * effective date.
     *
     * Because nothing here depends on when the computation runs, a
     * backdated payment yields the same figure whether penalty is
     * recomputed on the payment's effective date or any day after it —
     * and a reduction dated on (or before) the due date means no
     * penalty ever accrues at all.
     *
     * The cap, when configured, is measured against the base of the
     * first segment that actually accrued (reductions only shrink the
     * base, so that is the largest base penalty was ever charged on —
     * and with no reductions this degenerates to amount()'s behavior
     * exactly).
     *
     * @param  BaseReduction[]  $reductions  any order; sorted internally
     */
    public static function accruedWithHistory(
        DateTimeImmutable $dueDate,
        Money $scheduledBase,
        array $reductions,
        PenaltyConfig $config,
        DateTimeImmutable $asOf,
    ): Money {
        usort($reductions, fn (BaseReduction $a, BaseReduction $b) => $a->date <=> $b->date);

        $total = Money::zero($scheduledBase->currency, $scheduledBase->scale);
        $base = $scheduledBase;
        $capBase = null;
        $daysBilled = 0;

        foreach ($reductions as $reduction) {
            $upTo = $reduction->date < $asOf ? $reduction->date : $asOf;
            $days = self::chargeableDays($dueDate, $upTo, $config->graceDays) - $daysBilled;

            if ($days > 0) {
                if ($base->minor > 0) {
                    $capBase ??= $base;
                    $total = $total->add($base->multiply($config->dailyRatePercent / 100 * $days));
                }
                $daysBilled += $days;
            }

            $base = $base->sub($reduction->amount);
            if ($base->isNegative()) {
                $base = Money::zero($base->currency, $base->scale);
            }
        }

        $days = self::chargeableDays($dueDate, $asOf, $config->graceDays) - $daysBilled;
        if ($days > 0 && $base->minor > 0) {
            $capBase ??= $base;
            $total = $total->add($base->multiply($config->dailyRatePercent / 100 * $days));
        }

        if ($config->capPercentOfBase !== null && $capBase !== null) {
            $cap = $capBase->multiply($config->capPercentOfBase / 100);
            if ($total->minor > $cap->minor) {
                return $cap;
            }
        }

        return $total;
    }
}
