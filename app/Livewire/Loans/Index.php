<?php

namespace App\Livewire\Loans;

use App\Models\Loan;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\View\View;
use TableWire\Table\BaseTable;
use TableWire\Table\Column;

class Index extends BaseTable
{
    protected function query(): Builder
    {
        return Loan::query()->with(['borrower', 'product']);
    }

    protected function columns(): array
    {
        return [
            Column::make('loan_number', __('Loan #'))->sortable()->searchable(),
            Column::make('borrower.first_name', __('Borrower'))
                ->searchable()
                ->format(fn ($value, $row) => $row->borrower?->fullName()),
            Column::make('product.name', __('Product')),
            Column::make('principal_minor', __('Principal'))
                ->right()
                ->sortable()
                ->format(fn ($value, $row) => $row->principal()->toDecimalString().' '.$row->currency),
            Column::make('status', __('Status'))->center()->format(
                fn ($value) => '<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium '.match ($value->value) {
                    'active' => 'bg-green-100 text-green-700',
                    'approved' => 'bg-blue-100 text-blue-700',
                    'closed' => 'bg-gray-100 text-gray-600',
                    'written_off' => 'bg-red-100 text-red-700',
                    default => 'bg-yellow-100 text-yellow-700',
                }.'">'.e(str_replace('_', ' ', $value->value)).'</span>',
                html: true
            ),
            Column::make('disbursed_at', __('Disbursed'))->date()->sortable(),
        ];
    }

    public function rowUrl(mixed $row): ?string
    {
        return route('loans.show', $row);
    }

    public function render(): View
    {
        return view('livewire.loans.index', ['columns' => $this->columns()]);
    }
}
