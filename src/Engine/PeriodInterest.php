<?php

declare(strict_types=1);

namespace LoanEngine;

use DateTimeImmutable;

final class PeriodInterest
{
    /**
     * Interest accrued on $balance from $from (exclusive) to $to
     * (inclusive), per the loan's accrual basis.
     */
    public static function on(Money $balance, LoanTerms $terms, DateTimeImmutable $from, DateTimeImmutable $to): Money
    {
        if ($terms->basis === AccrualBasis::EqualPeriods) {
            return $balance->multiply($terms->periodicRate());
        }

        $days = (int) $from->setTime(0, 0)->diff($to->setTime(0, 0))->format('%a');

        return $balance->multiply($terms->dailyRate() * $days);
    }
}
