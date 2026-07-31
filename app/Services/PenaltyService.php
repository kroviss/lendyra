<?php

namespace App\Services;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\LoanInstallment;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LoanEngine\Money;
use LoanEngine\PenaltyCalculator;
use LogicException;

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

        // Lock the loan and its overdue rows before recomputing: racing
        // a payment must not ratchet penalty_minor up from a stale read
        // or write a penalty onto an installment that was just settled.
        DB::transaction(function () use ($loan, $config, $asOf, $cutoff) {
            $locked = Loan::lockForUpdate()->find($loan->id);

            if ($locked === null) {
                return;
            }

            $locked->installments()
                ->lockForUpdate()
                ->where('due_date', '<', $cutoff)
                ->whereNull('settled_at')
                ->get()
                ->each(function (LoanInstallment $installment) use ($config, $asOf) {
                    $accrued = PenaltyCalculator::forInstallment($installment->toDue(), $config, $asOf);

                    // $accrued is the from-scratch total for ALL overdue days —
                    // never ADD what was already paid, or every payment gets
                    // re-billed on the next accrual. Total accrued can only
                    // grow and never drops below what was collected.
                    $installment->update([
                        'penalty_minor' => max(
                            $accrued->minor,
                            (int) $installment->penalty_minor,
                            (int) $installment->penalty_paid_minor
                        ),
                    ]);
                });
        });

        $loan->unsetRelation('installments');
    }

    /**
     * Waive the outstanding penalty on one installment, and close the
     * loan when that penalty was the last thing owed — the same
     * transition RepaymentService::record() performs.
     *
     * @return Money the waived amount
     */
    public function waive(Loan $loan, int $installmentId): Money
    {
        return DB::transaction(function () use ($loan, $installmentId) {
            // Lock + re-read: a concurrent payment must not race this
            // write into a negative penaltyDue or a missed auto-close.
            $loan = Loan::lockForUpdate()->findOrFail($loan->id);

            $installments = $loan->installments()->lockForUpdate()->get();
            $loan->setRelation('installments', $installments);

            /** @var LoanInstallment $installment */
            $installment = $installments->firstWhere('id', $installmentId)
                ?? throw (new ModelNotFoundException)->setModel(LoanInstallment::class, [$installmentId]);

            $waived = $installment->penaltyDue();

            if ($waived->minor <= 0) {
                throw new LogicException(__('Nothing to waive on this installment.'));
            }

            $installment->penalty_minor = (int) $installment->penalty_paid_minor;

            if ($installment->isSettled() && $installment->settled_at === null) {
                $installment->settled_at = today()->format('Y-m-d');
            }

            $installment->save();

            if ($loan->status === LoanStatus::Active
                && $installments->every(fn (LoanInstallment $i) => $i->isSettled())) {
                $loan->update([
                    'status' => LoanStatus::Closed,
                    'closed_at' => today()->format('Y-m-d'),
                ]);
            }

            return $waived;
        });
    }
}
