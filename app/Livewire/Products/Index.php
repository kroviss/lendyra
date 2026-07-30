<?php

namespace App\Livewire\Products;

use App\Models\LoanProduct;
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
        return LoanProduct::query()
            ->when($this->activeFilter !== '', fn (Builder $q) => $q->where('is_active', $this->activeFilter === '1'))->withCount('loans');
    }

    protected function columns(): array
    {
        return [
            Column::make('name', __('Name'))->sortable()->searchable(),
            Column::make('code', __('Code'))->searchable(),
            Column::make('annual_rate', __('Rate %/yr'))->right()->sortable(),
            Column::make('term_count', __('Term'))->center(),
            Column::make('method', __('Method'))
                ->format(fn ($value) => str_replace('_', ' ', $value->value)),
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
        return route('products.edit', $row);
    }

    public function render(): View
    {
        return view('livewire.products.index', ['columns' => $this->columns()]);
    }
}
