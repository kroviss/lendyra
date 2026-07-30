<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanInstallment;
use DateTimeImmutable;
use LoanEngine\PenaltyCalculator;

class PenaltyService
{
    /**
     * Recompute accrued penalties for every overdue, unsettled
     * installment as of $asOf. Always computed from scratch on the
     * CURRENT unpaid base and stored as a replacement — running this
     * any number of times for the same date changes nothing
     * (idempotent), and it never drops below what was already paid.
     */
    public function accrue(Loan $loan, DateTimeImmutable $asOf): void
    {
        $config = $loan->product->penaltyConfig();

        if ($config->dailyRatePercent <= 0) {
            return;
        }

        $cutoff = $asOf->format('Y-m-d');

        $loan->installments()
            ->whereDate('due_date', '<', $cutoff)
            ->whereNull('settled_at')
            ->get()
            ->each(function (LoanInstallment $installment) use ($config, $asOf) {
                $accrued = PenaltyCalculator::forInstallment($installment->toDue(), $config, $asOf);

                $installment->update([
                    'penalty_minor' => max(
                        $accrued->minor + (int) $installment->penalty_paid_minor,
                        (int) $installment->penalty_paid_minor
                    ),
                ]);
            });

        $loan->unsetRelation('installments');
    }
}
