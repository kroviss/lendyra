<?php

declare(strict_types=1);

namespace LoanEngine;

use DateTimeImmutable;

final class Installment
{
    public function __construct(
        public readonly int $number,
        public readonly DateTimeImmutable $dueDate,
        public readonly Money $openingBalance,
        public readonly Money $principal,
        public readonly Money $interest,
        public readonly Money $closingBalance,
    ) {}

    public function total(): Money
    {
        return $this->principal->add($this->interest);
    }
}
