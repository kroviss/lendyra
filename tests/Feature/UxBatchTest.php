<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LoanStatus;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanScheduleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use LoanEngine\Money;
use Tests\TestCase;

class UxBatchTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@example.com',
            'password' => bcrypt('secret123'), 'role' => $role,
        ]);
    }

    private function makeActiveLoan(): Loan
    {
        $product = LoanProduct::create([
            'name' => 'UX', 'code' => 'UX-'.uniqid(), 'annual_rate' => 12.0, 'term_count' => 6,
        ])->refresh();
        $borrower = Borrower::create(['first_name' => 'Ux', 'last_name' => 'Zorigt', 'phone' => '+97699110022']);
        $loan = Loan::create([
            'loan_number' => 'UX-'.uniqid(),
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

        return $loan->refresh();
    }

    public function test_accountant_cannot_touch_collateral_or_guarantors(): void
    {
        $loan = $this->makeActiveLoan();
        $loan->collaterals()->create(['type' => 'Gold', 'estimated_value_minor' => 100000]);
        $collateral = $loan->collaterals()->first();
        $accountant = $this->makeUser('accountant');

        // Denials now surface in the page's error banner instead of
        // throwing the user out to a full-page 403.
        Livewire::actingAs($accountant)
            ->test(\App\Livewire\Loans\Show::class, ['loan' => $loan->id])
            ->call('releaseCollateral', $collateral->id)
            ->assertSet('actionError', fn ($error) => $error !== null);

        $this->assertSame('held', $collateral->fresh()->status);
    }

    public function test_loans_status_filter(): void
    {
        $active = $this->makeActiveLoan();
        $admin = $this->makeUser('admin');

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Loans\Index::class)
            ->set('statusFilter', 'active')
            ->assertSee($active->loan_number)
            ->set('statusFilter', 'closed')
            ->assertDontSee($active->loan_number);
    }

    public function test_search_finds_borrower_by_last_name_and_phone(): void
    {
        $loan = $this->makeActiveLoan();
        $admin = $this->makeUser('admin');

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Loans\Index::class)
            ->set('search', 'Zorigt')
            ->assertSee($loan->loan_number)
            ->set('search', '+97699110022')
            ->assertSee($loan->loan_number);

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Borrowers\Index::class)
            ->set('search', 'Zorigt')
            ->assertSee('Ux Zorigt');
    }

    public function test_payment_date_cannot_be_in_the_future(): void
    {
        $loan = $this->makeActiveLoan();
        $admin = $this->makeUser('admin');

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Loans\Show::class, ['loan' => $loan->id])
            ->set('paymentAmount', 100.0)
            ->set('paymentDate', now()->addYear()->format('Y-m-d'))
            ->call('recordPayment')
            ->assertHasErrors('paymentDate');

        $this->assertSame(0, $loan->payments()->count());
    }

    public function test_profile_page_and_password_change(): void
    {
        config(['lms.demo' => false]);
        $user = $this->makeUser('loan_officer');

        $this->actingAs($user)->get('/profile')->assertOk()->assertSee('My Profile');

        Livewire::actingAs($user)
            ->test(\App\Livewire\Profile::class)
            ->set('name', 'New Name')
            ->set('current_password', 'secret123')
            ->set('password', 'newpassword9')
            ->set('password_confirmation', 'newpassword9')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('newpassword9', $user->fresh()->password));
    }

    public function test_collections_report_lists_due_installments(): void
    {
        $loan = $this->makeActiveLoan(); // all installments overdue (2026 dates are past)
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->get('/reports/collections?window=overdue')
            ->assertOk()
            ->assertSee('Collections')
            ->assertSee($loan->loan_number);
    }
}
