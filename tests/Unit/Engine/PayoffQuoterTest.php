<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use DateTimeImmutable;
use LoanEngine\AccrualBasis;
use LoanEngine\EarlyPayoffInterestMode;
use LoanEngine\InstallmentDue;
use LoanEngine\InterestMethod;
use LoanEngine\LoanTerms;
use LoanEngine\Money;
use LoanEngine\PayoffQuoter;
use LoanEngine\RepaymentFrequency;
use LoanEngine\Schedule;
use LoanEngine\ScheduleGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Base case: 10,000.00 @ 12%/yr, 12 monthly declining installments,
 * disbursed 2026-01-15. Installment #1 (due Feb 15) fully paid.
 */
class PayoffQuoterTest extends TestCase
{
    private LoanTerms $terms;

    private Schedule $schedule;

    protected function setUp(): void
    {
        $this->terms = new LoanTerms(
            principal: Money::of('10000.00'),
            annualRatePercent: 12.0,
            termCount: 12,
            frequency: RepaymentFrequency::Monthly,
            method: InterestMethod::DecliningEqualPrincipal,
            disbursedAt: new DateTimeImmutable('2026-01-15'),
        );
        $this->schedule = ScheduleGenerator::generate($this->terms);
    }

    /** @return InstallmentDue[] dues with installment #1 settled */
    private function duesAfterFirstPayment(): array
    {
        $dues = [];
        foreach ($this->schedule->installments as $installment) {
            $dues[] = $installment->number === 1
                ? new InstallmentDue(1, $installment->dueDate, Money::zero(), Money::zero(), Money::zero())
                : InstallmentDue::fromInstallment($installment);
        }

        return $dues;
    }

    public function test_prorated_quote_mid_period(): void
    {
        // As of Mar 5: current period Feb 15 → Mar 15 (28 days), 18 elapsed.
        // Accrued = 9166.67 × 1% × 18/28 = 58.93
        $quote = PayoffQuoter::quote(
            $this->terms, $this->schedule, $this->duesAfterFirstPayment(),
            new DateTimeImmutable('2026-03-05')
        );

        $this->assertSame('9166.67', $quote->principalOutstanding->toDecimalString());
        $this->assertSame('0.00', $quote->pastDueInterest->toDecimalString());
        $this->assertSame('58.93', $quote->accruedInterest->toDecimalString());
        $this->assertSame('0.00', $quote->penalty->toDecimalString());
        $this->assertSame('9225.60', $quote->total()->toDecimalString());
    }

    public function test_full_period_mode_charges_scheduled_interest(): void
    {
        // Installment #2's full scheduled interest is 91.67.
        $quote = PayoffQuoter::quote(
            $this->terms, $this->schedule, $this->duesAfterFirstPayment(),
            new DateTimeImmutable('2026-03-05'),
            EarlyPayoffInterestMode::FullPeriod
        );

        $this->assertSame('91.67', $quote->accruedInterest->toDecimalString());
    }

    public function test_overdue_installment_counts_as_past_due_interest(): void
    {
        // As of Apr 1: #2 (due Mar 15) unpaid → past-due interest 91.67 + its 4.17 penalty.
        // Current period #3: Mar 15 → Apr 15 (31 days), 17 elapsed.
        // Accrued = 9166.67 × 1% × 17/31 = 50.27
        $dues = $this->duesAfterFirstPayment();
        $second = $dues[1];
        $dues[1] = new InstallmentDue(2, $second->dueDate, $second->principalDue, $second->interestDue, Money::of('4.17'));

        $quote = PayoffQuoter::quote($this->terms, $this->schedule, $dues, new DateTimeImmutable('2026-04-01'));

        $this->assertSame('9166.67', $quote->principalOutstanding->toDecimalString());
        $this->assertSame('91.67', $quote->pastDueInterest->toDecimalString());
        $this->assertSame('50.27', $quote->accruedInterest->toDecimalString());
        $this->assertSame('4.17', $quote->penalty->toDecimalString());
    }

