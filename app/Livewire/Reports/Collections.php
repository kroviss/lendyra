<?php

namespace App\Livewire\Reports;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Support\CurrencyScale;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /** Same PII bar as the borrower export — cashiers cannot bulk-export. */
    protected function canExport(): bool
    {
        return in_array(auth()->user()?->role, ['admin', 'manager', 'loan_officer'], true);
    }

    #[Computed]
    public function exportAllowed(): bool
    {
        return $this->canExport();
    }

    /**
     * The route sheet leaves the office: stream the whole current window
     * as CSV (same filters as the page, capped like tablewire exports).
     */
    public function exportCsv(): StreamedResponse
    {
        abort_unless($this->canExport(), 403);

        $query = $this->buildQuery();

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('Due date'), __('Loan #'), __('Borrower'), __('Phone'), __('Principal'), __('Interest'), __('Penalty'), __('Total due'), __('Currency')]);

            $limit = (int) config('tablewire.export_limit', 10000);

            for ($offset = 0; $offset < $limit; $offset += 500) {
                $rows = (clone $query)->skip($offset)->take(min(500, $limit - $offset))->get();

                foreach ($rows as $installment) {
                    $due = $installment->toDue();
                    $clean = fn (string $v) => preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;

                    fputcsv($out, [
                        $installment->due_date->format('Y-m-d'),
                        $clean($installment->loan->loan_number),
                        $clean($installment->loan->borrower->fullName()),
                        $clean((string) $installment->loan->borrower->phone),
                        $due->principalDue->toDecimalString(),
                        $due->interestDue->toDecimalString(),
                        $due->penaltyDue->toDecimalString(),
                        $due->totalDue()->toDecimalString(),
                        $installment->loan->currency,
                    ]);
                }

                if ($rows->count() < 500) {
                    break;
                }
            }

            fclose($out);
        }, 'collections-'.$this->window.'-'.now()->format('Ymd-His').'.csv');
    }

    private function buildQuery()
    {
        [$from, $to] = match ($this->window) {
            'week' => [today(), today()->endOfWeek()],
            'month' => [today(), today()->endOfMonth()],
            'overdue' => [null, today()->subDay()],
            default => [today(), today()],
        };

        return LoanInstallment::query()
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
            // due_date is highly non-unique; without a stable tiebreaker the
            // export's skip/take chunks and page 1→2 can repeat or drop rows.
            ->orderBy('due_date')
            ->orderBy('id');
    }

    public function render(): View
    {
        $query = $this->buildQuery();

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
        ])->title(__('Collections'));
    }
}
