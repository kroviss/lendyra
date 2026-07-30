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
        $totalOutstanding = [];   // currency => minor
        $parBuckets = [30 => [], 60 => [], 90 => []];  // bucket => currency => minor
        $overdueRows = [];

        Loan::query()
            ->where('status', LoanStatus::Active)
            ->with(['installments', 'borrower'])
            ->chunkById(200, function ($loans) use ($today, &$totalOutstanding, &$parBuckets, &$overdueRows) {
                foreach ($loans as $loan) {
                    $currency = $loan->currency;
                    $outstanding = $loan->principalOutstanding()->minor;
                    $totalOutstanding[$currency] = ($totalOutstanding[$currency] ?? 0) + $outstanding;

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
                            $parBuckets[$bucket][$currency] = ($parBuckets[$bucket][$currency] ?? 0) + $outstanding;
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

        // PAR ratio per currency: risky outstanding / total outstanding.
        $ratio = function (array $bucket) use ($totalOutstanding): string {
            if ($totalOutstanding === []) {
                return '—';
            }

            return collect($totalOutstanding)
                ->map(function (int $total, string $currency) use ($bucket, $totalOutstanding) {
                    $risky = $bucket[$currency] ?? 0;

                    return number_format($total > 0 ? $risky / $total * 100 : 0, 1).'%'
                        .(count($totalOutstanding) > 1 ? ' '.$currency : '');
                })
                ->implode(' · ');
        };

        $outstandingLabel = $totalOutstanding === []
            ? '0.00'
            : collect($totalOutstanding)
                ->map(fn (int $minor, string $currency) => Money::minor($minor, $currency)->toDecimalString().' '.$currency)
                ->implode(' · ');

        return view('livewire.reports.portfolio', [
            'totalOutstanding' => $outstandingLabel,
            'par30' => $ratio($parBuckets[30]),
            'par60' => $ratio($parBuckets[60]),
            'par90' => $ratio($parBuckets[90]),
            'overdueRows' => $overdueRows,
        ]);
    }
}