    public function test_quote_on_due_date_has_no_extra_accrual(): void
    {
        // As of exactly Feb 15 with nothing paid: #1 is past due (due <= asOf),
        // current period is #2 with 0 days elapsed.
        $dues = array_map(
            fn ($i) => InstallmentDue::fromInstallment($i),
            $this->schedule->installments
        );

        $quote = PayoffQuoter::quote($this->terms, $this->schedule, $dues, new DateTimeImmutable('2026-02-15'));

        $this->assertSame('10000.00', $quote->principalOutstanding->toDecimalString());
        $this->assertSame('100.00', $quote->pastDueInterest->toDecimalString());
        $this->assertSame('0.00', $quote->accruedInterest->toDecimalString());
    }

    public function test_actual_365_accrual_uses_daily_rate(): void
    {
        // 36.5%/yr = 0.1%/day. Disbursed Jan 15, quote Jan 25 (10 days, period #1).
        // Accrued = 1000.00 × 0.001 × 10 = 10.00
        $terms = new LoanTerms(
            principal: Money::of('1000.00'),
            annualRatePercent: 36.5,
            termCount: 2,
            frequency: RepaymentFrequency::Monthly,
            method: InterestMethod::DecliningEqualPrincipal,
            disbursedAt: new DateTimeImmutable('2026-01-15'),
            basis: AccrualBasis::Actual365,
        );
        $schedule = ScheduleGenerator::generate($terms);
        $dues = array_map(fn ($i) => InstallmentDue::fromInstallment($i), $schedule->installments);

        $quote = PayoffQuoter::quote($terms, $schedule, $dues, new DateTimeImmutable('2026-01-25'));

        $this->assertSame('1000.00', $quote->principalOutstanding->toDecimalString());
        $this->assertSame('10.00', $quote->accruedInterest->toDecimalString());
    }

    public function test_prorated_quote_nets_out_prepaid_current_period_interest(): void
    {
        // #1 settled; #2's interest (91.67) prepaid 40.00 through an
        // overpayment waterfall → interestDue 51.67.
        $dues = $this->duesAfterFirstPayment();
        $second = $dues[1];
        $dues[1] = new InstallmentDue(
            2, $second->dueDate, $second->principalDue,
            $second->interestDue->sub(Money::of('40.00')), $second->penaltyDue
        );

        // Gross prorated accrual as of Mar 5 is 58.93 (see base case);
        // 40.00 of it is already paid → only 18.93 may be charged.
        $quote = PayoffQuoter::quote(
            $this->terms, $this->schedule, $dues, new DateTimeImmutable('2026-03-05')
        );

        $this->assertSame('18.93', $quote->accruedInterest->toDecimalString());
    }

    public function test_prorated_quote_never_negative_when_prepaid_exceeds_accrual(): void
    {
        // #2's interest fully prepaid: interestDue 0 → nothing more may
        // accrue, and both modes agree the current period charges zero.
        $dues = $this->duesAfterFirstPayment();
        $second = $dues[1];
        $dues[1] = new InstallmentDue(2, $second->dueDate, $second->principalDue, Money::zero(), $second->penaltyDue);

        foreach ([EarlyPayoffInterestMode::Prorated, EarlyPayoffInterestMode::FullPeriod] as $mode) {
            $quote = PayoffQuoter::quote(
                $this->terms, $this->schedule, $dues, new DateTimeImmutable('2026-03-05'), $mode
            );

            $this->assertSame('0.00', $quote->accruedInterest->toDecimalString(), $mode->value);
        }
    }

    public function test_fully_paid_loan_quotes_zero(): void
    {
        $dues = array_map(
            fn ($i) => new InstallmentDue($i->number, $i->dueDate, Money::zero(), Money::zero(), Money::zero()),
            $this->schedule->installments
        );

        $quote = PayoffQuoter::quote($this->terms, $this->schedule, $dues, new DateTimeImmutable('2026-06-01'));

        $this->assertTrue($quote->total()->isZero());
    }
}
