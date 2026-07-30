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

        // All aggregation happens in SQL; only overdue loans are hydrated
        // (capped), so the page stays fast on large books.
        $currencies = Loan::where('status', LoanStatus::Active)->pluck('currency', 'id');

        $outstandingPerLoan = \App\Models\LoanInstallment::query()
            ->whereIn('loan_id', $currencies->keys())
            ->groupBy('loan_id')
            ->select('loan_id', \Illuminate\Support\Facades\DB::raw('SUM(principal_minor - principal_paid_minor) as outstanding'))
            ->pluck('outstanding', 'loan_id');

        $overdueAgg = \App\Models\LoanInstallment::query()
            ->whereIn('loan_id', $currencies->keys())
            ->whereNull('settled_at')
            ->whereDate('due_date', '<', $today)
            ->groupBy('loan_id')
            ->select(
                'loan_id',
                \Illuminate\Support\Facades\DB::raw('MIN(due_date) as first_overdue'),
                \Illuminate\Support\Facades\DB::raw('SUM(principal_minor - principal_paid_minor + interest_minor - interest_paid_minor + penalty_minor - penalty_paid_minor) as overdue_minor')
            )
            ->get()
            ->keyBy('loan_id');

        $totalOutstanding = [];
        $parBuckets = [30 => [], 60 => [], 90 => []];

        foreach ($currencies as $loanId => $currency) {
            $outstanding = (int) ($outstandingPerLoan[$loanId] ?? 0);
            $totalOutstanding[$currency] = ($totalOutstanding[$currency] ?? 0) + $outstanding;

            if ($agg = $overdueAgg->get($loanId)) {
                $days = (int) \Illuminate\Support\Carbon::parse($agg->first_overdue)->diffInDays($today);

                foreach ([30, 60, 90] as $bucket) {
                    if ($days > $bucket) {
                        $parBuckets[$bucket][$currency] = ($parBuckets[$bucket][$currency] ?? 0) + $outstanding;
                    }
                }
            }
        }

        // Detail rows: hydrate ONLY overdue loans, worst first, capped.
        $overdueRows = [];
        $overdueLoans = Loan::with('borrower')
            ->whereIn('id', $overdueAgg->keys())
            ->get()
            ->keyBy('id');

        foreach ($overdueAgg as $loanId => $agg) {
            $loan = $overdueLoans->get($loanId);

            if (! $loan) {
                continue;
            }

            $overdueRows[] = [
                'id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'borrower' => $loan->borrower?->fullName() ?? '—',
                'days' => (int) \Illuminate\Support\Carbon::parse($agg->first_overdue)->diffInDays($today),
                'overdue' => Money::minor((int) $agg->overdue_minor, $loan->currency, (int) $loan->scale)->toDecimalString(),
                'outstanding' => Money::minor((int) ($outstandingPerLoan[$loanId] ?? 0), $loan->currency, (int) $loan->scale)->toDecimalString(),
                'currency' => $loan->currency,
            ];
        }

        usort($overdueRows, fn ($a, $b) => $b['days'] <=> $a['days']);
        $overdueRows = array_slice($overdueRows, 0, 200);

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
