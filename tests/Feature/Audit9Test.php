<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LoanStatus;
use App\Livewire\Users\Form as UserForm;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanScheduleService;
use App\Services\PenaltyService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use LoanEngine\Money;
use Tests\TestCase;

/**
 * Ninth-audit regressions: branch-scope fail-closed and penalty-terms
 * snapshot immunity.
 */
class Audit9Test extends TestCase
{
    use DatabaseTransactions;

    // ── S1: branch scope fails CLOSED for a branchless scoped user ──────

    public function test_branchless_scoped_user_gets_non_matching_sentinel(): void
    {
        config()->set('lms.branch_scoping', true);

        $cashier = new User(['role' => 'cashier', 'branch_id' => null]);
        $this->assertSame(User::NO_BRANCH_SENTINEL, $cashier->scopedBranchId());
        // The sentinel is truthy (so ->when() still fires) but matches no branch.
        $this->assertTrue((bool) $cashier->scopedBranchId());
        $this->assertNotSame(0, $cashier->scopedBranchId());

        $this->assertSame(5, (new User(['role' => 'cashier', 'branch_id' => 5]))->scopedBranchId());
        $this->assertNull((new User(['role' => 'admin', 'branch_id' => null]))->scopedBranchId());

        config()->set('lms.branch_scoping', false);
        $this->assertNull((new User(['role' => 'cashier', 'branch_id' => null]))->scopedBranchId());
    }

    public function test_users_form_requires_branch_for_scoped_roles_when_scoping_on(): void
    {
        config()->set('lms.branch_scoping', true);
        config()->set('lms.demo', false); // demo mode blocks account changes

        $admin = User::create([
            'name' => 'Admin', 'email' => 'a9-admin-'.uniqid().'@example.com',
            'password' => 'password', 'role' => 'admin', 'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(UserForm::class)
            ->set('name', 'New Cashier')
            ->set('email', 'a9-cashier-'.uniqid().'@example.com')
            ->set('password', 'password123')
            ->set('role', 'cashier')
            ->set('branch_id', null)
            ->call('save')
            ->assertHasErrors('branch_id');

        // An admin without a branch is fine — admins are never scoped.
        Livewire::actingAs($admin)
            ->test(UserForm::class)
            ->set('name', 'New Admin')
            ->set('email', 'a9-admin2-'.uniqid().'@example.com')
            ->set('password', 'password123')
            ->set('role', 'admin')
            ->set('branch_id', null)
            ->call('save')
            ->assertHasNoErrors('branch_id');
    }

    // ── M1: penalty terms are snapshotted onto the loan ─────────────────

    public function test_penalty_snapshot_immune_to_product_reprice(): void
    {
        $product = LoanProduct::create([
            'name' => 'A9', 'code' => 'A9-'.uniqid(), 'annual_rate' => 12.0,
            'term_count' => 6, 'penalty_daily_rate' => 0.5,
        ])->refresh();

        $borrower = Borrower::create(['first_name' => 'A9', 'last_name' => 'Snap']);

        $loan = Loan::create([
            'loan_number' => 'A9-'.uniqid(),
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'currency' => 'USD', 'scale' => 2,
            'principal_minor' => Money::of('6000.00')->minor,
            'annual_rate' => 12.0, 'term_count' => 6,
            'method' => $product->method->value,
            'frequency' => 'monthly', 'basis' => 'equal_periods',
            'disbursed_at' => '2026-01-15',
            'status' => LoanStatus::Approved,
            // Snapshot as Loans\Form does at origination.
            'penalty_daily_rate' => $product->penalty_daily_rate,
            'penalty_grace_days' => (int) $product->penalty_grace_days,
            'penalty_base' => $product->penalty_base->value,
            'penalty_cap_percent' => $product->penalty_cap_percent,
            'payoff_interest_mode' => $product->payoff_interest_mode->value,
        ]);
        app(LoanScheduleService::class)->generateAndPersist($loan);
        $loan->update(['status' => LoanStatus::Active]);
        $loan->refresh();

        // 23 days overdue @ 0.5%/day on 1,000.00 = 115.00.
        app(PenaltyService::class)->accrue($loan, new DateTimeImmutable('2026-03-10'));
        $first = $loan->installments()->where('number', 1)->first();
        $this->assertSame(11500, (int) $first->penalty_minor);

        // Double the product's penalty rate — meant for NEW loans only.
        $product->update(['penalty_daily_rate' => 1.0]);

        // Re-accrue this loan as of the same date: it must NOT reprice off the
        // product's new rate — the loan carries its own snapshot.
        app(PenaltyService::class)->accrue($loan->refresh(), new DateTimeImmutable('2026-03-10'));
        $this->assertSame(11500, (int) $loan->installments()->where('number', 1)->first()->penalty_minor);
    }
}
