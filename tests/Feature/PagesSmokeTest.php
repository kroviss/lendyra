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

class PagesSmokeTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Tester',
            'email' => 'tester-'.uniqid().'@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'admin',
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Log in');
    }

    public function test_login_works_with_valid_credentials(): void
    {
        $this->post('/login', [
            'email' => $this->user->email,
            'password' => 'secret123',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_all_pages_render_for_authenticated_user(): void
    {
        $this->actingAs($this->user);

        $this->get('/')->assertOk()->assertSee('Dashboard');
        $this->get('/borrowers')->assertOk()->assertSee('Borrowers');
        $this->get('/borrowers/create')->assertOk();
        $this->get('/products')->assertOk();
        $this->get('/products/create')->assertOk();
        $this->get('/loans')->assertOk();
        $this->get('/loans/create')->assertOk();
    }

    public function test_loan_show_page_renders_with_schedule(): void
    {
        $product = LoanProduct::create([
            'name' => 'Smoke Product',
            'code' => 'SMK-'.uniqid(),
            'annual_rate' => 12.0,
            'term_count' => 6,
        ])->refresh();

        $borrower = Borrower::create(['first_name' => 'Smoke', 'last_name' => 'Test']);

        $loan = Loan::create([
            'loan_number' => 'SMK-'.uniqid(),
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'currency' => $product->currency,
            'scale' => $product->scale,
            'principal_minor' => Money::of('5000.00')->minor,
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

        $this->actingAs($this->user)
            ->get("/loans/{$loan->id}")
            ->assertOk()
            ->assertSee($loan->loan_number)
            ->assertSee('Smoke Test')
            ->assertSee('Record payment');
    }
}
