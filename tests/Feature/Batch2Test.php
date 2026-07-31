<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LoanStatus;
use App\Livewire\Loans\Form;
use App\Livewire\Loans\Show;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanScheduleService;
use App\Services\PenaltyService;
use App\Services\RepaymentService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use LoanEngine\Money;
use Tests\TestCase;

class Batch2Test extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@example.com',
            'password' => bcrypt('secret123'), 'role' => $role,
        ]);
    }

    private function makeProduct(): LoanProduct
    {
        return LoanProduct::create([
            'name' => 'B2', 'code' => 'B2-'.uniqid(), 'annual_rate' => 12.0,
            'term_count' => 6, 'penalty_daily_rate' => 0.5,
        ])->refresh();
    }

    private function makeLoan(LoanStatus $status = LoanStatus::Approved): Loan
    {
        $product = $this->makeProduct();
        $borrower = Borrower::create(['first_name' => 'Batch', 'last_name' => 'Two']);

        return Loan::create([
            'loan_number' => 'B2-'.uniqid(),
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'currency' => 'USD', 'scale' => 2,
            'principal_minor' => Money::of('3000.00')->minor,
            'annual_rate' => 12.0, 'term_count' => 6,
            'method' => $product->method->value,
            'frequency' => 'monthly', 'basis' => 'equal_periods',
            'disbursed_at' => '2026-01-15',
            'status' => $status,
        ]);
    }

    public function test_officer_created_loans_need_approval_and_officer_cannot_approve(): void
    {
        $officer = $this->makeUser('loan_officer');
        $product = $this->makeProduct();
        $borrower = Borrower::create(['first_name' => 'Maker', 'last_name' => 'Checker']);

        Livewire::actingAs($officer)
            ->test(Form::class)
            ->set('borrower_id', $borrower->id)
            ->set('loan_product_id', $product->id)
            ->set('amount', 1000.0)
            ->set('annual_rate', '12')
            ->set('term_count', '6')
            ->set('disbursed_at', '2026-08-01')
            ->call('save');

        $loan = Loan::where('borrower_id', $borrower->id)->first();
        $this->assertSame(LoanStatus::PendingApproval, $loan->status);

        Livewire::actingAs($officer)
            ->test(Show::class, ['loan' => $loan->id])
            ->call('approve');
        $this->assertSame(LoanStatus::PendingApproval, $loan->fresh()->status);

        $manager = $this->makeUser('manager');
        Livewire::actingAs($manager)
            ->test(Show::class, ['loan' => $loan->id])
            ->call('approve')
            ->assertSet('actionError', null);

        $loan->refresh();
        $this->assertSame(LoanStatus::Approved, $loan->status);
        $this->assertSame($manager->id, (int) $loan->approved_by);
    }

    public function test_admin_created_loans_skip_approval_queue(): void
    {
        $admin = $this->makeUser('admin');
        $product = $this->makeProduct();
        $borrower = Borrower::create(['first_name' => 'Direct', 'last_name' => 'Admin']);

        Livewire::actingAs($admin)
            ->test(Form::class)
            ->set('borrower_id', $borrower->id)
            ->set('loan_product_id', $product->id)
            ->set('amount', 1000.0)
            ->set('annual_rate', '12')
            ->set('term_count', '6')
            ->set('disbursed_at', '2026-08-01')
            ->call('save');

        $this->assertSame(LoanStatus::Approved, Loan::where('borrower_id', $borrower->id)->first()->status);
    }

    public function test_pre_active_loan_can_be_edited(): void
    {
        $loan = $this->makeLoan();
        $admin = $this->makeUser('admin');

        Livewire::actingAs($admin)
            ->test(Form::class, ['loan' => $loan])
            ->assertSet('amount', 3000.0)
            ->set('amount', 4500.0)
            ->call('save');

        $this->assertSame(450000, (int) $loan->fresh()->principal_minor);
    }

    public function test_active_loan_cannot_be_edited_or_deleted(): void
    {
        $loan = $this->makeLoan();
        app(LoanScheduleService::class)->generateAndPersist($loan);
        $loan->update(['status' => LoanStatus::Active]);
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->get("/loans/{$loan->id}/edit")->assertForbidden();

        Livewire::actingAs($admin)
            ->test(Show::class, ['loan' => $loan->id])
            ->call('deleteLoan')
            ->assertSet('actionError', fn ($e) => $e !== null);

        $this->assertNotNull(Loan::find($loan->id));
    }

    public function test_pre_active_loan_can_be_deleted(): void
    {
        $loan = $this->makeLoan();
        $admin = $this->makeUser('admin');

        Livewire::actingAs($admin)
            ->test(Show::class, ['loan' => $loan->id])
            ->call('deleteLoan');

        $this->assertNull(Loan::find($loan->id));
        $this->assertNotNull(Loan::withTrashed()->find($loan->id));
    }

    public function test_penalty_waiver_clears_outstanding_penalty(): void
    {
        $loan = $this->makeLoan();
        app(LoanScheduleService::class)->generateAndPersist($loan);
        $loan->update(['status' => LoanStatus::Active]);
        app(PenaltyService::class)->accrue($loan->refresh(), new DateTimeImmutable('2026-03-01'));

        $first = $loan->installments()->where('number', 1)->first();
        $this->assertGreaterThan(0, $first->penaltyDue()->minor);

        $admin = $this->makeUser('admin');
        Livewire::actingAs($admin)
            ->test(Show::class, ['loan' => $loan->id])
            ->call('waivePenalty', $first->id)
            ->assertSet('actionError', null);

        $this->assertSame(0, $first->fresh()->penaltyDue()->minor);
    }

    public function test_borrower_detail_page_shows_loans(): void
    {
        $loan = $this->makeLoan();
        $cashier = $this->makeUser('cashier');

        $this->actingAs($cashier)
            ->get("/borrowers/{$loan->borrower_id}")
            ->assertOk()
            ->assertSee('Batch Two')
            ->assertSee($loan->loan_number);
    }

    public function test_payments_page_shows_totals_and_rows(): void
    {
        $loan = $this->makeLoan();
        app(LoanScheduleService::class)->generateAndPersist($loan);
        $loan->update(['status' => LoanStatus::Active]);
        app(RepaymentService::class)->record($loan->refresh(), Money::of('250.00'), new DateTimeImmutable('2026-02-15'), reference: 'REF-XYZ');

        $admin = $this->makeUser('admin');
        $this->actingAs($admin)
            ->get('/payments')
            ->assertOk()
            ->assertSee('REF-XYZ')
            ->assertSee($loan->loan_number);
    }

    public function test_report_pages_gated_by_role(): void
    {
        $cashier = $this->makeUser('cashier');
        $accountant = $this->makeUser('accountant');

        // AuthenticateSession binds the session to a user's password hash —
        // switching users mid-test needs a fresh session, like a real browser.
        $this->actingAs($cashier)->get('/reports/portfolio')->assertForbidden();
        $this->actingAs($cashier)->get('/reports/collections')->assertOk();
        $this->flushSession();
        $this->actingAs($accountant)->get('/reports/trial-balance')->assertOk();

        $this->flushSession();
        $this->actingAs($cashier)->get('/sms-logs')->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->makeUser('manager'))->get('/sms-logs')->assertOk();
    }
}
