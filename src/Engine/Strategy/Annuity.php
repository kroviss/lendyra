<?php

declare(strict_types=1);

namespace LoanEngine\Strategy;

use LoanEngine\Installment;
use LoanEngine\LoanTerms;
use LoanEngine\Money;
use LoanEngine\PeriodInterest;

/**
 * Annuity (equal installments). Payment = P·r / (1 − (1+r)^−n).
 * Interest per period is computed on the outstanding balance and
 * rounded; the FINAL installment absorbs all rounding drift so the
 * principal parts always sum exactly and the balance closes at zero.
 */
final class Annuity implements ScheduleStrategy
{
    public function generate(LoanTerms $terms, array $dueDates): array
    {
        $rate = $terms->periodicRate();
        $n = $terms->termCount;

        $payment = $rate == 0.0
            ? $terms->principal->multiply(1 / $n)
            : $terms->principal->multiply($rate / (1 - (1 + $rate) ** -$n));

        $installments = [];
        $balance = $terms->principal;
        $previousDate = $terms->disbursedAt;

        foreach ($dueDates as $index => $dueDate) {
            $interest = PeriodInterest::on($balance, $terms, $previousDate, $dueDate);
            $isLast = $index === $n - 1;

            // Last installment: pay off whatever balance remains, exactly.
            $principal = $isLast ? $balance : $payment->sub($interest);

            // Guard against a payment smaller than the accrued interest
            // (possible with extreme rates + terms): never amortize negatively.
            if ($principal->isNegative()) {
                $principal = Money::zero($balance->currency, $balance->scale);
            }

            $closing = $balance->sub($principal);

            $installments[] = new Installment(
                number: $index + 1,
                dueDate: $dueDate,
                openingBalance: $balance,
                principal: $principal,
                interest: $interest,
                closingBalance: $closing,
            );

            $balance = $closing;
            $previousDate = $dueDate;
        }

        return $installments;
    }
}
