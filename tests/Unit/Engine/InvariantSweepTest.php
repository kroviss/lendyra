<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use DateTimeImmutable;
use LoanEngine\AccrualBasis;
use LoanEngine\InterestMethod;
use LoanEngine\LoanTerms;
use LoanEngine\Money;
use LoanEngine\RepaymentFrequency;
use LoanEngine\ScheduleGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Schedule's constructor throws on any invariant violation (principal
 * parts must sum exactly, balances must chain, final balance must be
 * zero, nothing negative). Sweeping the parameter space means every
 * combination here is PROVEN to satisfy those guarantees.
 */
class InvariantSweepTest extends TestCase
{
    public function test_every_combination_produces_a_valid_schedule(): void
    {
        $methods = InterestMethod::cases();
        $rates = [0.0, 1.5, 12.0, 36.0, 120.0];
        $terms = [1, 3, 12, 36];
        $principals = ['999.99', '10000.00', '333.33', '5000000.00'];
        $frequencies = [RepaymentFrequency::Monthly, RepaymentFrequency::Weekly];

        $count = 0;

        foreach ($methods as $method) {
            $bases = $method === InterestMethod::Annuity
                ? [AccrualBasis::EqualPeriods]
                : [AccrualBasis::EqualPeriods, AccrualBasis::Actual365, AccrualBasis::Actual360];

            foreach ($bases as $basis) {
                foreach ($rates as $rate) {
                    foreach ($terms as $termCount) {
                        foreach ($principals as $principal) {
                            foreach ($frequencies as $frequency) {
                                $schedule = ScheduleGenerator::generate(new LoanTerms(
                                    principal: Money::of($principal),
                                    annualRatePercent: $rate,
                                    termCount: $termCount,
                                    frequency: $frequency,
                                    method: $method,
                                    disbursedAt: new DateTimeImmutable('2026-01-31'),
                                    basis: $basis,
                                ));

                                $this->assertTrue(
                                    $schedule->last()->closingBalance->isZero()
                                );
                                $count++;
                            }
                        }
                    }
                }
            }
        }

        // 4 methods × (1 or 3 bases) × 5 rates × 4 terms × 4 principals × 2 freqs
        $this->assertSame(1600, $count);
    }
}
