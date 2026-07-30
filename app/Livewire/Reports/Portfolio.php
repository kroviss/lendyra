<?php

namespace App\Livewire\Reports;

use App\Enums\LoanStatus;
use App\Models\Loan;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use LoanEngine\Money;

/**
 * Portfolio at Risk (PAR) — the standard MFI risk metric: the share of
 * outstanding principal held by loans that have any installment overdue
 * by more than N days.
 */
class Portfolio extends Component
{
    public function render(): View
    {
        $today = today();
        $totalOutstanding = 0;
        $parBuckets = [30 => 0, 60 => 0, 90 => 0];
        $overdueRows = [];

        Loan::query()
            ->where('status', LoanStatus::Active)
            ->with(['installments', 'borrower'])
            ->chunkById(200, function ($loans) use ($today, &$totalOutstanding, &$parBuckets, &$overdueRows) {
                foreach ($loans as $loan) {
                    $outstanding = $loan->principalOutstanding()->minor;
                    $totalOutstanding += $outstanding;

                    $maxOverdueDays = 0;
                    $overdueAmountMinor = 0;

                    foreach ($loan->installments as $installment) {
                        if ($installment->settled_at === null && $installment->due_date->lt($today)) {
                            $maxOverdueDays = max($maxOverdueDays, (int) $installment->due_date->diffInDays($today));
                            $overdueAmountMinor += $installment->toDue()->totalDue()->minor;
                        }
                    }

                    foreach ([30, 60, 90] as $bucket) {
                        if ($maxOverdueDays > $bucket) {
                            $parBuckets[$bucket] += $outstanding;
                        }
                    }

                    if ($maxOverdueDays > 0) {
                        $overdueRows[] = [
                            'id' => $loan->id,
                            'loan_number' => $loan->loan_number,
                            'borrower' => $loan->borrower->fullName(),
                            'days' => $maxOverdueDays,
                            'overdue' => Money::minor($overdueAmountMinor, $loan->currency, (int) $loan->scale)->toDecimalString(),
                            'outstanding' => Money::minor($outstanding, $loan->currency, (int) $loan->scale)->toDecimalString(),
                            'currency' => $loan->currency,
                        ];
                    }
                }
            });

        usort($overdueRows, fn ($a, $b) => $b['days'] <=> $a['days']);

        $ratio = fn (int $minor) => $totalOutstanding > 0
            ? number_format($minor / $totalOutstanding * 100, 1).'%'
            : '—';

        return view('livewire.reports.portfolio', [
            'totalOutstanding' => Money::minor($totalOutstanding)->toDecimalString(),
            'par30' => $ratio($parBuckets[30]),
            'par60' => $ratio($parBuckets[60]),
            'par90' => $ratio($parBuckets[90]),
            'overdueRows' => $overdueRows,
        ]);
    }
}
