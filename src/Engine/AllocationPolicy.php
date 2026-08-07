<?php

declare(strict_types=1);

namespace LoanEngine;

use InvalidArgumentException;

final class AllocationPolicy
{
    /** @param AllocationComponent[] $order waterfall order within the mode */
    public function __construct(
        public readonly array $order = [
            AllocationComponent::Penalty,
            AllocationComponent::Interest,
            AllocationComponent::Principal,
        ],
        public readonly AllocationMode $mode = AllocationMode::OldestInstallmentFirst,
    ) {
        foreach ($this->order as $component) {
            if (! $component instanceof AllocationComponent) {
                throw new InvalidArgumentException('Allocation order must contain AllocationComponent cases.');
            }
        }
        if (count($this->order) !== count(array_unique(array_map(fn ($c) => $c->value, $this->order)))) {
            throw new InvalidArgumentException('Allocation order contains duplicates.');
        }
        // A partial order silently strands whatever it omits: an order of
        // just [Principal] would leave interest and penalty unallocated
        // forever — no installment would ever settle and every payment
        // would pile up as an overpayment liability. The waterfall must be
        // a permutation of every component.
        if (count($this->order) !== count(AllocationComponent::cases())) {
            throw new InvalidArgumentException('Allocation order must list every component exactly once.');
        }
    }

    /** Human-readable waterfall, e.g. "Penalty → Interest → Principal". */
    public function describe(): string
    {
        return implode(' → ', array_map(fn (AllocationComponent $c) => $c->label(), $this->order));
    }
}
