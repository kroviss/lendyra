<?php

namespace App\Console\Commands;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Services\PenaltyService;
use DateTimeImmutable;
use Illuminate\Console\Command;

class AccruePenalties extends Command
{
    protected $signature = 'loans:accrue-penalties {--date= : Accrue as of this date (Y-m-d), defaults to today}';

    protected $description = 'Recompute penalties for every active loan with overdue installments (idempotent)';

    public function handle(PenaltyService $penalties): int
    {
        $asOf = new DateTimeImmutable($this->option('date') ?: today()->format('Y-m-d'));
        $count = 0;

        Loan::query()
            ->where('status', LoanStatus::Active)
            ->whereHas('installments', fn ($q) => $q
                ->whereNull('settled_at')
                ->where('due_date', '<', $asOf->format('Y-m-d')))
            ->with('product')
            ->chunkById(100, function ($loans) use ($penalties, $asOf, &$count) {
                foreach ($loans as $loan) {
                    $penalties->accrue($loan, $asOf);
                    $count++;
                }
            });

        $this->info("Penalties accrued for {$count} loan(s) as of {$asOf->format('Y-m-d')}.");

        return self::SUCCESS;
    }
}
