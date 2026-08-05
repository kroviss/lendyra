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
use LoanEngine\Schedule;
use LoanEngine\ScheduleGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Golden values below were computed BY HAND (independently of the
 * implementation): payment = 10000·0.01/(1−1.01⁻¹²) = 888.4879 → 888.49,
 * then iterating interest = round(balance × 0.01) per row.
 */
class AnnuityScheduleTest extends TestCase
{
    private function schedule(): Schedule
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

    private function zeroRateSchedule(Money $principal, int $termCount, RepaymentFrequency $frequency = RepaymentFrequency::Monthly): Schedule
    {
        return ScheduleGenerator::generate(new LoanTerms(
            principal: $principal,
            annualRatePercent: 0.0,
            termCount: $termCount,
            frequency: $frequency,
            method: InterestMethod::Annuity,
            disbursedAt: new DateTimeImmutable('2026-01-15'),
        ));
    }

    public function test_zero_rate_tiny_principal_does_not_over_amortize(): void
    {
        // round(P/n) half-up used to give 0.02 × 66 = 1.32 > 1.00 and
        // blow the Schedule sum invariant. The constructor itself
        // asserts the invariants, so surviving generate() is the proof.
        $schedule = $this->zeroRateSchedule(Money::of('1.00'), 66);

        $this->assertCount(66, $schedule->installments);
        $this->assertTrue($schedule->totalInterest()->isZero());
        $this->assertTrue($schedule->last()->closingBalance->isZero());

        foreach ($schedule->installments as $installment) {
            $this->assertFalse($installment->principal->isNegative());
        }
    }

    public function test_zero_rate_one_minor_unit_principal(): void
    {
        $schedule = $this->zeroRateSchedule(Money::minor(1), 66);

        $sum = 0;
        foreach ($schedule->installments as $installment) {
            $this->assertGreaterThanOrEqual(0, $installment->principal->minor);
            $sum += $installment->principal->minor;
        }

        $this->assertSame(1, $sum);
        $this->assertSame(1, $schedule->last()->principal->minor); // last absorbs the remainder
    }

    public function test_zero_rate_last_installment_absorbs_remainder(): void
    {
        // 100 minor over 7: six parts of 14 and a last part of 16.
        $schedule = $this->zeroRateSchedule(Money::minor(100), 7);

        foreach (array_slice($schedule->installments, 0, 6) as $installment) {
            $this->assertSame(14, $installment->principal->minor, "Installment {$installment->number}");
        }
        $this->assertSame(16, $schedule->last()->principal->minor);
        $this->assertTrue($schedule->last()->closingBalance->isZero());
    }

    public function test_zero_rate_invariants_hold_for_small_principals_across_all_terms(): void
    {
        // Schedule's constructor throws on any invariant violation, so
        // every generated combination is proven exact.
        $count = 0;

        foreach ([1, 2, 3, 100, 999] as $minor) {
            for ($termCount = 1; $termCount <= 600; $termCount++) {
                $schedule = $this->zeroRateSchedule(
                    Money::minor($minor),
                    $termCount,
                    RepaymentFrequency::Weekly
                );

                $this->assertTrue($schedule->last()->closingBalance->isZero());
                $count++;
            }
        }

        $this->assertSame(3000, $count);
    }

    private function ratedSchedule(Money $principal, int $termCount, float $rate, RepaymentFrequency $frequency = RepaymentFrequency::Monthly): Schedule
    {
        return ScheduleGenerator::generate(new LoanTerms(
            principal: $principal,
            annualRatePercent: $rate,
            termCount: $termCount,
            frequency: $frequency,
            method: InterestMethod::Annuity,
            disbursedAt: new DateTimeImmutable('2026-01-15'),
        ));
    }

    /**
     * The rate > 0 twin of the zero-rate tiny-principal guard: rounding the
     * level payment up used to drive a non-last row's principal past the
     * remaining balance, turning it negative before the last row and tripping
     * the Schedule invariant (surviving generate() IS the proof). These are
     * the exact cases the 9th-audit engine pass reproduced.
     */
    public function test_rated_tiny_principals_do_not_over_amortize(): void
    {
        $cases = [
            [Money::of('0.54'), 12, 1.0],
            [Money::of('19.98'), 24, 360.0],
            [Money::of('7.27'), 36, 1.0],
            [Money::minor(199977, 'USD', 0), 600, 12.0], // 0-decimal currency, long term
        ];

        foreach ($cases as [$principal, $termCount, $rate]) {
            $schedule = $this->ratedSchedule($principal, $termCount, $rate);

            $this->assertCount($termCount, $schedule->installments);
            $this->assertTrue($schedule->last()->closingBalance->isZero());

            $sum = 0;
            foreach ($schedule->installments as $installment) {
                $this->assertFalse($installment->principal->isNegative(), "Installment {$installment->number}");
                $this->assertFalse($installment->closingBalance->isNegative(), "Closing {$installment->number}");
                $sum += $installment->principal->minor;
            }

            $this->assertSame($principal->minor, $sum);
        }
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
