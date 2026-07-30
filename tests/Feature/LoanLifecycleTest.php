<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LoanStatus;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanScheduleService;
use App\Services\PayoffService;
use App\Services\PenaltyService;
use App\Services\RepaymentService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LoanEngine\AllocationComponent;
use LoanEngine\Money;
use Tests\TestCase;

/**
 * Full lifecycle against the real database: product → loan → schedule →
 * partial payment → penalty accrual → payoff quote → early settlement.
 * Numbers follow the engine's golden case: 10,000.00 @ 12%/yr,
 * 12 monthly declining installments, disbursed 2026-01-15.
 */
class LoanLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();

        $product = LoanProduct::create([
            'name' => 'Business Loan',
            'code' => 'BIZ-'.uniqid(),
            'annual_rate' => 12.0,
            'term_count' => 12,
            'penalty_daily_rate' => 0.5,
            'penalty_grace_days' => 0,
        ])->refresh(); // pull DB column defaults (method, currency, ...) into the model

        $borrower = Borrower::create(['first_name' => 'Test', 'last_name' => 'Borrower']);

        $this->loan = Loan::create([
            'loan_number' => 'LN-'.uniqid(),
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'currency' => $product->currency,
            'scale' => $product->scale,
            'principal_minor' => Money::of('10000.00')->minor,
            'annual_rate' => $product->annual_rate,
            'term_count' => $product->term_count,
            'method' => $product->method->value,
            'frequency' => $product->frequency->value,
            'basis' => $product->basis->value,
            'disbursed_at' => '2026-01-15',
            'status' => LoanStatus::Approved,
        ]);
    }

    private function activate(): void
    {
        app(LoanScheduleService::class)->generateAndPersist($this->loan);
        $this->loan->update(['status' => LoanStatus::Active]);
        $this->loan->refresh();
    }

    public function test_schedule_persists_with_exact_sums(): void
    {
        $this->activate();

        $installments = $this->loan->installments;

        $this->assertCount(12, $installments);
        $this->assertSame('2026-02-15', $installments[0]->due_date->format('Y-m-d'));
        $this->assertSame(1000000, (int) $installments->sum('principal_minor'));
        $this->assertSame(65000, (int) $installments->sum('interest_minor')); // 650.00 golden
    }

    public function test_schedule_cannot_regenerate_after_activation(): void
    {
        $this->activate();

        $this->expectException(\LogicException::class);
        app(LoanScheduleService::class)->generateAndPersist($this->loan);
    }

    public function test_partial_payment_allocates_and_settles_first_installment(): void
    {
        $this->activate();

        // #1 owes 100.00 interest + 833.33 principal = 933.33
        $payment = app(RepaymentService::class)->record(
            $this->loan,
            Money::of('933.33'),
            new DateTimeImmutable('2026-02-15')
        );

        $this->assertSame(0, (int) $payment->unallocated_minor);
        $this->assertCount(2, $payment->allocations);

        $first = $this->loan->installments()->where('number', 1)->first();
        $this->assertTrue($first->isSettled());
        $this->assertSame('2026-02-15', $first->settled_at->format('Y-m-d'));

        $this->loan->refresh();
        $this->assertSame(LoanStatus::Active, $this->loan->status);
        $this->assertSame('9166.67', $this->loan->principalOutstanding()->toDecimalString());
    }

    public function test_penalty_accrual_is_idempotent(): void
    {
        $this->activate();
        $asOf = new DateTimeImmutable('2026-02-25'); // #1 due Feb 15 → 10 days overdue

        $service = app(PenaltyService::class);
        $service->accrue($this->loan, $asOf);
        $service->accrue($this->loan, $asOf); // running twice must change nothing

        $first = $this->loan->installments()->where('number', 1)->first();

        // 833.33 overdue principal × 0.5%/day × 10 days = 41.67
        $this->assertSame(4167, (int) $first->penalty_minor);
    }

    public function test_payment_waterfall_pays_penalty_first(): void
    {
        $this->activate();
        app(PenaltyService::class)->accrue($this->loan, new DateTimeImmutable('2026-02-25'));

        $payment = app(RepaymentService::class)->record(
            $this->loan->refresh(),
            Money::of('50.00'),
            new DateTimeImmutable('2026-02-25')
        );

        $this->assertSame(AllocationComponent::Penalty, $payment->allocations[0]->component);
        $this->assertSame(4167, (int) $payment->allocations[0]->amount_minor);
        $this->assertSame(AllocationComponent::Interest, $payment->allocations[1]->component);
        $this->assertSame(833, (int) $payment->allocations[1]->amount_minor); // 50.00 − 41.67
    }

    public function test_payoff_quote_and_early_settlement_close_the_loan(): void
    {
        $this->activate();

        // Pay installment #1 exactly on time.
        app(RepaymentService::class)->record(
            $this->loan,
            Money::of('933.33'),
            new DateTimeImmutable('2026-02-15')
        );
        $this->loan->refresh();

        // Quote at Mar 5: principal 9166.67 + accrued 18/28 of 91.67 = 58.93.
        $payoff = app(PayoffService::class);
        $quote = $payoff->quote($this->loan, new DateTimeImmutable('2026-03-05'));

        $this->assertSame('9166.67', $quote->principalOutstanding->toDecimalString());
        $this->assertSame('58.93', $quote->accruedInterest->toDecimalString());
        $this->assertSame('0.00', $quote->pastDueInterest->toDecimalString());
        $this->assertSame('9225.60', $quote->total()->toDecimalString());

        // Settle in full.
        $payment = $payoff->settle($this->loan, new DateTimeImmutable('2026-03-05'));

        $this->assertSame(922560, (int) $payment->amount_minor);
        $this->assertSame(0, (int) $payment->unallocated_minor);

        $this->loan->refresh();
        $this->assertSame(LoanStatus::Closed, $this->loan->status);
        $this->assertSame('2026-03-05', $this->loan->closed_at->format('Y-m-d'));
        $this->assertTrue($this->loan->principalOutstanding()->isZero());
        $this->assertTrue(
            $this->loan->installments->every(fn ($i) => $i->isSettled())
        );
    }

    public function test_payment_rejected_on_non_active_loan(): void
    {
        $this->expectException(\LogicException::class);

        app(RepaymentService::class)->record(
            $this->loan, // still Approved, never activated
            Money::of('100.00'),
            new DateTimeImmutable('2026-02-15')
        );
    }

    public function test_currency_mismatch_rejected(): void
    {
        $this->activate();

        $this->expectException(\InvalidArgumentException::class);

        app(RepaymentService::class)->record(
            $this->loan,
            Money::of('100.00', 'MNT'),
            new DateTimeImmutable('2026-02-15')
        );
    }
}
