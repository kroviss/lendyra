<?php

namespace App\Livewire\Guarantors;

use App\Models\Guarantor;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\View\View;
use TableWire\Table\BaseTable;
use TableWire\Table\Column;

class Index extends BaseTable
{
    protected function query(): Builder
    {
        return Guarantor::query()
            ->whereHas('loan', fn ($l) => $l->when(auth()->user()?->scopedBranchId(), fn ($q, $branch) => $q->where('branch_id', $branch)))
            ->with(['loan.borrower']);
    }

    protected function columns(): array
    {
        return [
            Column::make('name', __('Guarantor'))->sortable()->searchable(),
            Column::make('phone', __('Phone'))->searchable(),
            Column::make('id_number', __('ID Number'))->searchable(),
            Column::make('relationship', __('Relationship')),
            Column::make('loan.loan_number', __('Loan #')),
            Column::make('loan.borrower.first_name', __('Borrower'))
                ->format(fn ($value, $row) => $row->loan?->borrower?->fullName()),
            Column::make('loan.status', __('Loan status'))->center()->format(
                fn ($value) => $value
                    ? '<span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium '.$value->badgeClass().'">'.e($value->label()).'</span>'
                    : '',
                html: true
            ),
        ];
    }

    protected function searchAlso(): array
    {
        return ['loan.loan_number', 'loan.borrower.first_name', 'loan.borrower.last_name'];
    }

    public function rowUrl(mixed $row): ?string
    {
        return $row->loan ? route('loans.show', $row->loan) : null;
    }

    public function render(): View
    {
        return view('livewire.guarantors.index', ['columns' => $this->columns()]);
    }
}
