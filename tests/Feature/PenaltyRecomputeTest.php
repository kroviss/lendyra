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
 * Penalty is a pure function of (schedule, dated payment history,
 * waivers, as-of) — regression coverage for the old max-ratchet that
 * (a) billed backdated payments for penalty accrued AFTER their
 * effective date and (b) froze accrual after a partial payment shrank
 * the base.
 *
 * Loan under test: 6,000.00 @ 12%/yr, 6 monthly declining installments,
 * disbursed 2026-01-15 → #1 due 2026-02-15 owes 1,000.00 P + 60.00 I,
 * penalty 0.5%/day on overdue principal.
 */
class PenaltyRecomputeTest extends TestCase
{
    use DatabaseTransactions;

    private function makeActiveLoan(): Loan
    {
        $product = LoanProduct::create([
            'name' => 'PR', 'code' => 'PR-'.uniqid(), 'annual_rate' => 12.0,
            'term_count' => 6, 'penalty_daily_rate' => 0.5,
        ])->refresh();
        $borrower = Borrower::create(['first_name' => 'Penalty', 'last_name' => 'Recompute']);
        $loan = Loan::create([
            'loan_number' => 'PR-'.uniqid(),
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'currency' => 'USD', 'scale' => 2,
            'principal_minor' => Money::of('6000.00')->minor,
            'annual_rate' => 12.0, 'term_count' => 6,
            'method' => $product->method->value,
            'frequency' => 'monthly', 'basis' => 'equal_periods',
            'disbursed_at' => '2026-01-15',
            'status' => LoanStatus::Approved,
        ]);
        app(LoanScheduleService::class)->generateAndPersist($loan);
        $loan->update(['status' => LoanStatus::Active]);

        return $loan->refresh();
    }

    /**
     * (a) A bank transfer that arrived ON TIME but was keyed after the
     * cron accrued penalty: recording it backdated to the due date must
     * settle the installment in full with zero penalty diverted.
     */
    public function test_backdated_on_time_payment_settles_without_penalty(): void
    {
        $loan = $this->makeActiveLoan();

        // Nightly cron runs on Mar 10: 23 days × 0.5% × 1,000.00 = 115.00.
        app(PenaltyService::class)->accrue($loan, new DateTimeImmutable('2026-03-10'));
        $first = $loan->installments()->where('number', 1)->first();
        $this->assertSame(11500, (int) $first->penalty_minor);

        // Exact installment amount, backdated to the due date.
        $payment = app(RepaymentService::class)->record(
            $loan->refresh(), Money::of('1060.00'), new DateTimeImmutable('2026-02-15')
        );

        $this->assertSame(0, (int) $payment->unallocated_minor);
        $this->assertSame(
            0,
            (int) $payment->allocations()->where('component', AllocationComponent::Penalty->value)->sum('amount_minor')
        );

        $first->refresh();
        $this->assertTrue($first->isSettled());
        $this->assertSame('2026-02-15', $first->settled_at->format('Y-m-d'));
        $this->assertSame(0, (int) $first->penalty_minor);

        // A later cron run leaves the settled row alone and still
        // accrues normally on the next overdue installment.
        app(PenaltyService::class)->accrue($loan->refresh(), new DateTimeImmutable('2026-03-20'));
        $this->assertSame(0, (int) $first->fresh()->penalty_minor);
        $second = $loan->installments()->where('number', 2)->first();
        $this->assertSame(2500, (int) $second->penalty_minor); // 5 days × 0.5% × 1,000.00
    }

    /**
     * (b) Partial payment on an overdue installment: penalty accrued up
     * to the payment date on the old base is retained AND accrual
     * continues on the reduced base — no freeze, no loss.
     */
    public function test_partial_payment_keeps_accrued_penalty_and_keeps_accruing_on_reduced_base(): void
    {
        $loan = $this->makeActiveLoan();

        // Mar 10 (23 days overdue): record() accrues first → penalty
        // 115.00; the waterfall takes 115.00 penalty + 60.00 interest +
        // 500.00 principal from a 675.00 payment.
        app(RepaymentService::class)->record(
            $loan, Money::of('675.00'), new DateTimeImmutable('2026-03-10')
        );

        $first = $loan->installments()->where('number', 1)->first();
        $this->assertSame(11500, (int) $first->penalty_minor);
        $this->assertSame(11500, (int) $first->penalty_paid_minor);
        $this->assertSame(50000, $first->principalDue()->minor);

        // Ten days later: 115.00 retained + 10 days × 0.5% × 500.00 =
        // 25.00 new accrual. The old ratchet froze here (from-scratch on
        // 500.00 for 33 days = 82.50 < stored 115.00 → no growth).
        app(PenaltyService::class)->accrue($loan->refresh(), new DateTimeImmutable('2026-03-20'));

        $first->refresh();
        $this->assertSame(14000, (int) $first->penalty_minor);
        $this->assertSame(2500, $first->penaltyDue()->minor);
    }

    /**
     * Reversal recompute: after reversing a payment that had paid
     * penalty and principal, stored penalty lands exactly where it
     * would be had the payment never happened.
     */
    public function test_reversal_reaccrues_as_if_the_payment_never_happened(): void
    {
        $loan = $this->makeActiveLoan();

        $payment = app(RepaymentService::class)->record(
            $loan, Money::of('675.00'), new DateTimeImmutable('2026-03-10')
        );

        app(RepaymentService::class)->reverse($payment->fresh());

        $first = $loan->installments()->where('number', 1)->first();
        $this->assertSame(0, (int) $first->penalty_paid_minor);
        $this->assertSame(100000, $first->principalDue()->minor);

        // Full base for the whole overdue stretch: days(Feb 15 → today) × 5.00.
        $days = (int) (new DateTimeImmutable('2026-02-15'))
            ->diff(new DateTimeImmutable(today()->format('Y-m-d')))->format('%a');
        $this->assertSame(500 * $days, (int) $first->penalty_minor);
    }

    /** (c) settle → reverse → re-settle books the identical amount. */
    public function test_settle_reverse_resettle_is_exactly_idempotent(): void
    {
        $loan = $this->makeActiveLoan();
        $asOf = new DateTimeImmutable('2026-03-10');

        $firstSettle = app(PayoffService::class)->settle($loan, $asOf);
        $this->assertSame(LoanStatus::Closed, $loan->fresh()->status);

        $snapshot = $loan->installments()->get()
            ->map(fn ($i) => [$i->number, (int) $i->penalty_minor, (int) $i->interest_minor])
            ->all();

        app(RepaymentService::class)->reverse($firstSettle->fresh());
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);

        // The reversal re-accrued penalties as of TODAY (stale, larger
        // figures than the payoff date's) — re-settling at the original
        // effective date must pull them back down and book the exact
        // same amount as the first settlement.
        $secondSettle = app(PayoffService::class)->settle($loan->fresh(), $asOf);

        $this->assertSame((int) $firstSettle->amount_minor, (int) $secondSettle->amount_minor);
        $this->assertSame(LoanStatus::Closed, $loan->fresh()->status);

        $after = $loan->installments()->get()
            ->map(fn ($i) => [$i->number, (int) $i->penalty_minor, (int) $i->interest_minor])
            ->all();
        $this->assertSame($snapshot, $after);
        $this->assertTrue($loan->fresh()->installments->every(fn ($i) => $i->isSettled()));
    }
}
