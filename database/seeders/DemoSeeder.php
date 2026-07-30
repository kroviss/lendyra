<?php

namespace Database\Seeders;

use App\Enums\LoanStatus;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LedgerService;
use App\Services\LoanScheduleService;
use App\Services\PenaltyService;
use App\Services\RepaymentService;
use DateTimeImmutable;
use Illuminate\Database\Seeder;
use LoanEngine\Money;

/**
 * A realistic portfolio for demos and fresh installs: loans in every
 * state (healthy, overdue, closed, written off, pending, rejected),
 * several months of payment history so the dashboard chart has shape,
 * collateral, guarantors and penalties. Idempotent.
 */
class DemoSeeder extends Seeder
{
    private int $sequence = 0;

    public function run(): void
    {
        if (Loan::query()->exists()) {
            return;
        }

        $biz = LoanProduct::where('code', 'BIZ-12')->firstOrFail()->refresh();
        $quick = LoanProduct::where('code', 'QUICK-3')->firstOrFail()->refresh();

        $borrowers = collect([
            ['first_name' => 'Amina', 'last_name' => 'Okafor', 'phone' => '+2348012345001', 'id_number' => 'NG-A0231', 'address' => 'Lagos, Nigeria'],
            ['first_name' => 'Jose', 'last_name' => 'Reyes', 'phone' => '+639171234002', 'id_number' => 'PH-88412', 'address' => 'Cebu, Philippines'],
            ['first_name' => 'Dawit', 'last_name' => 'Bekele', 'phone' => '+251911234003', 'id_number' => 'ET-55190', 'address' => 'Addis Ababa, Ethiopia'],
            ['first_name' => 'Grace', 'last_name' => 'Wanjiku', 'phone' => '+254712345004', 'id_number' => 'KE-73301', 'address' => 'Nairobi, Kenya'],
            ['first_name' => 'Carlos', 'last_name' => 'Mendoza', 'phone' => '+521551234005', 'id_number' => 'MX-20984', 'address' => 'Guadalajara, Mexico'],
            ['first_name' => 'Fatima', 'last_name' => 'Diallo', 'phone' => '+221771234006', 'id_number' => 'SN-41207', 'address' => 'Dakar, Senegal'],
            ['first_name' => 'Nguyen', 'last_name' => 'Thi Lan', 'phone' => '+84901234007', 'id_number' => 'VN-66823', 'address' => 'Ho Chi Minh City, Vietnam'],
            ['first_name' => 'Peter', 'last_name' => 'Ochieng', 'phone' => '+254722345008', 'id_number' => 'KE-91555', 'address' => 'Kisumu, Kenya'],
        ])->map(fn ($b) => Borrower::firstOrCreate(['phone' => $b['phone']], $b));

        $repay = app(RepaymentService::class);

        // 1. Healthy declining loan, 5 months of on-time payments (fills the chart).
        $loan = $this->activeLoan($biz, $borrowers[0], '12000.00', 12, 'declining_equal_principal', now()->subMonths(5)->subDays(12));
        $this->payInstallments($repay, $loan, 5);
        $loan->collaterals()->create(['type' => 'Vehicle', 'description' => 'Toyota Hiace 2018, plate LAG-441', 'estimated_value_minor' => Money::of('15000.00')->minor]);
        $loan->guarantors()->create(['name' => 'Chidi Okafor', 'phone' => '+2348012345099', 'relationship' => 'Brother']);

        // 2. Healthy annuity loan, 4 payments.
        $loan = $this->activeLoan($biz, $borrowers[1], '9000.00', 12, 'annuity', now()->subMonths(4)->subDays(8));
        $this->payInstallments($repay, $loan, 4);
        $loan->collaterals()->create(['type' => 'Motorcycle', 'description' => 'Honda XRM 125, 2021 — OR/CR on file', 'estimated_value_minor' => Money::of('2800.00')->minor]);
        $loan->guarantors()->create(['name' => 'Maria Reyes', 'phone' => '+639171234099', 'relationship' => 'Spouse']);

        // 3. Flat loan fully repaid → Closed; its collateral released.
        $loan = $this->activeLoan($quick, $borrowers[2], '3000.00', 3, 'flat', now()->subMonths(4)->subDays(3));
        $this->payInstallments($repay, $loan, 3);
        $loan->collaterals()->create(['type' => 'Gold jewelry', 'description' => '2 rings + necklace, 24k, 31g total', 'estimated_value_minor' => Money::of('3400.00')->minor, 'status' => 'released', 'released_at' => now()->subMonths(1)->format('Y-m-d')]);

        // 4. Overdue ~40 days (PAR 30), one payment made, penalties accrued.
        $loan = $this->activeLoan($biz, $borrowers[3], '6000.00', 6, 'declining_equal_principal', now()->subMonths(3)->subDays(10));
        $this->payInstallments($repay, $loan, 1);
        $loan->collaterals()->create(['type' => 'Land title', 'description' => 'Plot 12, Ruiru — title KJD/1102', 'estimated_value_minor' => Money::of('20000.00')->minor]);

        // 5. Overdue 100+ days (PAR 90), nothing paid, penalties accrued.
        $loan = $this->activeLoan($quick, $borrowers[4], '2500.00', 3, 'flat', now()->subMonths(4)->subDays(15));
        $loan->collaterals()->create(['type' => 'Electronics', 'description' => 'MacBook Pro 14" 2023, serial C02XL...', 'estimated_value_minor' => Money::of('1600.00')->minor]);

        // 6. Interest-only balloon with 2 interest payments.
        $loan = $this->activeLoan($biz, $borrowers[5], '10000.00', 6, 'interest_only_balloon', now()->subMonths(2)->subDays(20));
        $this->payInstallments($repay, $loan, 2);
        $loan->collaterals()->create(['type' => 'Equipment', 'description' => 'Industrial sewing machines ×4, JUKI DDL-8700', 'estimated_value_minor' => Money::of('12000.00')->minor]);
        $loan->guarantors()->create(['name' => 'Ousmane Diallo', 'phone' => '+221771234098', 'relationship' => 'Business partner']);

        // 7. Weekly-frequency loan with 3 payments.
        $loan = $this->activeLoan($quick, $borrowers[6], '800.00', 8, 'flat', now()->subDays(30), 'weekly');
        $this->payInstallments($repay, $loan, 3);
        $loan->collaterals()->create(['type' => 'Livestock', 'description' => '3 dairy goats, ear-tagged VN-102..104', 'estimated_value_minor' => Money::of('950.00')->minor]);

        // 8. Freshly disbursed, first installment not yet due.
        $loan = $this->activeLoan($biz, $borrowers[7], '4500.00', 12, 'annuity', now()->subDays(6));
        $loan->guarantors()->create(['name' => 'Susan Ochieng', 'phone' => '+254722345097', 'relationship' => 'Sister']);

        // 9. Approved, awaiting disbursement.
        $this->makeLoan($biz, $borrowers[0], '8000.00', 12, 'annuity', now()->addDays(2), LoanStatus::Approved);

        // 10. Rejected application.
        $this->makeLoan($biz, $borrowers[4], '15000.00', 24, 'declining_equal_principal', now()->addDays(5), LoanStatus::Rejected);

        // 11. Small written-off loan (posts the loss to the ledger).
        $loan = $this->activeLoan($quick, $borrowers[2], '600.00', 3, 'flat', now()->subMonths(6)->subDays(5));
        $loan->update(['status' => LoanStatus::WrittenOff, 'written_off_at' => now()->subDays(10)]);
        app(LedgerService::class)->postWriteOff($loan, $loan->principalOutstanding());

        // Accrue penalties portfolio-wide as of today.
        Loan::where('status', LoanStatus::Active)->with('product')->get()->each(
            fn (Loan $l) => app(PenaltyService::class)->accrue($l, new DateTimeImmutable(today()->format('Y-m-d')))
        );
    }

