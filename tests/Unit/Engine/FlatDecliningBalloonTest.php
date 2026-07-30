<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use DateTimeImmutable;
use LoanEngine\InterestMethod;
use LoanEngine\LoanTerms;
use LoanEngine\Money;
use LoanEngine\RepaymentFrequency;
use LoanEngine\Schedule;
use LoanEngine\ScheduleGenerator;
use PHPUnit\Framework\TestCase;

class FlatDecliningBalloonTest extends TestCase
{
    private function generate(InterestMethod $method): Schedule
    {
        return ScheduleGenerator::generate(new LoanTerms(
            principal: Money::of('10000.00'),
            annualRatePercent: 12.0,
            termCount: 12,
            frequency: RepaymentFrequency::Monthly,
            method: $method,
            disbursedAt: new DateTimeImmutable('2026-01-15'),
        ));
    }

    public function test_flat_charges_interest_on_original_principal_every_period(): void
    {
        $schedule = $this->generate(InterestMethod::Flat);

        foreach ($schedule->installments as $installment) {
            $this->assertSame('100.00', $installment->interest->toDecimalString());
        }

        $this->assertSame('933.33', $schedule->installments[0]->total()->toDecimalString());
        $this->assertSame('933.37', $schedule->installments[11]->total()->toDecimalString());
        $this->assertSame('1200.00', $schedule->totalInterest()->toDecimalString());
    }

    public function test_declining_interest_shrinks_with_balance(): void
    {
        $schedule = $this->generate(InterestMethod::DecliningEqualPrincipal);

        $this->assertSame('100.00', $schedule->installments[0]->interest->toDecimalString());
        $this->assertSame('91.67', $schedule->installments[1]->interest->toDecimalString());
        $this->assertSame('83.33', $schedule->installments[2]->interest->toDecimalString());
        $this->assertSame('8.33', $schedule->installments[11]->interest->toDecimalString());
        $this->assertSame('650.00', $schedule->totalInterest()->toDecimalString());
    }

    public function test_balloon_pays_principal_only_at_the_end(): void
    {
        $schedule = $this->generate(InterestMethod::InterestOnlyBalloon);

        foreach (array_slice($schedule->installments, 0, 11) as $installment) {
            $this->assertTrue($installment->principal->isZero());
            $this->assertSame('100.00', $installment->interest->toDecimalString());
        }

        $last = $schedule->installments[11];
        $this->assertSame('10000.00', $last->principal->toDecimalString());
        $this->assertSame('10100.00', $last->total()->toDecimalString());
        $this->assertSame('1200.00', $schedule->totalInterest()->toDecimalString());
    }
}
