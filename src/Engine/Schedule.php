<?php

declare(strict_types=1);

namespace LoanEngine;

use LogicException;

final class Schedule
{
    /** @param Installment[] $installments */
    public function __construct(
        public readonly LoanTerms $terms,
        public readonly array $installments,
    ) {
        $this->assertInvariants();
    }

    public function totalInterest(): Money
    {
        return array_reduce(
            $this->installments,
            fn (Money $carry, Installment $i) => $carry->add($i->interest),
            Money::zero($this->terms->principal->currency, $this->terms->principal->scale)
        );
    }

    public function totalPayable(): Money
    {
        return $this->terms->principal->add($this->totalInterest());
    }

    public function last(): Installment
    {
        return $this->installments[array_key_last($this->installments)];
    }

    /**
     * Hard guarantees every generated schedule must satisfy. A schedule
     * that fails these is a bug, never data — so we throw.
     */
    private function assertInvariants(): void
    {
        if (count($this->installments) !== $this->terms->termCount) {
            throw new LogicException('Installment count mismatch.');
        }

        $principalSum = Money::zero($this->terms->principal->currency, $this->terms->principal->scale);
        $expectedOpening = $this->terms->principal;

        foreach ($this->installments as $installment) {
            if ($installment->principal->isNegative() || $installment->interest->isNegative()) {
                throw new LogicException("Negative amount in installment {$installment->number}.");
            }
            if (! $installment->openingBalance->equals($expectedOpening)) {
                throw new LogicException("Balance discontinuity at installment {$installment->number}.");
            }
            if (! $installment->closingBalance->equals($installment->openingBalance->sub($installment->principal))) {
                throw new LogicException("Closing balance mismatch at installment {$installment->number}.");
            }

            $principalSum = $principalSum->add($installment->principal);
            $expectedOpening = $installment->closingBalance;
        }

        if (! $principalSum->equals($this->terms->principal)) {
            throw new LogicException(
                "Principal parts sum to {$principalSum->toDecimalString()}, expected {$this->terms->principal->toDecimalString()}."
            );
        }

        if (! $this->last()->closingBalance->isZero()) {
            throw new LogicException('Final closing balance is not zero.');
        }
    }
}
