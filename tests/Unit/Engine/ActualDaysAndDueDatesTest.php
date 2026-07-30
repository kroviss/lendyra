<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use DateTimeImmutable;
use LoanEngine\AccrualBasis;
use LoanEngine\DueDateCalculator;
use LoanEngine\InterestMethod;
use LoanEngine\LoanTerms;
use LoanEngine\Money;
use LoanEngine\RepaymentFrequency;
use LoanEngine\ScheduleGenerator;
use PHPUnit\Framework\TestCase;

class ActualDaysAndDueDatesTest extends TestCase
{
    public function test_actual_365_interest_counts_real_days(): void
    {
        // 36.5%/yr → exactly 0.1%/day. Jan 15 → Feb 15 = 31 days, Feb 15 → Mar 15 = 28 days.
        $schedule = ScheduleGenerator::generate(new LoanTerms(
            principal: Money::of('1000.00'),
            annualRatePercent: 36.5,
            termCount: 2,
            frequency: RepaymentFrequency::Monthly,
            method: InterestMethod::DecliningEqualPrincipal,
            disbursedAt: new DateTimeImmutable('2026-01-15'),
            basis: AccrualBasis::Actual365,
        ));

        $this->assertSame('31.00', $schedule->installments[0]->interest->toDecimalString()); // 1000 × 0.001 × 31
        $this->assertSame('14.00', $schedule->installments[1]->interest->toDecimalString()); // 500 × 0.001 × 28
    }

    public function test_month_end_anchor_clamps_but_never_drifts(): void
    {
        $dates = DueDateCalculator::generate(new LoanTerms(
            principal: Money::of('1000.00'),
            annualRatePercent: 12.0,
            termCount: 4,
            frequency: RepaymentFrequency::Monthly,
            method: InterestMethod::Flat,
            disbursedAt: new DateTimeImmutable('2026-01-31'),
        ));

        $this->assertSame(
            ['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'],
            array_map(fn (DateTimeImmutable $d) => $d->format('Y-m-d'), $dates)
        );
    }

    public function test_leap_year_february(): void
    {
        $dates = DueDateCalculator::generate(new LoanTerms(
            principal: Money::of('1000.00'),
            annualRatePercent: 12.0,
            termCount: 2,
            frequency: RepaymentFrequency::Monthly,
            method: InterestMethod::Flat,
            disbursedAt: new DateTimeImmutable('2028-01-30'), // 2028 is a leap year
        ));

        $this->assertSame('2028-02-29', $dates[0]->format('Y-m-d'));
        $this->assertSame('2028-03-30', $dates[1]->format('Y-m-d'));
    }

    public function test_weekly_due_dates_are_seven_days_apart(): void
    {
        $dates = DueDateCalculator::generate(new LoanTerms(
            principal: Money::of('1000.00'),
            annualRatePercent: 12.0,
            termCount: 3,
            frequency: RepaymentFrequency::Weekly,
            method: InterestMethod::Flat,
            disbursedAt: new DateTimeImmutable('2026-01-15'),
        ));

        $this->assertSame(
            ['2026-01-22', '2026-01-29', '2026-02-05'],
            array_map(fn (DateTimeImmutable $d) => $d->format('Y-m-d'), $dates)
        );
    }

    public function test_explicit_first_due_date_is_respected(): void
    {
        $dates = DueDateCalculator::generate(new LoanTerms(
            principal: Money::of('1000.00'),
            annualRatePercent: 12.0,
            termCount: 2,
            frequency: RepaymentFrequency::Monthly,
            method: InterestMethod::Flat,
            disbursedAt: new DateTimeImmutable('2026-01-15'),
            firstDueDate: new DateTimeImmutable('2026-03-01'),
        ));

        $this->assertSame('2026-03-01', $dates[0]->format('Y-m-d'));
        $this->assertSame('2026-04-01', $dates[1]->format('Y-m-d'));
    }
}
