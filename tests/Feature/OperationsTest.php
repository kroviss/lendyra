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
use LoanEngine\Money;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use DatabaseTransactions;

    private function makeActiveLoan(): Loan
    {
        $product = LoanProduct::create([
            'name' => 'Ops Product',
            'code' => 'OPS-'.uniqid(),
            'annual_rate' => 12.0,
            'term_count' => 6,
            'penalty_daily_rate' => 0.5,
        ])->refresh();

        $borrower = Borrower::create(['first_name' => 'Ops', 'last_name' => 'Borrower']);

        $loan = Loan::create([
            'loan_number' => 'OPS-'.uniqid(),
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'currency' => $product->currency,
            'scale' => $product->scale,
            'principal_minor' => Money::of('6000.00')->minor,
            'annual_rate' => 12.0,
            'term_count' => 6,
            'method' => $product->method->value,
            'frequency' => $product->frequency->value,
            'basis' => $product->basis->value,
            'disbursed_at' => '2026-01-15',
            'status' => LoanStatus::Approved,
        ]);

        app(LoanScheduleService::class)->generateAndPersist($loan);
        $loan->update(['status' => LoanStatus::Active]);

        return $loan->refresh();
    }

    public function test_accrue_penalties_command_processes_overdue_loans(): void
    {
        $loan = $this->makeActiveLoan(); // installment #1 due 2026-02-15

        $this->artisan('loans:accrue-penalties', ['--date' => '2026-02-25'])
            ->expectsOutputToContain('1 loan(s)')
            ->assertSuccessful();

        $first = $loan->installments()->where('number', 1)->first();
        // 1000.00 overdue principal × 0.5% × 10 days = 50.00
        $this->assertSame(5000, (int) $first->penalty_minor);
    }

    public function test_accrue_penalties_command_skips_loans_without_overdue(): void
    {
        $this->makeActiveLoan();

        $this->artisan('loans:accrue-penalties', ['--date' => '2026-02-01'])
            ->expectsOutputToContain('0 loan(s)')
            ->assertSuccessful();
    }

    public function test_products_pages_require_admin_or_manager_role(): void
    {
        $officer = User::create([
            'name' => 'Officer', 'email' => 'officer-'.uniqid().'@example.com',
            'password' => bcrypt('x'), 'role' => 'loan_officer',
        ]);
        $admin = User::create([
            'name' => 'Admin2', 'email' => 'admin-'.uniqid().'@example.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $this->actingAs($officer)->get('/products')->assertForbidden();
        $this->actingAs($admin)->get('/products')->assertOk();
    }

    public function test_portfolio_report_renders_with_par_metrics(): void
    {
        $loan = $this->makeActiveLoan();
        $admin = User::create([
            'name' => 'Rep', 'email' => 'rep-'.uniqid().'@example.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $this->actingAs($admin)
            ->get('/reports/portfolio')
            ->assertOk()
            ->assertSee('PAR 30')
            ->assertSee('Portfolio outstanding');
    }

    public function test_statement_page_renders(): void
    {
        $loan = $this->makeActiveLoan();
        $admin = User::create([
            'name' => 'St', 'email' => 'st-'.uniqid().'@example.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $this->actingAs($admin)
            ->get("/loans/{$loan->id}/statement")
            ->assertOk()
            ->assertSee($loan->loan_number)
            ->assertSee('Repayment schedule');
    }
}
