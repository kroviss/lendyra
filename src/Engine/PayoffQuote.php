<?php

declare(strict_types=1);

namespace LoanEngine;

final class PayoffQuote
{
    public function __construct(
        public readonly Money $principalOutstanding,
        public readonly Money $pastDueInterest,
        public readonly Money $accruedInterest,
        public readonly Money $penalty,
    ) {
    }

    public function total(): Money
    {
        return $this->principalOutstanding
            ->add($this->pastDueInterest)
            ->add($this->accruedInterest)
            ->add($this->penalty);
    }
}
