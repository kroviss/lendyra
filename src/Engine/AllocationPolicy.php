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
        if (count($this->order) !== count(array_unique(array_map(fn ($c) => $c->value, $this->order)))) {
            throw new InvalidArgumentException('Allocation order contains duplicates.');
        }
        foreach ($this->order as $component) {
            if (! $component instanceof AllocationComponent) {
                throw new InvalidArgumentException('Allocation order must contain AllocationComponent cases.');
            }
        }
    }
}
