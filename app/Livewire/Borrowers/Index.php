<?php

namespace App\Livewire\Borrowers;

use App\Models\Borrower;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\View\View;
use TableWire\Table\BaseTable;
use TableWire\Table\Column;

class Index extends BaseTable
{
    protected function query(): Builder
    {
        return Borrower::query()
            ->withCount('loans')
            ->when(auth()->user()?->scopedBranchId(), fn (Builder $q, int $branch) => $q->where('branch_id', $branch));
    }

    protected function columns(): array
    {
        return [
            Column::make('first_name', __('Name'))
                ->sortable()
                ->searchable()
                ->format(fn ($value, $row) => $row->fullName()),
            Column::make('phone', __('Phone'))->searchable(),
            Column::make('id_number', __('ID Number'))->searchable(),
            Column::make('loans_count', __('Loans'))->center()->sortable(),
            Column::make('created_at', __('Registered'))->date()->sortable(),
        ];
    }

    protected function searchAlso(): array
    {
        return ['last_name', 'email'];
    }

    public function rowUrl(mixed $row): ?string
    {
        return route('borrowers.show', $row);
    }

    public function render(): View
    {
        return view('livewire.borrowers.index', ['columns' => $this->columns()]);
    }
}
