<?php

declare(strict_types=1);

namespace LoanEngine;

enum AllocationMode: string
{
    /** Settle the oldest installment completely before touching the next. */
    case OldestInstallmentFirst = 'oldest_installment_first';

    /** Settle one component across ALL installments before the next component. */
    case ComponentAcrossLoan = 'component_across_loan';

    public function label(): string
    {
        $labels = [
            'oldest_installment_first' => 'Oldest installment first',
            'component_across_loan' => 'Component across the whole loan',
        ];

        // Stay framework-agnostic: translate only when a real
        // translator is bound.
        return function_exists('app') && app()->bound('translator')
            ? __($labels[$this->value])
            : $labels[$this->value];
    }

    public function hint(): string
    {
        $hints = [
            'oldest_installment_first' => 'Clear the oldest installment completely (penalty, interest, principal) before touching the next one.',
            'component_across_loan' => 'Clear one component across every installment before moving to the next component — partial payments settle all penalties first, then all interest.',
        ];

        return function_exists('app') && app()->bound('translator')
            ? __($hints[$this->value])
            : $hints[$this->value];
    }
}
