<?php

namespace App\Livewire\Reports;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Support\CurrencyScale;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Forward-looking collections sheet: which installments are expected
 * (or overdue) in the chosen window — the cashier's morning list and
 * the field officer's route sheet.
 */
class Collections extends Component
{
    use WithPagination;

    public string $window = 'today';

    public string $search = '';

    protected $queryString = [
        'window' => ['except' => 'today'],
        'search' => ['except' => '', 'as' => 'q'],
    ];

    public function updatedWindow(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        [$from, $to] = match ($this->window) {
            'week' => [today(), today()->endOfWeek()],
            'month' => [today(), today()->endOfMonth()],
            'overdue' => [null, today()->subDay()],
            default => [today(), today()],
        };

        $query = LoanInstallment::query()
            ->whereNull('settled_at')
            ->whereHas('loan', fn ($q) => $q->where('status', LoanStatus::Active)
                ->when(auth()->user()?->scopedBranchId(), fn ($l, $branch) => $l->where(fn ($b) => $b->where('branch_id', $branch)->orWhereNull('branch_id'))))
            ->with(['loan.borrower'])
            ->when($from, fn ($q) => $q->where('due_date', '>=', $from))
            ->where('due_date', '<=', $to)
            ->when(trim($this->search) !== '', function ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';
                $q->whereHas('loan', fn ($l) => $l
                    ->where('loan_number', 'like', $like)
                    ->orWhereHas('borrower', fn ($b) => $b
                        ->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('phone', 'like', $like)));
            })
            ->orderBy('due_date');

        // Expected totals per currency for the whole window (not just this
        // page) — SQL aggregate, grouped per loan then mapped to currency.
        $sums = (clone $query)
            ->reorder()
            ->groupBy('loan_id')
            ->select('loan_id', DB::raw(
                'SUM(CAST(principal_minor AS SIGNED) - CAST(principal_paid_minor AS SIGNED) + CAST(interest_minor AS SIGNED) - CAST(interest_paid_minor AS SIGNED) + CAST(penalty_minor AS SIGNED) - CAST(penalty_paid_minor AS SIGNED)) as due'
            ))
            ->pluck('due', 'loan_id');

        $loanCurrencies = Loan::whereIn('id', $sums->keys())->pluck('currency', 'id');

        $totals = [];
        foreach ($sums as $loanId => $due) {
            $currency = $loanCurrencies[$loanId] ?? 'USD';
            $totals[$currency] = ($totals[$currency] ?? 0) + (int) $due;
        }

        $scales = app(CurrencyScale::class);
        $totalLabel = $totals === []
            ? '0.00'
            : collect($totals)
                ->map(fn (int $minor, string $currency) => $scales->money($minor, $currency)->formatted().' '.$currency)
                ->implode(' · ');

        return view('livewire.reports.collections', [
            'installments' => $query->paginate(25),
            'totalLabel' => $totalLabel,
        ]);
    }
}
