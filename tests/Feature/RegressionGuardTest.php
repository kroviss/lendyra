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

/** Guards for regressions the audits caught in previously "fixed" code. */
class RegressionGuardTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Reg', 'email' => 'reg-'.uniqid().'@example.com',
            'password' => bcrypt('secret123'), 'role' => 'admin',
        ]);
    }

    private function makeLoan(LoanStatus $status = LoanStatus::Active, ?int $branchId = null): Loan
    {
        $product = LoanProduct::create([
            'name' => 'RG', 'code' => 'RG-'.uniqid(), 'annual_rate' => 12.0, 'term_count' => 6,
        ])->refresh();
        $borrower = Borrower::create(['first_name' => 'Reg', 'last_name' => 'Guard']);

        $loan = Loan::create([
            'loan_number' => 'RG-'.uniqid(),
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'branch_id' => $branchId,
            'currency' => 'USD', 'scale' => 2,
            'principal_minor' => Money::of('1200.00')->minor,
            'annual_rate' => 12.0, 'term_count' => 6,
            'method' => $product->method->value,
            'frequency' => 'monthly', 'basis' => 'equal_periods',
            'disbursed_at' => '2026-01-15',
            'status' => LoanStatus::Approved,
        ]);

        if ($status === LoanStatus::Active) {
            app(LoanScheduleService::class)->generateAndPersist($loan);
            $loan->update(['status' => LoanStatus::Active]);
        } else {
            $loan->update(['status' => $status]);
        }

        return $loan->refresh();
    }

    /** Validation errors must stay inline, not collapse into the banner. */
    public function test_collateral_validation_errors_are_inline(): void
    {
        $loan = $this->makeLoan();

        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Loans\Show::class, ['loan' => $loan->id])
            ->set('collateralType', '')
            ->set('collateralValue', null)
            ->call('addCollateral')
            ->assertHasErrors(['collateralType', 'collateralValue'])
            ->assertSet('actionError', null);
    }

    /** Closing a modal must drop edit state so the next Add really adds. */
    public function test_cancelling_an_edit_does_not_hijack_the_next_add(): void
    {
        $loan = $this->makeLoan();
        $loan->collaterals()->create(['type' => 'Vehicle', 'estimated_value_minor' => 500000]);
        $existing = $loan->collaterals()->first();

        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Loans\Show::class, ['loan' => $loan->id])
            ->call('editCollateral', $existing->id)
            ->assertSet('editingCollateralId', $existing->id)
            ->set('showCollateralModal', false)          // Cancel
            ->assertSet('editingCollateralId', null)
            ->assertSet('collateralType', '')
            ->set('collateralType', 'Gold')
            ->set('collateralValue', 300.0)
            ->call('addCollateral')
            ->assertSet('actionError', null);

        $this->assertSame(2, $loan->collaterals()->count());
        $this->assertSame('Vehicle', $existing->fresh()->type);
    }

    /** Branch scoping must not lock people out of branchless records. */
    public function test_branchless_loan_stays_visible_to_scoped_user(): void
    {
        config(['lms.branch_scoping' => true]);

        $branch = \App\Models\Branch::create(['name' => 'B1', 'code' => 'B1-'.uniqid()]);
        $officer = User::create([
            'name' => 'Off', 'email' => 'off-'.uniqid().'@example.com',
            'password' => bcrypt('x'), 'role' => 'loan_officer', 'branch_id' => $branch->id,
        ]);

        $branchless = $this->makeLoan(branchId: null);
        $this->actingAs($officer)->get("/loans/{$branchless->id}")->assertOk();

        $other = \App\Models\Branch::create(['name' => 'B2', 'code' => 'B2-'.uniqid()]);
        $foreign = $this->makeLoan(branchId: $other->id);
        $this->actingAs($officer)->get("/loans/{$foreign->id}")->assertForbidden();
        $this->actingAs($officer)->get("/loans/{$foreign->id}/statement")->assertForbidden();
    }

    /** Collateral is locked once a loan is closed or written off. */
    public function test_collateral_locked_on_terminal_loans(): void
    {
        $loan = $this->makeLoan(LoanStatus::WrittenOff);

        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Loans\Show::class, ['loan' => $loan->id])
            ->set('collateralType', 'Gold')
            ->set('collateralValue', 100.0)
            ->call('addCollateral')
            ->assertSet('actionError', fn ($e) => $e !== null);

        $this->assertSame(0, $loan->collaterals()->count());
    }

    /** CSV export escapes formulas and skips action columns. */
    public function test_csv_export_is_safe(): void
    {
        $borrower = Borrower::create(['first_name' => '=cmd|calc', 'last_name' => 'Injection']);

        // Call the component method directly and capture the stream.
        $component = new \App\Livewire\Borrowers\Index;
        $this->actingAs($this->admin());

        ob_start();
        $component->exportCsv()->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringNotContainsString('<button', $csv);
        $this->assertStringContainsString('Injection', $csv);
    }

    /** Collateral on a settled loan must still be releasable. */
    public function test_collateral_can_be_released_after_closure(): void
    {
        $loan = $this->makeLoan(LoanStatus::WrittenOff);
        $loan->collaterals()->create(['type' => 'Gold', 'estimated_value_minor' => 100000]);
        $collateral = $loan->collaterals()->first();

        Livewire::actingAs($this->admin())
            ->test(\App\Livewire\Loans\Show::class, ['loan' => $loan->id])
            ->call('releaseCollateral', $collateral->id)
            ->assertSet('actionError', null);

        $this->assertSame('released', $collateral->fresh()->status);
    }

    /** Branchless records stay visible in lists too, not just by URL. */
    public function test_branchless_records_appear_in_scoped_lists(): void
    {
        config(['lms.branch_scoping' => true]);

        $branch = \App\Models\Branch::create(['name' => 'S1', 'code' => 'S1-'.uniqid()]);
        $officer = User::create([
            'name' => 'Off', 'email' => 'off-'.uniqid().'@example.com',
            'password' => bcrypt('x'), 'role' => 'loan_officer', 'branch_id' => $branch->id,
        ]);

        $branchless = $this->makeLoan(branchId: null);

        Livewire::actingAs($officer)
            ->test(\App\Livewire\Loans\Index::class)
            ->assertSee($branchless->loan_number);
    }

    /** A rejected application can be revised by its maker. */
    public function test_rejected_loan_is_editable(): void
    {
        $loan = $this->makeLoan(LoanStatus::Rejected);

        $this->actingAs($this->admin())
            ->get("/loans/{$loan->id}/edit")
            ->assertOk();
    }
}
