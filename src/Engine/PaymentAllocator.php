<?php

declare(strict_types=1);

namespace LoanEngine;

/**
 * Splits a received payment across unpaid installment components
 * according to an AllocationPolicy. Pure function of its inputs:
 * allocating the same payment against the same dues always yields the
 * same lines, and the caller applies them to storage.
 */
final class PaymentAllocator
{
    /**
     * @param  InstallmentDue[]  $dues
     */
    public static function allocate(Money $payment, array $dues, ?AllocationPolicy $policy = null): AllocationResult
    {
        $policy ??= new AllocationPolicy;

        usort($dues, fn (InstallmentDue $a, InstallmentDue $b) => $a->number <=> $b->number);

        $remaining = $payment;
        $lines = [];

        if ($policy->mode === AllocationMode::OldestInstallmentFirst) {
            foreach ($dues as $due) {
                foreach ($policy->order as $component) {
                    $remaining = self::take($due, $component, $remaining, $lines);
                }
            }
        } else {
            foreach ($policy->order as $component) {
                foreach ($dues as $due) {
                    $remaining = self::take($due, $component, $remaining, $lines);
                }
            }
        }

        return new AllocationResult($lines, $remaining);
    }

    /** @param AllocationLine[] $lines */
    private static function take(InstallmentDue $due, AllocationComponent $component, Money $remaining, array &$lines): Money
    {
        $owed = $due->amountFor($component);

        if ($owed->minor <= 0 || $remaining->minor <= 0) {
            return $remaining;
        }

        $taken = Money::minor(min($owed->minor, $remaining->minor), $remaining->currency, $remaining->scale);
        $lines[] = new AllocationLine($due->number, $component, $taken);

        return $remaining->sub($taken);
    }
}
