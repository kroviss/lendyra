<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LoanStatus;
use App\Livewire\Borrowers\Show as BorrowerShow;
use App\Livewire\Loans\Form as LoanForm;
use App\Livewire\Loans\Show as LoanShow;
use App\Livewire\Products\Form as ProductForm;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\LoanScheduleService;
use App\Services\RepaymentService;
use App\Support\CurrencyScale;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use LoanEngine\AllocationComponent;
use LoanEngine\AllocationMode;
use LoanEngine\AllocationPolicy;
use LoanEngine\Money;
use Tests\TestCase;

/**
 * Tenth-audit regressions: first-period repricing on activation, the
 * currency→scale map, the payment waterfall surface, product term limits
 * and orphaned private files.
 */
class Audit10Test extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'A10 Admin', 'email' => 'a10-'.uniqid().'@example.com',
            'password' => 'password', 'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function product(array $overrides = []): LoanProduct
    {
        $product = LoanProduct::create([
            'name' => 'A10 Product', 'code' => 'A10-'.strtoupper(substr(uniqid(), -8)),
            'currency' => 'USD', 'scale' => 2,
            'method' => 'declining_equal_principal', 'frequency' => 'monthly', 'basis' => 'equal_periods',
            'annual_rate' => 24.0, 'term_count' => 6,
            'penalty_daily_rate' => 0.5, 'penalty_grace_days' => 0, 'penalty_base' => 'overdue_principal',
            'payoff_interest_mode' => 'prorated', 'allocation_mode' => 'oldest_installment_first',
        ]);
        $product->update($overrides);

        return $product->refresh();
    }

    /** An approved loan whose first due date was planned against an older disbursement date. */
    private function approvedLoan(LoanProduct $product, string $disbursedAt, ?string $firstDue): Loan
    {
        return Loan::create([
            'loan_number' => 'A10-'.uniqid(),
            'borrower_id' => Borrower::create(['first_name' => 'A10'])->id,
            'loan_product_id' => $product->id,
            'currency' => $product->currency, 'scale' => $product->scale,
            'principal_minor' => 600000,
            'annual_rate' => $product->annual_rate, 'term_count' => $product->term_count,
            'method' => $product->method->value, 'frequency' => $product->frequency->value,
            'basis' => $product->basis->value,
            'penalty_daily_rate' => $product->penalty_daily_rate,
            'penalty_grace_days' => $product->penalty_grace_days,
            'penalty_base' => $product->penalty_base->value,
            'penalty_cap_percent' => $product->penalty_cap_percent,
            'payoff_interest_mode' => $product->payoff_interest_mode->value,
            'disbursed_at' => $disbursedAt,
            'first_due_date' => $firstDue,
            'status' => LoanStatus::Approved,
        ]);
    }

    // ── M1: re-anchoring disbursement must never silently reprice period 1 ──

    public function test_activation_refuses_a_first_due_date_that_makes_period_one_a_stub(): void
    {
        $loan = $this->approvedLoan(
            $this->product(),
            today()->subDays(28)->format('Y-m-d'),
            today()->addDays(3)->format('Y-m-d'),
        );

        Livewire::actingAs($this->admin())
            ->test(LoanShow::class, ['loan' => $loan->id])
            ->call('activate')
            ->assertSee('Edit the loan', false);

        // Nothing was disbursed: no schedule, no ledger entry, still approved.
        $loan->refresh();
        $this->assertSame(LoanStatus::Approved, $loan->status);
        $this->assertSame(0, $loan->installments()->count());
    }

    public function test_daily_accrual_bases_price_a_stub_correctly_so_activation_proceeds(): void
    {
        $loan = $this->approvedLoan(
            $this->product(['basis' => 'actual_365']),
            today()->subDays(28)->format('Y-m-d'),
            today()->addDays(3)->format('Y-m-d'),
        );

        Livewire::actingAs($this->admin())
            ->test(LoanShow::class, ['loan' => $loan->id])
            ->call('activate');

        $loan->refresh();
        $this->assertSame(LoanStatus::Active, $loan->status);

        // 3 days of daily interest, not a full month.
        $first = $loan->installments()->orderBy('number')->first();
        $this->assertLessThan(2000, (int) $first->interest_minor);
    }

    public function test_activation_explains_a_first_due_date_that_is_now_in_the_past(): void
    {
        $loan = $this->approvedLoan(
            $this->product(),
            today()->subMonths(3)->format('Y-m-d'),
            today()->subMonths(2)->format('Y-m-d'),
        );

        Livewire::actingAs($this->admin())
            ->test(LoanShow::class, ['loan' => $loan->id])
            ->call('activate')
            ->assertSee('on or before today', false)
            // Never the engine's bare internal message.
            ->assertDontSee('First due date must be after disbursement.', false);

        $this->assertSame(LoanStatus::Approved, $loan->refresh()->status);
    }

    public function test_a_sane_first_due_date_still_activates(): void
    {
        $loan = $this->approvedLoan(
            $this->product(),
            today()->subDays(5)->format('Y-m-d'),
            today()->addMonth()->format('Y-m-d'),
        );

        Livewire::actingAs($this->admin())
            ->test(LoanShow::class, ['loan' => $loan->id])
            ->call('activate');

        $loan->refresh();
        $this->assertSame(LoanStatus::Active, $loan->status);
        $this->assertSame(6, $loan->installments()->count());
    }

    public function test_loan_form_warns_about_a_stub_first_period_without_blocking_it(): void
    {
        $product = $this->product();
        $borrower = Borrower::create(['first_name' => 'A10 Warn']);

        $component = Livewire::actingAs($this->admin())
            ->test(LoanForm::class)
            ->set('loan_product_id', $product->id)
            ->set('borrower_id', $borrower->id)
            ->set('amount', 6000)
            ->set('term_count', '6')
            ->set('disbursed_at', today()->format('Y-m-d'))
            ->set('first_due_date', today()->addDays(3)->format('Y-m-d'));

        $this->assertStringContainsString('Period 1 is only', (string) $component->instance()->firstPeriodWarning);

        // A deliberate calendar anchor is still allowed at origination.
        $component->call('save')->assertHasNoErrors();

        $component->set('first_due_date', today()->addMonth()->format('Y-m-d'));
        $this->assertNull($component->instance()->firstPeriodWarning);
    }

    // ── M2: the currency→scale map must survive a product re-pointing ───────

    public function test_currency_scale_falls_back_to_loans_for_a_currency_no_product_carries(): void
    {
        $product = $this->product(['currency' => 'JPY', 'scale' => 0]);
        $loan = $this->approvedLoan($product, today()->subMonth()->format('Y-m-d'), null);
        app(LoanScheduleService::class)->generateAndPersist($loan);
        $loan->update(['status' => LoanStatus::Active]);

        $this->assertSame(0, app(CurrencyScale::class)->scale('JPY'));

        // The operator re-points the product at another 0-decimal currency;
        // the JPY book does not move with it.
        $product->update(['currency' => 'KRW']);
        app()->forgetInstance(CurrencyScale::class);

        $this->assertSame(0, app(CurrencyScale::class)->scale('JPY'));
        $this->assertSame('1,200,000', app(CurrencyScale::class)->money(1200000, 'JPY')->formatted());

        // A currency nothing knows about still degrades to the 2-decimal default.
        $this->assertSame(2, app(CurrencyScale::class)->scale('ZZZ'));
    }

    public function test_a_live_product_still_wins_over_the_loan_fallback(): void
    {
        $this->product(['currency' => 'BHD', 'scale' => 3]);
        app()->forgetInstance(CurrencyScale::class);

        $this->assertSame(3, app(CurrencyScale::class)->scale('BHD'));
    }

    // ── M3: the payment waterfall is configurable from the UI ──────────────

    public function test_product_form_persists_the_allocation_waterfall(): void
    {
        config()->set('lms.demo', false);

        Livewire::actingAs($this->admin())
            ->test(ProductForm::class)
            ->set('name', 'Waterfall product')
            ->set('code', 'A10W-'.strtoupper(substr(uniqid(), -6)))
            ->set('currency', 'USD')
            ->set('scale', '2')
            ->set('annual_rate', '24')
            ->set('term_count', '6')
            ->set('processing_fee_percent', '0')
            ->set('penalty_daily_rate', '0.5')
            ->set('penalty_grace_days', '0')
            ->set('allocation_mode', 'component_across_loan')
            ->set('allocation_order', 'interest,principal,penalty')
            ->call('save')
            ->assertHasNoErrors();

        $product = LoanProduct::where('name', 'Waterfall product')->firstOrFail();
        $policy = $product->allocationPolicy();

        $this->assertSame(AllocationMode::ComponentAcrossLoan, $policy->mode);
        $this->assertSame(
            [AllocationComponent::Interest, AllocationComponent::Principal, AllocationComponent::Penalty],
            $policy->order
        );

        // And it round-trips back into the form.
        Livewire::actingAs($this->admin())
            ->test(ProductForm::class, ['product' => $product])
            ->assertSet('allocation_mode', 'component_across_loan')
            ->assertSet('allocation_order', 'interest,principal,penalty');
    }

    public function test_product_form_rejects_a_waterfall_outside_the_presets(): void
    {
        config()->set('lms.demo', false);

        Livewire::actingAs($this->admin())
            ->test(ProductForm::class)
            ->set('name', 'Bad waterfall')
            ->set('code', 'A10X-'.strtoupper(substr(uniqid(), -6)))
            ->set('annual_rate', '24')
            ->set('term_count', '6')
            ->set('processing_fee_percent', '0')
            ->set('penalty_daily_rate', '0')
            ->set('penalty_grace_days', '0')
            // Would strand interest and penalty forever.
            ->set('allocation_order', 'principal')
            ->call('save')
            ->assertHasErrors('allocation_order');
    }

    public function test_a_configured_waterfall_actually_drives_allocation(): void
    {
        $product = $this->product([
            'penalty_daily_rate' => 0,
            'allocation_order' => ['interest', 'principal', 'penalty'],
        ]);

        $loan = $this->approvedLoan($product, today()->subMonths(3)->format('Y-m-d'), null);
        app(LoanScheduleService::class)->generateAndPersist($loan);
        $loan->update(['status' => LoanStatus::Active]);
        app(LedgerService::class)->postDisbursement($loan->refresh());

        $payment = app(RepaymentService::class)->record(
            $loan->refresh(), Money::of('50.00', 'USD', 2), new DateTimeImmutable(today()->format('Y-m-d'))
        );

        // Interest leads the waterfall, so a small payment lands there first.
        $this->assertSame(
            AllocationComponent::Interest,
            $payment->allocations()->orderBy('id')->first()->component
        );
    }

    public function test_allocation_policy_rejects_a_partial_order_but_the_model_repairs_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            // A hand-edited product row must never fatal the payment page…
            $product = $this->product(['allocation_order' => ['principal']]);
            $policy = $product->allocationPolicy();

            $this->assertSame(
                [AllocationComponent::Principal, AllocationComponent::Penalty, AllocationComponent::Interest],
                $policy->order
            );
            $this->assertSame('Principal → Penalty → Interest', $policy->describe());
        } catch (InvalidArgumentException $e) {
            $this->fail('the model must repair a partial order, not rethrow: '.$e->getMessage());
        }

        // …but the engine itself still refuses one outright.
        new AllocationPolicy(order: [AllocationComponent::Principal]);
    }

    // ── L1: product term limits are enforced like the principal limits ─────

    public function test_product_term_limits_are_enforced_on_the_loan_form(): void
    {
        $product = $this->product(['min_term_count' => 3, 'max_term_count' => 12]);
        $borrower = Borrower::create(['first_name' => 'A10 Term']);

        $form = fn (string $term) => Livewire::actingAs($this->admin())
            ->test(LoanForm::class)
            ->set('loan_product_id', $product->id)
            ->set('borrower_id', $borrower->id)
            ->set('amount', 6000)
            ->set('term_count', $term)
            ->set('disbursed_at', today()->format('Y-m-d'))
            ->call('save');

        $form('2')->assertHasErrors('term_count');
        $form('24')->assertHasErrors('term_count');
        $form('6')->assertHasNoErrors('term_count');
    }

    // ── L2/L3: private files and the payoff-date guard ─────────────────────

    public function test_deleting_a_borrower_removes_the_private_photo(): void
    {
        config()->set('lms.demo', false);

        $borrower = Borrower::create(['first_name' => 'A10 Photo']);
        $path = 'borrowers/a10-'.uniqid().'.jpg';
        Storage::disk('local')->put($path, 'not-really-a-jpeg');
        $borrower->update(['photo_path' => $path]);

        Livewire::actingAs($this->admin())
            ->test(BorrowerShow::class, ['borrower' => $borrower->id])
            ->call('deleteBorrower');

        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_payoff_quote_refuses_a_blank_or_garbage_date(): void
    {
        $product = $this->product(['penalty_daily_rate' => 0]);
        $loan = $this->approvedLoan($product, today()->subMonths(2)->format('Y-m-d'), null);
        app(LoanScheduleService::class)->generateAndPersist($loan);
        $loan->update(['status' => LoanStatus::Active]);

        $component = Livewire::actingAs($this->admin())->test(LoanShow::class, ['loan' => $loan->id]);

        $this->assertIsArray($component->instance()->payoffQuote);

        // new DateTimeImmutable('') is valid PHP and means "now" — a cleared
        // field must show nothing, not a confident number.
        $component->set('payoffDate', '');
        $this->assertNull($component->instance()->payoffQuote);

        $component->set('payoffDate', '2026-02-31');
        $this->assertNull($component->instance()->payoffQuote);
    }
}
