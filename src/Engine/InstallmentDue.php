<?php

declare(strict_types=1);

namespace LoanEngine;

use DateTimeImmutable;

/**
 * The UNPAID remainder of one installment at a point in time. This is
 * the engine's view of loan state — the application layer builds these
 * from its payment records.
 */
final class InstallmentDue
{
    public function __construct(
        public readonly int $number,
        public readonly DateTimeImmutable $dueDate,
        public readonly Money $principalDue,
        public readonly Money $interestDue,
        public readonly Money $penaltyDue,
    ) {
    }

    public static function fromInstallment(Installment $installment): self
    {
        return new self(
            number: $installment->number,
            dueDate: $installment->dueDate,
            principalDue: $installment->principal,
            interestDue: $installment->interest,
            penaltyDue: Money::zero($installment->principal->currency, $installment->principal->scale),
        );
    }

    public function amountFor(AllocationComponent $component): Money
    {
        return match ($component) {
            AllocationComponent::Penalty => $this->penaltyDue,
            AllocationComponent::Interest => $this->interestDue,
            AllocationComponent::Principal => $this->principalDue,
        };
    }

    public function totalDue(): Money
    {
        return $this->principalDue->add($this->interestDue)->add($this->penaltyDue);
    }

    public function isSettled(): bool
    {
        return $this->totalDue()->isZero();
    }
}
