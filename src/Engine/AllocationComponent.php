<?php

declare(strict_types=1);

namespace LoanEngine;

enum AllocationComponent: string
{
    case Penalty = 'penalty';
    case Interest = 'interest';
    case Principal = 'principal';

    public function label(): string
    {
        $labels = ['penalty' => 'Penalty', 'interest' => 'Interest', 'principal' => 'Principal'];

        // Stay framework-agnostic: translate only when a real
        // translator is bound.
        return function_exists('app') && app()->bound('translator')
            ? __($labels[$this->value])
            : $labels[$this->value];
    }
}
