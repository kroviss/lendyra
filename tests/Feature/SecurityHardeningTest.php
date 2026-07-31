<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LoanStatus;
use App\Livewire\Loans\Form;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LoanEngine\Money;
use Tests\TestCase;

/** Guards for the security-audit fixes: proxy trust, branch scoping on forms, sessions, private media. */
class SecurityHardeningTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $role = 'admin', ?int $branchId = null): User
    {
        return User::create([
            'name' => 'Sec', 'email' => 'sec-'.uniqid().'@example.com',
            'password' => bcrypt('secret123'), 'role' => $role,
            'branch_id' => $branchId, 'is_active' => true,
        ]);
    }

    private function makeBranch(): Branch
    {
        return Branch::create(['name' => 'SB', 'code' => 'SB-'.substr(uniqid(), -8)]);
    }

    private function makeLoan(?int $branchId = null, ?int $borrowerId = null): Loan
    {
        $product = LoanProduct::create([
            'name' => 'SEC', 'code' => 'SEC-'.uniqid(), 'annual_rate' => 12.0, 'term_count' => 6,
        ])->refresh();
        $borrowerId ??= Borrower::create(['first_name' => 'Sec', 'last_name' => 'Loan'])->id;

        return Loan::create([
            'loan_number' => 'SEC-'.uniqid(),
            'borrower_id' => $borrowerId,
            'loan_product_id' => $product->id,
            'branch_id' => $branchId,
            'currency' => 'USD', 'scale' => 2,
            'principal_minor' => Money::of('1200.00')->minor,
            'annual_rate' => 12.0, 'term_count' => 6,
            'method' => $product->method->value,
            'frequency' => 'monthly', 'basis' => 'equal_periods',
            'disbursed_at' => '2026-01-15',
            'status' => LoanStatus::PendingApproval,
        ])->refresh();
    }

    /** F1: spoofed X-Forwarded-For must not rotate the login throttle key. */
    public function test_spoofed_forwarded_for_does_not_bypass_login_throttle(): void
    {
        $status = null;

        for ($i = 1; $i <= 6; $i++) {
            $status = $this->withHeaders(['X-Forwarded-For' => "203.0.113.{$i}"])
                ->post('/login', ['email' => 'nobody-'.uniqid().'@example.com', 'password' => 'wrong'])
                ->status();
        }

        // With untrusted proxies every attempt keys on the real client IP,
        // so the 6th attempt locks out no matter what the header claims.
        $this->assertSame(429, $status);
    }

    /** F2: a branch-scoped officer must not open another branch's loan edit page. */
    public function test_branch_scoped_officer_cannot_open_foreign_loan_edit(): void
    {
        config(['lms.branch_scoping' => true]);

        $officer = $this->makeUser('loan_officer', $this->makeBranch()->id);
        $foreign = $this->makeLoan(branchId: $this->makeBranch()->id);

        $this->actingAs($officer)->get("/loans/{$foreign->id}/edit")->assertForbidden();

        // Branchless loans stay editable, per convention.
        $this->actingAs($officer)->get('/loans/'.$this->makeLoan()->id.'/edit')->assertOk();
    }

    /** F2: the save path re-checks branch scope even if mount slipped through. */
    public function test_loan_form_save_rechecks_branch_scope(): void
    {
        $officer = $this->makeUser('loan_officer', $this->makeBranch()->id);
        $foreign = $this->makeLoan(branchId: $this->makeBranch()->id);

        // Mount with scoping off (simulates a stale/forged snapshot), then
        // enable it — save must still refuse.
        config(['lms.branch_scoping' => false]);
        $component = Livewire::actingAs($officer)->test(Form::class, ['loan' => $foreign]);

        config(['lms.branch_scoping' => true]);
        $component->call('save')->assertForbidden();
    }

    /** F3: a branch-scoped officer must not read or rewrite another branch's borrower. */
    public function test_branch_scoped_officer_cannot_edit_foreign_borrower(): void
    {
        config(['lms.branch_scoping' => true]);

        $officer = $this->makeUser('loan_officer', $this->makeBranch()->id);
        $foreign = Borrower::create([
            'first_name' => 'Foreign', 'last_name' => 'PII', 'branch_id' => $this->makeBranch()->id,
        ]);

        // Own-branch borrower opens fine (also guards the mount route binding).
        $mine = Borrower::create(['first_name' => 'Own', 'last_name' => 'Client', 'branch_id' => $officer->branch_id]);
        $this->actingAs($officer)->get("/borrowers/{$mine->id}/edit")->assertOk();

        $this->actingAs($officer)->get("/borrowers/{$foreign->id}/edit")->assertForbidden();

        // Save path re-check.
        config(['lms.branch_scoping' => false]);
        $component = Livewire::actingAs($officer)->test(\App\Livewire\Borrowers\Form::class, ['borrower' => $foreign]);

        config(['lms.branch_scoping' => true]);
        $component->set('first_name', 'Hijacked')->call('save')->assertForbidden();

        $this->assertSame('Foreign', $foreign->fresh()->first_name);
    }

    /** F4: the loan form's borrower dropdown must not leak other branches' clients. */
    public function test_loan_form_borrower_options_are_branch_scoped(): void
    {
        config(['lms.branch_scoping' => true]);

        $branch = $this->makeBranch();
        $officer = $this->makeUser('loan_officer', $branch->id);

        $mine = Borrower::create(['first_name' => 'Mine', 'last_name' => 'Client', 'phone' => '+15550001111', 'branch_id' => $branch->id]);
        $foreign = Borrower::create(['first_name' => 'Foreign', 'last_name' => 'Client', 'phone' => '+15550002222', 'branch_id' => $this->makeBranch()->id]);

        Livewire::actingAs($officer)
            ->test(Form::class)
            ->assertSee('+15550001111')
            ->assertDontSee('+15550002222');
    }

    /** F4: originating a loan against another branch's borrower is a validation error. */
    public function test_loan_form_rejects_foreign_borrower_id(): void
    {
        config(['lms.branch_scoping' => true]);

        $officer = $this->makeUser('loan_officer', $this->makeBranch()->id);
        $foreign = Borrower::create(['first_name' => 'Foreign', 'last_name' => 'Target', 'branch_id' => $this->makeBranch()->id]);
        $product = LoanProduct::create([
            'name' => 'SEC4', 'code' => 'SEC4-'.uniqid(), 'annual_rate' => 12.0, 'term_count' => 6,
        ])->refresh();

        Livewire::actingAs($officer)
            ->test(Form::class)
            ->set('borrower_id', $foreign->id)
            ->set('loan_product_id', $product->id)
            ->set('amount', 1000.0)
            ->set('annual_rate', '12')
            ->set('term_count', '6')
            ->call('save')
            ->assertHasErrors('borrower_id');

        $this->assertSame(0, Loan::where('borrower_id', $foreign->id)->count());
    }

    /** F5: remember-me is opt-in — no recaller cookie unless the box is ticked. */
    public function test_login_remember_me_is_opt_in(): void
    {
        $user = $this->makeUser();
        $recaller = Auth::guard('web')->getRecallerName();

        $this->post('/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertRedirect('/')
            ->assertCookieMissing($recaller);
    }

    /** F5: ticking the box still issues the recaller cookie. */
    public function test_login_remember_me_sets_cookie_when_requested(): void
    {
        $user = $this->makeUser();
        $recaller = Auth::guard('web')->getRecallerName();

        $this->post('/login', ['email' => $user->email, 'password' => 'secret123', 'remember' => '1'])
            ->assertRedirect('/')
            ->assertCookie($recaller);
    }

    /** F6: a password change kills other sessions via the session⇄hash binding. */
    public function test_password_change_invalidates_existing_sessions(): void
    {
        $user = $this->makeUser();

        // First request binds this session to the current password hash.
        $this->actingAs($user)->get('/')->assertOk();

        // Password changes elsewhere (another device, reset link, admin).
        $user->forceFill(['password' => Hash::make('brand-new-pass-123')])->save();

        // The stale session must die on its next request.
        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    /** F7: org-wide trial balance is off-limits to branch-scoped accountants. */
    public function test_trial_balance_forbidden_for_branch_scoped_accountant(): void
    {
        config(['lms.branch_scoping' => true]);

        $scoped = $this->makeUser('accountant', $this->makeBranch()->id);
        $this->actingAs($scoped)->get('/reports/trial-balance')->assertForbidden();

        // Fresh session — AuthenticateSession would (correctly) evict a
        // session bound to a different user's password hash.
        $this->flushSession();

        $admin = $this->makeUser('admin');
        $this->actingAs($admin)->get('/reports/trial-balance')->assertOk();
    }

    /** F8: borrower photos land on the private disk, not the public one. */
    public function test_borrower_photo_stored_on_private_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        Livewire::actingAs($this->makeUser())
            ->test(\App\Livewire\Borrowers\Form::class)
            ->set('first_name', 'Photo')
            ->set('last_name', 'Owner')
            ->set('photo', UploadedFile::fake()->image('face.jpg'))
            ->call('save');

        $borrower = Borrower::where('first_name', 'Photo')->where('last_name', 'Owner')->latest('id')->first();

        $this->assertNotNull($borrower?->photo_path);
        Storage::disk('local')->assertExists($borrower->photo_path);
        Storage::disk('public')->assertMissing($borrower->photo_path);
    }

    /** F8: media URLs require login; authenticated staff can stream them. */
    public function test_media_route_requires_authentication(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('borrowers/secret.jpg', 'jpeg-bytes');

        $this->get('/media/borrowers/secret.jpg')->assertRedirect('/login');

        $staff = $this->makeUser();
        $this->actingAs($staff)->get('/media/borrowers/secret.jpg')->assertOk();

        // Legacy fallback: files still sitting on the public disk stream too.
        Storage::fake('public');
        Storage::disk('public')->put('collaterals/legacy.jpg', 'jpeg-bytes');
        $this->actingAs($staff)->get('/media/collaterals/legacy.jpg')->assertOk();

        // Whitelisted types and no traversal.
        $this->actingAs($staff)->get('/media/logs/laravel.log')->assertNotFound();
        $this->actingAs($staff)->get('/media/borrowers/..%2F..%2F.env')->assertNotFound();
    }
}
