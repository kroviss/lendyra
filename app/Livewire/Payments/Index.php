<?php

namespace App\Livewire\Payments;

use App\Models\LoanPayment;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\View\View;
use LoanEngine\Money;
use TableWire\Table\BaseTable;
use TableWire\Table\Column;

class Index extends BaseTable
{
    public string $methodFilter = '';
    public string $from = '';
    public string $to = '';

    protected function queryString(): array
    {
        return parent::queryString() + [
            'methodFilter' => ['except' => '', 'as' => 'method'],
            'from' => ['except' => ''],
            'to' => ['except' => ''],
        ];
    }

    public function updated($property): void
    {
        if (in_array($property, ['methodFilter', 'from', 'to'], true)) {
            $this->resetPage();
            $this->clearSelection();
        }
    }

    protected function query(): Builder
    {
        return LoanPayment::query()
            ->with(['loan.borrower', 'receivedBy'])
            ->when(auth()->user()?->scopedBranchId(), fn (Builder $q, int $branch) => $q->whereHas('loan', fn ($l) => $l->where(fn ($b) => $b->where('branch_id', $branch)->orWhereNull('branch_id'))))
            ->when($this->methodFilter !== '', fn (Builder $q) => $q->where('method', $this->methodFilter))
            ->when($this->from !== '', fn (Builder $q) => $q->where('paid_at', '>=', $this->from))
            ->when($this->to !== '', fn (Builder $q) => $q->where('paid_at', '<=', $this->to));
    }

    protected function searchAlso(): array
    {
        return ['loan.loan_number', 'loan.borrower.first_name', 'loan.borrower.last_name', 'loan.borrower.phone'];
    }

    protected function columns(): array
    {
        return [
            Column::make('paid_at', __('Date'))->date()->sortable(),
            Column::make('loan.loan_number', __('Loan #')),
            Column::make('loan.borrower.first_name', __('Borrower'))
                ->format(fn ($value, $row) => $row->loan?->borrower?->fullName()),
            Column::make('amount_minor', __('Amount'))
                ->right()
                ->sortable()
                ->format(fn ($value, $row) => Money::minor((int) $value, $row->loan?->currency ?? 'USD', (int) ($row->loan?->scale ?? 2))->formatted()
                    .' '.($row->loan?->currency ?? '')),
            Column::make('reversed_at', __('Status'))->center()->format(
                fn ($value) => $value
                    ? '<span class="inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">'.__('Reversed').'</span>'
                    : '<span class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">'.__('Posted').'</span>',
                html: true
            ),
            Column::make('method', __('Method'))->center()->badge([
                'cash' => ['label' => __('Cash'), 'class' => 'bg-green-100 text-green-700'],
                'bank' => ['label' => __('Bank transfer'), 'class' => 'bg-blue-100 text-blue-700'],
                'mobile' => ['label' => __('Mobile money'), 'class' => 'bg-purple-100 text-purple-700'],
            ]),
            Column::make('reference', __('Reference'))->searchable(),
            Column::make('receivedBy.name', __('Received by')),
            Column::make('id', __(''))->actions()->format(
                fn ($value, $row) => $row->loan
                    ? '<a href="'.route('loans.receipt', [$row->loan_id, $value]).'" target="_blank" onclick="event.stopPropagation()" class="text-xs text-indigo-600 hover:underline">'.e(__('Receipt')).'</a>'
                    : '',
                html: true
            ),
        ];
    }

    public function rowUrl(mixed $row): ?string
    {
        return $row->loan ? route('loans.show', $row->loan) : null;
    }

    protected function canExport(): bool
    {
        return in_array(auth()->user()?->role, ['admin', 'manager', 'accountant'], true);
    }

    public function setToday(): void
    {
        $this->from = today()->format('Y-m-d');
        $this->to = today()->format('Y-m-d');
        $this->resetPage();
    }

    public function render(): View
    {
        // Totals for the CURRENT filter+search window (excludes reversed),
        // aggregated in SQL — never hydrate the whole payments table.
        $query = $this->query()->whereNull('reversed_at');
        $this->applySearch($query);

        $rows = $query
            ->withoutEagerLoads()
            ->join('loans', 'loans.id', '=', 'loan_payments.loan_id')
            ->groupBy('loans.currency', 'loan_payments.method')
            ->select('loans.currency', 'loan_payments.method', \Illuminate\Support\Facades\DB::raw('SUM(amount_minor) as total'))
            ->get();

        $totals = [];
        $byMethod = [];
        foreach ($rows as $row) {
            $totals[$row->currency] = ($totals[$row->currency] ?? 0) + (int) $row->total;
            $byMethod[$row->method][$row->currency] = ($byMethod[$row->method][$row->currency] ?? 0) + (int) $row->total;
        }

        $format = fn (array $sums) => collect($sums)
            ->map(fn (int $minor, string $currency) => Money::minor($minor, $currency)->formatted().' '.$currency)
            ->implode(' · ');

        return view('livewire.payments.index', [
            'columns' => $this->columns(),
            'totalLabel' => $totals === [] ? '0.00' : $format($totals),
            'methodTotals' => collect($byMethod)->map($format)->all(),
        ]);
    }
}
