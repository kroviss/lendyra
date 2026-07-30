<?php

declare(strict_types=1);

namespace LoanEngine;

use DateTimeImmutable;

final class DueDateCalculator
{
    /**
     * Due dates for every installment. Monthly schedules anchor on the
     * first due date's day-of-month and clamp to shorter months
     * (anchor 31 → Feb 28, then back to Mar 31 — the anchor never drifts).
     *
     * @return DateTimeImmutable[]
     */
    public static function generate(LoanTerms $terms): array
    {
        // The anchor is the INTENDED day-of-month (disbursement day, or the
        // explicit first due date's day) — never a clamped result, so a loan
        // disbursed Jan 31 falls due Feb 28 but returns to the 31st in March.
        $anchorDay = $terms->firstDueDate !== null
            ? (int) $terms->firstDueDate->format('j')
            : (int) $terms->disbursedAt->format('j');

        $first = $terms->firstDueDate ?? self::addPeriods($terms->disbursedAt, $terms->frequency, 1, $anchorDay);

        $dates = [$first];

        for ($i = 1; $i < $terms->termCount; $i++) {
            $dates[] = self::addPeriods($first, $terms->frequency, $i, $anchorDay);
        }

        return $dates;
    }

    private static function addPeriods(DateTimeImmutable $from, RepaymentFrequency $frequency, int $count, int $anchorDay): DateTimeImmutable
    {
        return match ($frequency) {
            RepaymentFrequency::Weekly => $from->modify('+'.(7 * $count).' days'),
            RepaymentFrequency::Biweekly => $from->modify('+'.(14 * $count).' days'),
            RepaymentFrequency::Monthly => self::addMonthsClamped($from, $count, $anchorDay),
        };
    }

    private static function addMonthsClamped(DateTimeImmutable $from, int $months, int $anchorDay): DateTimeImmutable
    {
        $year = (int) $from->format('Y');
        $month = (int) $from->format('n') + $months;
        $year += intdiv($month - 1, 12);
        $month = (($month - 1) % 12) + 1;

        $daysInMonth = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        $day = min($anchorDay, $daysInMonth);

        return $from->setDate($year, $month, $day);
    }
}
