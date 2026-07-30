<?php

declare(strict_types=1);

namespace LoanEngine;

use LoanEngine\Strategy\Annuity;
use LoanEngine\Strategy\DecliningEqualPrincipal;
use LoanEngine\Strategy\FlatRate;
use LoanEngine\Strategy\InterestOnlyBalloon;
use LoanEngine\Strategy\ScheduleStrategy;

final class ScheduleGenerator
{
    public static function generate(LoanTerms $terms): Schedule
    {
        $dueDates = DueDateCalculator::generate($terms);
        $installments = self::strategyFor($terms->method)->generate($terms, $dueDates);

        return new Schedule($terms, $installments);
    }

    private static function strategyFor(InterestMethod $method): ScheduleStrategy
    {
        return match ($method) {
            InterestMethod::Flat => new FlatRate,
            InterestMethod::DecliningEqualPrincipal => new DecliningEqualPrincipal,
            InterestMethod::Annuity => new Annuity,
            InterestMethod::InterestOnlyBalloon => new InterestOnlyBalloon,
        };
    }
}
