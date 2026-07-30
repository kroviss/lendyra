<?php

namespace App\Livewire\Loans;

use App\Models\Loan;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\View\View;
use TableWire\Table\BaseTable;
use TableWire\Table\Column;

class Index extends BaseTable
{
    public string $statusFilter = '';

    protected function queryString(): array
    {
        return parent::queryString() + [
            'statusFilter' => ['except' => '', 'as' => 'status'],
        ];
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    protected function query(): Builder
    {
        return Loan::query()
            ->with(['borrower', 'product'])
            ->when(auth()->user()?->scopedBranchId(), fn (Builder $q, int $branch) => $q->where('branch_id', $branch))
            ->when($this->statusFilter === 'overdue', fn (Builder $q) => $q
                ->where('status', \App\Enums\LoanStatus::Active)
                ->whereHas('installments', fn ($i) => $i->whereNull('settled_at')->whereDate('due_date', '<', today())))
            ->when($this->statusFilter !== '' && $this->statusFilter !== 'overdue',
                fn (Builder $q) => $q->where('status', $this->statusFilter));
    }

    protected function searchAlso(): array
    {
        return ['borrower.last_name', 'borrower.phone', 'borrower.id_number'];
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
                ->format(fn ($value, $row) => $row->principal()->formatted().' '.$row->currency),
            Column::make('status', __('Status'))->center()->format(
                fn ($value) => '<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium '
                    .$value->badgeClass().'">'.e($value->label()).'</span>',
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
