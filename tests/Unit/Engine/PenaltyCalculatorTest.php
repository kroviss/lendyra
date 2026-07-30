<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use DateTimeImmutable;
use LoanEngine\InstallmentDue;
use LoanEngine\Money;
use LoanEngine\PenaltyBase;
use LoanEngine\PenaltyCalculator;
use LoanEngine\PenaltyConfig;
use PHPUnit\Framework\TestCase;

class PenaltyCalculatorTest extends TestCase
{
    public function test_chargeable_days_subtracts_grace(): void
    {
        $due = new DateTimeImmutable('2026-01-10');

        $this->assertSame(7, PenaltyCalculator::chargeableDays($due, new DateTimeImmutable('2026-01-20'), graceDays: 3));
        $this->assertSame(10, PenaltyCalculator::chargeableDays($due, new DateTimeImmutable('2026-01-20')));
        $this->assertSame(0, PenaltyCalculator::chargeableDays($due, new DateTimeImmutable('2026-01-12'), graceDays: 3));
        $this->assertSame(0, PenaltyCalculator::chargeableDays($due, new DateTimeImmutable('2026-01-10')));
        $this->assertSame(0, PenaltyCalculator::chargeableDays($due, new DateTimeImmutable('2026-01-05')));
    }

    public function test_amount_is_linear_in_days(): void
    {
        $config = new PenaltyConfig(dailyRatePercent: 0.5);

        $this->assertSame('35.00', PenaltyCalculator::amount(Money::of('1000.00'), $config, 7)->toDecimalString());
        $this->assertSame('0.00', PenaltyCalculator::amount(Money::of('1000.00'), $config, 0)->toDecimalString());
    }

    public function test_cap_limits_total_penalty(): void
    {
        $config = new PenaltyConfig(dailyRatePercent: 0.5, capPercentOfBase: 20.0);

        // 60 days × 0.5% = 30% > 20% cap → 200.00
        $this->assertSame('200.00', PenaltyCalculator::amount(Money::of('1000.00'), $config, 60)->toDecimalString());
        // 30 days × 0.5% = 15% < cap → 150.00
        $this->assertSame('150.00', PenaltyCalculator::amount(Money::of('1000.00'), $config, 30)->toDecimalString());
    }

    public function test_for_installment_respects_base_config(): void
    {
        $due = new InstallmentDue(
            number: 1,
            dueDate: new DateTimeImmutable('2026-01-10'),
            principalDue: Money::of('800.00'),
            interestDue: Money::of('200.00'),
            penaltyDue: Money::zero(),
        );
        $asOf = new DateTimeImmutable('2026-01-20'); // 10 days overdue

        $onPrincipal = new PenaltyConfig(dailyRatePercent: 0.5, base: PenaltyBase::OverduePrincipal);
        $onInstallment = new PenaltyConfig(dailyRatePercent: 0.5, base: PenaltyBase::OverdueInstallment);

        $this->assertSame('40.00', PenaltyCalculator::forInstallment($due, $onPrincipal, $asOf)->toDecimalString());   // 800 × 5%
        $this->assertSame('50.00', PenaltyCalculator::forInstallment($due, $onInstallment, $asOf)->toDecimalString()); // 1000 × 5%
    }

    public function test_recomputing_is_idempotent(): void
    {
        $due = new InstallmentDue(
            number: 1,
            dueDate: new DateTimeImmutable('2026-01-10'),
            principalDue: Money::of('1000.00'),
            interestDue: Money::zero(),
            penaltyDue: Money::zero(),
        );
        $config = new PenaltyConfig(dailyRatePercent: 0.5, graceDays: 2);
        $asOf = new DateTimeImmutable('2026-02-01');

        $first = PenaltyCalculator::forInstallment($due, $config, $asOf);
        $second = PenaltyCalculator::forInstallment($due, $config, $asOf);

        $this->assertTrue($first->equals($second));
        $this->assertSame('100.00', $first->toDecimalString()); // 22 days − 2 grace = 20 × 0.5%
    }
}
