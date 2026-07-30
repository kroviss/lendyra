<?php

namespace Database\Seeders;

use App\Enums\LoanStatus;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanScheduleService;
use App\Services\PenaltyService;
use App\Services\RepaymentService;
use DateTimeImmutable;
use Illuminate\Database\Seeder;
use LoanEngine\Money;

/**
 * Sample loans in three states (healthy, overdue with penalties,
 * awaiting disbursement) so a fresh install has something to look at.
 * Run after DatabaseSeeder. Idempotent.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Loan::query()->exists()) {
            return;
        }

        $product = LoanProduct::where('code', 'BIZ-12')->firstOrFail()->refresh();
        $borrowers = Borrower::limit(3)->get();
        $schedules = app(LoanScheduleService::class);
        $repay = app(RepaymentService::class);

        $disbursed1 = now()->subMonths(2)->subDays(20)->format('Y-m-d');
        $loan1 = $this->makeLoan($product, $borrowers[0]->id, 'LN-'.now()->format('y').'-00001', '12000.00', 12, 'declining_equal_principal', $disbursed1);
        $schedules->generateAndPersist($loan1);
        $loan1->update(['status' => LoanStatus::Active]);
        $first = $loan1->refresh()->installments[0];
        $second = $loan1->installments[1];
        $repay->record($loan1, $first->toDue()->totalDue(), new DateTimeImmutable($first->due_date->format('Y-m-d')));
        $repay->record($loan1->refresh(), $second->toDue()->totalDue(), new DateTimeImmutable($second->due_date->format('Y-m-d')));

        $disbursed2 = now()->subMonths(3)->subDays(10)->format('Y-m-d');
        $loan2 = $this->makeLoan($product, $borrowers[1]->id, 'LN-'.now()->format('y').'-00002', '5000.00', 6, 'flat', $disbursed2);
        $schedules->generateAndPersist($loan2);
        $loan2->update(['status' => LoanStatus::Active]);
        $firstDue = $loan2->refresh()->installments[0];
        $repay->record($loan2, $firstDue->toDue()->totalDue(), new DateTimeImmutable($firstDue->due_date->format('Y-m-d')));
        app(PenaltyService::class)->accrue($loan2->refresh(), new DateTimeImmutable(today()->format('Y-m-d')));

        $this->makeLoan($product, $borrowers[2]->id, 'LN-'.now()->format('y').'-00003', '8000.00', 12, 'annuity', now()->addDays(2)->format('Y-m-d'));
    }

    private function makeLoan(LoanProduct $product, int $borrowerId, string $number, string $amount, int $terms, string $method, string $disbursedAt): Loan
    {
        return Loan::create([
            'loan_number' => $number,
            'borrower_id' => $borrowerId,
            'loan_product_id' => $product->id,
            'currency' => $product->currency,
            'scale' => $product->scale,
            'principal_minor' => Money::of($amount)->minor,
            'annual_rate' => $product->annual_rate,
            'term_count' => $terms,
            'method' => $method,
            'frequency' => 'monthly',
            'basis' => 'equal_periods',
            'disbursed_at' => $disbursedAt,
            'status' => LoanStatus::Approved,
        ]);
    }
}
