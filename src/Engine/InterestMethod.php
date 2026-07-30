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

        return function_exists('__') ? __($labels[$this->value]) : $labels[$this->value];
    }
}
