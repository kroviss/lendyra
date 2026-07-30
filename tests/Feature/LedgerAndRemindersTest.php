<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LoanStatus;
use App\Models\Borrower;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\LoanScheduleService;
use App\Services\RepaymentService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use LoanEngine\Money;
use Tests\TestCase;

class LedgerAndRemindersTest extends TestCase
{
    use DatabaseTransactions;

    private function makeActiveLoan(string $phone = '+10000000001'): Loan
    {
        $product = LoanProduct::create([
            'name' => 'Ledger Product',
            'code' => 'LG-'.uniqid(),
            'annual_rate' => 12.0,
            'term_count' => 6,
            'penalty_daily_rate' => 0.5,
        ])->refresh();

        $borrower = Borrower::create(['first_name' => 'Ledger', 'last_name' => 'Test', 'phone' => $phone]);

        $loan = Loan::create([
            'loan_number' => 'LG-'.uniqid(),
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

    public function test_disbursement_posts_balanced_entry(): void
    {
        $loan = $this->makeActiveLoan();

        $entry = app(LedgerService::class)->postDisbursement($loan);

        $this->assertCount(2, $entry->lines);
        $this->assertSame(600000, (int) $entry->lines->sum('debit_minor'));
        $this->assertSame(600000, (int) $entry->lines->sum('credit_minor'));
        $this->assertSame('1100', $entry->lines->firstWhere('debit_minor', '>', 0)->account->code);
    }

    public function test_payment_posting_splits_by_component(): void
    {
        $loan = $this->makeActiveLoan();

        // Installment #1: 1000.00 principal + 60.00 interest.
        app(RepaymentService::class)->record($loan, Money::of('1060.00'), new DateTimeImmutable('2026-02-15'));

        $entry = JournalEntry::where('reference_type', 'loan_payment')->latest('id')->first();
        $lines = $entry->lines()->with('account')->get();

        $cash = $lines->firstWhere(fn ($l) => $l->account->code === '1000');
        $portfolio = $lines->firstWhere(fn ($l) => $l->account->code === '1100');
        $interest = $lines->firstWhere(fn ($l) => $l->account->code === '4000');

        $this->assertSame(106000, (int) $cash->debit_minor);
        $this->assertSame(100000, (int) $portfolio->credit_minor);
        $this->assertSame(6000, (int) $interest->credit_minor);
        $this->assertSame((int) $lines->sum('debit_minor'), (int) $lines->sum('credit_minor'));
    }

    public function test_overpayment_posts_to_liability(): void
    {
        $loan = $this->makeActiveLoan();

        // Total owed over the whole loan is 6000 + 210 interest = 6210. Pay more.
        app(RepaymentService::class)->record($loan, Money::of('6300.00'), new DateTimeImmutable('2026-02-15'));

        $entry = JournalEntry::where('reference_type', 'loan_payment')->latest('id')->first();
        $overpayment = $entry->lines()->with('account')->get()
            ->firstWhere(fn ($l) => $l->account->code === '2100');

        $this->assertNotNull($overpayment);
        $this->assertSame(9000, (int) $overpayment->credit_minor); // 90.00 unallocated
    }

    public function test_trial_balance_page_is_balanced(): void
    {
        $loan = $this->makeActiveLoan();
        app(LedgerService::class)->postDisbursement($loan);
        app(RepaymentService::class)->record($loan, Money::of('500.00'), new DateTimeImmutable('2026-02-15'));

        $admin = User::create([
            'name' => 'TB', 'email' => 'tb-'.uniqid().'@example.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get('/reports/trial-balance');
        $response->assertOk()->assertSee('Trial Balance')->assertDontSee('out of balance');
    }

    public function test_reminders_send_once_and_deduplicate(): void
    {
        $loan = $this->makeActiveLoan('+10000000042');
        // Installment #1 due 2026-02-15; remind 3 days before → run "today" = 2026-02-12.

        $this->artisan('loans:send-reminders', ['--date' => '2026-02-12'])
            ->expectsOutputToContain('1 reminder(s)')
            ->assertSuccessful();

        $this->artisan('loans:send-reminders', ['--date' => '2026-02-12'])
            ->expectsOutputToContain('0 reminder(s)')
            ->assertSuccessful();

        $log = DB::table('sms_logs')->where('to', '+10000000042')->first();
        $this->assertSame('upcoming', $log->kind);
        $this->assertStringContainsString($loan->loan_number, $log->message);
    }

    public function test_overdue_reminders_fire_weekly(): void
    {
        $this->makeActiveLoan('+10000000043');

        // 7 days after the Feb 15 due date → overdue notice fires.
        $this->artisan('loans:send-reminders', ['--date' => '2026-02-22'])->assertSuccessful();

        $kinds = DB::table('sms_logs')->where('to', '+10000000043')->pluck('kind');
        $this->assertContains('overdue', $kinds->all());

        // 8 days after → not a multiple of 7, nothing new.
        $before = DB::table('sms_logs')->count();
        $this->artisan('loans:send-reminders', ['--date' => '2026-02-23'])->assertSuccessful();
        $this->assertSame($before, DB::table('sms_logs')->count());
    }
}
