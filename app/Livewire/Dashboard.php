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
        $activeLoans = Loan::where('status', LoanStatus::Active)->count();

        // Sums are kept per currency — minor units of different
        // currencies must never be added together.
        $outstandingByCurrency = [];

        Loan::query()
            ->where('status', LoanStatus::Active)
            ->with('installments')
            ->chunkById(200, function ($loans) use (&$outstandingByCurrency) {
                foreach ($loans as $loan) {
                    $outstandingByCurrency[$loan->currency] =
                        ($outstandingByCurrency[$loan->currency] ?? 0) + $loan->principalOutstanding()->minor;
                }
            });

        $overdueCount = LoanInstallment::query()
            ->whereHas('loan', fn ($q) => $q->where('status', LoanStatus::Active))
            ->whereNull('settled_at')
            ->whereDate('due_date', '<', today())
            ->count();

        $collectedByCurrency = [];

        LoanPayment::query()
            ->whereNull('reversed_at')
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->with('loan:id,currency')
            ->chunkById(200, function ($payments) use (&$collectedByCurrency) {
                foreach ($payments as $payment) {
                    $currency = $payment->loan->currency;
                    $collectedByCurrency[$currency] = ($collectedByCurrency[$currency] ?? 0) + (int) $payment->amount_minor;
                }
            });

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

        LoanPayment::query()
            ->whereNull('reversed_at')
            ->whereDate('paid_at', '>=', now()->startOfMonth()->subMonths(5))
            ->whereHas('loan', fn ($q) => $q->where('currency', $primaryCurrency))
            ->chunkById(200, function ($payments) use (&$chart) {
                foreach ($payments as $payment) {
                    $key = $payment->paid_at->format('M');
                    if (array_key_exists($key, $chart)) {
                        $chart[$key] += (int) $payment->amount_minor;
                    }
                }
            });

        $chartMax = max(1, max($chart));
        $chartBars = collect($chart)->map(fn (int $minor, string $label) => [
            'label' => $label,
            'value' => Money::minor($minor, $primaryCurrency)->toDecimalString(),
            'height' => (int) round($minor / $chartMax * 100),
        ])->values()->all();

        return view('livewire.dashboard', [
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
            ->map(fn (int $minor, string $currency) => Money::minor($minor, $currency)->toDecimalString().' '.$currency)
            ->implode(' · ');
    }
}
