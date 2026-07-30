<?php

namespace App\Services;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanPayment;
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
}
