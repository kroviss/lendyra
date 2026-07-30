<?php

declare(strict_types=1);

namespace LoanEngine;

enum InterestMethod: string
{
    case Flat = 'flat';
    case DecliningEqualPrincipal = 'declining_equal_principal';
    case Annuity = 'annuity';
    case InterestOnlyBalloon = 'interest_only_balloon';

    public function label(): string
    {
        $labels = [
            'flat' => 'Flat rate',
            'declining_equal_principal' => 'Declining balance',
            'annuity' => 'Annuity (equal installments)',
            'interest_only_balloon' => 'Interest-only + balloon',
        ];

        // Stay framework-agnostic: translate only when a real
        // translator is bound.
        return function_exists('app') && app()->bound('translator')
            ? __($labels[$this->value])
            : $labels[$this->value];
    }
}
