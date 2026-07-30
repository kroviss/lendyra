<?php

declare(strict_types=1);

namespace LoanEngine;

enum RepaymentFrequency: string
{
    case Monthly = 'monthly';
    case Biweekly = 'biweekly';
    case Weekly = 'weekly';

    public function label(): string
    {
        $labels = ['monthly' => 'Monthly', 'biweekly' => 'Biweekly', 'weekly' => 'Weekly'];

        return function_exists('__') ? __($labels[$this->value]) : $labels[$this->value];
    }

    public function periodsPerYear(): int
    {
        return match ($this) {
            self::Monthly => 12,
            self::Biweekly => 26,
            self::Weekly => 52,
        };
    }
}
