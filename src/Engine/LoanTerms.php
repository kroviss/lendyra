<?php

declare(strict_types=1);

namespace LoanEngine;

use DateTimeImmutable;
use InvalidArgumentException;

final class LoanTerms
{
    public function __construct(
        public readonly Money $principal,
        public readonly float $annualRatePercent,
        public readonly int $termCount,
        public readonly RepaymentFrequency $frequency,
        public readonly InterestMethod $method,
        public readonly DateTimeImmutable $disbursedAt,
        public readonly ?DateTimeImmutable $firstDueDate = null,
        public readonly AccrualBasis $basis = AccrualBasis::EqualPeriods,
    ) {
        if ($principal->minor <= 0) {
            throw new InvalidArgumentException('Principal must be positive.');
        }
        if ($annualRatePercent < 0) {
            throw new InvalidArgumentException('Rate cannot be negative.');
        }
        if ($termCount < 1) {
            throw new InvalidArgumentException('Term count must be >= 1.');
        }
        if ($firstDueDate !== null && $firstDueDate <= $disbursedAt) {
            throw new InvalidArgumentException('First due date must be after disbursement.');
        }
        if ($method === InterestMethod::Annuity && $basis !== AccrualBasis::EqualPeriods) {
            throw new InvalidArgumentException(
                'Annuity schedules require the equal-periods basis; actual-day annuity is not supported.'
            );
        }
    }

    /** Periodic rate as a fraction (e.g. 12%/yr monthly → 0.01). Equal-periods basis only. */
    public function periodicRate(): float
    {
        return $this->annualRatePercent / 100 / $this->frequency->periodsPerYear();
    }

    /** Daily rate as a fraction. Actual-day bases only. */
    public function dailyRate(): float
    {
        $days = $this->basis->daysInYear()
            ?? throw new InvalidArgumentException('dailyRate() is undefined for the equal-periods basis.');

        return $this->annualRatePercent / 100 / $days;
    }
}
