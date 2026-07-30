<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LoanStatus;
use App\Models\Borrower;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanScheduleService;
use App\Services\RepaymentService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use LoanEngine\Money;
use Tests\TestCase;

class PolishBatchTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'pb-'.uniqid().'@example.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    public function test_processing_fee_is_stored_and_posted_at_disbursement(): void
    {
        // 2% + 5.00 flat on 1,000.00 → fee 25.00
        $product = LoanProduct::create([
            'name' => 'Fee Product', 'code' => 'FEE-'.uniqid(),
            'annual_rate' => 12.0, 'term_count' => 6,
            'processing_fee_percent' => 2.0,
            'processing_fee_flat_minor' => 500,
        ])->refresh();

        $borrower = Borrower::create(['first_name' => 'Fee', 'last_name' => 'Test']);

        $loan = Loan::create([
            'loan_number' => 'FEE-'.uniqid(),
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'currency' => $product->currency,
            'scale' => $product->scale,
            'principal_minor' => Money::of('1000.00')->minor,
            'fee_minor' => 2500,
            'annual_rate' => 12.0,
            'term_count' => 6,
            'method' => $product->method->value,
            'frequency' => $product->frequency->value,
            'basis' => $product->basis->value,
            'disbursed_at' => '2026-01-15',
            'status' => LoanStatus::Approved,
        ]);

        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Loans\Show::class, ['loan' => $loan->id])
            ->call('activate')
            ->assertSet('actionError', null);

        $entry = JournalEntry::where('reference_type', 'loan')->where('reference_id', $loan->id)->first();
        $lines = $entry->lines()->with('account')->get();

        $feeLine = $lines->firstWhere(fn ($l) => $l->account->code === '4200');
        $this->assertNotNull($feeLine);
        $this->assertSame(2500, (int) $feeLine->credit_minor);
        $this->assertSame((int) $lines->sum('debit_minor'), (int) $lines->sum('credit_minor'));
    }

    public function test_branch_pages_render_for_manager(): void
    {
        $manager = User::create([
            'name' => 'Mgr', 'email' => 'mgr-'.uniqid().'@example.com',
            'password' => bcrypt('x'), 'role' => 'manager',
        ]);

        $this->actingAs($manager)->get('/branches')->assertOk()->assertSee('Branches');
        $this->actingAs($manager)->get('/branches/create')->assertOk();

        $officer = User::create([
            'name' => 'Off', 'email' => 'off-'.uniqid().'@example.com',
            'password' => bcrypt('x'), 'role' => 'loan_officer',
        ]);
        $this->actingAs($officer)->get('/branches')->assertForbidden();
    }

    public function test_receipt_page_renders_for_payment(): void
    {
        $product = LoanProduct::create([
            'name' => 'R', 'code' => 'RCP-'.uniqid(), 'annual_rate' => 12.0, 'term_count' => 6,
        ])->refresh();
        $borrower = Borrower::create(['first_name' => 'Receipt', 'last_name' => 'Guy']);
        $loan = Loan::create([
            'loan_number' => 'RCP-'.uniqid(),
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'currency' => 'USD', 'scale' => 2,
            'principal_minor' => Money::of('1200.00')->minor,
            'annual_rate' => 12.0, 'term_count' => 6,
            'method' => $product->method->value,
            'frequency' => 'monthly', 'basis' => 'equal_periods',
            'disbursed_at' => '2026-01-15',
            'status' => LoanStatus::Approved,
        ]);
        app(LoanScheduleService::class)->generateAndPersist($loan);
        $loan->update(['status' => LoanStatus::Active]);

        $payment = app(RepaymentService::class)->record($loan->refresh(), Money::of('100.00'), new DateTimeImmutable('2026-02-15'));

        $this->actingAs($this->admin())
            ->get("/loans/{$loan->id}/payments/{$payment->id}/receipt")
            ->assertOk()
            ->assertSee('Payment Receipt')
            ->assertSee($loan->loan_number);
    }

    public function test_dashboard_renders_with_chart(): void
    {
        $this->actingAs($this->admin())
            ->get('/')
            ->assertOk()
            ->assertSee('Collections — last 6 months');
    }

    public function test_demo_reset_refuses_outside_demo_mode(): void
    {
        config(['lms.demo' => false]);

        $this->artisan('demo:reset')->assertFailed();
    }

    public function test_demo_mode_blocks_user_changes(): void
    {
        config(['lms.demo' => true]);

        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Users\Form::class)
            ->set('name', 'Hacker')
            ->set('email', 'hacker@example.com')
            ->set('password', 'password123')
            ->call('save')
            ->assertHasErrors('name');

        $this->assertNull(User::where('email', 'hacker@example.com')->first());
    }
}
