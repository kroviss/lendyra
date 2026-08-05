<?php

namespace App\Livewire\Reports;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Support\CurrencyScale;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        // All aggregation happens in SQL via a grouped join — no active
        // loan-ID round trip — and only overdue loans are hydrated
        // (capped), so the page stays fast on large books.
        $branch = auth()->user()?->scopedBranchId();
        $activeLoans = fn ($q) => $q
            ->whereNull('loans.deleted_at')
            ->where('loans.status', LoanStatus::Active)
            ->when($branch, fn ($qq) => $qq->where(fn ($b) => $b->where('loans.branch_id', $branch)->orWhereNull('loans.branch_id')));

        // Raw fragments must carry the table prefix themselves.
        $li = DB::getTablePrefix().'loan_installments';

        $totalOutstanding = LoanInstallment::query()
            ->join('loans', 'loans.id', '=', 'loan_installments.loan_id')
            ->tap($activeLoans)
            ->groupBy('loans.currency')
            ->select('loans.currency', DB::raw("SUM(CAST({$li}.principal_minor AS SIGNED) - CAST({$li}.principal_paid_minor AS SIGNED)) as outstanding"))
            ->pluck('outstanding', 'currency')
            ->map(fn ($outstanding) => (int) $outstanding)
            ->all();

        // One grouped query per overdue loan: its oldest overdue date, its
        // FULL outstanding principal (for PAR), and its overdue amount (for
        // the detail rows). No per-loan whereIn round trip, and — critically —
        // no hydrating a Loan/Borrower model for every loan in arrears.
        $todayStr = $today->toDateString();

        $overdueAgg = LoanInstallment::query()
            ->join('loans', 'loans.id', '=', 'loan_installments.loan_id')
            ->tap($activeLoans)
            ->groupBy('loans.id', 'loans.currency')
            ->havingRaw('first_overdue IS NOT NULL')
            ->select('loans.id as loan_id', 'loans.currency')
            ->selectRaw("MIN(CASE WHEN {$li}.settled_at IS NULL AND {$li}.due_date < ? THEN {$li}.due_date END) as first_overdue", [$todayStr])
            ->selectRaw("SUM(CAST({$li}.principal_minor AS SIGNED) - CAST({$li}.principal_paid_minor AS SIGNED)) as outstanding")
            ->selectRaw("SUM(CASE WHEN {$li}.settled_at IS NULL AND {$li}.due_date < ? THEN (CAST({$li}.principal_minor AS SIGNED) - CAST({$li}.principal_paid_minor AS SIGNED) + CAST({$li}.interest_minor AS SIGNED) - CAST({$li}.interest_paid_minor AS SIGNED) + CAST({$li}.penalty_minor AS SIGNED) - CAST({$li}.penalty_paid_minor AS SIGNED)) ELSE 0 END) as overdue_minor", [$todayStr])
            ->get();

        $parBuckets = [30 => [], 60 => [], 90 => []];

        foreach ($overdueAgg as $agg) {
            $days = (int) Carbon::parse($agg->first_overdue)->diffInDays($today);

            foreach ([30, 60, 90] as $bucket) {
                if ($days > $bucket) {
                    $parBuckets[$bucket][$agg->currency] = ($parBuckets[$bucket][$agg->currency] ?? 0) + (int) $agg->outstanding;
                }
            }
        }

        // Detail rows: take only the worst 200 (oldest overdue first) and
        // hydrate just those loans — never the whole book in arrears.
        $overdueTotal = $overdueAgg->count();
        $worst = $overdueAgg->sortBy('first_overdue')->take(200)->values();

        $detailLoans = Loan::with('borrower')
            ->whereIn('id', $worst->pluck('loan_id'))
            ->get()
            ->keyBy('id');

        $overdueRows = $worst
            ->map(function ($agg) use ($detailLoans, $today) {
                $loan = $detailLoans->get($agg->loan_id);

                if (! $loan) {
                    return null;
                }

                return [
                    'id' => $loan->id,
                    'loan_number' => $loan->loan_number,
                    'borrower' => $loan->borrower?->fullName() ?? '—',
                    'days' => (int) Carbon::parse($agg->first_overdue)->diffInDays($today),
                    'overdue' => Money::minor((int) $agg->overdue_minor, $loan->currency, (int) $loan->scale)->formatted(),
                    'outstanding' => Money::minor((int) $agg->outstanding, $loan->currency, (int) $loan->scale)->formatted(),
                    'currency' => $loan->currency,
                ];
            })
            ->filter()
            ->values()
            ->all();

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

        $scales = app(CurrencyScale::class);
        $outstandingLabel = $totalOutstanding === []
            ? '0.00'
            : collect($totalOutstanding)
                ->map(fn (int $minor, string $currency) => $scales->money($minor, $currency)->formatted().' '.$currency)
                ->implode(' · ');

        return view('livewire.reports.portfolio', [
            'totalOutstanding' => $outstandingLabel,
            'par30' => $ratio($parBuckets[30]),
            'par60' => $ratio($parBuckets[60]),
            'par90' => $ratio($parBuckets[90]),
            'overdueRows' => $overdueRows,
            'overdueTotal' => $overdueTotal,
        ])->title(__('Portfolio report'));
    }
}
