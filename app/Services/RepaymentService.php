<?php

namespace App\Services;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanPayment;
use App\Services\LoanScheduleService;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LoanEngine\AllocationComponent;
use LoanEngine\Money;
use LoanEngine\PaymentAllocator;
use LogicException;

class RepaymentService
{
    /**
     * Record a repayment: allocate it across unpaid installments per the
     * product's policy, persist the payment + allocation lines, update
     * paid amounts, and close the loan when everything is settled.
     */
    public function record(
        Loan $loan,
        Money $amount,
        DateTimeImmutable $paidAt,
        string $method = 'cash',
        ?string $reference = null,
        ?int $receivedBy = null,
    ): LoanPayment {
        if (! $loan->status->acceptsPayments()) {
            throw new LogicException("Loan {$loan->loan_number} is {$loan->status->value} and cannot accept payments.");
        }
        if ($amount->minor <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }
        if ($amount->currency !== $loan->currency) {
            throw new InvalidArgumentException("Payment currency {$amount->currency} does not match loan currency {$loan->currency}.");
        }

        return DB::transaction(function () use ($loan, $amount, $paidAt, $method, $reference, $receivedBy) {
            // Re-read the loan under lock: a concurrent request may have
            // just closed or written it off.
            $loan = Loan::lockForUpdate()->findOrFail($loan->id);

            if (! $loan->status->acceptsPayments()) {
                throw new LogicException("Loan {$loan->loan_number} is {$loan->status->value} and cannot accept payments.");
            }

            // Lock installments against concurrent payments on the same loan.
            $installments = $loan->installments()->lockForUpdate()->get();
            $loan->setRelation('installments', $installments);

            $result = PaymentAllocator::allocate(
                $amount,
                $loan->duesState(),
                $loan->product->allocationPolicy()
            );

            $payment = $loan->payments()->create([
                'branch_id' => $loan->branch_id,
                'amount_minor' => $amount->minor,
                'unallocated_minor' => $result->remainder->minor,
                'paid_at' => $paidAt->format('Y-m-d'),
                'method' => $method,
                'reference' => $reference,
                'received_by' => $receivedBy,
            ]);

            $byNumber = $installments->keyBy('number');

            foreach ($result->lines as $line) {
                /** @var LoanInstallment $installment */
                $installment = $byNumber[$line->installmentNumber];

                $payment->allocations()->create([
                    'loan_installment_id' => $installment->id,
                    'component' => $line->component->value,
                    'amount_minor' => $line->amount->minor,
                ]);

                $column = match ($line->component) {
                    AllocationComponent::Penalty => 'penalty_paid_minor',
                    AllocationComponent::Interest => 'interest_paid_minor',
                    AllocationComponent::Principal => 'principal_paid_minor',
                };
                $installment->{$column} = (int) $installment->{$column} + $line->amount->minor;

                if ($installment->isSettled() && $installment->settled_at === null) {
                    $installment->settled_at = $paidAt->format('Y-m-d');
                }

                $installment->save();
            }

            app(LedgerService::class)->postPayment($payment->load('allocations', 'loan'));

            $allSettled = $installments->every(fn (LoanInstallment $i) => $i->isSettled());

            if ($allSettled) {
                $loan->update([
                    'status' => LoanStatus::Closed,
                    'closed_at' => $paidAt->format('Y-m-d'),
                ]);
            }

            return $payment;
        });
    }

    /**
     * Undo a payment completely: restore every installment's paid
     * amounts from the allocation lines, post a mirrored ledger entry,
     * and reopen the loan if this payment had closed it.
     */
    public function reverse(LoanPayment $payment, ?int $reversedBy = null): void
    {
        if ($payment->reversed_at !== null) {
            throw new LogicException('Payment is already reversed.');
        }

        DB::transaction(function () use ($payment, $reversedBy) {
            // Lock the payment row itself — the pre-transaction guard above
            // reads a stale object and two concurrent reversals would both pass.
            $payment = LoanPayment::lockForUpdate()->findOrFail($payment->id);

            if ($payment->reversed_at !== null) {
                throw new LogicException('Payment is already reversed.');
            }

            $loan = Loan::lockForUpdate()->findOrFail($payment->loan_id);
            $installments = $loan->installments()->lockForUpdate()->get()->keyBy('id');

            foreach ($payment->allocations as $allocation) {
                /** @var LoanInstallment $installment */
                $installment = $installments[$allocation->loan_installment_id];

                $column = match ($allocation->component) {
                    AllocationComponent::Penalty => 'penalty_paid_minor',
                    AllocationComponent::Interest => 'interest_paid_minor',
                    AllocationComponent::Principal => 'principal_paid_minor',
                };

                $restored = (int) $installment->{$column} - (int) $allocation->amount_minor;

                if ($restored < 0) {
                    throw new LogicException("Reversal would make installment {$installment->number} negative — data is inconsistent.");
                }

                $installment->{$column} = $restored;

                if (! $installment->isSettled()) {
                    $installment->settled_at = null;
                }

                $installment->save();
            }

            $payment->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $reversedBy,
            ])->save();

            app(LedgerService::class)->reversePayment($payment->load('allocations', 'loan'));

            // A payoff settlement rewrote interest_minor to waive future
            // interest; reversing it must restore the contractual schedule.
            // The engine is deterministic, so the original numbers come
            // straight back from a preview.
            if ($payment->reference === 'payoff') {
                $schedule = app(LoanScheduleService::class)->preview($loan);

                foreach ($schedule->installments as $engineInstallment) {
                    $loan->installments()
                        ->where('number', $engineInstallment->number)
                        ->update(['interest_minor' => $engineInstallment->interest->minor]);
                }

                $loan->installments()
                    ->whereNotNull('settled_at')
                    ->get()
                    ->each(function (LoanInstallment $installment) {
                        if (! $installment->isSettled()) {
                            $installment->update(['settled_at' => null]);
                        }
                    });
            }

            if ($loan->status === LoanStatus::Closed) {
                $loan->update(['status' => LoanStatus::Active, 'closed_at' => null]);
            }
        });
    }
}
