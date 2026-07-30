<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanPayment;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use LoanEngine\Money;
use LoanEngine\PayoffQuote;
use LoanEngine\PayoffQuoter;

class PayoffService
{
    public function __construct(
        private readonly LoanScheduleService $schedules,
        private readonly RepaymentService $repayments,
    ) {
    }

    public function quote(Loan $loan, DateTimeImmutable $asOf): PayoffQuote
    {
        return PayoffQuoter::quote(
            $loan->terms(),
            $this->schedules->preview($loan),
            $loan->duesState(),
            $asOf,
            $loan->product->payoff_interest_mode,
        );
    }

    /**
     * Settle the loan in full at $asOf: rewrite the remaining interest
     * down to the quoted accrual (future interest is waived — paying
     * early always saves it), then record the payoff as a normal
     * payment so it settles every installment and closes the loan.
     */
    public function settle(Loan $loan, DateTimeImmutable $asOf, string $method = 'cash', ?int $receivedBy = null): LoanPayment
    {
        return DB::transaction(function () use ($loan, $asOf, $method, $receivedBy) {
            // Lock + re-check: a concurrent settle/payment must not produce
            // a second full payoff booked as overpayment.
            $loan = \App\Models\Loan::lockForUpdate()->findOrFail($loan->id);

            if (! $loan->status->acceptsPayments()) {
                throw new \LogicException("Loan {$loan->loan_number} is {$loan->status->value} and cannot be settled.");
            }

            $loan->installments()->lockForUpdate()->get();
            $loan->unsetRelation('installments');

            // Penalties must be current as of the settlement date, not as
            // of last night's cron.
            app(PenaltyService::class)->accrue($loan, $asOf);
            $loan->unsetRelation('installments');

            $quote = $this->quote($loan, $asOf);

            $cutoff = $asOf->format('Y-m-d');
            $accruedRemaining = $quote->accruedInterest->minor;

            $loan->installments()
                ->where('due_date', '>', $cutoff)
                ->orderBy('number')
                ->get()
                ->each(function (LoanInstallment $installment) use (&$accruedRemaining) {
                    // The first future installment carries the accrued
                    // interest; every later one is waived entirely.
                    $installment->update([
                        'interest_minor' => (int) $installment->interest_paid_minor + $accruedRemaining,
                    ]);
                    $accruedRemaining = 0;
                });

            $loan->unsetRelation('installments');

            return $this->repayments->record(
                $loan,
                $quote->total(),
                $asOf,
                method: $method,
                reference: 'payoff',
                receivedBy: $receivedBy,
            );
        });
    }
}
