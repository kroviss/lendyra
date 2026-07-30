<?php

namespace Database\Seeders;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::firstOrCreate(['code' => 'HQ'], ['name' => 'Head Office']);

        // Never plant the well-known default credential next to real
        // accounts — only on a completely empty install (demo/dev).
        if (User::query()->count() > 0) {
            $this->seedReferenceData($branch);

            return;
        }

        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'branch_id' => $branch->id,
            ]
        );

        $this->seedReferenceData($branch);
    }

    private function seedReferenceData(Branch $branch): void
    {
        LoanProduct::firstOrCreate(
            ['code' => 'BIZ-12'],
            [
                'name' => 'Business Loan',
                'annual_rate' => 24.0,
                'term_count' => 12,
                'method' => 'declining_equal_principal',
                'penalty_daily_rate' => 0.5,
                'penalty_grace_days' => 3,
            ]
        );

        LoanProduct::firstOrCreate(
            ['code' => 'QUICK-3'],
            [
                'name' => 'Quick Loan',
                'annual_rate' => 36.0,
                'term_count' => 3,
                'method' => 'flat',
                'penalty_daily_rate' => 1.0,
            ]
        );

        foreach ([
            ['first_name' => 'Amina', 'last_name' => 'Okafor', 'phone' => '+2348012345001'],
            ['first_name' => 'Jose', 'last_name' => 'Reyes', 'phone' => '+639171234002'],
            ['first_name' => 'Dawit', 'last_name' => 'Bekele', 'phone' => '+251911234003'],
        ] as $borrower) {
            Borrower::firstOrCreate(['phone' => $borrower['phone']], $borrower);
        }
    }
}