    private function activeLoan(LoanProduct $product, Borrower $borrower, string $amount, int $terms, string $method, $disbursedAt, string $frequency = 'monthly'): Loan
    {
        $loan = $this->makeLoan($product, $borrower, $amount, $terms, $method, $disbursedAt, LoanStatus::Approved, $frequency);

        app(LoanScheduleService::class)->generateAndPersist($loan);
        $loan->update(['status' => LoanStatus::Active]);
        app(LedgerService::class)->postDisbursement($loan->refresh());

        return $loan;
    }

    private function makeLoan(LoanProduct $product, Borrower $borrower, string $amount, int $terms, string $method, $disbursedAt, LoanStatus $status, string $frequency = 'monthly'): Loan
    {
        $this->sequence++;

        return Loan::create([
            'loan_number' => 'LN-'.now()->format('y').'-'.str_pad((string) $this->sequence, 5, '0', STR_PAD_LEFT),
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'currency' => $product->currency,
            'scale' => $product->scale,
            'principal_minor' => Money::of($amount)->minor,
            'annual_rate' => $product->annual_rate,
            'term_count' => $terms,
            'method' => $method,
            'frequency' => $frequency,
            'basis' => 'equal_periods',
            'disbursed_at' => $disbursedAt instanceof \DateTimeInterface ? $disbursedAt->format('Y-m-d') : $disbursedAt,
            'status' => $status,
            'created_by' => null,
        ]);
    }

    /** Pay the first $count installments in full, each on its due date. */
    private function payInstallments(RepaymentService $repay, Loan $loan, int $count): void
    {
        foreach ($loan->refresh()->installments->take($count) as $installment) {
            $due = $installment->fresh()->toDue();

            if ($due->totalDue()->minor <= 0) {
                continue;
            }

            $repay->record(
                $loan->refresh(),
                $due->totalDue(),
                new DateTimeImmutable($installment->due_date->format('Y-m-d')),
                method: ['cash', 'bank', 'mobile'][$installment->number % 3],
            );
        }
    }
}
