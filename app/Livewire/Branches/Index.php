<?php

namespace App\Livewire\Branches;

use App\Models\Branch;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\View\View;
use TableWire\Table\BaseTable;
use TableWire\Table\Column;

class Index extends BaseTable
{
    public string $activeFilter = '';

    protected function queryString(): array
    {
        return parent::queryString() + [
            'activeFilter' => ['except' => '', 'as' => 'active'],
        ];
    }

    public function updatedActiveFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    protected function query(): Builder
    {
        return Branch::query()
            ->when($this->activeFilter !== '', fn (Builder $q) => $q->where('is_active', $this->activeFilter === '1'))->withCount(['users', 'loans']);
    }

    protected function columns(): array
    {
        return [
            Column::make('code', __('Code'))->sortable()->searchable(),
            Column::make('name', __('Name'))->sortable()->searchable(),
            Column::make('phone', __('Phone')),
            Column::make('users_count', __('Users'))->center()->sortable(),
            Column::make('loans_count', __('Loans'))->center()->sortable(),
            Column::make('is_active', __('Status'))->center()->format(
                fn ($value) => $value
                    ? '<span class="inline-flex rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700">'.__('Active').'</span>'
                    : '<span class="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">'.__('Inactive').'</span>',
                html: true
            ),
        ];
    }

    public function rowUrl(mixed $row): ?string
    {
        return route('branches.edit', $row);
    }

    public function render(): View
    {
        return view('livewire.branches.index', ['columns' => $this->columns()]);
    }
}
