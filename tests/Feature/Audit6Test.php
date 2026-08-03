<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LoanStatus;
use App\Livewire\Loans\Form;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanScheduleService;
use App\Services\PayoffService;
use App\Services\PenaltyService;
use App\Services\RepaymentService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use LoanEngine\Money;
use LogicException;
use Tests\TestCase;

class Audit6Test extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@example.com',
            'password' => bcrypt('secret123'), 'role' => $role, 'is_active' => true,
        ]);
    }

    private function makeActiveLoan(
        float $penaltyRate = 0.5,
        string $principal = '6000.00',
        int $terms = 6,
        float $rate = 12.0,
        string $disbursedAt = '2026-01-15',
        array $productOverrides = [],
    ): Loan {
        $product = LoanProduct::create([
            'name' => 'A6', 'code' => 'A6-'.uniqid(), 'annual_rate' => $rate,
            'term_count' => $terms, 'penalty_daily_rate' => $penaltyRate,
        ] + $productOverrides)->refresh();
        $borrower = Borrower::create(['first_name' => 'Audit', 'last_name' => 'Six', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $loan = Loan::create([
            'loan_number' => 'A6-'.uniqid(),
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'currency' => 'USD', 'scale' => 2,
            'principal_minor' => Money::of($principal)->minor,
            'annual_rate' => $rate, 'term_count' => $terms,
            'method' => $product->method->value,
            'frequency' => 'monthly', 'basis' => 'equal_periods',
            'disbursed_at' => $disbursedAt,
            'status' => LoanStatus::Approved,
        ]);
        app(LoanScheduleService::class)->generateAndPersist($loan);
        $loan->update(['status' => LoanStatus::Active]);

        return $loan->refresh();
    }

    /** A waived penalty must not be re-billed by the next accrual run. */
    public function test_waiver_survives_subsequent_accrual_and_payment(): void
    {
        $loan = $this->makeActiveLoan();
        $penalties = app(PenaltyService::class);

        // Installment #1 (due 2026-02-15) is long overdue by real today.
        $penalties->accrue($loan, new DateTimeImmutable(today()->format('Y-m-d')));
        $first = $loan->installments()->where('number', 1)->first();
        $this->assertGreaterThan(0, $first->penaltyDue()->minor);

        $waived = $penalties->waive($loan, $first->id);
        $this->assertGreaterThan(0, $waived->minor);
        $this->assertSame(0, $first->fresh()->penaltyDue()->minor);

        // The nightly cron re-running as of today must not resurrect it.
        $penalties->accrue($loan->fresh(), new DateTimeImmutable(today()->format('Y-m-d')));
        $first->refresh();
        $this->assertSame(0, $first->penaltyDue()->minor);
        $this->assertSame($waived->minor, (int) $first->penalty_waived_minor);

        // A payment for exactly P+I must not have money diverted into a
        // resurrected penalty (record() accrues before allocating).
        $due = $first->principalDue()->add($first->interestDue());
        $payment = app(RepaymentService::class)->record($loan->fresh(), $due, new DateTimeImmutable(today()->format('Y-m-d')));

        $this->assertSame(0, (int) $payment->allocations()->where('component', 'penalty')->sum('amount_minor'));
        $this->assertNotNull($first->fresh()->settled_at);
    }

    /** Prepaid interest on installments BEYOND the current one nets out of a payoff. */
    public function test_payoff_nets_prepaid_interest_beyond_current_installment(): void
    {
        // 24%/yr → 2%/month declining on 12,000: #1 owes 240.00 interest.
        $loan = $this->makeActiveLoan(
            penaltyRate: 0, principal: '12000.00', terms: 12, rate: 24.0,
            productOverrides: ['payoff_interest_mode' => 'prorated'],
        );

        // Simulate a waterfall that prepaid 50.00 of installment #3's
        // interest (component-across mode spreads overpayments this way).
        $loan->installments()->where('number', 3)->update(['interest_paid_minor' => 5000]);

        // Payoff Feb 1: current period = #1 (due Feb 15), 17 of 31 days
        // elapsed → gross accrual 240.00 × 17/31 = 131.61.
        $quote = app(PayoffService::class)->quote($loan->fresh(), new DateTimeImmutable('2026-02-01'));
        $this->assertSame('81.61', $quote->accruedInterest->toDecimalString());

        // FullPeriod mode nets the same prepayment against the full
        // period's 240.00.
        $loan->product->update(['payoff_interest_mode' => 'full_period']);
        $fullQuote = app(PayoffService::class)->quote($loan->fresh(), new DateTimeImmutable('2026-02-01'));
        $this->assertSame('190.00', $fullQuote->accruedInterest->toDecimalString());
        $loan->product->update(['payoff_interest_mode' => 'prorated']);

        // Settling books exactly the quote and closes the loan.
        $payment = app(PayoffService::class)->settle($loan->fresh(), new DateTimeImmutable('2026-02-01'));
        $this->assertSame($quote->total()->minor, (int) $payment->amount_minor);
        $this->assertSame(0, (int) $payment->unallocated_minor);
        $this->assertSame(LoanStatus::Closed, $loan->fresh()->status);
    }

    /** A payoff dated before disbursement must be rejected, not waive all interest. */
    public function test_payoff_before_disbursement_is_rejected(): void
    {
        $loan = $this->makeActiveLoan();

        $this->expectException(LogicException::class);
        app(PayoffService::class)->settle($loan, new DateTimeImmutable('2026-01-01'));
    }

    /** A cashier typing "payoff" as a reference must not turn an ordinary payment into one. */
    public function test_free_text_payoff_reference_does_not_restore_schedule_on_reversal(): void
    {
        $loan = $this->makeActiveLoan(penaltyRate: 0);

        $ordinary = app(RepaymentService::class)->record(
            $loan, Money::of('100.00'), new DateTimeImmutable('2026-02-01'), reference: 'payoff',
        );
        $this->assertFalse((bool) $ordinary->is_payoff);

        $payoff = app(PayoffService::class)->settle($loan->fresh(), new DateTimeImmutable('2026-03-01'));
        $this->assertTrue((bool) $payoff->fresh()->is_payoff);
        $this->assertSame(LoanStatus::Closed, $loan->fresh()->status);

        // The payoff waived future interest; installment #6's interest was
        // rewritten down to what was paid on it.
        $waivedInterest = (int) $loan->installments()->where('number', 6)->first()->interest_minor;

        // Reversing the ORDINARY payment must not restore the contractual
        // schedule — only reversing the payoff payment may do that.
        app(RepaymentService::class)->reverse($ordinary->fresh());

        $this->assertSame(
            $waivedInterest,
            (int) $loan->installments()->where('number', 6)->first()->interest_minor,
        );
    }

    /** Product principal limits are enforced at origination. */
    public function test_product_principal_limits_enforced(): void
    {
        $product = LoanProduct::create([
            'name' => 'Limited', 'code' => 'LIM-'.uniqid(), 'annual_rate' => 12,
            'term_count' => 6, 'penalty_daily_rate' => 0,
            'min_principal_minor' => 100000, // 1,000.00
            'max_principal_minor' => 500000, // 5,000.00
        ]);
        $borrower = Borrower::create(['first_name' => 'Lim', 'last_name' => 'It', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $officer = $this->makeUser('loan_officer');

        Livewire::actingAs($officer)
            ->test(Form::class)
            ->set('borrower_id', $borrower->id)
            ->set('loan_product_id', $product->id)
            ->set('amount', 500.0)
            ->call('save')
            ->assertHasErrors('amount');

        Livewire::actingAs($officer)
            ->test(Form::class)
            ->set('borrower_id', $borrower->id)
            ->set('loan_product_id', $product->id)
            ->set('amount', 9000.0)
            ->call('save')
            ->assertHasErrors('amount');

        Livewire::actingAs($officer)
            ->test(Form::class)
            ->set('borrower_id', $borrower->id)
            ->set('loan_product_id', $product->id)
            ->set('amount', 3000.0)
            ->call('save')
            ->assertHasNoErrors();
    }
}
