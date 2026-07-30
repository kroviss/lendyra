<?php

namespace App\Livewire\Reports;

use App\Enums\LoanStatus;
use App\Models\LoanInstallment;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use LoanEngine\Money;

/**
 * Forward-looking collections sheet: which installments are expected
 * (or overdue) in the chosen window — the cashier's morning list and
 * the field officer's route sheet.
 */
class Collections extends Component
{
    use WithPagination;

    public string $window = 'today';

    protected $queryString = [
        'window' => ['except' => 'today'],
    ];

    public function updatedWindow(): void
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
            ->whereHas('loan', fn ($q) => $q->where('status', LoanStatus::Active))
            ->with(['loan.borrower'])
            ->when($from, fn ($q) => $q->whereDate('due_date', '>=', $from))
            ->whereDate('due_date', '<=', $to)
            ->orderBy('due_date');

        // Expected totals per currency for the whole window (not just this page).
        $totals = [];
        (clone $query)->chunkById(300, function ($installments) use (&$totals) {
            foreach ($installments as $installment) {
                $due = $installment->toDue()->totalDue();
                $totals[$due->currency] = ($totals[$due->currency] ?? 0) + $due->minor;
            }
        });

        $totalLabel = $totals === []
            ? '0.00'
            : collect($totals)
                ->map(fn (int $minor, string $currency) => Money::minor($minor, $currency)->toDecimalString().' '.$currency)
                ->implode(' · ');

        return view('livewire.reports.collections', [
            'installments' => $query->paginate(25),
            'totalLabel' => $totalLabel,
        ]);
    }
}
