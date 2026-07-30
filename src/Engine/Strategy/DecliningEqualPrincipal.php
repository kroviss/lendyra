<?php

declare(strict_types=1);

namespace LoanEngine\Strategy;

use LoanEngine\Installment;
use LoanEngine\LoanTerms;
use LoanEngine\PeriodInterest;

/**
 * Declining balance with equal principal parts: interest accrues on the
 * outstanding balance, so installment totals shrink over time.
 */
final class DecliningEqualPrincipal implements ScheduleStrategy
{
    public function generate(LoanTerms $terms, array $dueDates): array
    {
        $principalParts = $terms->principal->split($terms->termCount);
        $installments = [];
        $balance = $terms->principal;
        $previousDate = $terms->disbursedAt;

        foreach ($dueDates as $index => $dueDate) {
            $interest = PeriodInterest::on($balance, $terms, $previousDate, $dueDate);
            $closing = $balance->sub($principalParts[$index]);

            $installments[] = new Installment(
                number: $index + 1,
                dueDate: $dueDate,
                openingBalance: $balance,
                principal: $principalParts[$index],
                interest: $interest,
                closingBalance: $closing,
            );

            $balance = $closing;
            $previousDate = $dueDate;
        }

        return $installments;
    }
}
