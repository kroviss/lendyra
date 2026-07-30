<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use DateTimeImmutable;
use InvalidArgumentException;
use LoanEngine\AccrualBasis;
use LoanEngine\InterestMethod;
use LoanEngine\LoanTerms;
use LoanEngine\Money;
use LoanEngine\RepaymentFrequency;
use LoanEngine\ScheduleGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Golden values below were computed BY HAND (independently of the
 * implementation): payment = 10000·0.01/(1−1.01⁻¹²) = 888.4879 → 888.49,
 * then iterating interest = round(balance × 0.01) per row.
 */
class AnnuityScheduleTest extends TestCase
{
    private function schedule(): \LoanEngine\Schedule
    {
        return ScheduleGenerator::generate(new LoanTerms(
            principal: Money::of('10000.00'),
            annualRatePercent: 12.0,
            termCount: 12,
            frequency: RepaymentFrequency::Monthly,
            method: InterestMethod::Annuity,
            disbursedAt: new DateTimeImmutable('2026-01-15'),
        ));
    }

    public function test_first_installment(): void
    {
        $first = $this->schedule()->installments[0];

        $this->assertSame('100.00', $first->interest->toDecimalString());
        $this->assertSame('788.49', $first->principal->toDecimalString());
        $this->assertSame('888.49', $first->total()->toDecimalString());
        $this->assertSame('2026-02-15', $first->dueDate->format('Y-m-d'));
    }

    public function test_middle_installment_golden_row(): void
    {
        $sixth = $this->schedule()->installments[5];

        $this->assertSame('59.78', $sixth->interest->toDecimalString());
        $this->assertSame('828.71', $sixth->principal->toDecimalString());
        $this->assertSame('5149.20', $sixth->closingBalance->toDecimalString());
    }

    public function test_last_installment_absorbs_rounding_and_closes_to_zero(): void
    {
        $last = $this->schedule()->installments[11];

        $this->assertSame('879.67', $last->principal->toDecimalString());
        $this->assertSame('8.80', $last->interest->toDecimalString());
        $this->assertSame('888.47', $last->total()->toDecimalString());
        $this->assertTrue($last->closingBalance->isZero());
    }

    public function test_all_installments_equal_except_last(): void
    {
        foreach (array_slice($this->schedule()->installments, 0, 11) as $installment) {
            $this->assertSame('888.49', $installment->total()->toDecimalString(), "Installment {$installment->number}");
        }
    }

    public function test_total_interest(): void
    {
        $this->assertSame('661.86', $this->schedule()->totalInterest()->toDecimalString());
    }

    public function test_zero_rate_annuity_is_simple_division(): void
    {
        $schedule = ScheduleGenerator::generate(new LoanTerms(
            principal: Money::of('1200.00'),
            annualRatePercent: 0.0,
            termCount: 12,
            frequency: RepaymentFrequency::Monthly,
            method: InterestMethod::Annuity,
            disbursedAt: new DateTimeImmutable('2026-01-15'),
        ));

        $this->assertTrue($schedule->totalInterest()->isZero());
        $this->assertSame('100.00', $schedule->installments[0]->total()->toDecimalString());
    }

    public function test_annuity_rejects_actual_day_basis(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LoanTerms(
            principal: Money::of('10000.00'),
            annualRatePercent: 12.0,
            termCount: 12,
            frequency: RepaymentFrequency::Monthly,
            method: InterestMethod::Annuity,
            disbursedAt: new DateTimeImmutable('2026-01-15'),
            basis: AccrualBasis::Actual365,
        );
    }
}
