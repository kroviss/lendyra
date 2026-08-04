<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LoanStatus;
use App\Livewire\Loans\Form;
use App\Livewire\Loans\Show;
use App\Models\Borrower;
use App\Models\JournalEntry;
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
use Tests\TestCase;

class AuditFixesTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $role, bool $active = true): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@example.com',
            'password' => bcrypt('secret123'), 'role' => $role, 'is_active' => $active,
        ]);
    }

    private function makeActiveLoan(float $penaltyRate = 0.5): Loan
    {
        $product = LoanProduct::create([
            'name' => 'AF', 'code' => 'AF-'.uniqid(), 'annual_rate' => 12.0,
            'term_count' => 6, 'penalty_daily_rate' => $penaltyRate,
        ])->refresh();
        $borrower = Borrower::create(['first_name' => 'Audit', 'last_name' => 'Fix']);
        $loan = Loan::create([
            'loan_number' => 'AF-'.uniqid(),
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

    /** F1: paying a penalty must not get re-billed by the next accrual. */
    public function test_penalty_reaccrual_does_not_recharge_paid_penalties(): void
    {
        $loan = $this->makeActiveLoan();
        $service = app(PenaltyService::class);

        // 10 days overdue on installment #1: 1000.00 × 0.5% × 10 = 50.00
        $service->accrue($loan, new DateTimeImmutable('2026-02-25'));
        $first = $loan->installments()->where('number', 1)->first();
        $this->assertSame(5000, (int) $first->penalty_minor);

        // Pay the penalty in full.
        app(RepaymentService::class)->record($loan->refresh(), Money::of('50.00'), new DateTimeImmutable('2026-02-25'));
        $this->assertSame(0, $first->fresh()->penaltyDue()->minor);

        // Next day's accrual: total accrued = 55.00, paid 50.00 → due 5.00,
        // NOT 55.00 (the old bug re-billed everything already paid).
        $service->accrue($loan->refresh(), new DateTimeImmutable('2026-02-26'));
        $first->refresh();
        $this->assertSame(5500, (int) $first->penalty_minor);
        $this->assertSame(500, $first->penaltyDue()->minor);
    }

    /** F3: reversing a payoff restores the contractual interest schedule. */
    public function test_reversing_payoff_restores_future_interest(): void
    {
        $loan = $this->makeActiveLoan(0);
        $originalInterest = (int) $loan->installments()->sum('interest_minor');
        $this->assertGreaterThan(0, $originalInterest);

        $payment = app(PayoffService::class)->settle($loan, new DateTimeImmutable('2026-02-15'));
        $this->assertLessThan($originalInterest, (int) $loan->installments()->sum('interest_minor'));

        app(RepaymentService::class)->reverse($payment->fresh());

        $this->assertSame($originalInterest, (int) $loan->installments()->sum('interest_minor'));
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
    }

    /** F8: editing an approved loan invalidates the approval. */
    public function test_officer_edit_resets_approval_to_pending(): void
    {
        $manager = $this->makeUser('manager');
        $officer = $this->makeUser('loan_officer');

        $product = LoanProduct::create([
            'name' => 'MC', 'code' => 'MC-'.uniqid(), 'annual_rate' => 12.0, 'term_count' => 6,
        ])->refresh();
        $borrower = Borrower::create(['first_name' => 'Reset', 'last_name' => 'Approval']);
        $loan = Loan::create([
            'loan_number' => 'MC-'.uniqid(),
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'currency' => 'USD', 'scale' => 2,
            'principal_minor' => Money::of('1000.00')->minor,
            'annual_rate' => 12.0, 'term_count' => 6,
            'method' => $product->method->value,
            'frequency' => 'monthly', 'basis' => 'equal_periods',
            'disbursed_at' => '2026-08-01',
            'status' => LoanStatus::Approved,
            'approved_by' => $manager->id,
            'approved_at' => now(),
        ]);

        Livewire::actingAs($officer)
            ->test(Form::class, ['loan' => $loan])
            ->set('amount', 100000.0)
            ->call('save');

        $loan->refresh();
        $this->assertSame(LoanStatus::PendingApproval, $loan->status);
        $this->assertNull($loan->approved_by);
        $this->assertNull($loan->approved_at);
    }

    /** Installer: the admin step is one-shot — never mints a second admin. */
    public function test_install_admin_step_blocked_once_users_exist(): void
    {
        $this->makeUser('admin');

        $lock = storage_path('app/installed.lock');
        $backup = $lock.'.bak2';
        rename($lock, $backup);

        try {
            $this->get('/install/admin')->assertRedirect('/login');

            // The GET healed the lock, so the POST is now walled off by
            // EnsureInstalled before it ever reaches the controller.
            $this->post('/install/admin', [
                'name' => 'Evil', 'email' => 'evil@example.com',
                'password' => 'password123', 'password_confirmation' => 'password123',
            ])->assertRedirect('/');

            $this->assertNull(User::where('email', 'evil@example.com')->first());
            // The lock heals itself so the app is reachable again.
            $this->assertFileExists($lock);
        } finally {
            @unlink($lock);
            rename($backup, $lock);
        }
    }

    /** Disabled users are rejected at login AND kicked mid-session. */
    public function test_disabled_user_cannot_login_or_continue(): void
    {
        $user = $this->makeUser('cashier', active: false);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $active = $this->makeUser('cashier');
        $this->actingAs($active)->get('/loans')->assertOk();
        $active->update(['is_active' => false]);
        $this->actingAs($active->fresh())->get('/loans')->assertRedirect(route('login'));
    }

    /** Payoff date cannot be in the future. */
    public function test_payoff_date_cannot_be_future(): void
    {
        $loan = $this->makeActiveLoan();
        $admin = $this->makeUser('admin');

        Livewire::actingAs($admin)
            ->test(Show::class, ['loan' => $loan->id])
            ->set('payoffDate', now()->addMonth()->format('Y-m-d'))
            ->call('settlePayoff')
            ->assertHasErrors('payoffDate');

        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
    }

    /** Activation stamps the real disbursement date and is idempotent. */
    public function test_activation_sets_disbursement_date_and_cannot_run_twice(): void
    {
        $product = LoanProduct::create([
            'name' => 'ACT', 'code' => 'ACT-'.uniqid(), 'annual_rate' => 12.0, 'term_count' => 6,
        ])->refresh();
        $borrower = Borrower::create(['first_name' => 'Act', 'last_name' => 'Ivate']);
        $loan = Loan::create([
            'loan_number' => 'ACT-'.uniqid(),
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'currency' => 'USD', 'scale' => 2,
            'principal_minor' => Money::of('1200.00')->minor,
            'annual_rate' => 12.0, 'term_count' => 6,
            'method' => $product->method->value,
            'frequency' => 'monthly', 'basis' => 'equal_periods',
            'disbursed_at' => '2026-01-01', // application-time guess
            'status' => LoanStatus::Approved,
        ]);

        $admin = $this->makeUser('admin');
        $component = Livewire::actingAs($admin)
            ->test(Show::class, ['loan' => $loan->id])
            ->call('activate')
            ->assertSet('actionError', null);

        $loan->refresh();
        $this->assertSame(today()->format('Y-m-d'), $loan->disbursed_at->format('Y-m-d'));
        $this->assertSame(LoanStatus::Active, $loan->status);

        // Second activation attempt fails cleanly, posts nothing extra.
        $entriesBefore = JournalEntry::where('reference_type', 'loan')->where('reference_id', $loan->id)->count();
        $component->call('activate')->assertSet('actionError', fn ($e) => $e !== null);
        $this->assertSame($entriesBefore, JournalEntry::where('reference_type', 'loan')->where('reference_id', $loan->id)->count());
    }

    /** Default admin credential is never planted next to real users. */
    public function test_seeder_skips_default_admin_when_users_exist(): void
    {
        $this->makeUser('admin');
        User::where('email', 'admin@example.com')->delete();

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertNull(User::where('email', 'admin@example.com')->first());
    }
}
