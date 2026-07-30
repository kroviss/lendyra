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
            ->when($this->methodFilter !== '', fn (Builder $q) => $q->where('method', $this->methodFilter))
            ->when($this->from !== '', fn (Builder $q) => $q->whereDate('paid_at', '>=', $this->from))
            ->when($this->to !== '', fn (Builder $q) => $q->whereDate('paid_at', '<=', $this->to));
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
                ->format(fn ($value, $row) => ($row->reversed_at ? '⊘ ' : '')
                    .Money::minor((int) $value, $row->loan?->currency ?? 'USD', (int) ($row->loan?->scale ?? 2))->toDecimalString()
                    .' '.($row->loan?->currency ?? '')),
            Column::make('method', __('Method'))->center()->badge([
                'cash' => 'bg-green-100 text-green-700',
                'bank' => 'bg-blue-100 text-blue-700',
                'mobile' => 'bg-purple-100 text-purple-700',
            ]),
            Column::make('reference', __('Reference'))->searchable(),
            Column::make('receivedBy.name', __('Received by')),
        ];
    }

    public function rowUrl(mixed $row): ?string
    {
        return $row->loan ? route('loans.show', $row->loan) : null;
    }

    public function render(): View
    {
        // Totals for the CURRENT filter+search window (excludes reversed).
        $query = $this->query()->whereNull('reversed_at');
        $this->applySearch($query);

        $totals = [];
        $byMethod = [];
        $query->chunkById(300, function ($payments) use (&$totals, &$byMethod) {
            foreach ($payments as $payment) {
                $currency = $payment->loan?->currency ?? 'USD';
                $totals[$currency] = ($totals[$currency] ?? 0) + (int) $payment->amount_minor;
                $byMethod[$payment->method][$currency] = ($byMethod[$payment->method][$currency] ?? 0) + (int) $payment->amount_minor;
            }
        });

        $format = fn (array $sums) => collect($sums)
            ->map(fn (int $minor, string $currency) => Money::minor($minor, $currency)->toDecimalString().' '.$currency)
            ->implode(' · ');

        return view('livewire.payments.index', [
            'columns' => $this->columns(),
            'totalLabel' => $totals === [] ? '0.00' : $format($totals),
            'methodTotals' => collect($byMethod)->map($format)->all(),
        ]);
    }
}
