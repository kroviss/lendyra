<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DemoReset extends Command
{
    protected $signature = 'demo:reset {--force : Run even when demo mode is off}';

    protected $description = 'Wipe all business data and reseed the demo dataset (demo installations only)';

    /** Truncation order respects foreign keys (children first). */
    private const TABLES = [
        'loan_payment_allocations',
        'loan_payments',
        'sms_logs',
        'loan_installments',
        'collaterals',
        'guarantors',
        'loans',
        'borrowers',
        'journal_lines',
        'journal_entries',
        'ledger_accounts',
        'users',
        'branches',
    ];

    public function handle(): int
    {
        if (! config('lms.demo') && ! $this->option('force')) {
            $this->error('Refusing to reset: demo mode is off (set LMS_DEMO_MODE=true or pass --force).');

            return self::FAILURE;
        }

        // Requests served mid-truncate would re-link orphaned rows to new
        // seed IDs — hide the app behind maintenance mode until the
        // dataset is whole again.
        Artisan::call('down', ['--retry' => 15]);

        try {
            Schema::disableForeignKeyConstraints();

            try {
                foreach (self::TABLES as $table) {
                    DB::table($table)->truncate();
                }
            } finally {
                Schema::enableForeignKeyConstraints();
            }

            Artisan::call('db:seed', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        } finally {
            Artisan::call('up');
        }

        $this->info('Demo data reset complete.');

        return self::SUCCESS;
    }
}
