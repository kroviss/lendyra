<?php

declare(strict_types=1);

namespace LoanEngine\Strategy;

use LoanEngine\Installment;
use LoanEngine\LoanTerms;
use LoanEngine\Money;
use LoanEngine\PeriodInterest;

/**
 * Interest-only with balloon: every installment pays interest on the
 * full principal; the final installment also repays the principal.
 */
final class InterestOnlyBalloon implements ScheduleStrategy
{
    public function generate(LoanTerms $terms, array $dueDates): array
    {
        $installments = [];
        $previousDate = $terms->disbursedAt;
        $zero = Money::zero($terms->principal->currency, $terms->principal->scale);

        foreach ($dueDates as $index => $dueDate) {
            $isLast = $index === $terms->termCount - 1;
            $interest = PeriodInterest::on($terms->principal, $terms, $previousDate, $dueDate);
            $principal = $isLast ? $terms->principal : $zero;

            $installments[] = new Installment(
                number: $index + 1,
                dueDate: $dueDate,
                openingBalance: $terms->principal,
                principal: $principal,
                interest: $interest,
                closingBalance: $isLast ? $zero : $terms->principal,
            );

            $previousDate = $dueDate;
        }

        return $installments;
    }
}
