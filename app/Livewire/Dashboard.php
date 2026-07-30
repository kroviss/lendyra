<?php

namespace App\Livewire;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanPayment;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use LoanEngine\Money;

class Dashboard extends Component
{
    public function render(): View
    {
        $branch = auth()->user()?->scopedBranchId();
        $scope = fn ($q) => $q->when($branch, fn ($qq) => $qq->where(fn ($b) => $b->where('branch_id', $branch)->orWhereNull('branch_id')));

        $activeLoans = $scope(Loan::where('status', LoanStatus::Active))->count();

        // Sums are kept per currency — minor units of different
        // currencies must never be added together. Aggregated in SQL:
        // hydrating every active loan+installments melts at scale.
        $currencies = $scope(Loan::where('status', LoanStatus::Active))->pluck('currency', 'id');

        $sums = \App\Models\LoanInstallment::query()
            ->whereIn('loan_id', $currencies->keys())
            ->groupBy('loan_id')
            ->select('loan_id', \Illuminate\Support\Facades\DB::raw('SUM(CAST(principal_minor AS SIGNED) - CAST(principal_paid_minor AS SIGNED)) as outstanding'))
            ->pluck('outstanding', 'loan_id');

        $outstandingByCurrency = [];
        foreach ($sums as $loanId => $outstanding) {
            $currency = $currencies[$loanId];
            $outstandingByCurrency[$currency] = ($outstandingByCurrency[$currency] ?? 0) + (int) $outstanding;
        }

        $overdueCount = LoanInstallment::query()
            ->whereHas('loan', fn ($q) => $scope($q->where('status', LoanStatus::Active)))
            ->whereNull('settled_at')
            ->where('due_date', '<', today())
            ->count();

        $collectedByCurrency = LoanPayment::query()
            ->when($branch, fn ($q) => $q->whereHas('loan', fn ($l) => $l->where(fn ($b) => $b->where('branch_id', $branch)->orWhereNull('branch_id'))))
            ->whereNull('reversed_at')
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->join('loans', 'loans.id', '=', 'loan_payments.loan_id')
            ->groupBy('loans.currency')
            ->select('loans.currency', \Illuminate\Support\Facades\DB::raw('SUM(amount_minor) as total'))
            ->pluck('total', 'currency')
            ->map(fn ($v) => (int) $v)
            ->all();

        // Collections trend, last 6 months, in the portfolio's primary
        // (most common) currency only — a chart must not mix currencies.
        $primaryCurrency = Loan::query()
            ->selectRaw('currency, COUNT(*) as c')
            ->groupBy('currency')
            ->orderByDesc('c')
            ->value('currency') ?? 'USD';

        // startOfMonth BEFORE subMonths — subtracting months from e.g.
        // Jul 30 overflows through Feb 30 into March and loses a bucket.
        $chart = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->startOfMonth()->subMonths($i);
            $chart[$month->format('M')] = 0;
        }

        $monthly = LoanPayment::query()
            ->when($branch, fn ($q) => $q->whereHas('loan', fn ($l) => $l->where(fn ($b) => $b->where('branch_id', $branch)->orWhereNull('branch_id'))))
            ->whereNull('reversed_at')
            ->where('paid_at', '>=', now()->startOfMonth()->subMonths(5))
            ->whereHas('loan', fn ($q) => $q->where('currency', $primaryCurrency))
            ->groupBy('ym')
            ->select(
                \Illuminate\Support\Facades\DB::raw("DATE_FORMAT(paid_at, '%Y-%m') as ym"),
                \Illuminate\Support\Facades\DB::raw('SUM(amount_minor) as total')
            )
            ->pluck('total', 'ym');

        for ($i = 5; $i >= 0; $i--) {
            $month = now()->startOfMonth()->subMonths($i);
            $chart[$month->format('M')] = (int) ($monthly[$month->format('Y-m')] ?? 0);
        }

        $chartMax = max(1, max($chart));
        $chartBars = collect($chart)->map(fn (int $minor, string $label) => [
            'label' => $label,
            'value' => Money::minor($minor, $primaryCurrency)->formatted(),
            'height' => (int) round($minor / $chartMax * 100),
        ])->values()->all();

        $pendingCount = $scope(Loan::where('status', LoanStatus::PendingApproval))->count();

        return view('livewire.dashboard', [
            'pendingCount' => $pendingCount,
            'chartBars' => $chartBars,
            'chartCurrency' => $primaryCurrency,
            'activeLoans' => $activeLoans,
            'outstanding' => $this->formatByCurrency($outstandingByCurrency),
            'overdueCount' => $overdueCount,
            'collectedThisMonth' => $this->formatByCurrency($collectedByCurrency),
        ]);
    }

    private function formatByCurrency(array $byCurrency): string
    {
        if ($byCurrency === []) {
            return '0.00';
        }

        return collect($byCurrency)
            ->map(fn (int $minor, string $currency) => Money::minor($minor, $currency)->formatted().' '.$currency)
            ->implode(' · ');
    }
}
