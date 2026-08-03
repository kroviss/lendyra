<?php

declare(strict_types=1);

namespace LoanEngine;

enum PenaltyBase: string
{
    case OverduePrincipal = 'overdue_principal';
    case OverdueInstallment = 'overdue_installment';

    public function label(): string
    {
        $labels = [
            'overdue_principal' => 'Overdue principal',
            'overdue_installment' => 'Overdue principal + interest',
        ];

        // Stay framework-agnostic: translate only when a real
        // translator is bound.
        return function_exists('app') && app()->bound('translator')
            ? __($labels[$this->value])
            : $labels[$this->value];
    }
}
