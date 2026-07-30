<?php

declare(strict_types=1);

namespace LoanEngine;

enum AccrualBasis: string
{
    /** Interest per period = annual rate / periods per year. */
    case EqualPeriods = 'equal_periods';

    /** Interest = balance × (annual rate / 365) × actual days in period. */
    case Actual365 = 'actual_365';

    /** Interest = balance × (annual rate / 360) × actual days in period. */
    case Actual360 = 'actual_360';

    public function daysInYear(): ?int
    {
        return match ($this) {
            self::EqualPeriods => null,
            self::Actual365 => 365,
            self::Actual360 => 360,
        };
    }
}
