<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LoanStatus;
use App\Livewire\Loans\Show;
use App\Models\Borrower;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanScheduleService;
use App\Services\PayoffService;
use App\Services\RepaymentService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LoanEngine\Money;
use Tests\TestCase;

class LaunchHardeningTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@example.com',
            'password' => bcrypt('secret123'),
            'role' => $role,
        ]);
    }

    private function makeActiveLoan(): Loan
    {
        $product = LoanProduct::create([
            'name' => 'Hardening', 'code' => 'HRD-'.uniqid(),
            'annual_rate' => 12.0, 'term_count' => 6, 'penalty_daily_rate' => 0.5,
        ])->refresh();

        $borrower = Borrower::create(['first_name' => 'Hard', 'last_name' => 'Ening']);

        $loan = Loan::create([
            'loan_number' => 'HRD-'.uniqid(),
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

    // ── Payment reversal ────────────────────────────────────────────

    public function test_reversal_restores_installments_and_balances_ledger(): void
    {
        $loan = $this->makeActiveLoan();
        $payment = app(RepaymentService::class)->record($loan, Money::of('1060.00'), new DateTimeImmutable('2026-02-15'));

        $first = $loan->installments()->where('number', 1)->first();
        $this->assertTrue($first->isSettled());

        app(RepaymentService::class)->reverse($payment->fresh());

        $first->refresh();
        $this->assertFalse($first->isSettled());
        $this->assertNull($first->settled_at);
        $this->assertSame(0, (int) $first->principal_paid_minor);
        $this->assertSame('6000.00', $loan->fresh()->principalOutstanding()->toDecimalString());

        // Reversal entry exists and the whole ledger still balances.
        $this->assertTrue(JournalEntry::where('reference_type', 'loan_payment_reversal')->where('reference_id', $payment->id)->exists());
        $this->assertSame(
            (int) DB::table('journal_lines')->sum('debit_minor'),
            (int) DB::table('journal_lines')->sum('credit_minor')
        );
    }

    public function test_reversal_reopens_a_closed_loan_and_cannot_run_twice(): void
    {
        $loan = $this->makeActiveLoan();
        $payment = app(PayoffService::class)->settle($loan, new DateTimeImmutable('2026-02-15'));
        $this->assertSame(LoanStatus::Closed, $loan->fresh()->status);

        app(RepaymentService::class)->reverse($payment->fresh());
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
        $this->assertNull($loan->fresh()->closed_at);

        $this->expectException(\LogicException::class);
        app(RepaymentService::class)->reverse($payment->fresh());
    }

    // ── Role gating ─────────────────────────────────────────────────

    public function test_loan_officer_cannot_activate_or_reverse(): void
    {
        $loan = $this->makeActiveLoan();
        $loan->update(['status' => LoanStatus::Approved]);
        $officer = $this->makeUser('loan_officer');

        Livewire::actingAs($officer)
            ->test(Show::class, ['loan' => $loan->id])
            ->call('activate')
            ->assertSet('actionError', fn ($error) => $error !== null);

        $this->assertSame(LoanStatus::Approved, $loan->fresh()->status);
    }

    public function test_cashier_can_record_but_admin_pages_are_blocked(): void
    {
        $loan = $this->makeActiveLoan();
        $cashier = $this->makeUser('cashier');

        Livewire::actingAs($cashier)
            ->test(Show::class, ['loan' => $loan->id])
            ->set('paymentAmount', 100.0)
            ->set('paymentDate', '2026-02-15')
            ->call('recordPayment')
            ->assertSet('actionError', null);

        $this->assertSame(1, $loan->payments()->count());
        $this->actingAs($cashier)->get('/users')->assertForbidden();
        $this->actingAs($cashier)->get('/loans/create')->assertForbidden();
    }

    public function test_admin_can_manage_users_page(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin)->get('/users')->assertOk()->assertSee('Users');
    }

    // ── Write-off ───────────────────────────────────────────────────

    public function test_write_off_posts_loss_and_blocks_further_payments(): void
    {
        $loan = $this->makeActiveLoan();
        $admin = $this->makeUser('admin');

        Livewire::actingAs($admin)
            ->test(Show::class, ['loan' => $loan->id])
            ->call('writeOff')
            ->assertSet('actionError', null);

        $loan->refresh();
        $this->assertSame(LoanStatus::WrittenOff, $loan->status);

        $entry = JournalEntry::where('reference_type', 'loan_write_off')->where('reference_id', $loan->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame(600000, (int) $entry->lines()->sum('debit_minor'));

        $this->expectException(\LogicException::class);
        app(RepaymentService::class)->record($loan, Money::of('100.00'), new DateTimeImmutable('2026-03-01'));
    }

    // ── Login throttle ──────────────────────────────────────────────

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong'.$i]);
        }

        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertStatus(429);
    }
}
