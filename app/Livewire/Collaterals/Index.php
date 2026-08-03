<?php

namespace App\Livewire\Collaterals;

use App\Models\Collateral;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\View\View;
use LoanEngine\Money;
use TableWire\Table\BaseTable;
use TableWire\Table\Column;

class Index extends BaseTable
{
    public string $statusFilter = '';

    /** Same PII bar as the borrower export — cashiers cannot bulk-export. */
    protected function canExport(): bool
    {
        return in_array(auth()->user()?->role, ['admin', 'manager', 'loan_officer'], true);
    }

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
        return Collateral::query()
            ->whereHas('loan', fn ($l) => $l->when(auth()->user()?->scopedBranchId(), fn ($q, $branch) => $q->where(fn ($b) => $b->where('branch_id', $branch)->orWhereNull('branch_id'))))
            ->with(['loan.borrower'])
            ->when($this->statusFilter !== '', fn (Builder $q) => $q->where('status', $this->statusFilter));
    }

    protected function searchAlso(): array
    {
        return ['loan.loan_number', 'loan.borrower.first_name', 'loan.borrower.last_name'];
    }

    protected function columns(): array
    {
        return [
            Column::make('loan.loan_number', __('Loan #')),
            Column::make('loan.borrower.first_name', __('Borrower'))
                ->format(fn ($value, $row) => $row->loan?->borrower?->fullName()),
            Column::make('type', __('Type'))->sortable()->searchable(),
            Column::make('description', __('Description'))->searchable(),
            Column::make('estimated_value_minor', __('Est. value'))
                ->right()
                ->sortable()
                ->format(fn ($value, $row) => Money::minor((int) $value, $row->loan?->currency ?? 'USD', (int) ($row->loan?->scale ?? 2))->formatted().' '.($row->loan?->currency ?? '')),
            Column::make('loan.status', __('Loan status'))->center()->format(
                fn ($value) => $value
                    ? '<span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium '.$value->badgeClass().'">'.e($value->label()).'</span>'
                    : '',
                html: true
            ),
            Column::make('status', __('Status'))->center()->sortable()->badge([
                'held' => ['label' => __('Held'), 'class' => 'bg-green-100 text-green-700'],
                'released' => ['label' => __('Released'), 'class' => 'bg-gray-100 text-gray-600'],
            ]),
            Column::make('released_at', __('Released on'))->date(),
        ];
    }

    public function rowUrl(mixed $row): ?string
    {
        return $row->loan ? route('loans.show', $row->loan) : null;
    }

    public function render(): View
    {
        return view('livewire.collaterals.index', ['columns' => $this->columns()])->title(__('Collateral'));
    }
}
