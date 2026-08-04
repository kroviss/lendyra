<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use DateTimeImmutable;
use LoanEngine\BaseReduction;
use LoanEngine\Money;
use LoanEngine\PenaltyCalculator;
use LoanEngine\PenaltyConfig;
use PHPUnit\Framework\TestCase;

/**
 * accruedWithHistory(): penalty as the integral of daily rate × base
 * outstanding per time segment, where the base drops at each payment's
 * effective date. Pure function of (schedule, history, asOf) — the
 * fix for the ratchet that billed backdated payments for penalty
 * accrued after their effective date.
 */
class PenaltyHistoryTest extends TestCase
{
    private DateTimeImmutable $due;

    private PenaltyConfig $config;

    protected function setUp(): void
    {
        $this->due = new DateTimeImmutable('2026-02-15');
        $this->config = new PenaltyConfig(dailyRatePercent: 0.5);
    }

    private function reduction(string $date, string $amount): BaseReduction
    {
        return new BaseReduction(new DateTimeImmutable($date), Money::of($amount));
    }

    public function test_no_history_matches_single_shot_amount(): void
    {
        $asOf = new DateTimeImmutable('2026-03-10'); // 23 chargeable days

        $withHistory = PenaltyCalculator::accruedWithHistory(
            $this->due, Money::of('833.33'), [], $this->config, $asOf
        );
        $singleShot = PenaltyCalculator::amount(Money::of('833.33'), $this->config, 23);

        $this->assertTrue($withHistory->equals($singleShot));
        $this->assertSame('95.83', $withHistory->toDecimalString());
    }

    public function test_reduction_on_due_date_means_zero_penalty_at_any_as_of(): void
    {
        $reductions = [$this->reduction('2026-02-15', '833.33')];

        foreach (['2026-02-15', '2026-03-10', '2027-01-01'] as $asOf) {
            $accrued = PenaltyCalculator::accruedWithHistory(
                $this->due, Money::of('833.33'), $reductions, $this->config, new DateTimeImmutable($asOf)
            );
            $this->assertSame(0, $accrued->minor, "asOf {$asOf}");
        }
    }

    public function test_partial_payment_keeps_old_base_accrual_and_continues_on_reduced_base(): void
    {
        // 23 days on 833.33 → 95.83, then 10 more days on 529.16 → 26.46.
        $reductions = [$this->reduction('2026-03-10', '304.17')];

        $accrued = PenaltyCalculator::accruedWithHistory(
            $this->due, Money::of('833.33'), $reductions, $this->config, new DateTimeImmutable('2026-03-20')
        );

        $this->assertSame('122.29', $accrued->toDecimalString());

        // Recomputing ON the payment date gives exactly the pre-payment figure.
        $atPayment = PenaltyCalculator::accruedWithHistory(
            $this->due, Money::of('833.33'), $reductions, $this->config, new DateTimeImmutable('2026-03-10')
        );
        $this->assertSame('95.83', $atPayment->toDecimalString());
    }

    public function test_reduction_before_due_date_shrinks_the_starting_base(): void
    {
        $reductions = [$this->reduction('2026-02-01', '400.00')];

        // 10 days × 0.5% on 433.33 = 21.67.
        $accrued = PenaltyCalculator::accruedWithHistory(
            $this->due, Money::of('833.33'), $reductions, $this->config, new DateTimeImmutable('2026-02-25')
        );

        $this->assertSame('21.67', $accrued->toDecimalString());
    }

    public function test_grace_days_apply_across_segments(): void
    {
        $config = new PenaltyConfig(dailyRatePercent: 0.5, graceDays: 5);
        $reductions = [$this->reduction('2026-02-18', '433.33')]; // inside grace

        // Chargeable window opens after 5 grace days; the whole of it
        // runs on the already-reduced 400.00: 8 days × 0.5% = 16.00.
        $accrued = PenaltyCalculator::accruedWithHistory(
            $this->due, Money::of('833.33'), $reductions, $config, new DateTimeImmutable('2026-02-28')
        );

        $this->assertSame('16.00', $accrued->toDecimalString());
    }

    public function test_cap_measures_against_the_first_charged_base(): void
    {
        $config = new PenaltyConfig(dailyRatePercent: 0.5, capPercentOfBase: 10.0);
        $reductions = [$this->reduction('2026-02-25', '900.00')];

        // 10 days on 1000.00 (50.00) + 300 days on 100.00 (150.00) = 200.00,
        // capped at 10% of the 1000.00 first-segment base → 100.00.
        $accrued = PenaltyCalculator::accruedWithHistory(
            $this->due, Money::of('1000.00'), $reductions, $config, new DateTimeImmutable('2026-12-22')
        );

        $this->assertSame('100.00', $accrued->toDecimalString());
    }

    public function test_overpaying_reduction_clamps_base_at_zero(): void
    {
        $reductions = [$this->reduction('2026-02-20', '2000.00')];

        // 5 days on 1000.00 = 25.00, then nothing — never negative.
        $accrued = PenaltyCalculator::accruedWithHistory(
            $this->due, Money::of('1000.00'), $reductions, $this->config, new DateTimeImmutable('2026-03-01')
        );

        $this->assertSame('25.00', $accrued->toDecimalString());
    }

    public function test_reduction_dated_after_as_of_does_not_count_yet(): void
    {
        $reductions = [$this->reduction('2026-03-10', '500.00')];

        $accrued = PenaltyCalculator::accruedWithHistory(
            $this->due, Money::of('1000.00'), $reductions, $this->config, new DateTimeImmutable('2026-02-25')
        );

        // Identical to having no history at all: 10 days on 1000.00.
        $this->assertSame('50.00', $accrued->toDecimalString());
    }

    public function test_multiple_reductions_segment_in_date_order_regardless_of_input_order(): void
    {
        // Deliberately unsorted input.
        $reductions = [
            $this->reduction('2026-03-07', '300.00'),
            $this->reduction('2026-02-25', '200.00'),
        ];

        // 10d × 1000.00 = 50.00, 10d × 800.00 = 40.00, 10d × 500.00 = 25.00.
        $accrued = PenaltyCalculator::accruedWithHistory(
            $this->due, Money::of('1000.00'), $reductions, $this->config, new DateTimeImmutable('2026-03-17')
        );

        $this->assertSame('115.00', $accrued->toDecimalString());
    }
}
