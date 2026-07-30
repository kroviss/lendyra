<?php

namespace App\Livewire\Users;

use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\View\View;
use TableWire\Table\BaseTable;
use TableWire\Table\Column;

class Index extends BaseTable
{
    protected function query(): Builder
    {
        return User::query()->with('branch');
    }

    protected function columns(): array
    {
        return [
            Column::make('name', __('Name'))->sortable()->searchable(),
            Column::make('email', __('Email'))->searchable(),
            Column::make('role', __('Role'))->center()->badge([
                'admin' => 'bg-purple-100 text-purple-700',
                'manager' => 'bg-blue-100 text-blue-700',
                'loan_officer' => 'bg-green-100 text-green-700',
                'cashier' => 'bg-yellow-100 text-yellow-700',
                'accountant' => 'bg-gray-100 text-gray-700',
            ]),
            Column::make('branch.name', __('Branch')),
            Column::make('is_active', __('Status'))->center()->format(
                fn ($value) => $value
                    ? '<span class="inline-flex rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700">'.__('Active').'</span>'
                    : '<span class="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">'.__('Disabled').'</span>',
                html: true
            ),
        ];
    }

    public function rowUrl(mixed $row): ?string
    {
        return route('users.edit', $row);
    }

    public function render(): View
    {
        return view('livewire.users.index', ['columns' => $this->columns()]);
    }
}
