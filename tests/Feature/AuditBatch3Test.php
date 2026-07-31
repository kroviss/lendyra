<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LoanStatus;
use App\Livewire\Loans\Show;
use App\Livewire\Users\Form;
use App\Models\Borrower;
use App\Models\Branch;
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
use LoanEngine\AllocationComponent;
use LoanEngine\Money;
use Tests\TestCase;

class AuditBatch3Test extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $role, bool $active = true): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@example.com',
            'password' => bcrypt('secret123'), 'role' => $role, 'is_active' => $active,
        ]);
    }

    private function makeActiveLoan(
        float $penaltyRate = 0.5,
        string $principal = '6000.00',
        int $terms = 6,
        float $rate = 12.0,
        string $disbursedAt = '2026-01-15',
        array $productOverrides = [],
    ): Loan {
        $product = LoanProduct::create([
            'name' => 'AB3', 'code' => 'AB3-'.uniqid(), 'annual_rate' => $rate,
            'term_count' => $terms, 'penalty_daily_rate' => $penaltyRate,
        ] + $productOverrides)->refresh();
        $borrower = Borrower::create(['first_name' => 'Batch', 'last_name' => 'Three', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $loan = Loan::create([
            'loan_number' => 'AB3-'.uniqid(),
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'currency' => 'USD', 'scale' => 2,
            'principal_minor' => Money::of($principal)->minor,
            'annual_rate' => $rate, 'term_count' => $terms,
            'method' => $product->method->value,
            'frequency' => 'monthly', 'basis' => 'equal_periods',
            'disbursed_at' => $disbursedAt,
            'status' => LoanStatus::Approved,
        ]);
        app(LoanScheduleService::class)->generateAndPersist($loan);
        $loan->update(['status' => LoanStatus::Active]);

        return $loan->refresh();
    }

    /** F1: prorated payoff must not re-charge interest already prepaid. */
    public function test_prorated_payoff_nets_out_interest_prepaid_by_waterfall(): void
    {
        // 24%/yr → 2%/month declining. 12,000 over 12 months:
        // #1 owes 240.00 interest + 1,000.00 principal (due Feb 15),
        // #2 owes 220.00 interest on the remaining 11,000.00.
        $loan = $this->makeActiveLoan(
            penaltyRate: 0, principal: '12000.00', terms: 12, rate: 24.0,
            productOverrides: ['payoff_interest_mode' => 'prorated'],
        );

        // Overpay #1 by 60.00 — the waterfall prepays part of #2's interest.
        app(RepaymentService::class)->record($loan, Money::of('1300.00'), new DateTimeImmutable('2026-02-15'));

        $second = $loan->installments()->where('number', 2)->first();
        $this->assertSame(6000, (int) $second->interest_paid_minor);

        // Quote Mar 1: 14 of 28 days elapsed → gross accrual
        // 11,000 × 2% × ½ = 110.00, of which 60.00 is already paid.
        $payoff = app(PayoffService::class);
        $quote = $payoff->quote($loan->refresh(), new DateTimeImmutable('2026-03-01'));

        $this->assertSame('50.00', $quote->accruedInterest->toDecimalString());
        $this->assertSame('11050.00', $quote->total()->toDecimalString());

        // FullPeriod agrees in spirit: it charges the unpaid remainder
        // (160.00), never the prepaid 60.00 again.
        $loan->product->update(['payoff_interest_mode' => 'full_period']);
        $fullQuote = $payoff->quote($loan->fresh(), new DateTimeImmutable('2026-03-01'));
        $this->assertSame('160.00', $fullQuote->accruedInterest->toDecimalString());
        $loan->product->update(['payoff_interest_mode' => 'prorated']);

        // Settle: period interest lands at max(prepaid, time-accrued)
        // = 110.00, never prepaid + gross accrual.
        $payment = $payoff->settle($loan->fresh(), new DateTimeImmutable('2026-03-01'));

        $this->assertSame(1105000, (int) $payment->amount_minor);
        $this->assertSame(0, (int) $payment->unallocated_minor);

        $loan->refresh();
        $this->assertSame(LoanStatus::Closed, $loan->status);
        $this->assertSame(11000, (int) $loan->installments()->where('number', 2)->first()->interest_minor);
    }

    /** F2: waiving the last outstanding penalty closes the loan. */
    public function test_waiving_last_outstanding_penalty_closes_the_loan(): void
    {
        $loan = $this->makeActiveLoan();

        // Everything paid except a 50.00 penalty on installment #1.
        foreach ($loan->installments as $installment) {
            $installment->update([
                'principal_paid_minor' => $installment->principal_minor,
                'interest_paid_minor' => $installment->interest_minor,
                'settled_at' => $installment->number === 1 ? null : '2026-02-15',
            ]);
        }
        $first = $loan->installments()->where('number', 1)->first();
        $first->update(['penalty_minor' => 5000, 'settled_at' => null]);

        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);

        $admin = $this->makeUser('admin');
        Livewire::actingAs($admin)
            ->test(Show::class, ['loan' => $loan->id])
            ->call('waivePenalty', $first->id)
            ->assertSet('actionError', null);

        $loan->refresh();
        $this->assertSame(LoanStatus::Closed, $loan->status);
        $this->assertSame(today()->format('Y-m-d'), $loan->closed_at->format('Y-m-d'));
        $this->assertNotNull($first->fresh()->settled_at);
        $this->assertSame(0, $first->fresh()->penaltyDue()->minor);
    }

    /** F3: payments on a written-off loan cannot be reversed. */
    public function test_reversing_payment_on_written_off_loan_is_blocked(): void
    {
        $loan = $this->makeActiveLoan();
        $payment = app(RepaymentService::class)->record($loan, Money::of('100.00'), new DateTimeImmutable('2026-02-15'));

        $loan->update(['status' => LoanStatus::WrittenOff, 'written_off_at' => today()]);

        try {
            app(RepaymentService::class)->reverse($payment->fresh());
            $this->fail('Expected the reversal to be rejected.');
        } catch (\LogicException) {
            // expected
        }

        $this->assertNull($payment->fresh()->reversed_at);
        $first = $loan->installments()->where('number', 1)->first();
        $this->assertGreaterThan(0, (int) $first->interest_paid_minor);
    }

    /** F6: record() accrues penalties as of the payment date first. */
    public function test_record_accrues_penalties_up_to_payment_date(): void
    {
        $loan = $this->makeActiveLoan();

        // No cron ran. Paying 10 days after the Feb 15 due date must still
        // hit today's penalty first: 1,000.00 × 0.5% × 10 = 50.00.
        $payment = app(RepaymentService::class)->record($loan, Money::of('30.00'), new DateTimeImmutable('2026-02-25'));

        $this->assertSame(AllocationComponent::Penalty, $payment->allocations[0]->component);
        $this->assertSame(3000, (int) $payment->allocations[0]->amount_minor);

        $first = $loan->installments()->where('number', 1)->first();
        $this->assertSame(5000, (int) $first->penalty_minor);

        // "Pay everything today" now includes the penalty, so the loan
        // really closes: remaining 20.00 penalty + 210.00 interest +
        // 6,000.00 principal.
        app(RepaymentService::class)->record($loan->refresh(), Money::of('6230.00'), new DateTimeImmutable('2026-02-25'));

        $this->assertSame(LoanStatus::Closed, $loan->fresh()->status);
    }

    /** F8a: users referenced by financial rows must be deactivated, not deleted. */
    public function test_user_with_financial_history_cannot_be_deleted(): void
    {
        config(['lms.demo' => false]);

        $admin = $this->makeUser('admin');
        $officer = $this->makeUser('loan_officer');

        $loan = $this->makeActiveLoan();
        $loan->update(['created_by' => $officer->id]);

        Livewire::actingAs($admin)
            ->test(Form::class, ['user' => $officer])
            ->call('delete')
            ->assertHasErrors('name');

        $this->assertNotNull(User::find($officer->id));
    }

    /** F8b: the last active admin cannot be deactivated. */
    public function test_last_active_admin_cannot_be_deactivated(): void
    {
        config(['lms.demo' => false]);

        // Make our admin the only active one, whatever is seeded.
        User::where('role', 'admin')->update(['is_active' => false]);
        $admin = $this->makeUser('admin');

        Livewire::actingAs($admin)
            ->test(Form::class, ['user' => $admin])
            ->set('is_active', false)
            ->call('save')
            ->assertHasErrors('is_active');

        $this->assertTrue((bool) $admin->fresh()->is_active);
    }

    /** F12: a branch with borrowers or payments cannot be deleted. */
    public function test_branch_with_borrowers_cannot_be_deleted(): void
    {
        config(['lms.demo' => false]);

        $admin = $this->makeUser('admin');
        $branch = Branch::create(['name' => 'Waif Branch', 'code' => 'WB-'.substr(uniqid(), -6)]);
        Borrower::create(['first_name' => 'Branch', 'last_name' => 'Bound', 'branch_id' => $branch->id]);

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Branches\Form::class, ['branch' => $branch])
            ->call('delete')
            ->assertHasErrors('name');

        $this->assertNotNull(Branch::find($branch->id));
    }

    /** F10a: a missed cron day no longer skips the upcoming reminder. */
    public function test_missed_cron_day_still_sends_upcoming_reminder(): void
    {
        $loan = $this->makeActiveLoan();
        $phone = $loan->borrower->phone;

        // Exact-match logic would only have fired on 2026-02-12 (3 days
        // before the Feb 15 due date); the window catches it a day late.
        $this->artisan('loans:send-reminders', ['--date' => '2026-02-13'])->assertSuccessful();

        $log = DB::table('sms_logs')->where('to', $phone)->where('kind', 'upcoming')->first();
        $this->assertNotNull($log);
        $this->assertSame('2026-02-15', $log->sent_for);

        // Re-running inside the window stays deduplicated.
        $before = DB::table('sms_logs')->where('to', $phone)->count();
        $this->artisan('loans:send-reminders', ['--date' => '2026-02-14'])->assertSuccessful();
        $this->assertSame($before, DB::table('sms_logs')->where('to', $phone)->where('kind', 'upcoming')->count());
    }

    /** F10b: arrears older than a year still get overdue notices. */
    public function test_overdue_notice_not_capped_after_a_year(): void
    {
        $loan = $this->makeActiveLoan(disbursedAt: '2024-01-15');
        $phone = $loan->borrower->phone;

        $this->artisan('loans:send-reminders', ['--date' => '2026-07-01'])->assertSuccessful();

        $overdue = DB::table('sms_logs')->where('to', $phone)->where('kind', 'overdue')->count();
        $this->assertGreaterThan(0, $overdue);

        // Within the 7-day cadence nothing repeats...
        $this->artisan('loans:send-reminders', ['--date' => '2026-07-04'])->assertSuccessful();
        $this->assertSame($overdue, DB::table('sms_logs')->where('to', $phone)->where('kind', 'overdue')->count());

        // ...and after it, the weekly notice fires again.
        $this->artisan('loans:send-reminders', ['--date' => '2026-07-08'])->assertSuccessful();
        $this->assertSame($overdue * 2, DB::table('sms_logs')->where('to', $phone)->where('kind', 'overdue')->count());
    }
}
