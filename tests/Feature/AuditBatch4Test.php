<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LoanStatus;
use App\Livewire\Loans\Form as LoanForm;
use App\Livewire\Loans\Show;
use App\Livewire\Reports\Collections;
use App\Livewire\Users\Form as UserForm;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanScheduleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LoanEngine\Money;
use Tests\TestCase;

/** Guards for the batch-4 audit fixes: collateral photo tampering, stale modal dates, admin guards, export PII bar, borrower fallback scope, first-due-date bound. */
class AuditBatch4Test extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $role = 'admin', bool $active = true, ?int $branchId = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@example.com',
            'password' => bcrypt('secret123'), 'role' => $role,
            'branch_id' => $branchId, 'is_active' => $active,
        ]);
    }

    private function makeBranch(): Branch
    {
        return Branch::create(['name' => 'AB4', 'code' => 'AB4-'.substr(uniqid(), -8)]);
    }

    private function makeLoan(
        LoanStatus $status = LoanStatus::PendingApproval,
        ?string $disbursedAt = null,
        ?string $firstDueDate = null,
        bool $withSchedule = false,
    ): Loan {
        $product = LoanProduct::create([
            'name' => 'AB4', 'code' => 'AB4-'.uniqid(), 'annual_rate' => 12.0, 'term_count' => 6,
        ])->refresh();
        $borrower = Borrower::create(['first_name' => 'Batch', 'last_name' => 'Four', 'phone' => '+1'.random_int(1000000000, 9999999999)]);

        $loan = Loan::create([
            'loan_number' => 'AB4-'.uniqid(),
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'currency' => 'USD', 'scale' => 2,
            'principal_minor' => Money::of('6000.00')->minor,
            'annual_rate' => 12.0, 'term_count' => 6,
            'method' => $product->method->value,
            'frequency' => 'monthly', 'basis' => 'equal_periods',
            'disbursed_at' => $disbursedAt ?? '2026-01-15',
            'first_due_date' => $firstDueDate,
            'status' => $withSchedule ? LoanStatus::Approved : $status,
        ]);

        if ($withSchedule) {
            app(LoanScheduleService::class)->generateAndPersist($loan);
            $loan->update(['status' => $status]);
        }

        return $loan->refresh();
    }

    /** F2: forged existingCollateralPhotos must neither enter the DB nor delete other records' private files. */
    public function test_collateral_photo_paths_cannot_be_injected_or_used_to_delete_foreign_files(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('collaterals/mine-1.jpg', 'a');
        Storage::disk('local')->put('collaterals/mine-2.jpg', 'b');
        Storage::disk('local')->put('collaterals/victim.jpg', 'v');
        Storage::disk('local')->put('borrowers/kyc-secret.jpg', 'k');

        $loan = $this->makeLoan();
        $mine = $loan->collaterals()->create([
            'type' => 'Vehicle', 'estimated_value_minor' => 500000,
            'photos' => ['collaterals/mine-1.jpg', 'collaterals/mine-2.jpg'],
        ]);
        $victim = $this->makeLoan()->collaterals()->create([
            'type' => 'House', 'estimated_value_minor' => 900000,
            'photos' => ['collaterals/victim.jpg'],
        ]);

        Livewire::actingAs($this->makeUser('admin'))
            ->test(Show::class, ['loan' => $loan->id])
            ->call('editCollateral', $mine->id)
            // Tampered client payload: another record's photo, an arbitrary
            // private-disk path, plus one legitimately kept own photo.
            ->set('existingCollateralPhotos', ['collaterals/victim.jpg', 'borrowers/kyc-secret.jpg', 'collaterals/mine-1.jpg'])
            ->call('addCollateral')
            ->assertSet('actionError', null);

        // Foreign files survive; only the genuinely removed own photo is gone.
        Storage::disk('local')->assertExists('collaterals/victim.jpg');
        Storage::disk('local')->assertExists('borrowers/kyc-secret.jpg');
        Storage::disk('local')->assertExists('collaterals/mine-1.jpg');
        Storage::disk('local')->assertMissing('collaterals/mine-2.jpg');

        // DB photos stay a subset of the collateral's own legitimate paths.
        $this->assertSame(['collaterals/mine-1.jpg'], $mine->fresh()->photos);
        $this->assertSame(['collaterals/victim.jpg'], $victim->fresh()->photos);
    }

    /** F3: a backdated payment date must not silently prefill the next payment. */
    public function test_payment_date_resets_to_today_after_successful_save(): void
    {
        $loan = $this->makeLoan(status: LoanStatus::Active, withSchedule: true);

        Livewire::actingAs($this->makeUser('admin'))
            ->test(Show::class, ['loan' => $loan->id])
            ->set('showPaymentModal', true)
            ->set('paymentAmount', 100.0)
            ->set('paymentDate', '2026-02-15')
            ->call('recordPayment')
            ->assertSet('actionError', null)
            ->assertSet('showPaymentModal', false)
            ->assertSet('paymentDate', today()->format('Y-m-d'));

        $this->assertSame(1, $loan->payments()->count());
        $this->assertSame('2026-02-15', $loan->payments()->first()->paid_at->format('Y-m-d'));
    }

    /** F3: same stale-state guard for the payoff modal after settling. */
    public function test_payoff_date_and_method_reset_after_successful_settlement(): void
    {
        $loan = $this->makeLoan(status: LoanStatus::Active, withSchedule: true);

        Livewire::actingAs($this->makeUser('admin'))
            ->test(Show::class, ['loan' => $loan->id])
            ->set('showPayoffModal', true)
            ->set('payoffDate', '2026-02-15')
            ->set('payoffMethod', 'bank')
            ->call('settlePayoff')
            ->assertSet('actionError', null)
            ->assertSet('showPayoffModal', false)
            ->assertSet('payoffDate', today()->format('Y-m-d'))
            ->assertSet('payoffMethod', 'cash');

        $this->assertSame(LoanStatus::Closed, $loan->fresh()->status);
    }

    /** F8: demoting or deleting an already-inactive admin must not trip the last-admin guard. */
    public function test_inactive_admin_can_be_demoted_and_deleted(): void
    {
        config(['lms.demo' => false]);

        // Make our admin the only active one, whatever is seeded.
        User::where('role', 'admin')->update(['is_active' => false]);
        $active = $this->makeUser('admin');
        $inactiveB = $this->makeUser('admin', active: false);
        $inactiveC = $this->makeUser('admin', active: false);

        Livewire::actingAs($active)
            ->test(UserForm::class, ['user' => $inactiveB])
            ->set('role', 'manager')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('manager', $inactiveB->fresh()->role);

        Livewire::actingAs($active)
            ->test(UserForm::class, ['user' => $inactiveC])
            ->call('delete')
            ->assertHasNoErrors();
        $this->assertNull(User::find($inactiveC->id));
    }

    /** F8: the guard still protects the last ACTIVE admin. */
    public function test_last_active_admin_still_cannot_be_demoted_or_deleted(): void
    {
        config(['lms.demo' => false]);

        User::where('role', 'admin')->update(['is_active' => false]);
        $lastActive = $this->makeUser('admin');

        Livewire::actingAs($lastActive)
            ->test(UserForm::class, ['user' => $lastActive])
            ->set('role', 'manager')
            ->call('save')
            ->assertHasErrors('role');
        $this->assertSame('admin', $lastActive->fresh()->role);

        // Deleting the last active admin is blocked even for another
        // (inactive) admin account.
        $inactiveActor = $this->makeUser('admin', active: false);
        Livewire::actingAs($inactiveActor)
            ->test(UserForm::class, ['user' => $lastActive])
            ->call('delete')
            ->assertHasErrors('name');
        $this->assertNotNull(User::find($lastActive->id));
    }

    /** F9: cashiers keep page access but cannot bulk-export borrower PII as CSV. */
    public function test_cashier_cannot_export_collections_csv(): void
    {
        $cashier = $this->makeUser('cashier');

        // Page stays accessible, but the export button is not offered.
        $this->actingAs($cashier)
            ->get('/reports/collections')
            ->assertOk()
            ->assertDontSee('Export CSV');

        Livewire::actingAs($cashier)
            ->test(Collections::class)
            ->call('exportCsv')
            ->assertForbidden();

        // The borrower-bar roles still export.
        $this->flushSession();
        Livewire::actingAs($this->makeUser('loan_officer'))
            ->test(Collections::class)
            ->call('exportCsv')
            ->assertOk();
    }

    /** F10: the "keep the selected borrower visible" fallback must not leak foreign-branch PII. */
    public function test_branch_scoped_officer_cannot_probe_foreign_borrower_via_borrower_id(): void
    {
        config(['lms.branch_scoping' => true]);

        $officer = $this->makeUser('loan_officer', branchId: $this->makeBranch()->id);
        $foreign = Borrower::create([
            'first_name' => 'Foreign', 'last_name' => 'Probe',
            'phone' => '+15559998888', 'branch_id' => $this->makeBranch()->id,
        ]);

        Livewire::actingAs($officer)
            ->test(LoanForm::class)
            ->set('borrower_id', $foreign->id)
            ->assertDontSee('+15559998888')
            ->assertDontSee('Probe');
    }

    /** F7: a first due date beyond two period-lengths misprices the stub and is rejected. */
    public function test_first_due_date_beyond_two_periods_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $borrower = Borrower::create(['first_name' => 'Stub', 'last_name' => 'Period']);
        $product = LoanProduct::create([
            'name' => 'AB4-FDD', 'code' => 'AB4F-'.uniqid(), 'annual_rate' => 12.0, 'term_count' => 6,
        ])->refresh();

        $component = Livewire::actingAs($admin)
            ->test(LoanForm::class)
            ->set('borrower_id', $borrower->id)
            ->set('loan_product_id', $product->id)
            ->set('amount', 1000.0)
            ->set('annual_rate', '12')
            ->set('term_count', '6')
            ->set('disbursed_at', today()->format('Y-m-d'));

        $component
            ->set('first_due_date', today()->addMonthsNoOverflow(3)->format('Y-m-d'))
            ->call('save')
            ->assertHasErrors('first_due_date');
        $this->assertSame(0, Loan::where('borrower_id', $borrower->id)->count());

        // Within the bound the loan saves normally.
        $component
            ->set('first_due_date', today()->addMonthsNoOverflow(1)->format('Y-m-d'))
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(1, Loan::where('borrower_id', $borrower->id)->count());
    }

    /** F7: activation re-anchors on today — a planned first due date that drifted beyond the bound must block disbursement. */
    public function test_activation_rejects_first_due_date_beyond_bound_after_reanchor(): void
    {
        $admin = $this->makeUser('admin');

        // Planned for months from now; activating today would leave the
        // first due date ~5 months past the actual disbursement.
        $drifted = $this->makeLoan(
            status: LoanStatus::Approved,
            disbursedAt: today()->addMonthsNoOverflow(4)->format('Y-m-d'),
            firstDueDate: today()->addMonthsNoOverflow(5)->format('Y-m-d'),
        );

        $component = Livewire::actingAs($admin)
            ->test(Show::class, ['loan' => $drifted->id])
            ->call('activate');

        $this->assertNotNull($component->get('actionError'));
        $drifted->refresh();
        $this->assertSame(LoanStatus::Approved, $drifted->status);
        $this->assertSame(0, $drifted->installments()->count());

        // A first due date within two periods of today activates fine.
        $ok = $this->makeLoan(
            status: LoanStatus::Approved,
            disbursedAt: today()->format('Y-m-d'),
            firstDueDate: today()->addMonthsNoOverflow(1)->format('Y-m-d'),
        );

        Livewire::actingAs($admin)
            ->test(Show::class, ['loan' => $ok->id])
            ->call('activate')
            ->assertSet('actionError', null);

        $this->assertSame(LoanStatus::Active, $ok->fresh()->status);
    }
}
