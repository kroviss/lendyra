<?php

declare(strict_types=1);

namespace LoanEngine;

final class AllocationLine
{
    public function __construct(
        public readonly int $installmentNumber,
        public readonly AllocationComponent $component,
        public readonly Money $amount,
    ) {}
}
