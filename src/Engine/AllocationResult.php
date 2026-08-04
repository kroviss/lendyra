<?php

declare(strict_types=1);

namespace LoanEngine;

final class AllocationResult
{
    /** @param AllocationLine[] $lines */
    public function __construct(
        public readonly array $lines,
        public readonly Money $remainder,
    ) {}

    public function allocatedTo(AllocationComponent $component): Money
    {
        return array_reduce(
            array_filter($this->lines, fn (AllocationLine $l) => $l->component === $component),
            fn (Money $carry, AllocationLine $l) => $carry->add($l->amount),
            Money::zero($this->remainder->currency, $this->remainder->scale)
        );
    }

    public function totalAllocated(): Money
    {
        return array_reduce(
            $this->lines,
            fn (Money $carry, AllocationLine $l) => $carry->add($l->amount),
            Money::zero($this->remainder->currency, $this->remainder->scale)
        );
    }
}
